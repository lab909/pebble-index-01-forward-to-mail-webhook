<?php
declare(strict_types=1);

namespace Pebble\Config;

final readonly class NoteEmailConfig
{
    public function __construct(public SmtpConfig $smtp, public string $from, public string $to, public string $subjectPrefix)
    {
    }

    public static function fromEnvironment(Environment $environment): self
    {
        return new self(SmtpConfig::fromEnvironment($environment), $environment->required('MAIL_FROM'),
            $environment->required('MAIL_TO'), $environment->required('MAIL_SUBJECT_PREFIX'));
    }
}
