<?php
declare(strict_types=1);

namespace Pebble\Config;

use DateTimeZone;
use RuntimeException;

final readonly class WebhookConfig
{
    public function __construct(
        public string $webhookToken,
        public ?string $storageDirectory,
        public DateTimeZone $timezone,
        public ?string $smtpDsn,
        public ?string $mailFrom,
        public ?string $mailTo,
        public ?string $subjectPrefix,
        public bool $storeToFolder,
        public bool $forwardToMail,
        public bool $debugMode = false,
    ) {
    }

    public static function debugModeFromEnvironment(array $environment): bool
    {
        $raw = $environment['DEBUG_MODE'] ?? 'false';
        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null || $raw === '') {
            throw new RuntimeException('Invalid boolean configuration: DEBUG_MODE.');
        }
        return $value;
    }

    public static function fromEnvironment(string $projectDirectory, array $environment): self
    {
        $required = static function (string $name) use ($environment): string {
            $value = $environment[$name] ?? null;
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('Missing configuration: ' . $name . '.');
            }
            return $value;
        };
        $webhookToken = $required('WEBHOOK_TOKEN');
        if ($webhookToken === 'CHANGE-ME-TO-A-LONG-RANDOM-TOKEN') {
            throw new RuntimeException('Configure the webhook token.');
        }
        $boolean = static function (string $name) use ($environment): bool {
            $value = filter_var($environment[$name] ?? 'true', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null || ($environment[$name] ?? 'true') === '') {
                throw new RuntimeException('Invalid boolean configuration: ' . $name);
            }
            return $value;
        };
        $storeToFolder = $boolean('STORE_TO_FOLDER');
        $forwardToMail = $boolean('FORWARD_TO_MAIL');
        $timezone = new DateTimeZone($required('APP_TIMEZONE'));
        $storageDirectory = null;
        if ($storeToFolder) {
            $storageDirectory = $required('STORAGE_DIRECTORY');
            // Relative storage paths are resolved against the project directory.
            $normalizedPath = str_replace(chr(92), '/', $storageDirectory);
            if ($normalizedPath[0] !== '/' && !preg_match('~^[A-Za-z]:/~', $normalizedPath)) {
                $storageDirectory = $projectDirectory . '/' . $storageDirectory;
            }
        }
        $dsn = $mailFrom = $mailTo = $subjectPrefix = null;
        if ($forwardToMail) {
            $scheme = $required('SMTP_SCHEME');
            $host = $required('SMTP_HOST');
            $port = filter_var($required('SMTP_PORT'), FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if (!in_array($scheme, ['smtp', 'smtps'], true)
                || !preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || $port === false) {
                throw new RuntimeException('Invalid SMTP configuration.');
            }
            // Encode credentials automatically, including passwords containing @, #, or :.
            $dsn = sprintf('%s://%s:%s@%s:%d', $scheme,
                rawurlencode($required('SMTP_USERNAME')),
                rawurlencode($required('SMTP_PASSWORD')), $host, $port);
            // SMTP uses required STARTTLS; SMTPS uses TLS from connection establishment.
            if ($scheme === 'smtp') {
                $dsn .= '?require_tls=true';
            }
            $mailFrom = $required('MAIL_FROM');
            $mailTo = $required('MAIL_TO');
            $subjectPrefix = $required('MAIL_SUBJECT_PREFIX');
        }

        return new self($webhookToken, $storageDirectory, $timezone, $dsn,
            $mailFrom, $mailTo, $subjectPrefix, $storeToFolder, $forwardToMail, self::debugModeFromEnvironment($environment));
    }
}
