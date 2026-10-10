<?php
declare(strict_types=1);

namespace Pebble\Recording;

/** Local destination output; it does not replace or mutate the shared note. */
final readonly class StoredRecording
{
    public function __construct(
        public string $folder,
        public string $folderName,
        public ?string $audioPath,
    ) {
    }
}
