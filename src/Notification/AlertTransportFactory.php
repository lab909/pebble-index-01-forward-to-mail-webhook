<?php
declare(strict_types=1);
namespace Pebble\Notification;

use Pebble\Config\AlertConfig;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use RuntimeException;

final class AlertTransportFactory
{
    public static function create(AlertConfig $config): SmtpTransport
    {
        $transport = Transport::fromDsn($config->smtp->dsn);
        if (!$transport instanceof SmtpTransport) {
            throw new RuntimeException('Unsupported alert transport.');
        }
        $transport->getStream()->setTimeout($config->timeoutSeconds);
        return $transport;
    }
}
