<?php
declare(strict_types=1);
namespace Pebble\Notification;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Monolog\{Level, Logger, LogRecord};
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\SymfonyMailerHandler;
use Pebble\Config\AlertConfig;
use Pebble\Logging\{Redactor, RedactionProcessor};
use Pebble\Operation\{ExecutionReport, HttpFailure, ErrorCategory};
use Pebble\Services\SafeLog;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\Email;
use Throwable;

/** Explicit request finalization; never subscribes to the file logger. */
final class FailureNotifier
{
    private bool $finalized = false;
    private array $failures = [];
    private array $operations = [];

    public function __construct(private readonly AlertConfig $config, private readonly string $requestId,
        private readonly DateTimeZone $timezone, private readonly Redactor $redactor,
        private readonly Closure $fileLogger, private readonly ?Closure $mailerFactory = null,
        private readonly ?Closure $clock = null) {}

    public function report(ExecutionReport $report): void
    {
        foreach ($report->validationFailures as $id => $failure) {
            if ($failure->httpFailure !== HttpFailure::BadRequest && $failure->category !== ErrorCategory::Validation) {
                $this->failures[] = ['operationId' => $id, 'errorCategory' => $failure->category->value,
                    'httpStatus' => $failure->httpFailure->value, 'severity' => 'critical', 'reason' => $failure->message];
            }
        }
        foreach ($report->entries as $id => $entry) {
            $result = $entry->result;
            $metadata = ['operationId' => $id, 'outcome' => $result->outcome->value,
                'externalIds' => $result->externalIds, 'progress' => $result->progress,
                'outputs' => array_intersect_key($result->outputs, ['folder' => true, 'folderName' => true])];
            $this->operations[] = $metadata;
            if (!$result->succeeded() && $result->httpFailure !== HttpFailure::BadRequest
                && $result->errorCategory !== ErrorCategory::Validation) {
                $this->failures[] = $metadata + ['errorCategory' => $result->errorCategory?->value,
                    'httpStatus' => $result->httpFailure?->value, 'providerStatus' => $result->providerStatus,
                    'severity' => 'error', 'reason' => $result->message, 'diagnostics' => $result->diagnostics];
            }
        }
        if ($report->skippedOperationIds !== []) { $this->operations[] = ['skipped' => $report->skippedOperationIds]; }
    }

    public function configurationFailure(string $reason): void
    {
        $this->failures[] = ['operationId' => 'hub', 'errorCategory' => 'configuration',
            'httpStatus' => 500, 'severity' => 'critical', 'reason' => $reason];
    }

    public function finalize(): void
    {
        if ($this->finalized) { return; }
        $this->finalized = true;
        $failures = array_values(array_filter($this->failures, fn (array $failure): bool =>
            Logger::toMonologLevel($failure['severity'])->value >= Logger::toMonologLevel($this->config->level)->value));
        if ($failures === []) { return; }
        $fingerprints = [];
        foreach ($failures as $failure) {
            // IDs, messages, dates, paths, and provider prose are intentionally excluded.
            $stage = $failure['progress']['stage'] ?? $failure['diagnostics']['stage']
                ?? $failure['diagnostics']['todoistDebug']['stage'] ?? '';
            $fingerprints[] = hash('sha256', json_encode([$failure['operationId'], $stage,
                $failure['providerStatus'] ?? null, $failure['errorCategory']], JSON_THROW_ON_ERROR));
        }
        $now = $this->clock === null ? new DateTimeImmutable('now', $this->timezone) : ($this->clock)();
        $failure = null;
        try {
            $outcome = (new CooldownStore($this->config->stateDirectory))->deliver(array_unique($fingerprints),
                $now->getTimestamp(), $this->config->cooldownSeconds, function () use ($failures, $now, &$failure): bool {
                    $handler = null;
                    $mailer = null;
                    try {
                        $mailer = $this->mailerFactory === null ? Transport::fromDsn($this->config->smtp->dsn) : ($this->mailerFactory)($this->config);
                        if ($mailer instanceof SmtpTransport) { $mailer->getStream()->setTimeout($this->config->timeoutSeconds); }
                        $truncated = false;
                        $email = (new Email())->from($this->config->from)->to(...$this->config->recipients)
                            ->subject($this->redactor->text($this->config->subjectPrefix, $truncated));
                        $handler = new SymfonyMailerHandler($mailer, $email, Level::Debug);
                        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES));
                        $processor = new RedactionProcessor($this->redactor, privateContent: true);
                        $record = $processor(new LogRecord($now, 'notifications', Level::Error, 'Webhook delivery failure.',
                            ['requestId' => $this->requestId, 'timezone' => $this->timezone->getName(),
                                'failures' => $failures, 'operations' => $this->operations]));
                        $handler->handleBatch([$record]); // One email for the complete request report.
                        return true;
                    } catch (Throwable $exception) {
                        $failure = $exception;
                        return false;
                    } finally {
                        try { $handler?->close(); } catch (Throwable) {}
                        try { if ($mailer instanceof SmtpTransport) { $mailer->stop(); } } catch (Throwable) {}
                    }
                });
        } catch (Throwable $exception) { $failure = $exception; $outcome = 'failed'; }
        SafeLog::write(($this->fileLogger)(), $outcome === 'failed' ? 'error' : 'info', 'Failure notification ' . $outcome . '.',
            ['event' => 'notification_' . $outcome, 'requestId' => $this->requestId, 'diagnostics' => $failure]);
    }
}
