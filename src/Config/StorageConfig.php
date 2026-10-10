<?php
declare(strict_types=1);

namespace Pebble\Config;

final readonly class StorageConfig
{
    public function __construct(public string $directory)
    {
    }

    public static function fromEnvironment(Environment $environment): self
    {
        return new self($environment->privatePath('STORAGE_DIRECTORY'));
    }
}
