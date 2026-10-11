<?php
declare(strict_types=1);

namespace Pebble\Services;

use DateTimeImmutable;
use DateTimeZone;
use Pebble\Note\Note;
use Pebble\Recording\StoredRecording;
use RuntimeException;
use Throwable;
use Psr\Log\LoggerInterface;

final readonly class RecordingStorage
{
    public function __construct(
        private string $storageDirectory,
        private DateTimeZone $timezone,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function save(Note $note): StoredRecording
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
            if ($note->audio !== null) {
                $audioPath = $folder . '/audio.m4a';
                if (!copy($note->audio->path, $audioPath)) {
                    throw new RuntimeException('Cannot save audio.');
                }
            }
            if (file_put_contents($folder . '/info.txt', $note->infoText, LOCK_EX) !== strlen($note->infoText)) {
                throw new RuntimeException('Cannot save webhook information.');
            }
            SafeLog::write($this->logger, 'info', 'Local recording saved.', ['folderName' => $folderName]);
            return new StoredRecording($folder, $folderName, $audioPath);
        } catch (Throwable $exception) {
            if ($folder !== null) {
                @unlink($folder . '/audio.m4a');
                @unlink($folder . '/info.txt');
                @rmdir($folder);
            }
            SafeLog::write($this->logger, 'error', 'Local recording storage failed.');
            throw $exception;
        } finally {
            umask($previousUmask);
        }
    }

}
