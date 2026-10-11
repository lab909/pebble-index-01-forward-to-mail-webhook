<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Pebble\Bootstrap;
use Pebble\Config\{AlertConfig, Environment};
use Pebble\Http\{ErrorResponder, Request};
use Pebble\Logging\Redactor;
use Pebble\Notification\{CooldownStore, FailureNotifier};
use Pebble\Note\Note;
use Pebble\Operation\{ExecutionReport, ExecutionEntry, OperationResult, ErrorCategory, HttpFailure};
use Psr\Log\{AbstractLogger, NullLogger};
use Symfony\Component\Mailer\{MailerInterface, Envelope};
use Symfony\Component\Mime\{RawMessage, Email};

function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$root = sys_get_temp_dir() . '/pebble-notifications-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
mkdir($root . '/public', 0700);
mkdir($root . '/config', 0700);
copy(dirname(__DIR__) . '/config/operations.php', $root . '/config/operations.php');
$originalEnv = $_ENV;
$originalServer = $_SERVER;
$values = ['FAILURE_EMAIL_ENABLED' => 'true', 'FAILURE_EMAIL_FROM' => 'alerts@example.com',
    'FAILURE_EMAIL_TO' => 'one@example.com, two@example.com', 'FAILURE_EMAIL_STATE_DIRECTORY' => 'state',
    'FAILURE_EMAIL_SMTP_SCHEME' => 'smtp', 'FAILURE_EMAIL_SMTP_HOST' => 'localhost', 'FAILURE_EMAIL_SMTP_PORT' => '587',
    'FAILURE_EMAIL_SMTP_USERNAME' => 'alert-user', 'FAILURE_EMAIL_SMTP_PASSWORD' => 'alert-secret'];
