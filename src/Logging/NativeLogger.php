<?php
declare(strict_types=1);

namespace Pebble\Logging;

use Psr\Log\AbstractLogger;
use Stringable;

final class NativeLogger extends AbstractLogger
{
    public function __construct(private readonly Redactor $redactor, private readonly string $minimumLevel = 'debug') {}

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        $rank = array_search($level, $levels, true);
        if ($rank === false || $rank < array_search($this->minimumLevel, $levels, true)) {
            return;
        }
        $truncated = false;
        $safe = ['event' => 'logging_fallback', 'level' => $level,
            'message' => $this->redactor->text(is_string($message) ? $message : '[Stringable omitted]', $truncated),
            'context' => $this->redactor->value($context, $truncated), 'diagnosticsTruncated' => $truncated];
        try {
            // Avoid recursively entering the runtime warning bridge if the
            // hosting provider's own error-log destination also fails.
            @error_log('Pebble webhook logging fallback: ' . json_encode($safe, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // No remaining sink: logging must still never interrupt delivery.
        }
    }
}
