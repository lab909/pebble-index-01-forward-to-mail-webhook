<?php
declare(strict_types=1);

namespace Pebble\Http;


final readonly class Response
{
    public function __construct(
        public int $status,
        public array $body,
        public array $headers = [],
    ) {
    }

    public function send(bool $showErrors = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        // Error details are public only when debug mode is explicitly enabled.
        if ($this->status >= 400 && !$showErrors) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->body, JSON_UNESCAPED_SLASHES);
    }
}
