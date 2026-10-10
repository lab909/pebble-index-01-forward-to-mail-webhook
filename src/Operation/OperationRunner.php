<?php
declare(strict_types=1);

namespace Pebble\Operation;

use DateTimeImmutable;
use Pebble\Note\Note;
use Throwable;

final readonly class OperationRunner
{
    public function __construct(private OperationRegistry $registry)
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
            if (!$result->succeeded() && $definition->failurePolicy === FailurePolicy::Abort) {
                $aborted = true;
            }
        }
        return new ExecutionReport([], $entries, $skipped);
    }
}
