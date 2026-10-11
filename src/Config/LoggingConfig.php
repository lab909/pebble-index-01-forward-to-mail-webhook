<?php
declare(strict_types=1);

namespace Pebble\Config;

final readonly class LoggingConfig
{
    public function __construct(
        public string $directory,
        public string $filename,
        public bool $enabled = true,
        public string $level = 'info',
        public string $format = 'json',
        public string $rotation = 'daily',
        public int $maxFiles = 30,
        public bool $inDebug = false,
    ) {}

    public static function fromEnvironment(Environment $environment): self
    {
        $directory = $environment->privatePath('LOG_DIRECTORY', 'logs');
        $filename = $environment->string('LOG_FILENAME', 'webhook.log');
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $filename)) {
            throw new ConfigurationException('Invalid configuration: LOG_FILENAME.');
        }
        $environment->resolvePrivatePath('LOG_FILENAME', $directory . '/' . $filename);
        return new self($directory, $filename, $environment->boolean('LOG_ENABLED', true),
            $environment->choice('LOG_LEVEL', ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], 'info'),
            $environment->choice('LOG_FORMAT', ['json', 'text'], 'json'),
            $environment->choice('LOG_ROTATION', ['daily', 'monthly', 'none'], 'daily'),
            $environment->integer('LOG_MAX_FILES', 0, PHP_INT_MAX, 30),
            $environment->boolean('LOG_IN_DEBUG', false));
    }

    public function allows(bool $debugMode): bool
    {
        return $this->enabled && (!$debugMode || $this->inDebug);
    }

    public function path(): string
    {
        return $this->directory . '/' . $this->filename;
    }
}
