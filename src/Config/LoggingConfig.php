<?php
declare(strict_types=1);

namespace Pebble\Config;

final readonly class LoggingConfig
{
    public function __construct(public string $directory, public string $filename)
    {
    }

    public static function fromEnvironment(Environment $environment): self
    {
        $directory = $environment->privatePath('LOG_DIRECTORY', 'logs');
        $filename = $environment->string('LOG_FILENAME', 'webhook.log');
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $filename)) {
            throw new ConfigurationException('Invalid configuration: LOG_FILENAME.');
        }
        $environment->resolvePrivatePath('LOG_FILENAME', $directory . '/' . $filename);
        return new self($directory, $filename);
    }

    public function path(): string
    {
        return $this->directory . '/' . $this->filename;
    }
}
