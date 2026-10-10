<?php
declare(strict_types=1);

namespace Pebble\Http;

use Pebble\Services\FileLogger;

final class ErrorResponder
{
    private ?FileLogger $logger = null;
    private bool $phpErrorLog = false;

    public function __construct(private string $logPath, private bool $debugMode = false)
    {
        $this->setDebugMode($debugMode);
    }

    public function setDebugMode(bool $debugMode): void
    {
        $this->debugMode = $debugMode;
        // Use controlled JSON messages, even in debug mode; raw PHP errors can contain secrets.
        ini_set('display_errors', '0');
        ini_set('log_errors', $debugMode ? '0' : '1');
    }

    public function setLogPath(string $path): void
    {
        if ($path !== $this->logPath) {
            $this->logger = null;
        }
        $this->logPath = $path;
        $this->phpErrorLog = false;
    }

    public function usePhpErrorLog(): void
    {
        $this->phpErrorLog = true;
        $this->logger = null;
    }

    public function send(Response $response): void
    {
        if (!$this->debugMode) {
            if ($this->phpErrorLog) {
                error_log('Pebble webhook: HTTP ' . $response->status . ': '
                    . json_encode($response->body, JSON_UNESCAPED_SLASHES));
            } else {
                $this->logger ??= new FileLogger($this->logPath);
                $this->logger->error($response->status, json_encode($response->body, JSON_UNESCAPED_SLASHES));
            }
        }
        $response->send($this->debugMode);
    }
}
