<?php
declare(strict_types=1);

namespace Pebble;

use DateTimeImmutable;
use Pebble\Config\{ConfigurationException, CoreConfig, Environment, WebhookConfig};
use Pebble\Http\{ErrorResponder, NoteFactory, Request, Response, ResponseBuilder};
use Pebble\Operation\{ExecutionReport, RequestContext};
use Pebble\Services\RequestValidator;
use Pebble\Services\SafeLog;
use Throwable;

/** Coordinates the HTTP boundary and generic operation pipeline. */
final readonly class WebhookHub
{
    public function __construct(
        private Bootstrap $bootstrap,
        private ErrorResponder $errors,
        private string $projectDirectory,
        private string $requestId,
        private ResponseBuilder $responses = new ResponseBuilder(),
    ) {
    }

    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        $response = $this->process($request)->withRequestId($this->requestId);
        $this->bootstrap->finalizeNotifications();
        if ($this->bootstrap->hasLogging()) {
            SafeLog::write($this->bootstrap->hubLogger($this->requestId), 'info', 'Webhook request completed.',
                ['event' => 'hub_completed', 'httpStatus' => $response->status,
                    'outcome' => $response->status < 400 ? 'success' : 'failure',
                    'durationMs' => (hrtime(true) - $started) / 1_000_000]);
            $this->errors->markHandled();
        }
        return $response;
    }

    private function process(Request $request): Response
    {
        $receivedAt = new DateTimeImmutable();
        $configurationError = null;
        try {
            $values = $this->bootstrap->loadEnvironment();
            $this->errors->setDebugMode(WebhookConfig::debugModeFromEnvironment($values));
            $environment = new Environment($this->projectDirectory, $values);
            try {
                $this->bootstrap->configureLogging($values, $this->errors, $this->requestId);
            } catch (ConfigurationException $exception) {
                $this->errors->usePhpErrorLog();
                $configurationError = $exception->getMessage();
                throw $exception;
            }
            if (($request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
                $this->reject(405, 'Use POST.');
                return new Response(405, ['error' => 'Use POST.'], ['Allow' => 'POST']);
            }
            try {
                $token = CoreConfig::token($environment);
            } catch (ConfigurationException) {
                $configurationError = 'Configure WEBHOOK_TOKEN in .env with a non-placeholder token.';
                throw new ConfigurationException($configurationError);
            }
            // Authentication and HTTP validation precede optional credentials and client construction.
            $failure = (new RequestValidator($token))->validate($request);
            if ($failure !== null) {
                $this->reject($failure->status, $failure->body['error']);
                return $failure;
            }
            $this->bootstrap->configureNotifications($values, $this->requestId);
            SafeLog::write($this->bootstrap->hubLogger($this->requestId), 'info', 'Webhook request accepted.',
                ['event' => 'request_accepted']);
            try {
                $config = WebhookConfig::fromEnvironment($this->projectDirectory, $values);
            } catch (ConfigurationException $exception) {
                $configurationError = $exception->getMessage();
                throw $exception;
            }
            $context = new RequestContext($this->requestId, $receivedAt->setTimezone($config->timezone), $config->debugMode);
            $services = $this->bootstrap->assemble($config, $this->bootstrap->dependencies($context));
        } catch (Throwable) {
            if (($request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
                $this->reject(405, 'Use POST.');
                return new Response(405, ['error' => 'Use POST.'], ['Allow' => 'POST']);
            }
            SafeLog::write($this->bootstrap->hubLogger($this->requestId), 'critical', 'Webhook configuration or bootstrap failed.',
                ['event' => 'bootstrap_failed', 'httpStatus' => 500, 'errorCategory' => 'configuration', 'reason' => $configurationError ?? 'Invalid configuration or dependencies.']);
            // Configure alerts for an early logging failure only when this is
            // still an authenticated, well-formed request. Rejections never email.
            try {
                if (isset($environment, $values)
                    && (new RequestValidator(CoreConfig::token($environment)))->validate($request) === null) {
                    if (!$this->bootstrap->hasLogging()) {
                        $this->bootstrap->configureNotifications($values, $this->requestId);
                    }
                    $this->bootstrap->notificationFailure($configurationError ?? 'Invalid configuration or dependencies.');
                }
            } catch (Throwable) {}
            return new Response(500, ['error' => $configurationError
                ?? 'Check .env configuration, SMTP settings, and Composer dependencies.']);
        }
        $note = null;
        try {
            try {
                if ($services->operations->operations() !== []) {
                    $note = (new NoteFactory($config->timezone, $context->receivedAt))->fromRequest($request);
                    $this->bootstrap->protectNote($note);
                }
            } catch (Throwable) {
                SafeLog::write($this->bootstrap->hubLogger($this->requestId), 'critical', 'Note preparation failed.',
                    ['event' => 'note_preparation_failed', 'httpStatus' => 500, 'errorCategory' => 'unexpected']);
                $this->bootstrap->notificationFailure('Note preparation failed.');
                return new Response(500, ['error' => 'Unable to process webhook. Check recording data and storage directory permissions.']);
            }
            $report = $note === null ? new ExecutionReport([], [], []) : $services->runner->run($note);
            $this->bootstrap->notificationReport($report);
            $services->outcomeRecorder->record($report, $config->debugMode);
            return $this->responses->fromReport($report, $config->debugMode);
        } finally {
            $note?->audio?->cleanup();
        }
    }

    private function reject(int $status, string $reason): void
    {
        SafeLog::write($this->bootstrap->hubLogger($this->requestId), 'warning', 'Webhook request rejected.',
            ['event' => 'request_rejected', 'httpStatus' => $status, 'errorCategory' => 'validation', 'reason' => $reason]);
    }

}
