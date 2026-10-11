<?php
declare(strict_types=1);

namespace Pebble;

use DateTimeImmutable;
use Pebble\Config\{ConfigurationException, CoreConfig, Environment, LoggingConfig, WebhookConfig};
use Pebble\Http\{ErrorResponder, NoteFactory, Request, Response, ResponseBuilder};
use Pebble\Operation\{ExecutionReport, RequestContext};
use Pebble\Services\RequestValidator;
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
        return $this->process($request)->withRequestId($this->requestId);
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
                $this->errors->setLogPath(LoggingConfig::fromEnvironment($environment)->path());
            } catch (ConfigurationException $exception) {
                $this->errors->usePhpErrorLog();
                $configurationError = $exception->getMessage();
                throw $exception;
            }
            if (($request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
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
                return $failure;
            }
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
                return new Response(405, ['error' => 'Use POST.'], ['Allow' => 'POST']);
            }
            return new Response(500, ['error' => $configurationError
                ?? 'Check .env configuration, SMTP settings, and Composer dependencies.']);
        }
        $note = null;
        try {
            try {
                if ($services->operations->operations() !== []) {
                    $note = (new NoteFactory($config->timezone, $context->receivedAt))->fromRequest($request);
                }
            } catch (Throwable) {
                return new Response(500, ['error' => 'Unable to process webhook. Check recording data and storage directory permissions.']);
            }
            $report = $note === null ? new ExecutionReport([], [], []) : $services->runner->run($note);
            $services->outcomeRecorder->record($report, $config->debugMode);
            return $this->responses->fromReport($report, $config->debugMode);
        } finally {
            $note?->audio?->cleanup();
        }
    }
}
