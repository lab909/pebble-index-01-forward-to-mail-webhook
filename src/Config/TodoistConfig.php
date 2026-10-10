<?php
declare(strict_types=1);

namespace Pebble\Config;

final readonly class TodoistConfig
{
    public function __construct(#[\SensitiveParameter] public string $apiToken, public string $parentProjectId)
    {
    }

    public static function fromEnvironment(Environment $environment): self
    {
        $token = $environment->required('TODOIST_API_TOKEN');
        $parent = $environment->required('TODOIST_PARENT_PROJECT');
        if (strpbrk($token, "\r\n") !== false) {
            throw new ConfigurationException('Invalid TODOIST_API_TOKEN.');
        }
        if (!extension_loaded('curl')) {
            throw new ConfigurationException('Todoist requires the PHP cURL extension.');
        }
        return new self($token, $parent);
    }
}
