<?php
declare(strict_types=1);

namespace Pebble\Logging;

use Monolog\LogRecord;

final readonly class RedactionProcessor
{
    public function __construct(private Redactor $redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $truncated = false;
        $message = $this->redactor->text($record->message, $truncated);
        $context = $this->redactor->value($record->context, $truncated);
        $extra = $this->redactor->value($record->extra, $truncated);
        if ($truncated) {
            $extra['diagnosticsTruncated'] = true;
        }
        return $record->with(message: $message, context: $context, extra: $extra);
    }
}
