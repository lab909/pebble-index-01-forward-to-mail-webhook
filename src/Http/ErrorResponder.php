<?php
declare(strict_types=1);

namespace Pebble\Http;

use Psr\Log\LoggerInterface;

/** Minimal native fallback is usable even when Composer cannot be loaded. */
final class ErrorResponder
{
    private ?LoggerInterface $logger = null;
    private bool $loggingAllowed = true;
    private bool $handled = false;

    public function __construct(string $logPath, private bool $debugMode = false, private ?string $requestId = null)
    {
        $this->setDebugMode($debugMode);
    }

    public function setDebugMode(bool $debugMode): void
    {
        $this->debugMode = $debugMode;
        $this->loggingAllowed = !$debugMode;
        ini_set('display_errors', '0');
        ini_set('log_errors', $debugMode ? '0' : '1');
    }

    public function configureLogging(LoggerInterface $logger, bool $allowed): void
    {
        $this->logger = $logger;
        $this->loggingAllowed = $allowed;
    }

    public function markHandled(): void
    {
        $this->handled = true;
    }

    public function usePhpErrorLog(): void
    {
        $this->logger = null;
        $this->loggingAllowed = !$this->debugMode;
    }

    public function send(Response $response): void
    {
        if ($this->requestId !== null) {
            $response = $response->withRequestId($this->requestId);
        }
        if ($response->status >= 400 && $this->loggingAllowed && !$this->handled) {
            // Only trusted error messages and correlation metadata enter this bootstrap fallback.
            $context = ['event' => 'bootstrap_failed', 'httpStatus' => $response->status,
                'requestId' => $response->body['requestId'] ?? null,
                'reason' => $response->body['error'] ?? 'Webhook failed.'];
            if ($this->logger !== null) {
                \Pebble\Services\SafeLog::write($this->logger, $response->status < 500 ? 'warning' : 'critical', 'Webhook response failed.', $context);
            } else {
                error_log('Pebble webhook: HTTP ' . $response->status . ': ' . json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            }
        }
        $response->send($this->debugMode);
    }
}
