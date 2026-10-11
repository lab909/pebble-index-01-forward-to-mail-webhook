<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pebble\Bootstrap;
use Pebble\Http\{ErrorResponder, Request, ResponseBuilder};
use Pebble\Operation\{ErrorCategory, ExecutionEntry, ExecutionReport, HttpFailure, OperationResult, ValidationFailure};

function assertHub(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$builder = new ResponseBuilder();
$now = new DateTimeImmutable();
$entry = static fn (string $id, OperationResult $result): ExecutionEntry => new ExecutionEntry($id, $now, 1.5, $result);
$empty = $builder->fromReport(new ExecutionReport([], [], []), false);
assertHub($empty->status === 200 && $empty->body['ok'] && json_encode($empty->body['operations']) === '{}', 'Empty operations serialize as a JSON object');
$report = new ExecutionReport([], [
    'custom_archive' => $entry('custom_archive', OperationResult::success(['privatePath' => '/private/hidden'], ['recordId' => 'external-1'])),
    'custom_sync' => $entry('custom_sync', OperationResult::failure(ErrorCategory::Remote, HttpFailure::BadGateway,
        'Attachment failed.', ['stage' => 'upload', 'operations' => 'must-not-overwrite-map'], ['taskId' => 'task-1'], ['commentsCreated' => 1], partial: true)),
], ['later_operation']);
$response = $builder->fromReport($report, true)->withRequestId('generated-id');
$operations = (array) $response->body['operations'];
assertHub($response->status === 502 && !isset($response->body['ok']), 'Classify arbitrary failed destinations');
assertHub($operations['custom_archive']['status'] === 'success' && $operations['custom_archive']['externalIds']['recordId'] === 'external-1', 'Map unknown operation without destination branches');
assertHub(!isset($response->body['stage']), 'Custom diagnostics remain scoped to their operation');
assertHub($operations['custom_sync']['status'] === 'partial' && $operations['custom_sync']['progress']['commentsCreated'] === 1, 'Expose partial external progress');
assertHub($operations['later_operation']['status'] === 'skipped', 'Expose skipped operations');
assertHub($response->body['requestId'] === $response->headers['X-Request-Id'], 'Match body and header request IDs');
assertHub(!str_contains(json_encode($response->body), '/private/hidden') && !isset($operations['custom_archive']['durationMilliseconds']), 'Keep internal paths and timing out of public results');
$production = (array) $builder->fromReport($report, false)->body['operations'];
assertHub(!isset($production['custom_sync']['diagnostics']), 'Debug-only operation diagnostics');
$validation = new ExecutionReport([
    'first' => new ValidationFailure('Bad input.'),
    'second' => new ValidationFailure('Internal preflight failure.', HttpFailure::InternalServerError, ErrorCategory::Unexpected),
], [], ['first', 'second']);
$response = $builder->fromReport($validation, true);
assertHub($response->status === 500 && $response->body['error'] === 'Internal preflight failure.', 'Choose message matching aggregate validation classification');
assertHub(((array) $response->body['operations'])['first']['status'] === 'validation_failed', 'Validation failures supersede skipped statuses');

$logger = new class extends \Psr\Log\AbstractLogger {
    public array $contexts = [];
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->contexts[] = $context;
    }
};
$bootstrap = new Bootstrap(dirname(__DIR__), logger: $logger);
$dependencies = $bootstrap->dependencies(new \Pebble\Operation\RequestContext('trusted-id', $now, false));
$dependencies->logger->info('Delivery event.', ['requestId' => 'forged-id']);
assertHub($logger->contexts[0]['requestId'] === 'trusted-id', 'Service logs use trusted correlation context');
$bootstrap->dependencies(new \Pebble\Operation\RequestContext('debug-id', $now, true))->logger->info('Debug event.');
assertHub(count($logger->contexts) === 1, 'Suppress debug PSR-3 logs before Monolog integration');

