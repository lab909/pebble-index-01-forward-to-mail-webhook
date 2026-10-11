<?php
declare(strict_types=1);

namespace Pebble\Operation\Builtin;

use Closure;
use DateTimeZone;
use Pebble\Config\TodoistConfig;
use Pebble\Operation\{OperationFactoryInterface, OperationInterface, OperationDependencies};
use Pebble\Services\{TodoistClient, TodoistService, SmtpDiagnostics};
use Psr\Log\NullLogger;

final readonly class TodoistFactory implements OperationFactoryInterface
{
    public function __construct(private TodoistConfig $config, private DateTimeZone $timezone,
        private SmtpDiagnostics $diagnostics, private ?Closure $clientFactory = null) {}

    public function create(OperationDependencies $dependencies): OperationInterface
    {
        $logger = $dependencies->requestContext->debugMode && !$dependencies->logInDebug ? new NullLogger() : ($dependencies->serviceLogger ?? $dependencies->logger);
        $client = $this->clientFactory === null ? new TodoistClient($this->config->apiToken, logger: $logger) : ($this->clientFactory)($this->config);
        return new TodoistOperation(new TodoistService($client, $this->config->parentProjectId, $this->timezone, $logger), $this->diagnostics);
    }
}
