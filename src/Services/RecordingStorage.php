<?php
declare(strict_types=1);

namespace Pebble\Services;

use DateTimeImmutable;
use DateTimeZone;
use Pebble\Recording\Recording;
use RuntimeException;
use Throwable;

final readonly class RecordingStorage
{
    public function __construct(
        private string $storageDirectory,
        private DateTimeZone $timezone,
    ) {
    }

    public function save(Recording $recording): Recording
    {
        $previousUmask = umask(0077);
        $folder = null;
        try {
            if (!is_dir($this->storageDirectory)
                && !mkdir($this->storageDirectory, 0700, true) && !is_dir($this->storageDirectory)) {
                throw new RuntimeException('Cannot create storage directory.');
            }
            $folderName = (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d_H-i-s-v')
                . '_' . bin2hex(random_bytes(3));
            $candidate = $this->storageDirectory . '/' . $folderName;
            if (!mkdir($candidate, 0700)) {
                throw new RuntimeException('Cannot create recording folder.');
            }
            $folder = $candidate;
            $audioPath = null;
            if ($recording->audioPath !== null) {
                $audioPath = $folder . '/audio.m4a';
                if (!move_uploaded_file($recording->audioPath, $audioPath)) {
                    throw new RuntimeException('Cannot save audio.');
                }
            }
            if (file_put_contents($folder . '/info.txt', $recording->text, LOCK_EX) !== strlen($recording->text)) {
                throw new RuntimeException('Cannot save webhook information.');
            }
            return new Recording($recording->recordedAt, $recording->text, $audioPath, $folder, $folderName);
        } catch (Throwable $exception) {
            if ($folder !== null) {
                @unlink($folder . '/audio.m4a');
                @unlink($folder . '/info.txt');
                @rmdir($folder);
            }
            throw $exception;
        } finally {
            umask($previousUmask);
        }
    }

    public function recordMailStatus(Recording $recording, bool $sent): void
    {
        if ($recording->folder === null) {
            return;
        }
        $status = $sent ? "Accepted by SMTP server.\n"
            : "SMTP sending failed. Local files retained.\n";
        @file_put_contents($recording->folder . '/mail-status.txt', $status);
    }
}