$mailer = new class implements MailerInterface {
    public array $emails = [];
    public bool $fail = false;
    public function send(RawMessage $message, ?Envelope $envelope = null): void {
        if ($this->fail) { throw new RuntimeException('alert-secret SMTP failed'); }
        $this->emails[] = $message;
    }
};
$logger = new class extends AbstractLogger {
    public array $events = [];
    public function log($level, string|Stringable $message, array $context = []): void { $this->events[] = $context['event'] ?? ''; }
};
$now = new DateTimeImmutable('2026-04-01', new DateTimeZone('Europe/Rome'));
$clock = static function () use (&$now): DateTimeImmutable { return $now; };
$config = AlertConfig::fromEnvironment(new Environment($root, $values));
$make = static function (string $id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ?AlertConfig $settings = null) use ($values, $config, $mailer, $logger, $clock): FailureNotifier {
    $redactor = new Redactor($values, $id);
    $date = new DateTimeImmutable();
    $redactor->protectNote(new Note('PRIVATE NOTE CONTENT', [], $date, $date, new DateTimeZone('Europe/Rome'), []));
    return new FailureNotifier($settings ?? $config, $id, new DateTimeZone('Europe/Rome'), $redactor,
        fn () => $logger, fn () => $mailer, $clock);
};
$report = static function (string $task = 'task-one', int $provider = 403): ExecutionReport {
    return new ExecutionReport([], [
        'local_storage' => new ExecutionEntry('local_storage', new DateTimeImmutable(), 1, OperationResult::success(['folder' => '/private/retained'])),
        'email' => new ExecutionEntry('email', new DateTimeImmutable(), 2, OperationResult::failure(ErrorCategory::Transport, HttpFailure::BadGateway, 'SMTP failure. PRIVATE NOTE CONTENT')),
        'todoist' => new ExecutionEntry('todoist', new DateTimeImmutable(), 3, OperationResult::failure(ErrorCategory::Remote, HttpFailure::BadGateway,
            'API failure.', ['todoistDebug' => ['stage' => 'info.txt upload', 'message' => 'Bearer secret alert-secret', 'large' => str_repeat('x', 4096)],
                'postFields' => ['private'], 'transcription' => 'private', 'audio' => 'private'], externalIds: ['taskId' => $task],
            partial: true, providerStatus: $provider)),
    ], []);
};
try {
    $notifier = $make(); $notifier->report($report()); $notifier->finalize(); $notifier->finalize();
    check(count($mailer->emails) === 1, 'Aggregate and finalize once');
    $email = $mailer->emails[0];
    check(count($email->getTo()) === 2 && count($email->getAttachments()) === 0, 'Recipients without attachments');
    $body = $email->getTextBody();
    check(str_contains($body, '/private/retained') && str_contains($body, 'task-one') && str_contains($body, '+02:00'), 'Partial success, IDs, timezone');
    check(!str_contains($body, 'PRIVATE NOTE CONTENT') && !str_contains($body, 'alert-secret') && !str_contains($body, 'Bearer secret') && !str_contains($body, '"private"'), 'Sanitize before email formatting');
    check(str_contains($body, 'diagnosticsTruncated'), 'Truncation marker');
    $notifier = $make(str_repeat('b', 32)); $notifier->report($report('changing-task')); $notifier->finalize();
    check(count($mailer->emails) === 1 && end($logger->events) === 'notification_suppressed', 'Stable persistent fingerprint');
    $notifier = $make(); $notifier->report($report('task-two', 429)); $notifier->finalize();
    check(count($mailer->emails) === 2, 'Different provider failure remains reportable');
    $now = $now->modify('+301 seconds');
    $notifier = $make(); $notifier->report($report()); $notifier->finalize();
    check(count($mailer->emails) === 3, 'Cooldown expires');
    $state = json_decode(file_get_contents($config->stateDirectory . '/cooldown.json'), true);
    check(count(array_filter($state, fn ($entry) => $entry['delivered'])) === count($state), 'Successful state');
    foreach (['cooldown.json', 'cooldown.lock'] as $file) { check((fileperms($config->stateDirectory . '/' . $file) & 0777) === 0600, 'State permissions'); }
    check((fileperms($config->stateDirectory) & 0777) === 0700, 'Directory permissions');
    $now = $now->modify('+301 seconds'); $mailer->fail = true;
    $notifier = $make(); $notifier->report($report()); $notifier->finalize();
    check(end($logger->events) === 'notification_failed', 'Failed notification logged once');
    $state = json_decode(file_get_contents($config->stateDirectory . '/cooldown.json'), true);
    check(count(array_filter($state, fn ($entry) => !$entry['delivered'])) === count($state), 'Failed attempt never marked delivered');
    $mailer->fail = false;
    $notifier = $make(); $notifier->report($report()); $notifier->finalize();
    check(count($mailer->emails) === 3, 'Failed-send short retry suppression');
    $now = $now->modify('+31 seconds');
    $notifier = $make(); $notifier->report($report()); $notifier->finalize();
    check(count($mailer->emails) === 4, 'Failed-send window expires sooner');
    $store = new CooldownStore($root . '/concurrent'); $store->prepare();
    $lock = fopen($root . '/concurrent/cooldown.lock', 'c'); flock($lock, LOCK_EX);
    check($store->deliver(['test'], time(), 300, fn () => throw new RuntimeException('Must not send')) === 'suppressed', 'Concurrent lock suppresses without waiting');
    flock($lock, LOCK_UN); fclose($lock);
    $criticalConfig = AlertConfig::fromEnvironment(new Environment($root, $values + ['FAILURE_EMAIL_LEVEL' => 'critical']));
    $notifier = $make(settings: $criticalConfig); $notifier->report($report()); $notifier->finalize();
    check(count($mailer->emails) === 4, 'Severity independent of file logger');
    $notifier = $make(settings: $criticalConfig); $notifier->configurationFailure('Missing MAIL_TO.'); $notifier->finalize();
    check(count($mailer->emails) === 5, 'Configuration failures alert');

    // Exercise the real hub with only injected SMTP; no account credentials.
    $base = ['WEBHOOK_TOKEN' => 'test-token', 'APP_TIMEZONE' => 'UTC', 'STORE_TO_FOLDER' => 'false',
        'FORWARD_TO_MAIL' => 'false', 'SAVE_TO_TODOIST' => 'false', 'LOG_ENABLED' => 'false'];
    $run = static function (array $settings, array $server = [], array $post = []) use ($root, $base, $mailer): int {
        $_ENV = []; $_SERVER = [];
        $settings = array_replace($base, $settings);
        file_put_contents($root . '/.env', implode("\n", array_map(fn ($key, $value) => $key . "='" . $value . "'", array_keys($settings), $settings)));
        $bootstrap = new Bootstrap($root, alertMailerFactory: fn () => $mailer);
        $errors = new ErrorResponder('', false, str_repeat('a', 32));
        return $bootstrap->createHub($errors, str_repeat('a', 32))->handle(new Request(
            array_replace(['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data', 'HTTP_AUTHORIZATION' => 'Bearer test-token'], $server),
            array_replace(['transcription' => 'private note', 'recordedAt' => '1791536400000'], $post), []))->status;
    };
    $count = count($mailer->emails);
    foreach ([['REQUEST_METHOD' => 'GET'], ['HTTP_AUTHORIZATION' => 'Bearer wrong']] as $server) {
        check($run($values, $server) >= 400, 'Rejected request');
    }
    check($run($values, post: ['recordedAt' => 'bad']) === 400, 'Malformed request');
    check(count($mailer->emails) === $count, 'Rejections never alert at any threshold');
    check($run($values + ['LOG_DIRECTORY' => 'public/logs'], ['HTTP_AUTHORIZATION' => 'Bearer wrong']) === 500, 'Early logging failure response preserved');
    check(count($mailer->emails) === $count, 'Bad token cannot trigger configuration alert');
    check($run($values + ['STORE_TO_FOLDER' => 'true', 'STORAGE_DIRECTORY' => 'public/storage']) === 500, 'Normal configuration failure');
    check(count($mailer->emails) === $count + 1 && !is_dir($root . '/logs'), 'Alerts independent of LOG_ENABLED and note mail');
    check($run(['FAILURE_EMAIL_ENABLED' => 'false', 'FAILURE_EMAIL_SMTP_SCHEME' => 'invalid', 'FAILURE_EMAIL_TIMEOUT_SECONDS' => 'bad']) === 200, 'Disabled credentials inert');
    check($run(['FAILURE_EMAIL_ENABLED' => 'true']) === 200, 'Broken alert credentials preserve successful path');
    check($run($values + ['DEBUG_MODE' => 'true', 'STORE_TO_FOLDER' => 'true', 'STORAGE_DIRECTORY' => 'public/storage']) === 500, 'Debug status preserved');
    check(count($mailer->emails) === $count + 1, 'Debug alerts off by default');
    $now = $now->modify('+301 seconds');
    check($run(array_replace($values, ['DEBUG_MODE' => 'true', 'FAILURE_EMAIL_IN_DEBUG' => 'true', 'FAILURE_EMAIL_COOLDOWN_SECONDS' => '0',
        'FAILURE_EMAIL_STATE_DIRECTORY' => 'debug-state', 'STORE_TO_FOLDER' => 'true', 'STORAGE_DIRECTORY' => 'public/storage'])) === 500, 'Debug opt-in status');
    check(count($mailer->emails) === $count + 2, 'Debug alert opt-in independent of debug logging');
    $zero = AlertConfig::fromEnvironment(new Environment($root, array_replace($values, ['FAILURE_EMAIL_COOLDOWN_SECONDS' => '0', 'FAILURE_EMAIL_STATE_DIRECTORY' => 'zero'])));
    $count = count($mailer->emails);
    for ($i = 0; $i < 2; ++$i) { $notifier = $make(settings: $zero); $notifier->report($report()); $notifier->finalize(); }
    check(count($mailer->emails) === $count + 2, 'Zero success cooldown permits repeated sends');
    echo "Notification checks passed: aggregation, sanitization, partial results, fingerprint/cooldown persistence, concurrency, retry window, settings independence, severity, debug policy, safe configuration failure.\n";
} finally {
    $_ENV = $originalEnv; $_SERVER = $originalServer;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
