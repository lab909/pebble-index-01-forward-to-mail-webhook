<?php
declare(strict_types=1);

namespace Pebble\Operation;

final readonly class RequestContext
{
    public function __construct(
        public string $requestId,
        public \DateTimeImmutable $receivedAt,
        public bool $debugMode,
    ) {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $requestId)) {
            throw new \InvalidArgumentException('Invalid request ID.');
        }
    }
}
