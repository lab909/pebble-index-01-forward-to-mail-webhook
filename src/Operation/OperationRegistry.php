<?php
declare(strict_types=1);

namespace Pebble\Operation;

use Closure;
use Pebble\Config\ConfigurationException;
use Throwable;

final readonly class OperationRegistry
{
    /** @param list<OperationDefinition> $definitions
     *  @param list<RegisteredOperation> $operations
     */
    private function __construct(public array $definitions, private array $operations)
    {
    }

    /** Factories may be instances, no-argument class names, or lazy providers.
     *  @param array<string, bool> $enabledSettings Already parsed settings; missing means disabled.
     *  @param array<string, OperationFactoryInterface|class-string|Closure> $factories
     */
    public static function fromConfiguration(
        array $configuration,
        array $enabledSettings,
        array $factories,
        OperationDependencies $dependencies,
    ): self {
        $definitions = [];
        $ids = [];
        // Validate the whole catalogue before loading a factory or constructing an operation.
        foreach ($configuration as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)
                || !is_string($row['enabled'] ?? null) || !is_string($row['factory'] ?? null)
                || !is_int($row['order'] ?? null) || !is_string($row['failure'] ?? null)) {
                throw new ConfigurationException('Invalid operation definition.');
            }
            $policy = FailurePolicy::tryFrom($row['failure']);
            if ($policy === null) {
                throw new ConfigurationException('Unsupported operation failure policy.');
            }
            $definition = new OperationDefinition($row['id'], $row['enabled'], $row['factory'], $row['order'], $policy);
            if (isset($ids[$definition->id])) {
                throw new ConfigurationException('Duplicate operation ID: ' . $definition->id . '.');
            }
            $ids[$definition->id] = true;
            if (array_key_exists($definition->enabledSetting, $enabledSettings)
                && !is_bool($enabledSettings[$definition->enabledSetting])) {
                throw new ConfigurationException('Invalid enabled setting: ' . $definition->enabledSetting . '.');
            }
            if (array_key_exists($definition->factory, $factories)) {
                $binding = $factories[$definition->factory];
                if (!$binding instanceof OperationFactoryInterface && !$binding instanceof Closure && !is_string($binding)) {
                    throw new ConfigurationException('Invalid operation factory: ' . $definition->id . '.');
                }
            }
            $definitions[] = $definition;
        }
        usort($definitions, static fn (OperationDefinition $a, OperationDefinition $b): int =>
            ($a->order <=> $b->order) ?: strcmp($a->id, $b->id));

        $enabled = array_values(array_filter($definitions, static fn (OperationDefinition $definition): bool =>
            $enabledSettings[$definition->enabledSetting] ?? false));
        // Resolve every enabled binding before create(); disabled class names are never autoloaded.
        $resolved = [];
        foreach ($enabled as $definition) {
            // A fully qualified class named explicitly in the catalogue is also
            // a binding. Only enabled rows reach this autoload/constructor path.
            $binding = $factories[$definition->factory] ?? (str_contains($definition->factory, '\\') ? $definition->factory : null);
            try {
                if (is_string($binding)) {
                    if (!is_subclass_of($binding, OperationFactoryInterface::class)) {
                        throw new ConfigurationException('Invalid factory class.');
                    }
                    $binding = new $binding();
                } elseif ($binding instanceof Closure) {
                    $binding = $binding();
                }
                if (!$binding instanceof OperationFactoryInterface) {
                    throw new ConfigurationException('Missing or invalid factory binding.');
                }
            } catch (Throwable $exception) {
                throw new ConfigurationException('Invalid operation factory: ' . $definition->id . '.', 0, $exception);
            }
            $resolved[$definition->id] = $binding;
        }
        $operations = [];
        foreach ($enabled as $definition) {
            try {
                $operation = $resolved[$definition->id]->create($dependencies);
            } catch (Throwable $exception) {
                throw new ConfigurationException('Cannot construct operation: ' . $definition->id . '.', 0, $exception);
            }
            $operations[] = new RegisteredOperation($definition, $operation);
        }
        return new self($definitions, $operations);
    }

    /** @return list<RegisteredOperation> */
    public function operations(): array
    {
        return $this->operations;
    }
}
