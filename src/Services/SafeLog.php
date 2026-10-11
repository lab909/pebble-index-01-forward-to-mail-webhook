<?php
declare(strict_types=1);

namespace Pebble\Services;

use Psr\Log\LoggerInterface;
use Throwable;

/** Logging is best-effort and must never change destination delivery outcomes. */
final class SafeLog
{
    public static function write(?LoggerInterface $logger, string $level, string $message, array $context = []): void
    {
        try {
            $logger?->log($level, $message, $context);
        } catch (Throwable) {
            // Do not recursively log a logger failure or expose it to callers.
        }
    }
}
