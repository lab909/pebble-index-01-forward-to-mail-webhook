<?php
declare(strict_types=1);

namespace Pebble\Config;

final readonly class Environment
{
    public function __construct(public string $projectDirectory, #[\SensitiveParameter] private array $values)
    {
    }

    public function required(string $name): string
    {
        $value = $this->values[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ConfigurationException('Missing configuration: ' . $name . '.');
        }
        return $value;
    }

    public function string(string $name, string $default): string
    {
        return array_key_exists($name, $this->values) ? $this->required($name) : $default;
    }

    public function boolean(string $name, bool $default): bool
    {
        $raw = array_key_exists($name, $this->values) ? $this->values[$name] : $default;
        if (!is_string($raw) && !is_bool($raw)) {
            throw new ConfigurationException('Invalid boolean configuration: ' . $name . '.');
        }
        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null || $raw === '') {
            throw new ConfigurationException('Invalid boolean configuration: ' . $name . '.');
        }
        return $value;
    }

    public function integer(string $name, int $minimum, int $maximum, ?int $default = null): int
    {
        $raw = $default !== null && !array_key_exists($name, $this->values) ? $default : $this->required($name);
        $value = filter_var($raw, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if ($value === false) {
            throw new ConfigurationException('Invalid integer configuration: ' . $name . '.');
        }
        return $value;
    }

    public function choice(string $name, array $choices, string $default): string
    {
        $value = $this->string($name, $default);
        if (!in_array($value, $choices, true)) {
            throw new ConfigurationException('Invalid configuration: ' . $name . '.');
        }
        return $value;
    }

    public function privatePath(string $name, ?string $default = null): string
    {
        return $this->resolvePrivatePath($name, $default === null ? $this->required($name) : $this->string($name, $default));
    }

    public function resolvePrivatePath(string $name, string $raw): string
    {
        if (str_contains($raw, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $raw)) {
            throw new ConfigurationException('Invalid private path: ' . $name . '.');
        }
        $raw = str_replace('\\', '/', $raw);
        $absolute = $raw[0] === '/' || preg_match('~^[A-Za-z]:/~', $raw)
            ? $raw : $this->projectDirectory . '/' . $raw;
        $path = self::normalize($absolute);
        $resolved = self::resolveExistingParent($path);
        $public = self::resolveExistingParent(self::normalize($this->projectDirectory . '/public'));
        if ($resolved === $public || str_starts_with($resolved, $public . '/')) {
            throw new ConfigurationException($name . ' must be outside public/.');
        }
        return $path;
    }

    private static function normalize(string $path): string
    {
        $prefix = str_starts_with($path, '/') ? '/' : substr($path, 0, 3);
        $parts = [];
        foreach (explode('/', substr($path, strlen($prefix))) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        return $prefix . implode('/', $parts);
    }

    private static function resolveExistingParent(string $path): string
    {
        $tail = [];
        $parent = $path;
        while (!file_exists($parent) && !is_link($parent) && dirname($parent) !== $parent) {
            array_unshift($tail, basename($parent));
            $parent = dirname($parent);
        }
        $resolved = realpath($parent);
        if ($resolved === false) {
            if (is_link($parent)) {
                throw new ConfigurationException('Cannot resolve private path.');
            }
            $resolved = $parent;
        }
        return rtrim(str_replace('\\', '/', $resolved), '/') . ($tail === [] ? '' : '/' . implode('/', $tail));
    }
}
