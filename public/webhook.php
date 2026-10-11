<?php
declare(strict_types=1);

use Pebble\Bootstrap;
use Pebble\Http\{ErrorResponder, Request, Response};

$projectDirectory = dirname(__DIR__);
$requestId = bin2hex(random_bytes(16));
// Minimal handling remains available when Composer cannot be loaded.
require_once $projectDirectory . '/src/Http/Response.php';
require_once $projectDirectory . '/src/Http/ErrorResponder.php';
$errorResponder = new ErrorResponder($projectDirectory . '/logs/webhook.log',
    filter_var($_ENV['DEBUG_MODE'] ?? $_SERVER['DEBUG_MODE'] ?? getenv('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN), $requestId);
$errorResponder->usePhpErrorLog();

try {
    require $projectDirectory . '/vendor/autoload.php';
} catch (Throwable) {
    $errorResponder->send(new Response(500, ['error' => 'Composer dependencies are unavailable. Run composer install.']));
    return;
}

try {
    $bootstrap = new Bootstrap($projectDirectory);
    $response = $bootstrap->createHub($errorResponder, $requestId)->handle(Request::fromGlobals());
} catch (Throwable) {
    $response = new Response(500, ['error' => 'Unable to process webhook. Check configuration and server logs.']);
}
$errorResponder->send($response);
