<?php
declare(strict_types=1);

namespace Pebble\Operation;

final readonly class ExecutionReport
{
    /** @param array<string, ValidationFailure> $validationFailures
     *  @param array<string, ExecutionEntry> $entries
     *  @param list<string> $skippedOperationIds
     */
    public function __construct(
        public array $validationFailures,
        public array $entries,
        public array $skippedOperationIds,
    ) {
    }

    public function succeeded(): bool
    {
        if ($this->validationFailures !== [] || $this->skippedOperationIds !== []) {
            return false;
        }
        foreach ($this->entries as $entry) {
            if (!$entry->result->succeeded()) {
                return false;
            }
        }
        return true;
    }

    /** Internal failures take precedence over upstream failures and bad input. */
    public function httpFailure(): ?HttpFailure
    {
        $failures = [];
        foreach ($this->validationFailures as $failure) {
            $failures[] = $failure->httpFailure;
        }
        foreach ($this->entries as $entry) {
            if ($entry->result->httpFailure !== null) {
                $failures[] = $entry->result->httpFailure;
            }
        }
        foreach ([HttpFailure::InternalServerError, HttpFailure::BadGateway, HttpFailure::BadRequest] as $classification) {
            if (in_array($classification, $failures, true)) {
                return $classification;
            }
        }
        return null;
    }
}
