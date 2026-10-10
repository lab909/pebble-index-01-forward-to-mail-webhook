<?php
declare(strict_types=1);

namespace Pebble\Note;

use RuntimeException;

final readonly class AudioAsset
{
    public int $sizeBytes;

    public function __construct(
        public string $path,
        public ?string $originalFilename,
        public ?string $reportedContentType,
        public AssetOwnership $ownership,
    ) {
        $size = is_file($path) && is_readable($path) ? filesize($path) : false;
        if ($size === false) {
            throw new RuntimeException('Audio asset is not a readable file.');
        }
        $this->sizeBytes = $size;
    }

    public function isTemporary(): bool
    {
        return $this->ownership !== AssetOwnership::DurableFile;
    }

    public function cleanup(): void
    {
        if ($this->ownership === AssetOwnership::HubTemporary) {
            @unlink($this->path);
        }
    }
}
