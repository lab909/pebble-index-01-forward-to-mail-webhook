<?php
declare(strict_types=1);

namespace Pebble\Services;

use Pebble\Http\Request;
use Pebble\Http\Response;

final readonly class RequestValidator
{
    public function __construct(private string $webhookToken)
    {
    }

    public function validate(Request $request): ?Response
    {
        $authorization = $request->server['HTTP_AUTHORIZATION']
            ?? $request->server['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if (!hash_equals('Bearer ' . $this->webhookToken, $authorization)) {
            return new Response(401, ['error' => 'Invalid authorization.']);
        }

        if (stripos($request->server['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== 0) {
            return new Response(415, ['error' => 'Expected multipart/form-data.']);
        }

        // An oversized request can leave both $request->post and $request->files empty in PHP.
        if ($request->post === [] && $request->files === []) {
            return new Response(400, ['error' => 'Empty request. Check post_max_size and upload_max_filesize.']);
        }

        if (!isset($request->post['recordedAt']) || !is_string($request->post['recordedAt'])
            || !preg_match('/^\d{1,13}$/D', $request->post['recordedAt'])) {
            return new Response(400, ['error' => 'Missing or invalid recordedAt (Unix milliseconds).']);
        }

        $audio = $request->files['audio'] ?? null;
        if ($audio !== null) {
            if (!is_array($audio) || !isset($audio['error']) || is_array($audio['error'])) {
                return new Response(400, ['error' => 'Invalid audio upload.']);
            }
            if ($audio['error'] !== UPLOAD_ERR_OK) {
                return new Response(400, ['error' => 'Audio upload failed.', 'uploadError' => $audio['error']]);
            }
            if (!isset($audio['tmp_name']) || !is_string($audio['tmp_name'])
                || !is_uploaded_file($audio['tmp_name'])) {
                return new Response(400, ['error' => 'Invalid uploaded audio file.']);
            }
        }

        return null;
    }
}
