<?php
declare(strict_types=1);
namespace Pebble\Notification;

use Closure;
use RuntimeException;

/** The lock covers read/check/send/commit, not just the final write. */
final readonly class CooldownStore
{
    public function __construct(private string $directory) {}

    public function prepare(): void
    {
        $mask = umask(0077);
        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
                throw new RuntimeException('Cannot create notification state directory.');
            }
            if (!is_writable($this->directory)) {
                throw new RuntimeException('Notification state directory is not writable.');
            }
            foreach (['cooldown.lock', 'cooldown.json'] as $name) {
                if (is_link($this->directory . '/' . $name)) {
                    throw new RuntimeException('Refusing notification state symlink.');
                }
            }
        } finally { umask($mask); }
    }

    /** Callback returns true only when SMTP accepted the alert. */
    public function deliver(array $fingerprints, int $now, int $cooldown, Closure $send): string
    {
        $this->prepare();
        $mask = umask(0077);
        $lock = null;
        try {
            $lock = @fopen($this->directory . '/cooldown.lock', 'c');
            if ($lock === false || !chmod($this->directory . '/cooldown.lock', 0600)) {
                throw new RuntimeException('Cannot open notification lock.');
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) { return 'suppressed'; }
            $path = $this->directory . '/cooldown.json';
            $state = file_exists($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
            if (!is_array($state)) { throw new RuntimeException('Invalid notification state.'); }
            foreach ($state as $key => $entry) {
                if (!is_array($entry) || !isset($entry['until']) || !is_int($entry['until'])) {
                    throw new RuntimeException('Invalid notification state.');
                }
                if ($entry['until'] <= $now) { unset($state[$key]); }
            }
            $eligible = array_values(array_filter($fingerprints, static fn (string $key): bool => !isset($state[$key])));
            if ($eligible === []) { return 'suppressed'; }
            // Reserve the short retry window before network I/O. A process crash
            // cannot cause every concurrent request to retry SMTP immediately.
            $retry = 30;
            foreach ($eligible as $key) { $state[$key] = ['until' => $now + $retry, 'delivered' => false]; }
            $this->save($path, $state);
            $sent = $send();
            if ($sent) {
                foreach ($eligible as $key) {
                    $state[$key] = ['until' => $now + $cooldown, 'delivered' => true];
                }
                $this->save($path, $state);
            }
            return $sent ? 'sent' : 'failed';
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            umask($mask);
        }
    }

    private function save(string $path, array $state): void
    {
        $temporary = tempnam($this->directory, '.cooldown-');
        if ($temporary === false) { throw new RuntimeException('Cannot write notification state.'); }
        try {
            if (!chmod($temporary, 0600)
                || file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
                || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot commit notification state.');
            }
        } finally { if (file_exists($temporary)) { @unlink($temporary); } }
    }
}
