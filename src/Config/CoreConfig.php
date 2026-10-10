<?php
declare(strict_types=1);

namespace Pebble\Config;

use DateTimeZone;
use Throwable;

final readonly class CoreConfig
{
    public function __construct(public string $webhookToken, public DateTimeZone $timezone, public bool $debugMode)
    {
    }

    public static function token(Environment $environment): string
    {
        $token = $environment->required('WEBHOOK_TOKEN');
        if ($token === 'CHANGE-ME-TO-A-LONG-RANDOM-TOKEN') {
            throw new ConfigurationException('Configure the webhook token.');
        }
        return $token;
    }

    public static function fromEnvironment(Environment $environment): self
    {
        try {
            $timezone = new DateTimeZone($environment->required('APP_TIMEZONE'));
        } catch (ConfigurationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ConfigurationException('Invalid configuration: APP_TIMEZONE.');
        }
        return new self(self::token($environment), $timezone, $environment->boolean('DEBUG_MODE', false));
    }
}
