<?php
declare(strict_types=1);

namespace Pebble\Operation;

final readonly class OperationDefinition
{
    public function __construct(
        public string $id,
        public string $enabledSetting,
        public string $factory,
        public int $order,
        public FailurePolicy $failurePolicy,
    ) {
        foreach ([$id, $factory] as $identifier) {
            if (!preg_match('/^[a-z][a-z0-9_.-]*$/D', $identifier)) {
                throw new \Pebble\Config\ConfigurationException('Invalid operation identifier.');
            }
        }
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $enabledSetting) || $order < 0) {
            throw new \Pebble\Config\ConfigurationException('Invalid operation definition: ' . $id . '.');
        }
    }
}
