<?php
declare(strict_types=1);
namespace TestExtension;

require dirname(__DIR__) . '/vendor/autoload.php';
if (!isset($argv[1]) || !is_dir($argv[1] . '/vendor/pebble-example/receipt-operation')) {
    throw new \RuntimeException('Run PHP_BINARY=php8.2 python3 tests/extensions.py to install the disposable package first.');
}
$root = $argv[1];
require $root . '/vendor/autoload.php';

use Pebble\Bootstrap;
use Pebble\Config\WebhookConfig;
use Pebble\Http\{ErrorResponder, Request};
use Pebble\Note\{Note, AudioAsset, AssetOwnership};
use Pebble\Operation\{OperationFactoryInterface, OperationInterface, OperationDependencies, OperationResult};
use PebbleExample\Receipt\ReceiptFactory;

function check(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
final class AfterFactory implements OperationFactoryInterface
{
    public static int $executions = 0;
    public function create(OperationDependencies $dependencies): OperationInterface
    {
        return new class implements OperationInterface {
            public function validate(Note $note): void {}
            public function execute(Note $note): OperationResult {
                ++AfterFactory::$executions;
                if ($note->audio !== null) {
                    check(file_get_contents($note->audio->path) === 'shared audio', 'Later operation can still read audio');
                }
                return OperationResult::success();
            }
        };
    }
}

$originalEnv = $_ENV;
$originalServer = $_SERVER;
$originalIni = ['display_errors' => ini_get('display_errors'), 'log_errors' => ini_get('log_errors')];
$base = ['WEBHOOK_TOKEN' => 'extension-test-token', 'APP_TIMEZONE' => 'UTC', 'DEBUG_MODE' => 'true',
    'STORE_TO_FOLDER' => 'false', 'FORWARD_TO_MAIL' => 'false', 'SAVE_TO_TODOIST' => 'false',
    'LOG_ENABLED' => 'false', 'EXAMPLE_RECEIPT_ENABLED' => 'true', 'EXAMPLE_RECEIPT_DIRECTORY' => 'receipts'];
$definitions = require dirname(__DIR__) . '/config/operations.php';
$exampleRow = ['id' => 'example_receipt', 'enabled' => 'EXAMPLE_RECEIPT_ENABLED', 'factory' => 'example_receipt', 'order' => 400, 'failure' => 'continue'];
$afterRow = ['id' => 'after_example', 'enabled' => 'EXAMPLE_RECEIPT_ENABLED', 'factory' => AfterFactory::class, 'order' => 500, 'failure' => 'continue'];
$catalogue = static function (array $rows) use ($root): void {
    file_put_contents($root . '/config/operations.php', "<?php\nreturn " . var_export($rows, true) . ";\n");
};
$provider = <<<'CONFIG'
<?php
use Pebble\Config\Environment;
use PebbleExample\Receipt\{ReceiptConfig, ReceiptFactory};
return static function (Environment $environment): array {
    return ['example_receipt' => static fn () => new ReceiptFactory(ReceiptConfig::fromEnvironment($environment))];
};
CONFIG;
file_put_contents($root . '/config/operation-factories.php', $provider);
$catalogue([...$definitions, $exampleRow, $afterRow]);
$prepare = static function (array $extra = []) use ($root, $base): array {
    $_ENV = []; $_SERVER = [];
    $values = array_replace($base, $extra);
    file_put_contents($root . '/.env', implode("\n", array_map(static fn ($key, $value) => $key . "='" . $value . "'", array_keys($values), $values)));
    $bootstrap = new Bootstrap($root);
    return [$bootstrap, WebhookConfig::fromEnvironment($root, $bootstrap->loadEnvironment())];
};
$request = static fn (string $text = 'example note', array $server = []): Request => new Request(array_replace([
    'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data', 'HTTP_AUTHORIZATION' => 'Bearer extension-test-token'], $server),
    ['transcription' => $text, 'recordedAt' => '1791536400000'], []);
$handle = static function (array $extra = [], ?Request $request = null) use ($root, $prepare): \Pebble\Http\Response {
    [$bootstrap] = $prepare($extra);
    return $bootstrap->createHub(new ErrorResponder('', true, str_repeat('a', 32)), str_repeat('a', 32))->handle(
        $request ?? new Request(['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data', 'HTTP_AUTHORIZATION' => 'Bearer extension-test-token'],
            ['transcription' => 'example note', 'recordedAt' => '1791536400000'], []));
};
try {
    check(!class_exists(ReceiptFactory::class, false), 'Composer registration alone does not load a factory');
    $response = $handle(['EXAMPLE_RECEIPT_ENABLED' => 'false', 'EXAMPLE_RECEIPT_MODE' => 'invalid', 'EXAMPLE_RECEIPT_DIRECTORY' => 'public/forbidden']);
    check($response->status === 200 && !is_dir($root . '/receipts') && !class_exists(ReceiptFactory::class, false), 'Disabled package/config remains inert');
    check($handle([], $request(server: ['HTTP_AUTHORIZATION' => 'Bearer wrong']))->status === 401, 'Auth before external configuration');
    check(!class_exists(ReceiptFactory::class, false), 'Bad token never loads a package');
    check($handle(['EXAMPLE_RECEIPT_ENABLED' => 'typo'])->status === 500, 'Additional switches strictly parsed');
    check($handle(['EXAMPLE_RECEIPT_MODE' => 'invalid'])->status === 500 && !is_dir($root . '/receipts'), 'Enabled typed configuration validated before side effects');
    check($handle([], $request(''))->status === 400 && AfterFactory::$executions === 0 && !is_dir($root . '/receipts'), 'Package preflight blocks all execution');
    $response = $handle();
    $operations = (array) $response->body['operations'];
    check($response->status === 200 && $operations['example_receipt']['status'] === 'success' && AfterFactory::$executions === 1, 'Installed package runs through live hub');
    $id = $operations['example_receipt']['externalIds']['receiptId'];
    $receipt = json_decode(file_get_contents($root . '/receipts/' . $id . '/receipt.json'), true, flags: JSON_THROW_ON_ERROR);
    check($receipt['requestId'] === $response->body['requestId'] && $receipt['transcriptionBytes'] === strlen('example note'), 'Request context and metadata-only receipt');
    check(!str_contains(json_encode($response->body), $root), 'Generic response hides package filesystem outputs');
    check((fileperms($root . '/receipts/' . $id . '/receipt.json') & 0777) === 0600, 'Private receipt permissions');
    $before = count(glob($root . '/receipts/*'));
    $response = $handle(['EXAMPLE_RECEIPT_MODE' => 'partial']);
    $operations = (array) $response->body['operations'];
    $partialId = $operations['example_receipt']['externalIds']['receiptId'];
    check($response->status === 500 && $operations['example_receipt']['status'] === 'partial'
        && $operations['after_example']['status'] === 'success' && is_file($root . '/receipts/' . $partialId . '/receipt.json'), 'Partial work retained with continuation');
    check($operations['example_receipt']['diagnostics']['stage'] === 'acknowledge' && count(glob($root . '/receipts/*')) === $before + 1, 'Stage scoped to operation');
    $before = count(glob($root . '/receipts/*'));
    $response = $handle(['EXAMPLE_RECEIPT_MODE' => 'failure']);
    check($response->status === 500 && ((array)$response->body['operations'])['after_example']['status'] === 'success'
        && count(glob($root . '/receipts/*')) === $before, 'Failure mode creates no receipt and continues');
    $response = $handle(['EXAMPLE_RECEIPT_MODE' => 'partial', 'DEBUG_MODE' => 'false']);
    ob_start(); $response->send(); $body = ob_get_clean();
    check($response->status === 500 && $body === '', 'Production extension errors stay silent');
    $abortRow = array_replace($exampleRow, ['failure' => 'abort']);
    $catalogue([...$definitions, $abortRow, $afterRow]);
    $response = $handle(['EXAMPLE_RECEIPT_MODE' => 'failure']);
    check(((array)$response->body['operations'])['after_example']['status'] === 'skipped', 'Registry owns abort policy');
    $catalogue([...$definitions, $exampleRow, $afterRow]);
    [$bootstrap, $config] = $prepare();
    $services = $bootstrap->assemble($config);
    file_put_contents($root . '/audio.m4a', 'shared audio');
    $date = new \DateTimeImmutable();
    $audio = new AudioAsset($root . '/audio.m4a', 'test.m4a', 'audio/mp4', AssetOwnership::DurableFile);
    $report = $services->runner->run(new Note('shared note', [], $date, $date, new \DateTimeZone('UTC'), [], $audio));
    check($report->succeeded() && file_get_contents($root . '/audio.m4a') === 'shared audio', 'Package respects shared audio ownership');

    // Direct class names are explicit registration, not discovery. Disabled
    // nonexistent classes remain unloaded; enabled missing classes fail safely.
    $disabledClass = ['id' => 'uninstalled', 'enabled' => 'UNINSTALLED_ENABLED', 'factory' => 'NotInstalled\\ExampleFactory', 'order' => 600, 'failure' => 'continue'];
    $catalogue([...$definitions, $disabledClass]);
    check($handle()->status === 200, 'Disabled uninstalled package is harmless');
    check($handle(['UNINSTALLED_ENABLED' => 'true'])->status === 500, 'Enabled missing class is a safe configuration error');
    $catalogue([...$definitions, $exampleRow]);
    file_put_contents($root . '/config/operation-factories.php', '<?php return static fn ($environment) => ["email" => static fn () => null];');
    check($handle()->status === 500, 'Extension cannot silently replace built-in bindings');
    file_put_contents($root . '/config/operation-factories.php', '<?php return [];');
    check($handle()->status === 500, 'Malformed provider fails before delivery');
    echo "External operation checks passed: real package autoload, disabled laziness, typed settings, auth/preflight, success/failure/partial, continue/abort, generic response, silent errors, audio ownership, explicit classes, conflicting bindings.\n";
} finally {
    $_ENV = $originalEnv; $_SERVER = $originalServer;
    foreach ($originalIni as $key => $value) { ini_set($key, (string) $value); }
}
