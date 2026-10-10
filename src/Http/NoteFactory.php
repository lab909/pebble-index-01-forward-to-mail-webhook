<?php
declare(strict_types=1);

namespace Pebble\Http;

use DateTimeImmutable;
use DateTimeZone;
use Pebble\Note\AssetOwnership;
use Pebble\Note\AudioAsset;
use Pebble\Note\Note;
use RuntimeException;

/** Converts an authenticated, validated HTTP request to destination-independent data. */
final readonly class NoteFactory
{
    public function __construct(private DateTimeZone $timezone)
    {
    }

    public function fromRequest(Request $request): Note
    {
        $timestamp = $request->post['recordedAt'] ?? null;
        if (!is_string($timestamp) || !preg_match('/^\d{1,13}$/D', $timestamp)) {
            throw new RuntimeException('Missing or invalid recordedAt (Unix milliseconds).');
        }
        $upload = $request->files['audio'] ?? null;
        $audio = null;
        if ($upload !== null) {
            // Never allow callers to turn arbitrary local paths into PHP upload assets.
            if (!is_array($upload) || ($upload['error'] ?? null) !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])
                || (isset($upload['name']) && !is_string($upload['name']))
                || (isset($upload['type']) && !is_string($upload['type']))) {
                throw new RuntimeException('Invalid uploaded audio file.');
            }
            $audio = new AudioAsset($upload['tmp_name'], $upload['name'] ?? null,
                $upload['type'] ?? null, AssetOwnership::PhpUpload);
        }
        $transcription = $request->post['transcription'] ?? '';
        return new Note(is_string($transcription) ? $transcription : '', $request->post,
            new DateTimeImmutable('now', $this->timezone),
            (new DateTimeImmutable('@' . intdiv((int) $timestamp, 1000)))->setTimezone($this->timezone),
            $this->timezone, [
                'Content-Type' => $request->server['CONTENT_TYPE'] ?? null,
                'X-Audio-Size' => $request->server['HTTP_X_AUDIO_SIZE'] ?? null,
                'User-Agent' => $request->server['HTTP_USER_AGENT'] ?? null,
            ], $audio);
    }
}
