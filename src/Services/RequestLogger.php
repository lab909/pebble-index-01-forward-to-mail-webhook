<?php
declare(strict_types=1);

namespace Pebble\Services;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/** Adds trusted correlation context without allowing logging to interrupt delivery. */
final class RequestLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $logger, private readonly string $requestId) {}

    public function log($level, string|Stringable $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, array_replace($context, ['requestId' => $this->requestId]));
        } catch (Throwable) {
            // The application outcome must not depend on the logging transport.
        }
    }
}
