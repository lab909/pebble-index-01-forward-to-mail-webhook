<?php
declare(strict_types=1);

namespace Pebble\Services;

final readonly class FileLogger
{
    public function __construct(private string $path)
    {
        $previousUmask = umask(0077);
        try {
            $directory = dirname($this->path);
            if (!is_dir($directory)) {
                @mkdir($directory, 0700, true);
            }
            // Also keep PHP warnings out of the response and in the private log.
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
            if (is_dir($directory) && @file_put_contents($this->path, '', FILE_APPEND | LOCK_EX) !== false) {
                ini_set('error_log', $this->path);
            }
        } finally {
            umask($previousUmask);
        }
    }

    public function error(int $status, string $message): void
    {
        $line = sprintf("[%s] HTTP %d: %s\n", gmdate('c'), $status,
            str_replace(["\r", "\n"], ' ', $message));
        $previousUmask = umask(0077);
        try {
            if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
                error_log('Pebble webhook: unable to write private log. ' . trim($line));
            }
        } finally {
            umask($previousUmask);
        }
    }
}
