<?php
declare(strict_types=1);

namespace Pebble\Config;

final readonly class SmtpConfig
{
    public function __construct(#[\SensitiveParameter] public string $dsn)
    {
    }

    public static function fromEnvironment(Environment $environment, string $prefix = 'SMTP_'): self
    {
        $scheme = $environment->required($prefix . 'SCHEME');
        $host = $environment->required($prefix . 'HOST');
        $port = $environment->integer($prefix . 'PORT', 1, 65535);
        if (!in_array($scheme, ['smtp', 'smtps'], true) || !preg_match('/^[a-zA-Z0-9.-]+$/D', $host)) {
            throw new ConfigurationException('Invalid SMTP configuration.');
        }
        $dsn = sprintf('%s://%s:%s@%s:%d', $scheme,
            rawurlencode($environment->required($prefix . 'USERNAME')),
            rawurlencode($environment->required($prefix . 'PASSWORD')), $host, $port);
        return new self($dsn . ($scheme === 'smtp' ? '?require_tls=true' : ''));
    }
}
