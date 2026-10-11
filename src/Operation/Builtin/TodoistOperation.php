<?php
declare(strict_types=1);

namespace Pebble\Operation\Builtin;

use Pebble\Note\Note;
use Pebble\Operation\{OperationInterface, OperationResult, ErrorCategory, HttpFailure, OperationValidationException, ValidationFailure};
use Pebble\Services\{TodoistService, SmtpDiagnostics};
use Pebble\Exception\TodoistException;

final readonly class TodoistOperation implements OperationInterface
{
    public function __construct(private TodoistService $todoist, private SmtpDiagnostics $diagnostics) {}

    public function validate(Note $note): void
    {
        if (trim($note->transcription) === '') {
            throw new OperationValidationException(new ValidationFailure('Todoist requires a non-empty transcription.'));
        }
    }

    public function execute(Note $note): OperationResult
    {
        try {
            $result = $this->todoist->save($note);
            return OperationResult::success(externalIds: ['projectId' => $result['projectId'], 'taskId' => $result['taskId']],
                progress: ['commentsCreated' => $result['commentsCreated']]);
        } catch (TodoistException $exception) {
            $context = [];
            $ids = [];
            foreach (['projectId', 'taskId', 'stage'] as $key) {
                if (isset($exception->context[$key]) && is_string($exception->context[$key])) {
                    $context[$key] = $this->diagnostics->redact($exception->context[$key]);
                    if ($key !== 'stage') {
                        $ids[$key] = $context[$key];
                    }
                }
            }
            $progress = [];
            if (isset($exception->context['commentsCreated']) && is_int($exception->context['commentsCreated'])) {
                $progress['commentsCreated'] = $exception->context['commentsCreated'];
            }
            return OperationResult::failure(ErrorCategory::Remote, HttpFailure::BadGateway,
                'Todoist task or attachment saving failed.',
                ['todoistDebug' => ['message' => $this->diagnostics->redact($exception->getMessage())] + $context + $progress],
                externalIds: $ids, progress: $progress, partial: isset($ids['taskId']));
        }
    }
}
