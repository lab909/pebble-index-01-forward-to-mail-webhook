<?php
declare(strict_types=1);

namespace Pebble\Logging;

use DateTimeInterface;
use Pebble\Note\Note;
use Pebble\Services\SmtpDiagnostics;
use Throwable;

/** Sanitizes values before formatting; never serializes objects or exception arguments. */
final class Redactor
{
    private array $secrets = [];
    private array $noteStrings = [];
    private SmtpDiagnostics $smtp;

    public function __construct(#[\SensitiveParameter] array $environment = [], private readonly ?string $requestId = null)
    {
        $this->smtp = new SmtpDiagnostics($environment);
        foreach ($environment as $key => $value) {
            if (preg_match('/token|password|passwd|secret|credential|username|dsn/i', (string) $key)
                && is_string($value) && $value !== '') {
                foreach ([$value, rawurlencode($value), urlencode($value), base64_encode($value)] as $secret) {
                    $this->secrets[] = $secret;
                }
            }
        }
        usort($this->secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    public function protectNote(Note $note): void
    {
        if ($note->transcription !== '') {
            $this->noteStrings = [$note->transcription, rawurlencode($note->transcription),
                base64_encode($note->transcription), substr(json_encode($note->transcription, JSON_THROW_ON_ERROR), 1, -1)];
        }
    }

    public function text(string $text, bool &$truncated, bool $privateContent = false): string
    {
        $text = $this->smtp->redact($text);
        $text = str_replace($this->secrets, '[redacted]', $text);
        $text = preg_replace('~([a-z][a-z0-9+.-]*://)[^\s/@]+(?::[^\s/@]*)?@~i', '$1[redacted]@', $text);
        $text = preg_replace('/\b(Bearer|Basic)\s+[^\s,;]+/i', '$1 [redacted]', $text);
        $text = preg_replace('/\bAUTH\s+(?:PLAIN|LOGIN|CRAM-MD5|XOAUTH2)\s+[^\r\n]+/i', 'AUTH [redacted]', $text);
        $text = preg_replace('/((?:access_token|api_key|password|token)=)[^&\s]+/i', '$1[redacted]', $text);
        if ($privateContent) {
            $text = str_replace($this->noteStrings, '[omitted]', $text);
        }
        if (!preg_match('//u', $text)) {
            return '[invalid UTF-8 omitted]';
        }
        if (strlen($text) > 2048) {
            $truncated = true;
            return iconv('UTF-8', 'UTF-8//IGNORE', substr($text, 0, 2048)) . ' [truncated]';
        }
        return $text;
    }

    public function value(mixed $value, bool &$truncated, int $depth = 0, bool $privateContent = false): mixed
    {
        if ($depth > 8) {
            $truncated = true;
            return '[depth limit]';
        }
        if ($value instanceof Throwable) {
            return ['class' => get_class($value), 'code' => $value->getCode(),
                'message' => $this->text($value->getMessage(), $truncated, true),
                'previous' => $value->getPrevious() === null ? null : $this->value($value->getPrevious(), $truncated, $depth + 1, true)];
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (is_object($value) || is_resource($value)) {
            return '[object/resource omitted]';
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (count($result) >= 100) {
                    $truncated = true;
                    $result['_truncated'] = true;
                    break;
                }
                $name = (string) $key;
                $key = is_string($key) ? $this->text($key, $truncated) : $key;
                if ($name === 'requestId' && $value[$name] === $this->requestId && $this->requestId !== null
                    && preg_match('/^[a-f0-9]{32}$/D', $this->requestId)) {
                    // Only the ID supplied by bootstrap is trusted. Short environment
                    // secrets can otherwise accidentally match random ID substrings.
                    $result[$key] = $item;
                } elseif (preg_match('/authorization|token|password|passwd|secret|credential|dsn|username|authentication|arguments|args|trace/i', $name)) {
                    $result[$key] = '[redacted]';
                } elseif (preg_match('/^(?:postFields|post|transcription|text|note|audio|audioContent|body|request|server|files)$/i', $name)) {
                    $result[$key] = '[omitted]';
                } else {
                    $result[$key] = $this->value($item, $truncated, $depth + 1,
                        $privateContent || preg_match('/diagnostics|providerResponse|exception/i', $name) === 1);
                }
            }
            return $result;
        }
        return is_string($value) ? $this->text($value, $truncated, $privateContent) : $value;
    }
}
