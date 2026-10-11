<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pebble\Bootstrap;
use Pebble\Config\AlertConfig;
use Pebble\Config\ConfigurationException;
use Pebble\Config\Environment;
use Pebble\Config\LoggingConfig;
use Pebble\Config\WebhookConfig;
use Pebble\Services\TodoistClient;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $operation, string $field): void
{
    try {
        $operation();
    } catch (ConfigurationException $exception) {
        verify(str_contains($exception->getMessage(), $field), 'Identify invalid field: ' . $field);
        verify(!str_contains($exception->getMessage(), 'secret-value'), 'Never echo setting values');
        return;
    }
    throw new RuntimeException('Expected invalid setting: ' . $field);
}

$root = sys_get_temp_dir() . '/pebble-config-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
mkdir($root . '/public', 0700);
mkdir($root . '/config', 0700);
copy(dirname(__DIR__) . '/config/operations.php', $root . '/config/operations.php');
symlink($root . '/public', $root . '/linked-public');
$originalEnv = $_ENV;
$originalServer = $_SERVER;
$originalTimeout = ini_get('default_socket_timeout');
try {
    foreach (['true', '1', 'on', 'yes', true] as $value) {
        verify((new Environment($root, ['FLAG' => $value]))->boolean('FLAG', false), 'Parse true');
    }
    foreach (['false', '0', 'off', 'no', false] as $value) {
        verify(!(new Environment($root, ['FLAG' => $value]))->boolean('FLAG', true), 'Parse false');
    }
    foreach (['', 'secret-value', [], null, 1] as $value) {
        rejects(fn () => (new Environment($root, ['FLAG' => $value]))->boolean('FLAG', false), 'FLAG');
    }
    $environment = new Environment($root, []);
    verify($environment->boolean('FLAG', true), 'Keep default boolean');
    verify($environment->integer('PORT', 1, 65535, 587) === 587, 'Keep integer default');
    foreach (['0', '65536', '1.5', 'secret-value'] as $value) {
        rejects(fn () => (new Environment($root, ['PORT' => $value]))->integer('PORT', 1, 65535), 'PORT');
    }
    rejects(fn () => $environment->required('TOKEN'), 'TOKEN');
    verify($environment->privatePath('DIRECTORY', 'var/../logs') === $root . '/logs', 'Normalize relative path');
    verify($environment->privatePath('DIRECTORY', $root . '/public-backup') === $root . '/public-backup', 'Allow sibling prefix');
    foreach (['public', 'public/logs', 'linked-public/new/logs', 'var/../public/logs', 'php://secret-value'] as $value) {
        rejects(fn () => (new Environment($root, ['DIRECTORY' => $value]))->privatePath('DIRECTORY'), 'DIRECTORY');
    }
    file_put_contents($root . '/public/exposed.log', '');
    symlink($root . '/public/exposed.log', $root . '/errors.log');
    rejects(fn () => LoggingConfig::fromEnvironment(new Environment($root, ['LOG_DIRECTORY' => '.', 'LOG_FILENAME' => 'errors.log'])), 'LOG_FILENAME');
    $logging = LoggingConfig::fromEnvironment(new Environment($root, ['LOG_DIRECTORY' => 'private/logs', 'LOG_FILENAME' => 'errors.log']));
    verify($logging->path() === $root . '/private/logs/errors.log', 'Custom log location');
    rejects(fn () => LoggingConfig::fromEnvironment(new Environment($root, ['LOG_FILENAME' => '../secret-value'])), 'LOG_FILENAME');

    $values = ['WEBHOOK_TOKEN' => 'test-token', 'APP_TIMEZONE' => 'UTC',
        'STORE_TO_FOLDER' => 'false', 'FORWARD_TO_MAIL' => 'false', 'SAVE_TO_TODOIST' => 'false',
        'SMTP_PORT' => 'secret-value', 'TODOIST_API_TOKEN' => [], 'STORAGE_DIRECTORY' => 'public'];
    $factoryCalls = 0;
    $bootstrap = new Bootstrap($root, function ($config) use (&$factoryCalls): TodoistClient {
        ++$factoryCalls;
        return new TodoistClient($config->apiToken);
    });
    $config = WebhookConfig::fromEnvironment($root, $values);
    $services = $bootstrap->assemble($config);
    verify($config->storage === null && $config->email === null && $config->todoist === null, 'Disabled configuration ignores credentials');
    verify($services->operations->operations() === [] && $factoryCalls === 0, 'Disabled clients not constructed');
    $values = array_replace($values, ['STORE_TO_FOLDER' => 'true', 'FORWARD_TO_MAIL' => 'true', 'SAVE_TO_TODOIST' => 'true',
        'STORAGE_DIRECTORY' => 'recordings', 'SMTP_SCHEME' => 'smtp', 'SMTP_HOST' => 'localhost', 'SMTP_PORT' => '587',
        'SMTP_USERNAME' => 'user', 'SMTP_PASSWORD' => 'password', 'MAIL_FROM' => 'from@example.com', 'MAIL_TO' => 'to@example.com',
        'MAIL_SUBJECT_PREFIX' => 'Pebble', 'TODOIST_API_TOKEN' => 'token', 'TODOIST_PARENT_PROJECT' => 'parent']);
    $services = $bootstrap->assemble(WebhookConfig::fromEnvironment($root, $values));
    verify(count($services->operations->operations()) === 3 && $factoryCalls === 1, 'Construct enabled clients');
    verify(!file_exists($root . '/recordings') && !file_exists($root . '/logs'), 'Bootstrap does not execute destination work or logging');

    $alertValues = ['FAILURE_EMAIL_FROM' => 'from@example.com', 'FAILURE_EMAIL_TO' => 'one@example.com, two@example.com',
        'FAILURE_EMAIL_SMTP_SCHEME' => 'smtp', 'FAILURE_EMAIL_SMTP_HOST' => 'localhost', 'FAILURE_EMAIL_SMTP_PORT' => '587',
        'FAILURE_EMAIL_SMTP_USERNAME' => 'user', 'FAILURE_EMAIL_SMTP_PASSWORD' => 'password'];
    $alerts = AlertConfig::fromEnvironment(new Environment($root, $alertValues));
    verify(count($alerts->recipients) === 2 && $alerts->cooldownSeconds === 300 && $alerts->timeoutSeconds === 10, 'Prepare alert defaults');
    verify($alerts->stateDirectory === $root . '/var/alerts' && !file_exists($alerts->stateDirectory), 'Alert parsing does not create state or send email');
    foreach (['FAILURE_EMAIL_STATE_DIRECTORY' => 'public/state', 'FAILURE_EMAIL_TIMEOUT_SECONDS' => '0', 'FAILURE_EMAIL_COOLDOWN_SECONDS' => '-1', 'FAILURE_EMAIL_LEVEL' => 'secret-value'] as $field => $value) {
        rejects(fn () => AlertConfig::fromEnvironment(new Environment($root, array_replace($alertValues, [$field => $value]))), $field);
    }

    // Use an isolated .env and restore PHP superglobals; never read the real project .env.
    $_ENV = [];
    $_SERVER = ['PEBBLE_CONFIG_CACHE_TEST' => 'server-value'];
    file_put_contents($root . '/.env', "PEBBLE_CONFIG_CACHE_TEST=file-value\nPEBBLE_CONFIG_NEW_TEST=first\n");
    $bootstrap = new Bootstrap($root);
    $snapshot = $bootstrap->loadEnvironment();
    verify($snapshot['PEBBLE_CONFIG_CACHE_TEST'] === 'server-value', 'Preserve externally supplied environment');
    file_put_contents($root . '/.env', "PEBBLE_CONFIG_NEW_TEST=second\n");
    $_ENV['PEBBLE_CONFIG_NEW_TEST'] = 'mutated';
    verify($bootstrap->loadEnvironment()['PEBBLE_CONFIG_NEW_TEST'] === 'first', 'Load and snapshot environment only once');
    echo "Configuration checks passed: strict settings, private paths, optional clients, inert bootstrap, alert preparation, and cached environment.\n";
} finally {
    $_ENV = $originalEnv;
    $_SERVER = $originalServer;
    ini_set('default_socket_timeout', (string) $originalTimeout);
    unlink($root . '/errors.log');
    unlink($root . '/public/exposed.log');
    unlink($root . '/linked-public');
    if (file_exists($root . '/.env')) {
        unlink($root . '/.env');
    }
    unlink($root . '/config/operations.php');
    rmdir($root . '/config');
    rmdir($root . '/public');
    rmdir($root);
}
