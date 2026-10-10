<?php
declare(strict_types=1);

namespace Pebble\Exception;

use RuntimeException;
use Throwable;

final class TodoistException extends RuntimeException
{
    public function __construct(string $message, public readonly array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
