<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Pebble\Config\{Environment, LoggingConfig, ConfigurationException};
use Pebble\Logging\{LoggerFactory, Redactor};
use Pebble\Note\Note;

function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$root = sys_get_temp_dir() . '/pebble-logging-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
mkdir($root . '/public', 0700);
$oldErrorLog = ini_get('error_log');
try {
    $defaults = LoggingConfig::fromEnvironment(new Environment($root, []));
    check($defaults->enabled && $defaults->rotation === 'daily' && $defaults->maxFiles === 30
        && $defaults->level === 'info' && $defaults->format === 'json' && !$defaults->inDebug, 'Defaults');
    foreach (['LOG_LEVEL' => 'verbose', 'LOG_FORMAT' => 'xml', 'LOG_ROTATION' => 'hourly',
        'LOG_MAX_FILES' => '-1', 'LOG_ENABLED' => 'typo', 'LOG_IN_DEBUG' => '', 'LOG_FILENAME' => '../public/leak.log',
        'LOG_DIRECTORY' => 'public/logs'] as $key => $value) {
        try { LoggingConfig::fromEnvironment(new Environment($root, [$key => $value])); }
        catch (ConfigurationException) { continue; }
        throw new RuntimeException('Accepted invalid ' . $key);
    }
    $timezone = new DateTimeZone('Europe/Rome');
    $now = new DateTimeImmutable('2026-03-28 23:59:59', $timezone);
    $clock = static function () use (&$now): DateTimeImmutable { return $now; };
    $config = new LoggingConfig($root . '/daily', 'webhook.log', maxFiles: 2);
    $logger = (new LoggerFactory($config, $timezone, new Redactor(), false, $clock))->create();
    $mask = umask();
    $logger->info('Before midnight.', ['event' => 'test']);
    $now = new DateTimeImmutable('2026-03-29 00:00:01', $timezone);
    $logger->info('After midnight.');
    $now = new DateTimeImmutable('2026-03-30 00:00:01', $timezone);
    $logger->info('After DST.');
    check(umask() === $mask, 'Restore caller umask');
    $files = glob($root . '/daily/*.log');
    check(count($files) === 2 && !file_exists($root . '/daily/webhook-2026-03-28.log'), 'Retention includes current file');
    check((fileperms($root . '/daily') & 0777) === 0700, 'Private directory permissions');
    foreach ($files as $file) { check((fileperms($file) & 0777) === 0600, 'Private file permissions'); }
    $record = json_decode(trim(file_get_contents($root . '/daily/webhook-2026-03-30.log')), true, flags: JSON_THROW_ON_ERROR);
    check(str_contains($record['datetime'], '+02:00'), 'APP_TIMEZONE after DST');
    unset($logger);

    $now = new DateTimeImmutable('2026-01-31 23:59:59', $timezone);
    $logger = (new LoggerFactory(new LoggingConfig($root . '/monthly', 'webhook.log', rotation: 'monthly', maxFiles: 0),
        $timezone, new Redactor(), false, $clock))->create('operations');
    // This file is outside the rotating handler's filename pattern.
    $logger->info('January.');
    file_put_contents($root . '/monthly/notification-state.json', '{}');
    foreach (['2026-02-01', '2026-03-01'] as $date) {
        $now = new DateTimeImmutable($date, $timezone);
        $logger->info('Next month.');
    }
    check(count(glob($root . '/monthly/*.log')) === 3, 'Zero retention means unlimited');
    check(file_exists($root . '/monthly/notification-state.json'), 'Retention excludes notification state');
    unset($logger);
    foreach ([[false, false, false], [true, true, false], [true, true, true]] as [$enabled, $debug, $inDebug]) {
        $path = $root . '/policy-' . (int)$enabled . (int)$debug . (int)$inDebug;
        $logger = (new LoggerFactory(new LoggingConfig($path, 'webhook.log', enabled: $enabled, rotation: 'none', inDebug: $inDebug),
            $timezone, new Redactor(), $debug))->create();
        $logger->info('Success.');
        $logger->error('Failure.');
        check(is_dir($path) === ($enabled && $inDebug), 'Same sink policy for success and failure');
    }
    $logger = (new LoggerFactory(new LoggingConfig($root . '/text', 'webhook.log', level: 'error', format: 'text', rotation: 'none'),
        $timezone, new Redactor(), false))->create('notifications');
    $logger->warning('Filtered.');
    $logger->error('Included.');
    $text = file_get_contents($root . '/text/webhook.log');
    check(!str_contains($text, 'Filtered') && str_contains($text, 'notifications.ERROR'), 'Text output and severity');

    $secret = 'private password/@';
    $redactor = new Redactor(['SMTP_PASSWORD' => $secret, 'WEBHOOK_TOKEN' => 'private-token']);
    $trustedId = str_repeat('a', 32);
    $shortSecretRedactor = new Redactor(['OTHER_SECRET' => 'aaa'], $trustedId);
    $truncated = false;
    check($shortSecretRedactor->value(['requestId' => $trustedId, 'diagnostics' => 'aaa'], $truncated)
        === ['requestId' => $trustedId, 'diagnostics' => '[redacted]'], 'Trusted generated ID survives short secret collisions');
    $date = new DateTimeImmutable();
    $redactor->protectNote(new Note('PRIVATE NOTE CONTENT', [], $date, $date, $timezone, []));
    $object = new class implements Stringable, JsonSerializable {
        public function __toString(): string { throw new RuntimeException('Must not stringify'); }
        public function jsonSerialize(): mixed { throw new RuntimeException('Must not serialize'); }
    };
    $logger = (new LoggerFactory(new LoggingConfig($root . '/redaction', 'webhook.log', rotation: 'none'),
        $timezone, $redactor, false))->create();
    $logger->error('Failure.', ['event' => 'operation_failed', 'requestId' => str_repeat('a', 32),
        'diagnostics' => ['providerResponse' => $secret . ' ' . rawurlencode($secret) . ' ' . base64_encode($secret)
            . ' smtp://someone:unknown@example.test AUTH PLAIN encoded\nBearer something',
            'nested' => new RuntimeException('PRIVATE NOTE CONTENT private-token'), 'large' => str_repeat('X', 4096)],
        'postFields' => ['hidden'], 'audio' => 'binary', 'transcription' => 'hidden', 'object' => $object,
        'arguments' => ['hidden'], 'authorization' => 'hidden']);
    $text = file_get_contents($root . '/redaction/webhook.log');
    foreach ([$secret, rawurlencode($secret), base64_encode($secret), 'private-token', 'PRIVATE NOTE CONTENT',
        'someone:unknown', 'something', 'hidden'] as $sensitive) { check(!str_contains($text, $sensitive), 'Redact ' . $sensitive); }
    $record = json_decode(trim($text), true, flags: JSON_THROW_ON_ERROR);
    check($record['extra']['diagnosticsTruncated'] && str_contains($text, '[truncated]'), 'Marked truncation');
    check(!str_contains($text, 'trace') && $record['context']['object'] === '[object/resource omitted]', 'Safe exceptions/objects');

    ini_set('error_log', $root . '/native.log');
    file_put_contents($root . '/blocked', 'file blocks directory');
    $logger = (new LoggerFactory(new LoggingConfig($root . '/blocked/logs', 'webhook.log', rotation: 'none'),
        $timezone, $redactor, false))->create();
    $logger->error('Failure private-token', ['diagnostics' => $secret]);
    $fallback = file_get_contents($root . '/native.log');
    check(str_contains($fallback, 'logging fallback') && !str_contains($fallback, $secret)
        && !str_contains($fallback, 'private-token'), 'Safe handler failure fallback');
    symlink($root . '/public/exposed.log', $root . '/symlink-2026-03-01.log');
    $now = new DateTimeImmutable('2026-03-01', $timezone);
    $logger = (new LoggerFactory(new LoggingConfig($root, 'symlink.log'), $timezone, $redactor, false, $clock))->create();
    $logger->error('No public write.');
    check(!file_exists($root . '/public/exposed.log'), 'Reject generated filename symlink');
    echo "Logging checks passed: settings, timezone/DST, rotation, retention, permissions, levels, debug policy, redaction, truncation, safe fallback.\n";
} finally {
    ini_set('error_log', $oldErrorLog);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
