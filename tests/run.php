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

    $storage = new RecordingStorage($config->storageDirectory, $config->timezone);
    $service = new RecordingMailer($mailer, $config->mailFrom, $config->mailTo, $config->subjectPrefix);
    $recording = $storage->save(Recording::fromRequest(new Request($server, $post, []), $config->timezone));
    $service->send($recording);
    $storage->recordMailStatus($recording, true);
    $folder = $recording->folder;
    $info = json_decode(file_get_contents($folder . '/info.txt'), true, flags: JSON_THROW_ON_ERROR);
    check($info['postFields'] === $post, 'Preserve all POST fields');
    check(!str_contains(file_get_contents($folder . '/info.txt'), 'test-token'), 'Exclude authorization');
    check(file_get_contents($folder . '/mail-status.txt') === "Accepted by SMTP server.\n", 'Record SMTP success');
    $email = $mailer->messages[0];
    check($email instanceof Email && count($email->getAttachments()) === 1, 'Attach info.txt');
    check(str_contains($email->toString(), 'info.txt'), 'Serialize email attachments');

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
