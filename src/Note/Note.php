<?php
declare(strict_types=1);

namespace Pebble\Note;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final readonly class Note
{
    public string $infoText;

    public function __construct(
        public string $transcription,
        public array $postFields,
        public DateTimeImmutable $receivedAt,
        public DateTimeImmutable $recordedAt,
        public DateTimeZone $timezone,
        public array $headers,
        public ?AudioAsset $audio = null,
    ) {
        // Generate one stable representation shared by all destinations.
        $this->infoText = json_encode([
            'receivedAt' => $receivedAt->format('Y-m-d\TH:i:s.vP'),
            'recordedAt' => $recordedAt->format(DateTimeInterface::ATOM),
            'timezone' => $timezone->getName(),
            'postFields' => $postFields,
            'audio' => $audio === null ? null : [
                'savedAs' => 'audio.m4a',
                'originalFilename' => $audio->originalFilename,
                'reportedContentType' => $audio->reportedContentType,
                'sizeBytes' => $audio->sizeBytes,
            ],
            'headers' => $headers,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}
