<?php
declare(strict_types=1);

namespace Pebble\Logging;

use Closure;
use DateTimeZone;
use Monolog\Formatter\{JsonFormatter, LineFormatter};
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Pebble\Config\LoggingConfig;
use Psr\Log\{LoggerInterface, NullLogger};

final readonly class LoggerFactory
{
    public function __construct(private LoggingConfig $config, private DateTimeZone $timezone,
        private Redactor $redactor, private bool $debugMode, private ?Closure $clock = null) {}

    public function create(string $channel = 'hub'): LoggerInterface
    {
        if (!$this->config->allows($this->debugMode)) {
            return new NullLogger();
        }
        try {
            $level = Logger::toMonologLevel($this->config->level);
            $handler = $this->config->rotation === 'none'
                ? new PrivateStreamHandler($this->config->path(), $level, true, 0600, true)
                : new PrivateRotatingFileHandler($this->config->path(), $this->config->maxFiles, $level,
                    $this->config->rotation === 'monthly' ? RotatingFileHandler::FILE_PER_MONTH : RotatingFileHandler::FILE_PER_DAY,
                    $this->timezone, $this->clock);
            $handler->setFormatter($this->config->format === 'json' ? new JsonFormatter()
                : new LineFormatter("[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n", 'Y-m-d\\TH:i:sP'));
            $logger = new Logger($channel, [$handler], [new RedactionProcessor($this->redactor)], $this->timezone);
            if ($this->clock !== null) {
                $clock = $this->clock;
                $timezone = $this->timezone;
                $logger->pushProcessor(static fn (\Monolog\LogRecord $record): \Monolog\LogRecord => $record->with(datetime: $clock()->setTimezone($timezone)));
            }
            return new GuardedLogger($logger, $this->redactor);
        } catch (\Throwable) {
            return new NativeLogger($this->redactor, $this->config->level);
        }
    }
}
