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
use Pebble\Http\NoteFactory;
use Pebble\Http\ErrorResponder;
use Pebble\Services\RequestValidator;

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
} catch (Throwable $exception) {
    if (($request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
        $errorResponder->send(new Response(405, ['error' => 'Use POST.'], ['Allow' => 'POST']));
        exit;
    }
    $errorResponder->send(new Response(500, ['error' => $configurationError
        ?? 'Check .env configuration, SMTP settings, and Composer dependencies.']));
    exit;
}

$note = null;
try {
    try {
        if ($services->operations->operations() !== []) {
            $note = (new NoteFactory($config->timezone))->fromRequest($request);
        }
    } catch (Throwable) {
        $errorResponder->send(new Response(500, ['error' => 'Unable to process webhook. Check recording data and storage directory permissions.']));
        return;
    }
    // Legacy response mapping stays here until Phase 6 introduces the response builder.
    $report = $note === null ? new \Pebble\Operation\ExecutionReport([], [], []) : $services->runner->run($note);
    $services->outcomeRecorder->record($report, $config->debugMode);
    if ($report->validationFailures !== []) {
        $failure = array_values($report->validationFailures)[0];
        $errorResponder->send(new Response($report->httpFailure()->value, ['error' => $failure->message]));
        return;
    }
    if ($report->httpFailure() === \Pebble\Operation\HttpFailure::InternalServerError) {
        foreach ($report->entries as $entry) {
            if ($entry->result->httpFailure === \Pebble\Operation\HttpFailure::InternalServerError) {
                $errorResponder->send(new Response(500, ['error' => $entry->result->message]));
                return;
            }
        }
    }
    $stored = $report->entries['local_storage']->result ?? null;
    $email = $report->entries['email']->result ?? null;
    $todoist = $report->entries['todoist']->result ?? null;
    $result = ['ok' => true, 'saved' => $stored?->succeeded() ?? false,
        'emailSent' => $email?->succeeded() ?? false, 'todoistSaved' => $todoist?->succeeded() ?? false];
    if (isset($stored->outputs['folderName'])) {
        $result['folder'] = $stored->outputs['folderName'];
    }
    foreach (['projectId' => 'todoistProjectId', 'taskId' => 'todoistTaskId'] as $key => $field) {
        if (isset($todoist->externalIds[$key])) {
            $result[$field] = $todoist->externalIds[$key];
        }
    }
    $failures = [];
    foreach ($report->entries as $id => $entry) {
        if (!$entry->result->succeeded()) {
            $failures[] = $id === 'email' && ($stored?->succeeded() ?? false)
                ? 'Saved locally, but SMTP sending failed.' : $entry->result->message;
            $result += $entry->result->diagnostics;
        }
    }
    if ($failures !== []) {
        unset($result['ok']);
        $result['error'] = implode(' ', $failures);
        $errorResponder->send(new Response($report->httpFailure()->value, $result));
        return;
    }
    (new Response(200, $result))->send();
} finally {
    // PHP owns HTTP uploads. Only explicitly hub-owned temporary assets are removed.
    $note?->audio?->cleanup();
}
