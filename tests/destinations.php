<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pebble\Config\WebhookConfig;
use Pebble\Http\NoteFactory;
use Pebble\Http\Request;
use Pebble\Operation\Builtin\EmailFactory;
use Pebble\Operation\Builtin\LocalStorageFactory;
use Pebble\Operation\Builtin\TodoistFactory;
use Pebble\Operation\ErrorCategory;
use Pebble\Operation\ExecutionEntry;
use Pebble\Operation\ExecutionReport;
use Pebble\Operation\HttpFailure;
use Pebble\Operation\OperationDependencies;
use Pebble\Operation\OperationRegistry;
use Pebble\Operation\OperationResult;
use Pebble\Operation\OperationRunner;
use Pebble\Operation\OutcomeRecorder;
use Pebble\Operation\RequestContext;
use Pebble\Services\SmtpDiagnostics;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

function assertDestination(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/pebble-destinations-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$logger = new class extends AbstractLogger {
    public int $calls = 0;
    public function log($level, string|Stringable $message, array $context = []): void
    {
        ++$this->calls;
        throw new RuntimeException('Logger unavailable');
    }
};
$mailer = new class implements MailerInterface {
    public array $messages = [];
    public bool $fail = false;
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->fail) {
            throw new RuntimeException('SMTP failure private-password private-user private-token');
        }
        $this->messages[] = $message;
    }
};
try {
    $values = ['WEBHOOK_TOKEN' => 'private-token', 'APP_TIMEZONE' => 'UTC',
        'STORE_TO_FOLDER' => 'true', 'STORAGE_DIRECTORY' => $root . '/recordings',
        'FORWARD_TO_MAIL' => 'true', 'SMTP_SCHEME' => 'smtp', 'SMTP_HOST' => 'localhost', 'SMTP_PORT' => '587',
        'SMTP_USERNAME' => 'private-user', 'SMTP_PASSWORD' => 'private-password',
        'MAIL_FROM' => 'from@example.com', 'MAIL_TO' => 'to@example.com', 'MAIL_SUBJECT_PREFIX' => 'Note'];
    $config = WebhookConfig::fromEnvironment($root, $values);
    $note = (new NoteFactory($config->timezone))->fromRequest(new Request([], [
        'recordedAt' => '0', 'transcription' => 'Destination adapter test', 'extra' => ['retained'],
    ], []));
    $definitions = array_slice(require dirname(__DIR__) . '/config/operations.php', 0, 2);
    $recorder = new OutcomeRecorder($logger);
    foreach ([false, true] as $debug) {
        $dependencies = new OperationDependencies($logger, new RequestContext('adapter-test', $note->receivedAt, $debug));
        $registry = OperationRegistry::fromConfiguration($definitions, ['STORE_TO_FOLDER' => true, 'FORWARD_TO_MAIL' => true], [
            'local_storage' => new LocalStorageFactory($config->storage, $config->timezone),
            'email' => new EmailFactory($config->email, new SmtpDiagnostics($values), static fn () => $mailer),
        ], $dependencies);
        $mailer->fail = false;
        $calls = $logger->calls;
        $report = (new OperationRunner($registry))->run($note);
        $recorder->record($report, $debug);
        assertDestination($report->succeeded(), 'Logger errors never interrupt actual delivery');
        assertDestination($debug ? $logger->calls === $calls : $logger->calls > $calls, 'Debug suppresses destination logging');
        $stored = $report->entries['local_storage']->result;
        assertDestination(file_get_contents($stored->outputs['folder'] . '/mail-status.txt') === "Accepted by SMTP server.\n", 'Record successful SMTP status in both modes');
        assertDestination(file_get_contents($stored->outputs['folder'] . '/info.txt') === $note->infoText, 'Preserve adapter metadata');
        assertDestination($mailer->messages[array_key_last($mailer->messages)]->getTextBody() === $note->transcription, 'Preserve transcription-only email body');
        $mailer->fail = true;
        $report = (new OperationRunner($registry))->run($note);
        $recorder->record($report, $debug);
        $storageResult = $report->entries['local_storage']->result;
        $emailResult = $report->entries['email']->result;
        assertDestination($storageResult->succeeded() && !$emailResult->succeeded() && $emailResult->httpFailure === HttpFailure::BadGateway,
            'Mail failure retains successful storage and upstream classification');
        $statusPath = $storageResult->outputs['folder'] . '/mail-status.txt';
        assertDestination($debug ? !file_exists($statusPath) : str_contains(file_get_contents($statusPath), 'failed'), 'Suppress debug failure status files');
        assertDestination($debug === isset($emailResult->diagnostics['smtpDebug']), 'SMTP details are available only in debug');
        $serialized = json_encode($emailResult, JSON_THROW_ON_ERROR);
        foreach (['private-password', 'private-user', 'private-token'] as $secret) {
            assertDestination(!str_contains($serialized, $secret), 'Adapters redact SMTP diagnostics');
        }
    }
    // The outcome recorder is independent and harmless without both executed destinations.
    $now = new DateTimeImmutable();
    $badFolder = OperationResult::success(['folder' => "invalid\0folder"]);
    $report = new ExecutionReport([], [
        'local_storage' => new ExecutionEntry('local_storage', $now, 0, $badFolder),
        'email' => new ExecutionEntry('email', $now, 0, OperationResult::success()),
    ], []);
    $recorder->record($report, false); // Filesystem and logger errors must both be ignored.
    $recorder->record(new ExecutionReport([], ['email' => $report->entries['email']], []), false);
    $recorder->record(new ExecutionReport([], ['local_storage' => $report->entries['local_storage']], ['email']), false);

    // Built-in Todoist preflight blocks all writes, without contacting an API.
    $todoistValues = array_replace($values, ['SAVE_TO_TODOIST' => 'true', 'TODOIST_API_TOKEN' => 'todoist-token', 'TODOIST_PARENT_PROJECT' => 'parent']);
    $config = WebhookConfig::fromEnvironment($root, $todoistValues);
    $dependencies = new OperationDependencies($logger, new RequestContext('preflight-test', $note->receivedAt, false));
    $registry = OperationRegistry::fromConfiguration(require dirname(__DIR__) . '/config/operations.php',
        ['STORE_TO_FOLDER' => true, 'FORWARD_TO_MAIL' => true, 'SAVE_TO_TODOIST' => true], [
            'local_storage' => new LocalStorageFactory($config->storage, $config->timezone),
            'email' => new EmailFactory($config->email, new SmtpDiagnostics($todoistValues), static fn () => $mailer),
            'todoist' => new TodoistFactory($config->todoist, $config->timezone, new SmtpDiagnostics($todoistValues)),
        ], $dependencies);
    $missing = (new NoteFactory($config->timezone))->fromRequest(new Request([], ['recordedAt' => '0'], []));
    $before = glob($root . '/recordings/*');
    $report = (new OperationRunner($registry))->run($missing);
    assertDestination($report->httpFailure() === HttpFailure::BadRequest && isset($report->validationFailures['todoist']), 'Todoist adapter owns transcription validation');
    assertDestination($report->entries === [] && glob($root . '/recordings/*') === $before, 'Preflight precedes local writes and mail/API execution');

    $blocked = $root . '/blocked';
    file_put_contents($blocked, 'not a directory');
    $badConfig = WebhookConfig::fromEnvironment($root, array_replace($values, ['STORAGE_DIRECTORY' => $blocked]));
    $registry = OperationRegistry::fromConfiguration($definitions, ['STORE_TO_FOLDER' => true, 'FORWARD_TO_MAIL' => true], [
        'local_storage' => new LocalStorageFactory($badConfig->storage, $config->timezone),
        'email' => new EmailFactory($config->email, new SmtpDiagnostics($values), static fn () => $mailer),
    ], $dependencies);
    $report = @(new OperationRunner($registry))->run($note);
    assertDestination($report->entries['local_storage']->result->errorCategory === ErrorCategory::Storage && $report->skippedOperationIds === ['email'],
        'Local storage adapter preserves abort policy');
    echo "Destination checks passed: factory wiring, preflight, SMTP redaction, mail status recording, debug suppression, and logger failure isolation.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
