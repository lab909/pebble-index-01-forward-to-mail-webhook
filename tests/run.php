<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pebble\Config\WebhookConfig;
use Pebble\Recording\Recording;
use Pebble\Http\Request;
use Pebble\Services\RecordingMailer;
use Pebble\Services\RecordingStorage;
use Pebble\Services\RequestValidator;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\RawMessage;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/pebble-tests-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$originalUmask = umask();
$mailer = new class implements MailerInterface {
    public array $messages = [];
    public bool $fail = false;

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->fail) {
            throw new RuntimeException('Simulated SMTP failure');
        }
        $this->messages[] = $message;
    }
};

try {
    $config = WebhookConfig::fromEnvironment($directory, [
        'WEBHOOK_TOKEN' => 'test-token', 'STORAGE_DIRECTORY' => 'recordings',
        'APP_TIMEZONE' => 'UTC', 'SMTP_SCHEME' => 'smtp', 'SMTP_HOST' => 'localhost',
        'SMTP_PORT' => '587', 'SMTP_USERNAME' => 'user@example.com',
        'SMTP_PASSWORD' => 'p@ss:#$', 'MAIL_FROM' => 'sender@example.com',
        'MAIL_TO' => 'recipient@example.com', 'MAIL_SUBJECT_PREFIX' => 'Pebble',
    ]);
    check($config->storeToFolder && $config->forwardToMail && !$config->saveToTodoist && !$config->debugMode,
        'Defaults enable storage/email and disable Todoist/debug');
    check($config->storageDirectory === $directory . '/recordings', 'Resolve relative storage path');
    check(str_contains($config->smtpDsn, 'p%40ss%3A%23%24'), 'Encode SMTP credentials');
    check(str_ends_with($config->smtpDsn, '?require_tls=true'), 'Require STARTTLS');

    $validator = new RequestValidator('test-token');
    $server = ['HTTP_AUTHORIZATION' => 'Bearer test-token', 'CONTENT_TYPE' => 'multipart/form-data'];
    $post = ['recordedAt' => '1791536400000', 'transcription' => 'Hello', 'extra' => ['preserved']];
    check($validator->validate(new Request([], $post, []))?->status === 401, 'Reject invalid token');
    check($validator->validate(new Request(array_replace($server, ['CONTENT_TYPE' => 'application/json']), $post, []))?->status === 415, 'Reject wrong content type');
    check($validator->validate(new Request($server, [], []))?->status === 400, 'Reject empty request');
    check($validator->validate(new Request($server, ['recordedAt' => 'invalid'], []))?->status === 400, 'Reject invalid timestamp');
    check($validator->validate(new Request($server, $post, ['audio' => ['error' => UPLOAD_ERR_PARTIAL]]))?->body['uploadError'] === UPLOAD_ERR_PARTIAL, 'Report upload error');
    check($validator->validate(new Request($server, $post, ['audio' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => []]]))?->status === 400, 'Reject malformed upload');

    check($validator->validate(new Request([
        'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer test-token', 'CONTENT_TYPE' => 'multipart/form-data',
    ], $post, [])) === null, 'Accept forwarded authorization');
    foreach (['', '-1', '1.5', '12345678901234', ['nested']] as $invalidTimestamp) {
        check($validator->validate(new Request($server, ['recordedAt' => $invalidTimestamp], []))?->status === 400,
            'Reject invalid timestamp forms');
    }
    check($validator->validate(new Request($server, ['recordedAt' => '0'], [])) === null,
        'Allow text-only requests without transcription when Todoist is disabled');
    check($validator->validate(new Request($server, $post, ['audio' => 'invalid']))?->status === 400,
        'Reject malformed audio structure');
    check($validator->validate(new Request($server, $post, ['audio' => [
        'error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__,
    ]]))?->status === 400, 'Reject files not uploaded through PHP HTTP handling');

    $storage = new RecordingStorage($config->storageDirectory, $config->timezone);
    $service = new RecordingMailer($mailer, $config->mailFrom, $config->mailTo, $config->subjectPrefix);
    $recording = $storage->save(Recording::fromRequest(new Request($server, $post, []), $config->timezone));
    $service->send($recording);
    $storage->recordMailStatus($recording, true);
    $folder = $recording->folder;
    $info = json_decode(file_get_contents($folder . '/info.txt'), true, flags: JSON_THROW_ON_ERROR);
    check(array_keys($info) === ['receivedAt', 'recordedAt', 'timezone', 'postFields', 'audio', 'headers'],
        'Preserve info.txt top-level schema');
    check($info['audio'] === null && $info['timezone'] === 'UTC', 'Text-only metadata has no audio');
    check(array_keys($info['headers']) === ['Content-Type', 'X-Audio-Size', 'User-Agent'],
        'Preserve metadata header whitelist');
    check($info['recordedAt'] === (new DateTimeImmutable('@1791536400'))->format(DateTimeInterface::ATOM),
        'Convert milliseconds to timezone-adjusted recording date');
    check($info['postFields'] === $post, 'Preserve all POST fields');
    check(!str_contains(file_get_contents($folder . '/info.txt'), 'test-token'), 'Exclude authorization');
    check(file_get_contents($folder . '/mail-status.txt') === "Accepted by SMTP server.\n", 'Record SMTP success');
    $email = $mailer->messages[0];
    check($email instanceof Email && count($email->getAttachments()) === 1, 'Attach info.txt');
    check(str_contains($email->toString(), 'info.txt'), 'Serialize email attachments');
    check($email->getTextBody() === $post['transcription'], 'Email body contains only transcription');

    // Email-only delivery attaches upload data without creating storage files.
    $audioPath = $directory . '/upload.m4a';
    file_put_contents($audioPath, 'sample audio');
    $emailOnly = Recording::fromRequest(new Request($server, $post, ['audio' => [
        'tmp_name' => $audioPath, 'name' => 'upload.m4a', 'type' => 'audio/mp4',
    ]]), $config->timezone);
    $before = glob($config->storageDirectory . '/*');
    $service->send($emailOnly);
    check(count($mailer->messages[1]->getAttachments()) === 2, 'Attach audio and info');
    check(str_contains($mailer->messages[1]->toString(), 'audio.m4a'), 'Serialize audio attachment');
    check($emailOnly->folder === null && glob($config->storageDirectory . '/*') === $before, 'Email-only creates no recordings');
    check(count($email->getAttachments()) === 1, 'Keep messages independent across sends');

    $service->send(Recording::fromRequest(new Request($server, ['recordedAt' => $post['recordedAt']], []), $config->timezone));
    check($mailer->messages[2]->getTextBody() === '', 'Missing transcription leaves an empty email body');

    $mailer->fail = true;
    try {
        $service->send($recording);
        throw new LogicException('Expected sending failure');
    } catch (RuntimeException $exception) {
        $storage->recordMailStatus($recording, false);
    }
    check(is_file($folder . '/info.txt'), 'Retain files after SMTP failure');
    check(str_contains(file_get_contents($folder . '/mail-status.txt'), 'failed'), 'Record SMTP failure');

    // A failed audio move removes the newly created, incomplete folder.
    try {
        @$storage->save(new Recording($recording->recordedAt, $recording->text, $directory . '/missing'));
        throw new LogicException('Expected storage failure');
    } catch (RuntimeException $exception) {
        check(glob($config->storageDirectory . '/*') === $before, 'Remove incomplete recording');
    }

    $minimal = ['WEBHOOK_TOKEN' => 'test-token', 'APP_TIMEZONE' => 'UTC',
        'STORE_TO_FOLDER' => 'false', 'FORWARD_TO_MAIL' => 'false'];
    $disabled = WebhookConfig::fromEnvironment($directory, $minimal);
    $ignored = WebhookConfig::fromEnvironment($directory, array_replace($minimal, [
        'STORAGE_DIRECTORY' => '', 'SMTP_SCHEME' => 'invalid', 'SMTP_HOST' => 'invalid host',
        'SMTP_PORT' => 'invalid', 'SMTP_USERNAME' => '', 'SMTP_PASSWORD' => '',
        'MAIL_FROM' => 'invalid address', 'MAIL_TO' => 'invalid address',
        'TODOIST_API_TOKEN' => '', 'TODOIST_PARENT_PROJECT' => '',
    ]));
    check($ignored->smtpDsn === null && $ignored->storageDirectory === null && $ignored->todoistApiToken === null,
        'Ignore invalid settings for disabled destinations');
    check(!$disabled->saveToTodoist && $disabled->todoistApiToken === null, 'Todoist is disabled by default');
    $todoistConfig = WebhookConfig::fromEnvironment($directory, array_replace($minimal, [
        'SAVE_TO_TODOIST' => 'true', 'TODOIST_API_TOKEN' => 'todoist-secret', 'TODOIST_PARENT_PROJECT' => 'parent-id',
    ]));
    check($todoistConfig->saveToTodoist && $todoistConfig->todoistParentProject === 'parent-id', 'Configure Todoist independently');
    check(!$disabled->debugMode, 'Debug mode defaults to false');
    check(WebhookConfig::fromEnvironment($directory, array_replace($minimal, ['DEBUG_MODE' => 'true']))->debugMode, 'Enable debug mode');
    check(!$disabled->storeToFolder && !$disabled->forwardToMail, 'Disable both actions');
    check($disabled->storageDirectory === null && $disabled->smtpDsn === null, 'Disabled actions need no configuration');
    $storageOnly = WebhookConfig::fromEnvironment($directory, array_replace($minimal,
        ['STORE_TO_FOLDER' => 'true', 'STORAGE_DIRECTORY' => 'recordings']));
    check($storageOnly->storeToFolder && $storageOnly->smtpDsn === null, 'Storage-only needs no SMTP configuration');
    try {
        WebhookConfig::fromEnvironment($directory, array_replace($minimal, ['STORE_TO_FOLDER' => 'typo']));
        throw new LogicException('Expected invalid boolean rejection');
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), 'STORE_TO_FOLDER'), 'Reject invalid boolean');
    }
    $diagnosticEnvironment = ['SMTP_USERNAME' => 'private-user@example.com',
        'SMTP_PASSWORD' => 'secret@:#$', 'WEBHOOK_TOKEN' => 'private-webhook-token'];
    $password = $diagnosticEnvironment['SMTP_PASSWORD'];
    $plain = base64_encode("\0" . $diagnosticEnvironment['SMTP_USERNAME'] . "\0" . $password);
    $cause = new RuntimeException('Underlying connection failure');
    $failure = new Symfony\Component\Mailer\Exception\TransportException(
        'Authentication failed for ' . $diagnosticEnvironment['SMTP_USERNAME'] . ' using ' . $password
        . ' ' . rawurlencode($password) . ' ' . $plain . ' private-webhook-token', 535, $cause);
    $failure->appendDebug("[timestamp] > EHLO local\n[timestamp] > AUTH PLAIN " . $plain
        . "\n[timestamp] > AUTH LOGIN\n[timestamp] > " . base64_encode($password)
        . "\n[timestamp] > unknown-cram-response\n[timestamp] < 535 Authentication failed\n");
    $details = (new Pebble\Services\SmtpDiagnostics($diagnosticEnvironment))->describe($failure);
    $serialized = json_encode($details, JSON_THROW_ON_ERROR);
    foreach ([$password, rawurlencode($password), base64_encode($password), $plain,
        $diagnosticEnvironment['SMTP_USERNAME'], 'private-webhook-token', 'unknown-cram-response'] as $secret) {
        check(!str_contains($serialized, $secret), 'Redact SMTP diagnostic secrets');
    }
    check(count($details['exceptions']) === 2, 'Include nested SMTP causes');
    check($details['exceptions'][0]['code'] === 535, 'Include SMTP error code');
    check(str_contains($details['exceptions'][0]['smtpTranscript'], '535 Authentication failed'), 'Keep SMTP reply');
    check(str_contains($details['exceptions'][0]['smtpTranscript'], 'AUTH PLAIN [redacted]'), 'Keep AUTH mechanism without credentials');
    echo "All regression checks passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
    umask($originalUmask);
}
