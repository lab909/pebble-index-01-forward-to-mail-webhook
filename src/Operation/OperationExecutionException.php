<?php
declare(strict_types=1);

namespace Pebble\Operation;

/** Optional adapter boundary for an expected, already sanitized failure result. */
final class OperationExecutionException extends \RuntimeException
{
    public function __construct(public readonly OperationResult $result)
    {
        if ($result->succeeded()) {
            throw new \InvalidArgumentException('An execution exception requires a failure result.');
        }
        parent::__construct($result->message ?? 'Operation failed.');
    }
}
