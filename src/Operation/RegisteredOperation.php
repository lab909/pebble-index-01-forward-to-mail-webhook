<?php
declare(strict_types=1);

namespace Pebble\Operation;

final readonly class RegisteredOperation
{
    public function __construct(public OperationDefinition $definition, public OperationInterface $operation)
    {
    }
}