$root = sys_get_temp_dir() . '/pebble-hub-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
mkdir($root . '/public', 0700);
mkdir($root . '/config', 0700);
copy(dirname(__DIR__) . '/config/operations.php', $root . '/config/operations.php');
$originalEnvironment = $_ENV;
$originalServer = $_SERVER;
$originalIni = [];
foreach (['display_errors', 'log_errors', 'error_log'] as $name) {
    $originalIni[$name] = ini_get($name);
}
$base = "WEBHOOK_TOKEN=test-token\nAPP_TIMEZONE=UTC\nSTORE_TO_FOLDER=false\nFORWARD_TO_MAIL=false\nSAVE_TO_TODOIST=false\nDEBUG_MODE=false\nLOG_ROTATION=none\n";
try {
    $handle = static function (Request $request, string $extra = '') use ($root, $base): array {
        $_ENV = [];
        $_SERVER = [];
        file_put_contents($root . '/.env', $base . $extra);
        $id = bin2hex(random_bytes(16));
        $errors = new ErrorResponder($root . '/logs/webhook.log', false, $id);
        $errors->usePhpErrorLog();
        $response = (new Bootstrap($root))->createHub($errors, $id)->handle($request);
        assertHub($response->body['requestId'] === $id && $response->headers['X-Request-Id'] === $id, 'Hub applies an internal correlation ID to every response');
        return [$response, $errors];
    };
    $server = ['REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer test-token',
        'CONTENT_TYPE' => 'multipart/form-data', 'HTTP_X_REQUEST_ID' => 'forged-id'];
    $valid = new Request($server, ['recordedAt' => '0', 'transcription' => 'Hub test'], []);
    [$response] = $handle($valid);
    assertHub($response->status === 200 && $response->body['ok'] && !file_exists($root . '/recordings'), 'No-op requests acknowledge without destination writes');
    [$response] = $handle(new Request(array_replace($server, ['REQUEST_METHOD' => 'GET']), [], []));
    assertHub($response->status === 405 && $response->headers['Allow'] === 'POST', 'Preserve method response and headers');
    [$response, $errors] = $handle(new Request(array_replace($server, ['HTTP_AUTHORIZATION' => 'Bearer wrong']), $valid->post, []), "FORWARD_TO_MAIL=true\n");
    assertHub($response->status === 401, 'Authenticate before optional SMTP credentials or factories');
    ob_start();
    $errors->send($response);
    $body = ob_get_clean();
    assertHub($body === '', 'Production errors remain empty');
    $privateLog = file_get_contents($root . '/logs/webhook.log');
    assertHub(str_contains($privateLog, $response->body['requestId']) && !str_contains($privateLog, 'forged-id'), 'Correlate private logs without trusting incoming IDs');
    [$response, $errors] = $handle(new Request(array_replace($server, ['HTTP_AUTHORIZATION' => 'Bearer wrong']), $valid->post, []), "DEBUG_MODE=true\n");
    ob_start();
    $errors->send($response);
    $body = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    assertHub($body['error'] === 'Invalid authorization.' && $body['requestId'] === $response->headers['X-Request-Id'], 'Debug JSON remains meaningful and correlated');
    assertHub(file_get_contents($root . '/logs/webhook.log') === $privateLog, 'Debug requests do not append logs');
    [$response] = $handle($valid, "FORWARD_TO_MAIL=true\n");
    assertHub($response->status === 500 && str_contains($response->body['error'], 'SMTP_SCHEME'), 'Keep safe configuration guidance after authentication');
    [$response] = $handle($valid, "STORE_TO_FOLDER=true\nSTORAGE_DIRECTORY=recordings\n");
    assertHub($response->status === 200 && $response->body['saved'] && ((array) $response->body['operations'])['local_storage']['status'] === 'success', 'Hub executes enabled typed operations');
    $info = json_decode(file_get_contents($root . '/recordings/' . $response->body['folder'] . '/info.txt'), true, flags: JSON_THROW_ON_ERROR);
    assertHub(!str_contains(json_encode($info), 'forged-id'), 'Do not persist incoming correlation headers in note metadata');
} finally {
    $_ENV = $originalEnvironment;
    $_SERVER = $originalServer;
    foreach ($originalIni as $name => $value) {
        ini_set($name, (string) $value);
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
echo "Hub checks passed: arbitrary results, compatibility mapping, private data exclusion, preflight classification, optional configuration, silent/debug responses, and correlation.\n";
