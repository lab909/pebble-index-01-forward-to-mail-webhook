<?php
declare(strict_types=1);

namespace Pebble\Http;

use Pebble\Operation\ExecutionReport;
use Pebble\Operation\HttpFailure;

final readonly class ResponseBuilder
{
    public function __construct(private LegacyResultMapper $legacy = new LegacyResultMapper()) {}

    public function fromReport(ExecutionReport $report, bool $debugMode): Response
    {
        $operations = [];
        foreach ($report->entries as $id => $entry) {
            $result = $entry->result;
            $item = ['status' => $result->outcome->value];
            if ($result->externalIds !== []) {
                $item['externalIds'] = $result->externalIds;
            }
            if ($result->progress !== []) {
                $item['progress'] = $result->progress;
            }
            if (!$result->succeeded()) {
                $item['error'] = ['category' => $result->errorCategory->value,
                    'httpStatus' => $result->httpFailure->value, 'message' => $result->message];
                if ($debugMode && $result->diagnostics !== []) {
                    $item['diagnostics'] = $result->diagnostics;
                }
            }
            // Internal output paths and runner timing are not public result data.
            $operations[$id] = $item;
        }
        foreach ($report->skippedOperationIds as $id) {
            $operations[$id] = ['status' => 'skipped'];
        }
        foreach ($report->validationFailures as $id => $failure) {
            $operations[$id] = ['status' => 'validation_failed', 'error' => [
                'category' => $failure->category->value, 'httpStatus' => $failure->httpFailure->value,
                'message' => $failure->message]];
            if ($debugMode && $failure->diagnostics !== []) {
                $operations[$id]['diagnostics'] = $failure->diagnostics;
            }
        }
        $status = $report->httpFailure()?->value ?? 200;
        // Keep the legacy validation/internal-error shape, adding operation details.
        if ($report->validationFailures !== []) {
            foreach ($report->validationFailures as $failure) {
                if ($failure->httpFailure->value === $status) {
                    return new Response($status, ['error' => $failure->message, 'operations' => (object) $operations]);
                }
            }
        }
        if ($report->httpFailure() === HttpFailure::InternalServerError) {
            foreach ($report->entries as $entry) {
                if ($entry->result->httpFailure === HttpFailure::InternalServerError) {
                    return new Response(500, ['error' => $entry->result->message, 'operations' => (object) $operations]);
                }
            }
        }
        $body = $this->legacy->map($report);
        if ($report->succeeded()) {
            $body = ['ok' => true] + $body;
        }
        return new Response($status, $body + ['operations' => (object) $operations]);
    }
}
