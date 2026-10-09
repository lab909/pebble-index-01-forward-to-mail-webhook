<?php
declare(strict_types=1);

namespace Pebble\Recording;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Pebble\Http\Request;

final readonly class Recording
{
    public function __construct(
        public DateTimeImmutable $recordedAt,
        public string $text,
        public ?string $audioPath,
        public ?string $folder = null,
        public ?string $folderName = null,
    ) {
    }

    public static function fromRequest(Request $request, DateTimeZone $timezone): self
    {
        $receivedAt = new DateTimeImmutable('now', $timezone);
        $recordedAt = (new DateTimeImmutable('@' . intdiv((int) $request->post['recordedAt'], 1000)))
            ->setTimezone($timezone);
        $audio = $request->files['audio'] ?? null;
        // Only selected headers are recorded; authorization is excluded.
        $info = [
            'receivedAt' => $receivedAt->format('Y-m-d\TH:i:s.vP'),
            'recordedAt' => $recordedAt->format(DateTimeInterface::ATOM),
            'timezone' => $timezone->getName(),
            'postFields' => $request->post,
            'audio' => $audio === null ? null : [
                'savedAs' => 'audio.m4a',
                'originalFilename' => $audio['name'] ?? null,
                'reportedContentType' => $audio['type'] ?? null,
                'sizeBytes' => filesize($audio['tmp_name']),
            ],
            'headers' => [
                'Content-Type' => $request->server['CONTENT_TYPE'] ?? null,
                'X-Audio-Size' => $request->server['HTTP_X_AUDIO_SIZE'] ?? null,
                'User-Agent' => $request->server['HTTP_USER_AGENT'] ?? null,
            ],
        ];
        $text = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        return new self($recordedAt, $text, $audio['tmp_name'] ?? null);
    }
}
