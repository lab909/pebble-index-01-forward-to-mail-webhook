<?php
declare(strict_types=1);

namespace Pebble\Operation;

interface OperationFactoryInterface
{
    /** Construct an operation without executing destination work. */
    public function create(OperationDependencies $dependencies): OperationInterface;
}
