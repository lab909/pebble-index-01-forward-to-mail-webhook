<?php
declare(strict_types=1);

namespace Pebble\Operation;

final class OperationValidationException extends \RuntimeException
{
    public function __construct(public readonly ValidationFailure $failure)
    {
        parent::__construct($failure->message);
    }
}
