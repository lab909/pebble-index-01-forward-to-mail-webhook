<?php
declare(strict_types=1);

namespace Pebble;

use Closure;
use Pebble\Config\WebhookConfig;
use Pebble\Services\RecordingStorage;
use Pebble\Services\RecordingMailer;
use Pebble\Services\TodoistClient;
use Pebble\Services\TodoistService;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;

final class Bootstrap
{
    private ?array $environment = null;

    public function __construct(
        private readonly string $projectDirectory,
        private readonly ?Closure $todoistClientFactory = null,
    ) {
    }

    public function loadEnvironment(): array
    {
        if ($this->environment === null) {
            (new Dotenv())->load($this->projectDirectory . '/.env');
            $this->environment = array_replace($_SERVER, $_ENV);
        }
        return $this->environment;
    }

    public function assemble(WebhookConfig $config): BootstrappedServices
    {
        $storage = $config->storage === null ? null : new RecordingStorage($config->storage->directory, $config->core->timezone);
        $todoist = null;
        if ($config->todoist !== null) {
            $client = $this->todoistClientFactory === null
                ? new TodoistClient($config->todoist->apiToken)
                : ($this->todoistClientFactory)($config->todoist);
            $todoist = new TodoistService($client, $config->todoist->parentProjectId, $config->core->timezone);
        }
        $mailer = null;
        if ($config->email !== null) {
            ini_set('default_socket_timeout', '15');
            $mailer = new RecordingMailer(new Mailer(Transport::fromDsn($config->email->smtp->dsn)),
                $config->email->from, $config->email->to, $config->email->subjectPrefix);
        }
        return new BootstrappedServices($config, $storage, $mailer, $todoist);
    }
}
