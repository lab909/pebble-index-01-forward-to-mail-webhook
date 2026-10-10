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

    /** Prepare the operation pipeline independently of the legacy HTTP coordinator.
     *  Built-in destination adapters are supplied during the Phase 5 migration.
     *  @param array<string, \Pebble\Operation\OperationFactoryInterface|class-string|Closure> $factories
     */
    public function prepareOperations(
        WebhookConfig $config,
        array $factories,
        \Pebble\Operation\OperationDependencies $dependencies,
        array $additionalEnabledSettings = [],
    ): \Pebble\Operation\OperationRegistry {
        $definitions = require $this->projectDirectory . '/config/operations.php';
        if (!is_array($definitions)) {
            throw new \Pebble\Config\ConfigurationException('Invalid operations configuration.');
        }
        $settings = array_replace($additionalEnabledSettings, [
            'STORE_TO_FOLDER' => $config->storeToFolder,
            'FORWARD_TO_MAIL' => $config->forwardToMail,
            'SAVE_TO_TODOIST' => $config->saveToTodoist,
        ]);
        return \Pebble\Operation\OperationRegistry::fromConfiguration($definitions, $settings, $factories, $dependencies);
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
