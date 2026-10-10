<?php
declare(strict_types=1);

use Pebble\Bootstrap;
use Pebble\Config\WebhookConfig;
use Pebble\Config\ConfigurationException;
use Pebble\Config\CoreConfig;
use Pebble\Config\Environment;
use Pebble\Config\LoggingConfig;
use Pebble\Http\Request;
use Pebble\Http\Response;
use Pebble\Recording\Recording;
use Pebble\Http\ErrorResponder;
use Pebble\Services\RequestValidator;
use Pebble\Services\SmtpDiagnostics;
use Pebble\Exception\TodoistException;

$projectDirectory = dirname(__DIR__);
// Load error handling directly so missing Composer dependencies are handled too.
require_once $projectDirectory . '/src/Services/FileLogger.php';
require_once $projectDirectory . '/src/Http/Response.php';
require_once $projectDirectory . '/src/Http/ErrorResponder.php';
$errorResponder = new ErrorResponder($projectDirectory . '/logs/webhook.log',
    filter_var($_ENV['DEBUG_MODE'] ?? $_SERVER['DEBUG_MODE'] ?? getenv('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN));

// Before a private log path can be validated, use the server's error log.
$errorResponder->usePhpErrorLog();

try {
    require $projectDirectory . '/vendor/autoload.php';
} catch (Throwable $exception) {
    $errorResponder->send(new Response(500, ['error' => 'Composer dependencies are unavailable. Run composer install.']));
    exit;
}

$request = Request::fromGlobals();
$configurationError = null;
$bootstrap = new Bootstrap($projectDirectory);
try {
    $environment = $bootstrap->loadEnvironment();
    $errorResponder->setDebugMode(WebhookConfig::debugModeFromEnvironment($environment));
    try {
        $logging = LoggingConfig::fromEnvironment(new Environment($projectDirectory, $environment));
        $errorResponder->setLogPath($logging->path());
    } catch (ConfigurationException $exception) {
        $errorResponder->usePhpErrorLog();
        $configurationError = $exception->getMessage();
        throw $exception;
    }
    if (($request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
        $errorResponder->send(new Response(405, ['error' => 'Use POST.'], ['Allow' => 'POST']));
        exit;
    }
    try {
        $webhookToken = CoreConfig::token(new Environment($projectDirectory, $environment));
    } catch (ConfigurationException) {
        $configurationError = 'Configure WEBHOOK_TOKEN in .env with a non-placeholder token.';
        throw new ConfigurationException($configurationError);
    }
    // Reject invalid requests before checking optional storage and SMTP settings.
    $error = (new RequestValidator($webhookToken))->validate($request);
    if ($error !== null) {
        $errorResponder->send($error);
        exit;
    }
    try {
        $config = WebhookConfig::fromEnvironment($projectDirectory, $environment);
    } catch (RuntimeException $exception) {
        // These messages come from our configuration checks, never transport or Dotenv exceptions.
        $configurationError = $exception->getMessage();
        throw $exception;
    }
    $errorResponder->setLogPath($config->logging->path());
    $services = $bootstrap->assemble($config);
    $storage = $services->storage;
    $mailer = $services->mailer;
    $todoist = $services->todoist;
} catch (Throwable $exception) {
    if (($request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
        $errorResponder->send(new Response(405, ['error' => 'Use POST.'], ['Allow' => 'POST']));
        exit;
    }
    $errorResponder->send(new Response(500, ['error' => $configurationError
        ?? 'Check .env configuration, SMTP settings, and Composer dependencies.']));
    exit;
}

if ($todoist !== null && (!isset($request->post['transcription'])
    || !is_string($request->post['transcription']) || trim($request->post['transcription']) === '')) {
    $errorResponder->send(new Response(400, ['error' => 'Todoist requires a non-empty transcription.']));
    exit;
}

$recording = null;
try {
    if ($storage !== null || $mailer !== null || $todoist !== null) {
        $recording = Recording::fromRequest($request, $config->timezone);
    }
    if ($storage !== null) {
        $recording = $storage->save($recording);
    }
} catch (Throwable $exception) {
    $errorResponder->send(new Response(500, ['error' => 'Unable to process webhook. Check recording data and storage directory permissions.']));
    exit;
}

$result = ['ok' => true, 'saved' => $storage !== null, 'emailSent' => false, 'todoistSaved' => false];
$failures = [];
if ($recording?->folderName !== null) {
    $result['folder'] = $recording->folderName;
}
if ($mailer !== null) {
    try {
        $mailer->send($recording);
        $storage?->recordMailStatus($recording, true);
        $result['emailSent'] = true;
    } catch (Throwable $exception) {
        if (!$config->debugMode) {
            $storage?->recordMailStatus($recording, false);
        }
        $failures[] = $storage !== null ? 'Saved locally, but SMTP sending failed.' : 'SMTP sending failed.';
        if ($config->debugMode) {
            $result['smtpDebug'] = (new SmtpDiagnostics($environment))->describe($exception);
        }
    }
}
if ($todoist !== null) {
    try {
        $todoistResult = $todoist->save($recording);
        $result['todoistSaved'] = true;
        $result['todoistProjectId'] = $todoistResult['projectId'];
        $result['todoistTaskId'] = $todoistResult['taskId'];
    } catch (TodoistException $exception) {
        $failures[] = 'Todoist task or attachment saving failed.';
        if (isset($exception->context['projectId'])) {
            $result['todoistProjectId'] = $exception->context['projectId'];
        }
        if (isset($exception->context['taskId'])) {
            $result['todoistTaskId'] = $exception->context['taskId'];
        }
        // API errors are sanitized in the client; never include a raw request or token.
        $result['todoistDebug'] = ['message' => $exception->getMessage()] + $exception->context;
    }
}
if ($failures !== []) {
    unset($result['ok']);
    $result['error'] = implode(' ', $failures);
    $errorResponder->send(new Response(502, $result));
    exit;
}
(new Response(200, $result))->send();
