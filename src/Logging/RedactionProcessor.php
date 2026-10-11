<?php
declare(strict_types=1);

namespace Pebble\Logging;

use Monolog\LogRecord;

final readonly class RedactionProcessor
{
    public function __construct(private Redactor $redactor, private bool $privateContent = false) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $truncated = false;
        $message = $this->redactor->text($record->message, $truncated, $this->privateContent);
        $context = $this->redactor->value($record->context, $truncated, privateContent: $this->privateContent);
        $extra = $this->redactor->value($record->extra, $truncated);
        if ($truncated) {
            $extra['diagnosticsTruncated'] = true;
        }
        return $record->with(message: $message, context: $context, extra: $extra);
    }
}
