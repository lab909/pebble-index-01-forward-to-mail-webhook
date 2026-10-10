<?php
declare(strict_types=1);

namespace Pebble\Note;

/** Who cleans up the file and how long consumers may retain its path. */
enum AssetOwnership
{
    case PhpUpload; // PHP removes it when the HTTP request ends.
    case HubTemporary; // The hub must remove it after synchronous consumers finish.
    case DurableFile; // The owner manages its lifetime; the hub must not delete it.
}
