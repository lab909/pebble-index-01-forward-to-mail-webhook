<?php
declare(strict_types=1);
namespace PebbleExample\Receipt;

use Pebble\Config\Environment;

final readonly class ReceiptConfig
{
    public function __construct(public string $directory, public string $mode) {}

    public static function fromEnvironment(Environment $environment): self
    {
        return new self($environment->privatePath('EXAMPLE_RECEIPT_DIRECTORY', 'var/example-receipts'),
            $environment->choice('EXAMPLE_RECEIPT_MODE', ['success', 'partial', 'failure'], 'success'));
    }
}
