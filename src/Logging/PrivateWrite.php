<?php
declare(strict_types=1);

namespace Pebble\Logging;

use Monolog\LogRecord;
use RuntimeException;

trait PrivateWrite
{
    protected function write(LogRecord $record): void
    {
        $umask = umask(0077);
        try {
            if ($this->getUrl() !== null && is_link($this->getUrl())) {
                throw new RuntimeException('Refusing a symlink log file.');
            }
            parent::write($record);
        } finally {
            umask($umask);
        }
    }
}
