<?php
declare(strict_types=1);

namespace Pebble\Operation;

/** Timing is measured by the runner, not by destination services. */
final readonly class ExecutionEntry
{
    public function __construct(
        public string $operationId,
        public \DateTimeImmutable $startedAt,
        public float $durationMilliseconds,
        public OperationResult $result,
    ) {
    }
}
