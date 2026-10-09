<?php
declare(strict_types=1);

namespace Pebble\Http;


final readonly class Request
{
    public function __construct(
        public array $server,
        public array $post,
        public array $files,
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_POST, $_FILES);
    }
}
