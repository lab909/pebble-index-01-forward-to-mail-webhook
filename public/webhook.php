<?php
declare(strict_types=1);

use Pebble\Config\WebhookConfig;
use Pebble\Http\Request;
use Pebble\Http\Response;
use Pebble\Recording\Recording;
use Pebble\Services\FileLogger;
use Pebble\Services\RecordingMailer;
use Pebble\Services\RecordingStorage;
use Pebble\Services\RequestValidator;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;

$projectDirectory = dirname(__DIR__);
// Load the logger directly so missing Composer dependencies are logged too.
require_once $projectDirectory . '/src/Services/FileLogger.php';
$logger = new FileLogger($projectDirectory . '/logs/webhook.log');

try {
    require $projectDirectory . '/vendor/autoload.php';
} catch (Throwable $exception) {
    $logger->error(500, 'Composer autoload failed (' . get_class($exception) . ').');
    http_response_code(500);
    exit;
}

$request = Request::fromGlobals();
if (($request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
    $logger->error(405, 'Use POST.');
    (new Response(405, ['error' => 'Use POST.'], ['Allow' => 'POST']))->send();
    exit;
}

try {
    (new Dotenv())->load($projectDirectory . '/.env');
    $environment = array_replace($_SERVER, $_ENV);
    $webhookToken = $environment['WEBHOOK_TOKEN'] ?? null;
    if (!is_string($webhookToken) || $webhookToken === ''
        || $webhookToken === 'CHANGE-ME-TO-A-LONG-RANDOM-TOKEN') {
        throw new RuntimeException('Configure the webhook token.');
    }
    // Reject invalid requests before checking optional storage and SMTP settings.
    $error = (new RequestValidator($webhookToken))->validate($request);
    if ($error !== null) {
        $logger->error($error->status, json_encode($error->body, JSON_UNESCAPED_SLASHES));
        $error->send();
        exit;
    }
    $config = WebhookConfig::fromEnvironment($projectDirectory, $environment);
    $storage = $config->storeToFolder ? new RecordingStorage($config->storageDirectory, $config->timezone) : null;
    $mailer = null;
    if ($config->forwardToMail) {
        ini_set('default_socket_timeout', '15');
        $mailer = new RecordingMailer(new Mailer(Transport::fromDsn($config->smtpDsn)),
            $config->mailFrom, $config->mailTo, $config->subjectPrefix);
    }
} catch (Throwable $exception) {
    // Configuration and transport exception messages can contain secrets.
    $logger->error(500, 'Check .env configuration and Composer dependencies. Configuration failed ('
        . get_class($exception) . ').');
    (new Response(500, ['error' => 'Check .env configuration and Composer dependencies.']))->send();
    exit;
}

$recording = null;
try {
    if ($storage !== null || $mailer !== null) {
        $recording = Recording::fromRequest($request, $config->timezone);
    }
    if ($storage !== null) {
        $recording = $storage->save($recording);
    }
} catch (Throwable $exception) {
    $logger->error(500, 'Unable to process webhook (' . get_class($exception) . ').');
    (new Response(500, ['error' => 'Unable to process webhook. Check the server error log.']))->send();
    exit;
}

$result = ['ok' => true, 'saved' => $storage !== null, 'emailSent' => false];
if ($recording?->folderName !== null) {
    $result['folder'] = $recording->folderName;
}
if ($mailer !== null) {
    try {
        $mailer->send($recording);
    } catch (Throwable $exception) {
        $storage?->recordMailStatus($recording, false);
        $logger->error(502, ($storage !== null ? 'Saved locally, but SMTP sending failed.' : 'SMTP sending failed.')
            . ' (' . get_class($exception) . ').');
        unset($result['ok']);
        $result['error'] = $storage !== null ? 'Saved locally, but SMTP sending failed.' : 'SMTP sending failed.';
        (new Response(502, $result))->send();
        exit;
    }
    $storage?->recordMailStatus($recording, true);
    $result['emailSent'] = true;
}
(new Response(200, $result))->send();
