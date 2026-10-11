<?php
declare(strict_types=1);

namespace Pebble\Services;

use CURLFile;
use CURLStringFile;
use JsonException;
use Psr\Log\LoggerInterface;
use Pebble\Exception\TodoistException;

final readonly class TodoistClient
{
    public function __construct(
        #[\SensitiveParameter] private string $token,
        private string $baseUrl = 'https://api.todoist.com/api/v1',
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function uploadAudio(string $path, string $projectId): array
    {
        return $this->request('POST', '/uploads', [
            'project_id' => $projectId,
            'file' => new CURLFile($path, 'audio/mp4', 'audio.m4a'),
        ]);
    }

    public function uploadInfo(string $text, string $projectId): array
    {
        return $this->request('POST', '/uploads', [
            'project_id' => $projectId,
            'file' => new CURLStringFile($text, 'info.txt', 'text/plain'),
        ]);
    }

    private function request(string $method, string $path, string|array|null $body = null): array
    {
        $curl = curl_init($this->baseUrl . $path);
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        if (is_string($body)) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => str_starts_with($path, '/uploads') ? 300 : 30,
            // Never forward the API token to a redirect target.
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        try {
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($response === false) {
                throw new TodoistException($this->redact('Todoist connection failed: ' . curl_error($curl)));
            }
            if ($status < 200 || $status >= 300) {
                throw new TodoistException($this->redact(sprintf('Todoist %s %s returned HTTP %d: %s',
                    $method, strtok($path, '?'), $status, $response)), ['providerStatus' => $status]);
            }
            try {
                $data = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new TodoistException('Todoist returned invalid JSON.');
            }
            if (!is_array($data)) {
                throw new TodoistException('Todoist returned an unexpected response.');
            }
            SafeLog::write($this->logger, 'debug', 'Todoist API request succeeded.', ['method' => $method, 'path' => strtok($path, '?'), 'status' => $status]);
            return $data;
        } finally {
            curl_close($curl);
        }
    }

    private function redact(string $message): string
    {
        return str_replace([$this->token, rawurlencode($this->token), base64_encode($this->token)],
            '[redacted]', $message);
    }
}
