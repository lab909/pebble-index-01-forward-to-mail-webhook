<?php
declare(strict_types=1);

namespace Pebble\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/** The fallback never receives a handler exception, raw record, or stack trace. */
final class GuardedLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $logger, private readonly Redactor $redactor) {}

    public function log($level, string|Stringable $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (Throwable) {
            (new NativeLogger($this->redactor))->log($level, $message, $context);
        }
    }
}
