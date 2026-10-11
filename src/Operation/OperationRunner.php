<?php
declare(strict_types=1);

namespace Pebble\Operation;

use DateTimeImmutable;
use Pebble\Note\Note;
use Throwable;
use Psr\Log\LoggerInterface;
use Pebble\Services\SafeLog;

final readonly class OperationRunner
{
    public function __construct(private OperationRegistry $registry, private ?LoggerInterface $logger = null)
    {
    }

    public function run(Note $note): ExecutionReport
    {
        $operations = $this->registry->operations();
        $validationFailures = [];
        foreach ($operations as $registered) {
            try {
                $registered->operation->validate($note);
            } catch (OperationValidationException $exception) {
                $validationFailures[$registered->definition->id] = $exception->failure;
            } catch (Throwable) {
                // Raw exception messages, traces, and transport credentials never enter a report.
                $validationFailures[$registered->definition->id] = new ValidationFailure(
                    'Operation validation failed unexpectedly.', HttpFailure::InternalServerError, ErrorCategory::Unexpected);
            }
        }
        if ($validationFailures !== []) {
            foreach ($validationFailures as $id => $failure) {
                SafeLog::write($this->logger, $failure->httpFailure === HttpFailure::InternalServerError ? 'critical' : 'warning',
                    'Operation preflight rejected.', ['event' => 'operation_validation_failed', 'operationId' => $id,
                        'httpStatus' => $failure->httpFailure->value, 'errorCategory' => $failure->category->value, 'reason' => $failure->message]);
            }
            return new ExecutionReport($validationFailures, [], array_map(
                static fn (RegisteredOperation $registered): string => $registered->definition->id, $operations));
        }

        $entries = [];
        $skipped = [];
        $aborted = false;
        foreach ($operations as $registered) {
            $definition = $registered->definition;
            if ($aborted) {
                $skipped[] = $definition->id;
                continue;
            }
            SafeLog::write($this->logger, 'info', 'Operation started.', ['event' => 'operation_started', 'operationId' => $definition->id]);
            $startedAt = new DateTimeImmutable();
            $started = hrtime(true);
            try {
                $result = $registered->operation->execute($note);
            } catch (OperationExecutionException $exception) {
                $result = $exception->result;
            } catch (Throwable) {
                $result = OperationResult::failure(ErrorCategory::Unexpected,
                    HttpFailure::InternalServerError, 'Operation failed unexpectedly.');
            }
            $entries[$definition->id] = new ExecutionEntry($definition->id, $startedAt,
                (hrtime(true) - $started) / 1_000_000, $result);
            SafeLog::write($this->logger, $result->succeeded() ? 'info' : 'error',
                $result->succeeded() ? 'Operation completed.' : 'Operation failed.',
                ['event' => $result->succeeded() ? 'operation_completed' : 'operation_failed',
                    'operationId' => $definition->id, 'durationMs' => $entries[$definition->id]->durationMilliseconds,
                    'outcome' => $result->outcome->value, 'httpStatus' => $result->httpFailure?->value ?? 200,
                    'errorCategory' => $result->errorCategory?->value, 'providerStatus' => $result->providerStatus, 'externalIds' => $result->externalIds,
                    'progress' => $result->progress, 'outputs' => array_intersect_key($result->outputs, ['folder' => true, 'folderName' => true, 'audioPath' => true]),
                    'diagnostics' => $result->diagnostics, 'reason' => $result->message]);
            if (!$result->succeeded() && $definition->failurePolicy === FailurePolicy::Abort) {
                $aborted = true;
            }
        }
        return new ExecutionReport([], $entries, $skipped);
    }
}
