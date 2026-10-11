<?php
declare(strict_types=1);

namespace Pebble\Logging;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

final class PrivateRotatingFileHandler extends RotatingFileHandler
{
    use PrivateWrite;

    /** The optional clock supports deterministic rotation tests. */
    public function __construct(string $filename, int $maxFiles, Level $level, string $dateFormat,
        DateTimeZone $timezone, private readonly ?Closure $clock = null)
    {
        parent::__construct($filename, $maxFiles, $level, true, 0600, true, $dateFormat, '{filename}-{date}', $timezone);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock === null ? new DateTimeImmutable('now', $this->timezone) : ($this->clock)()->setTimezone($this->timezone);
    }

    protected function getTimedFilename(): string
    {
        $info = pathinfo($this->filename);
        $path = $info['dirname'] . '/' . $info['filename'] . '-' . $this->now()->format($this->dateFormat)
            . (isset($info['extension']) ? '.' . $info['extension'] : '');
        if (is_link($path)) {
            throw new \RuntimeException('Refusing a symlink log file.');
        }
        return $path;
    }

    protected function getNextRotation(): DateTimeImmutable
    {
        return $this->now()->modify($this->dateFormat === self::FILE_PER_MONTH ? 'first day of next month' : 'tomorrow')->setTime(0, 0);
    }

    protected function rotate(): void
    {
        parent::rotate();
        // At a boundary Monolog cleans up before creating the new file. Repeat
        // cleanup after its first write so retention includes the current file.
        $this->mustRotate = $this->url !== null && !file_exists($this->url);
    }
}
