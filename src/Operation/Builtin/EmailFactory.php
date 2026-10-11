<?php
declare(strict_types=1);

namespace Pebble\Operation\Builtin;

use Closure;
use Pebble\Config\NoteEmailConfig;
use Pebble\Operation\{OperationFactoryInterface, OperationInterface, OperationDependencies};
use Pebble\Services\{RecordingMailer, SmtpDiagnostics};
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\{Mailer, Transport};

final readonly class EmailFactory implements OperationFactoryInterface
{
    public function __construct(private NoteEmailConfig $config, private SmtpDiagnostics $diagnostics, private ?Closure $mailerFactory = null) {}

    public function create(OperationDependencies $dependencies): OperationInterface
    {
        ini_set('default_socket_timeout', '15');
        $mailer = $this->mailerFactory === null ? new Mailer(Transport::fromDsn($this->config->smtp->dsn)) : ($this->mailerFactory)($this->config);
        return new EmailOperation(new RecordingMailer($mailer, $this->config->from, $this->config->to,
            $this->config->subjectPrefix, $dependencies->requestContext->debugMode && !$dependencies->logInDebug ? new NullLogger() : ($dependencies->serviceLogger ?? $dependencies->logger)),
            $this->diagnostics, $dependencies->requestContext->debugMode);
    }
}
