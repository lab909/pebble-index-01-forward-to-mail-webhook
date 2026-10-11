<?php
declare(strict_types=1);

namespace Pebble;

use Closure;
use Pebble\Config\WebhookConfig;
use Pebble\Operation\Builtin\{LocalStorageFactory, EmailFactory, TodoistFactory};
use Pebble\Operation\{OperationDependencies, OperationRegistry, OperationRunner, OutcomeRecorder, RequestContext};
use Pebble\Services\SmtpDiagnostics;
use Pebble\Services\RequestLogger;
use Pebble\Logging\{LoggerFactory, Redactor, RuntimeErrorBridge};
use Pebble\Config\{Environment, LoggingConfig};
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Dotenv\Dotenv;

final class Bootstrap
{
    private ?array $environment = null;
    private ?LoggerFactory $loggers = null;
    private ?LoggingConfig $logging = null;
    private ?Redactor $redactor = null;
    private ?\Pebble\Notification\FailureNotifier $notifications = null;

    public function __construct(
        private readonly string $projectDirectory,
        private readonly ?Closure $todoistClientFactory = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?Closure $alertMailerFactory = null,
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

    public function configureLogging(array $values, \Pebble\Http\ErrorResponder $errors, string $requestId): void
    {
        $environment = new Environment($this->projectDirectory, $values);
        $logging = LoggingConfig::fromEnvironment($environment);
        $debugMode = WebhookConfig::debugModeFromEnvironment($values);
        try {
            $timezone = new \DateTimeZone($environment->string('APP_TIMEZONE', 'UTC'));
        } catch (\Throwable) {
            // Core configuration still validates timezone after authentication.
            $timezone = new \DateTimeZone('UTC');
        }
        $this->logging = $logging;
        $this->redactor = new Redactor($values, $requestId);
        $this->loggers = new LoggerFactory($logging, $timezone, $this->redactor, $debugMode);
        $logger = $this->hubLogger($requestId);
        $errors->configureLogging($logger, $logging->allows($debugMode));
        if (PHP_SAPI !== 'cli') {
            RuntimeErrorBridge::install($logger, $errors);
        }
    }

    public function hasLogging(): bool
    {
        return $this->logging !== null;
    }

    public function hubLogger(string $requestId): LoggerInterface
    {
        return new RequestLogger($this->loggers?->create('hub') ?? new NullLogger(), $requestId);
    }

    public function protectNote(\Pebble\Note\Note $note): void
    {
        $this->redactor?->protectNote($note);
    }

    public function configureNotifications(array $values, string $requestId): void
    {
        $environment = new Environment($this->projectDirectory, $values);
        try {
            if (!$environment->boolean('FAILURE_EMAIL_ENABLED', false)) { return; }
            $debug = $environment->boolean('DEBUG_MODE', false);
            if ($debug && !$environment->boolean('FAILURE_EMAIL_IN_DEBUG', false)) { return; }
            $config = \Pebble\Config\AlertConfig::fromEnvironment($environment);
            (new \Pebble\Notification\CooldownStore($config->stateDirectory))->prepare();
            try { $timezone = new \DateTimeZone($environment->string('APP_TIMEZONE', 'UTC')); }
            catch (\Throwable) { $timezone = new \DateTimeZone('UTC'); }
            $this->redactor ??= new Redactor($values, $requestId);
            $this->notifications = new \Pebble\Notification\FailureNotifier($config, $requestId, $timezone,
                $this->redactor, fn () => $this->notificationLogger($values, $requestId), $this->alertMailerFactory);
        } catch (\Throwable $exception) {
            // Invalid alert credentials never stop normal webhook operations.
            \Pebble\Services\SafeLog::write($this->notificationLogger($values, $requestId), 'error',
                'Invalid failure notification configuration or state directory.',
                ['event' => 'notification_configuration_failed',
                    'reason' => $exception instanceof \Pebble\Config\ConfigurationException ? $exception->getMessage() : 'Cannot prepare private notification state.']);
        }
    }

    private function notificationLogger(array $values, string $requestId): LoggerInterface
    {
        if ($this->loggers !== null) {
            return new RequestLogger($this->loggers->create('notifications'), $requestId);
        }
        try {
            $environment = new Environment($this->projectDirectory, $values);
            if (!$environment->boolean('LOG_ENABLED', true)
                || ($environment->boolean('DEBUG_MODE', false) && !$environment->boolean('LOG_IN_DEBUG', false))) {
                return new NullLogger();
            }
        } catch (\Throwable) { return new NullLogger(); }
        return new RequestLogger(new \Pebble\Logging\NativeLogger(new Redactor($values, $requestId)), $requestId);
    }

    public function notificationReport(\Pebble\Operation\ExecutionReport $report): void
    {
        try { $this->notifications?->report($report); } catch (\Throwable) {}
    }

    public function notificationFailure(string $reason): void
    {
        $this->notifications?->configurationFailure($reason);
    }

    public function finalizeNotifications(): void
    {
        try { $this->notifications?->finalize(); } catch (\Throwable) {}
    }

    public function dependencies(RequestContext $context): OperationDependencies
    {
        $inDebug = $this->logging?->inDebug ?? false;
        $allowed = $this->logging === null ? !$context->debugMode : $this->logging->allows($context->debugMode);
        $logger = !$allowed ? new NullLogger() : ($this->loggers?->create('operations') ?? $this->logger ?? new NullLogger());
        $services = !$allowed ? new NullLogger() : ($this->logger ?? $logger);
        return new OperationDependencies(new RequestLogger($logger, $context->requestId), $context, $inDebug,
            new RequestLogger($services, $context->requestId));
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
        return new BootstrappedServices($config, $registry, new OperationRunner($registry, $dependencies->logger),
            new OutcomeRecorder($dependencies->logger));
    }
}
