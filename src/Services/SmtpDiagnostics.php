<?php
declare(strict_types=1);

namespace Pebble\Services;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

final readonly class SmtpDiagnostics
{
    public function __construct(#[\SensitiveParameter] private array $environment)
    {
    }

    public function describe(Throwable $exception): array
    {
        $exceptions = [];
        do {
            $details = [
                'class' => get_class($exception),
                'code' => $exception->getCode(),
                'message' => $this->redact($exception->getMessage()),
            ];
            if ($exception instanceof TransportExceptionInterface) {
                $details['smtpTranscript'] = $this->redact($exception->getDebug());
            }
            $exceptions[] = $details;
        } while (($exception = $exception->getPrevious()) !== null);

        return ['exceptions' => $exceptions];
    }

    private function redact(string $text): string
    {
        // Keep SMTP commands and server replies, but remove authentication payloads.
        $text = preg_replace_callback('/^(.*? > )([^\r\n]*)(\r?)$/m', static function (array $match): string {
            $command = $match[2];
            if (preg_match('/^AUTH\s+(\S+)/i', $command, $auth)) {
                $command = 'AUTH ' . $auth[1] . ' [redacted]';
            } elseif (!preg_match('/^(?:(?:EHLO|HELO|STARTTLS|DATA|RSET|NOOP|QUIT)\b|MAIL FROM:|RCPT TO:|\.$)/i', $command)) {
                // LOGIN and CRAM-MD5 responses are standalone encoded lines.
                $command = '[redacted]';
            }
            return $match[1] . $command . $match[3];
        }, $text);

        $secrets = [];
        foreach (['SMTP_USERNAME', 'SMTP_PASSWORD', 'WEBHOOK_TOKEN', 'TODOIST_API_TOKEN'] as $name) {
            $value = $this->environment[$name] ?? null;
            if (is_string($value) && $value !== '') {
                array_push($secrets, $value, rawurlencode($value), urlencode($value), base64_encode($value));
            }
        }
        $username = $this->environment['SMTP_USERNAME'] ?? '';
        $password = $this->environment['SMTP_PASSWORD'] ?? '';
        if (is_string($username) && is_string($password) && $username !== '' && $password !== '') {
            $secrets[] = base64_encode($username . "\0" . $username . "\0" . $password);
            $secrets[] = base64_encode("\0" . $username . "\0" . $password);
            $secrets[] = base64_encode('user=' . $username . "\1auth=Bearer " . $password . "\1\1");
        }
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $text = str_replace($secrets, '[redacted]', $text);
        // Remove any credentials embedded in a transport URL, even if encoded differently.
        return preg_replace('~(smtps?://)[^\s/@]+(?::[^\s/@]*)?@~i', '$1[redacted]@', $text);
    }
}
