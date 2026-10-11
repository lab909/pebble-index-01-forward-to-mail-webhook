<?php
declare(strict_types=1);

namespace Pebble\Operation;

/** Adapters supply sanitized messages/diagnostics; never store raw exceptions here. */
final readonly class OperationResult
{
    private function __construct(
        public Outcome $outcome,
        public array $outputs,
        public array $externalIds,
        public array $progress,
        public ?ErrorCategory $errorCategory,
        public ?HttpFailure $httpFailure,
        public ?string $message,
        public array $diagnostics,
        public ?int $providerStatus = null,
    ) {
    }

    public static function success(array $outputs = [], array $externalIds = [], array $progress = []): self
    {
        return new self(Outcome::Success, $outputs, $externalIds, $progress, null, null, null, []);
    }

    public static function failure(
        ErrorCategory $category,
        HttpFailure $httpFailure,
        string $message,
        array $diagnostics = [],
        array $externalIds = [],
        array $progress = [],
        array $outputs = [],
        bool $partial = false,
        ?int $providerStatus = null,
    ): self {
        return new self($partial ? Outcome::Partial : Outcome::Failure, $outputs,
            $externalIds, $progress, $category, $httpFailure, $message, $diagnostics, $providerStatus);
    }

    public function succeeded(): bool
    {
        return $this->outcome === Outcome::Success;
    }
}
