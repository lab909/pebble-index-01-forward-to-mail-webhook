<?php
declare(strict_types=1);

namespace Pebble\Operation;

use Pebble\Services\SafeLog;
use Psr\Log\LoggerInterface;
use Throwable;

/** Observes outcomes after delivery; email has no dependency on local storage. */
final readonly class OutcomeRecorder
{
    public function __construct(private ?LoggerInterface $logger = null) {}

    public function record(ExecutionReport $report, bool $debugMode): void
    {
        $storage = $report->entries['local_storage']->result ?? null;
        $email = $report->entries['email']->result ?? null;
        if ($storage === null || !$storage->succeeded() || $email === null
            || ($debugMode && !$email->succeeded())) {
            return;
        }
        $folder = $storage->outputs['folder'] ?? null;
        if (!is_string($folder)) {
            return;
        }
        $status = $email->succeeded() ? "Accepted by SMTP server.\n" : "SMTP sending failed. Local files retained.\n";
        try {
            if (@file_put_contents($folder . '/mail-status.txt', $status) === false && !$debugMode) {
                SafeLog::write($this->logger, 'warning', 'Unable to record mail status.');
            }
        } catch (Throwable) {
            if (!$debugMode) {
                SafeLog::write($this->logger, 'warning', 'Unable to record mail status.');
            }
        }
    }
}
