<?php
declare(strict_types=1);

namespace Pebble\Config;

use Symfony\Component\Mime\Address;
use Throwable;

/** Separate transport and private cooldown settings for failure notifications. */
final readonly class AlertConfig
{
    public function __construct(
        public SmtpConfig $smtp,
        public string $from,
        public array $recipients,
        public string $subjectPrefix,
        public string $level,
        public bool $inDebug,
        public int $cooldownSeconds,
        public string $stateDirectory,
        public int $timeoutSeconds,
    ) {
    }

    public static function fromEnvironment(Environment $environment): self
    {
        $from = $environment->required('FAILURE_EMAIL_FROM');
        $recipients = array_map('trim', explode(',', $environment->required('FAILURE_EMAIL_TO')));
        try {
            new Address($from);
            foreach ($recipients as $recipient) {
                new Address($recipient);
            }
        } catch (Throwable) {
            throw new ConfigurationException('Invalid failure email sender or recipients.');
        }
        $subject = $environment->string('FAILURE_EMAIL_SUBJECT_PREFIX', 'Pebble Hub failure');
        if (preg_match('/[\r\n\x00]/', $subject)) {
            throw new ConfigurationException('Invalid configuration: FAILURE_EMAIL_SUBJECT_PREFIX.');
        }
        return new self(
            SmtpConfig::fromEnvironment($environment, 'FAILURE_EMAIL_SMTP_'), $from, $recipients,
            $subject,
            $environment->choice('FAILURE_EMAIL_LEVEL', ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], 'error'),
            $environment->boolean('FAILURE_EMAIL_IN_DEBUG', false),
            $environment->integer('FAILURE_EMAIL_COOLDOWN_SECONDS', 0, PHP_INT_MAX, 300),
            $environment->privatePath('FAILURE_EMAIL_STATE_DIRECTORY', 'var/alerts'),
            $environment->integer('FAILURE_EMAIL_TIMEOUT_SECONDS', 1, 300, 10),
        );
    }
}
