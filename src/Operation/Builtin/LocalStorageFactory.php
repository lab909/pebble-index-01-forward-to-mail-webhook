<?php
declare(strict_types=1);

namespace Pebble\Operation\Builtin;

use DateTimeZone;
use Pebble\Config\StorageConfig;
use Pebble\Operation\{OperationFactoryInterface, OperationInterface, OperationDependencies};
use Pebble\Services\RecordingStorage;
use Psr\Log\NullLogger;

final readonly class LocalStorageFactory implements OperationFactoryInterface
{
    public function __construct(private StorageConfig $config, private DateTimeZone $timezone) {}

    public function create(OperationDependencies $dependencies): OperationInterface
    {
        return new LocalStorageOperation(new RecordingStorage($this->config->directory, $this->timezone,
            $dependencies->requestContext->debugMode ? new NullLogger() : $dependencies->logger));
    }
}
