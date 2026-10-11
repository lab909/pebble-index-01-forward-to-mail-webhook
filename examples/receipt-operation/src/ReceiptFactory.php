<?php
declare(strict_types=1);
namespace PebbleExample\Receipt;

use Pebble\Operation\{OperationDependencies, OperationFactoryInterface, OperationInterface};

final readonly class ReceiptFactory implements OperationFactoryInterface
{
    public function __construct(private ReceiptConfig $config) {}

    public function create(OperationDependencies $dependencies): OperationInterface
    {
        return new ReceiptOperation($this->config, $dependencies->requestContext);
    }
}
