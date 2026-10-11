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
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/D', $id)) {
            throw new \Pebble\Config\ConfigurationException('Invalid operation identifier.');
        }
        $classParts = explode('\\', ltrim($factory, '\\'));
        $isClass = count($classParts) > 1;
        foreach ($classParts as $part) {
            $isClass = $isClass && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) === 1;
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/D', $factory) && !$isClass) {
            throw new \Pebble\Config\ConfigurationException('Invalid operation factory identifier.');
        }
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $enabledSetting) || $order < 0) {
            throw new \Pebble\Config\ConfigurationException('Invalid operation definition: ' . $id . '.');
        }
    }
}
