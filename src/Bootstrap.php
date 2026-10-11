<?php
declare(strict_types=1);

namespace Pebble;

use Closure;
use Pebble\Config\WebhookConfig;
use Pebble\Operation\Builtin\{LocalStorageFactory, EmailFactory, TodoistFactory};
use Pebble\Operation\{OperationDependencies, OperationRegistry, OperationRunner, OutcomeRecorder, RequestContext};
use Pebble\Services\SmtpDiagnostics;
use Pebble\Services\RequestLogger;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Dotenv\Dotenv;

final class Bootstrap
{
    private ?array $environment = null;

    public function __construct(
        private readonly string $projectDirectory,
        private readonly ?Closure $todoistClientFactory = null,
        private readonly ?LoggerInterface $logger = null,
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

    /** Load and validate explicitly registered operations.
     *  @param array<string, \Pebble\Operation\OperationFactoryInterface|class-string|Closure> $factories
     */
    public function prepareOperations(
        WebhookConfig $config,
        array $factories,
        \Pebble\Operation\OperationDependencies $dependencies,
        array $additionalEnabledSettings = [],
    ): \Pebble\Operation\OperationRegistry
    {
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

    public function createHub(\Pebble\Http\ErrorResponder $errors, string $requestId): WebhookHub
    {
        return new WebhookHub($this, $errors, $this->projectDirectory, $requestId);
    }

    public function dependencies(RequestContext $context): OperationDependencies
    {
        return new OperationDependencies(new RequestLogger(
            $context->debugMode ? new NullLogger() : ($this->logger ?? new NullLogger()), $context->requestId), $context);
    }

    public function assemble(WebhookConfig $config, ?OperationDependencies $dependencies = null): BootstrappedServices
    {
        $dependencies ??= $this->dependencies(new RequestContext(
            bin2hex(random_bytes(16)), new \DateTimeImmutable('now', $config->timezone), $config->debugMode));
        // Do not reload Dotenv. Direct callers may supply a typed config without loading .env.
        $secrets = ['WEBHOOK_TOKEN' => $config->webhookToken, 'TODOIST_API_TOKEN' => $config->todoistApiToken];
        if ($config->smtpDsn !== null) {
            $url = parse_url($config->smtpDsn);
            $secrets['SMTP_USERNAME'] = rawurldecode($url['user'] ?? '');
            $secrets['SMTP_PASSWORD'] = rawurldecode($url['pass'] ?? '');
        }
        $diagnostics = new SmtpDiagnostics($this->environment ?? $secrets);
        $registry = $this->prepareOperations($config, [
            'local_storage' => fn () => new LocalStorageFactory($config->storage, $config->timezone),
            'email' => fn () => new EmailFactory($config->email, $diagnostics),
            'todoist' => fn () => new TodoistFactory($config->todoist, $config->timezone, $diagnostics, $this->todoistClientFactory),
        ], $dependencies);
        return new BootstrappedServices($config, $registry, new OperationRunner($registry),
            new OutcomeRecorder($dependencies->logger));
    }
}
