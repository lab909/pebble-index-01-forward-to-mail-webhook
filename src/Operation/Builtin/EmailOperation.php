<?php
declare(strict_types=1);

namespace Pebble\Operation\Builtin;

use Pebble\Note\Note;
use Pebble\Operation\{OperationInterface, OperationResult, ErrorCategory, HttpFailure};
use Pebble\Services\{RecordingMailer, SmtpDiagnostics};
use Throwable;

final readonly class EmailOperation implements OperationInterface
{
    public function __construct(private RecordingMailer $mailer, private SmtpDiagnostics $diagnostics, private bool $debugMode) {}

    public function validate(Note $note): void {}

    public function execute(Note $note): OperationResult
    {
        try {
            $this->mailer->send($note);
            return OperationResult::success();
        } catch (Throwable $exception) {
            return OperationResult::failure(ErrorCategory::Transport, HttpFailure::BadGateway,
                'SMTP sending failed.', $this->debugMode ? ['smtpDebug' => $this->diagnostics->describe($exception)] : []);
        }
    }
}
