<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pebble\Bootstrap;
use Pebble\Config\ConfigurationException;
use Pebble\Config\WebhookConfig;
use Pebble\Http\NoteFactory;
use Pebble\Http\Request;
use Pebble\Note\Note;
use Pebble\Operation\ErrorCategory;
use Pebble\Operation\HttpFailure;
use Pebble\Operation\OperationDependencies;
use Pebble\Operation\OperationExecutionException;
use Pebble\Operation\OperationFactoryInterface;
use Pebble\Operation\OperationInterface;
use Pebble\Operation\OperationRegistry;
use Pebble\Operation\OperationResult;
use Pebble\Operation\OperationRunner;
use Pebble\Operation\OperationValidationException;
use Pebble\Operation\Outcome;
use Pebble\Operation\RequestContext;
use Pebble\Operation\ValidationFailure;
use Psr\Log\NullLogger;

function assertOperation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ProbeFactory implements OperationFactoryInterface
{
    public int $calls = 0;
    public ?OperationDependencies $received = null;

    public function __construct(private Closure $validate, private Closure $execute)
    {
    }

    public function create(OperationDependencies $dependencies): OperationInterface
    {
        ++$this->calls;
        $this->received = $dependencies;
        return new class($this->validate, $this->execute) implements OperationInterface {
            public function __construct(private Closure $validation, private Closure $execution)
            {
            }
            public function validate(Note $note): void
            {
                ($this->validation)($note);
            }
            public function execute(Note $note): OperationResult
            {
                return ($this->execution)($note);
            }
        };
    }
}

function definition(string $id, int $order = 100, string $policy = 'continue'): array
{
    return ['id' => $id, 'enabled' => strtoupper($id) . '_ENABLED', 'factory' => $id,
        'order' => $order, 'failure' => $policy];
}

function factory(?Closure $execute = null, ?Closure $validate = null): ProbeFactory
{
    return new ProbeFactory($validate ?? static function (): void {},
        $execute ?? static fn (): OperationResult => OperationResult::success());
}

$note = (new NoteFactory(new DateTimeZone('UTC')))->fromRequest(new Request([], [
    'recordedAt' => '0', 'transcription' => 'Independent operation test',
], []));
$dependencies = new OperationDependencies(new NullLogger(), new RequestContext('test-request', $note->receivedAt, false));

// Validate the entire catalogue before providers or factories run.
$created = 0;
$bindings = ['archive' => function () use (&$created): OperationFactoryInterface {
    ++$created;
    return factory();
}];
$invalidConfigurations = [
    [definition('archive'), definition('archive')],
    [definition('archive'), definition('another', 200, 'retry')],
    [definition('archive'), array_replace(definition('another'), ['order' => '200'])],
    [definition('archive'), definition('Bad_ID')],
    [definition('archive'), array_replace(definition('another'), ['factory' => '../secret'])],
];
foreach ($invalidConfigurations as $rows) {
    try {
        OperationRegistry::fromConfiguration($rows, ['ARCHIVE_ENABLED' => true], $bindings, $dependencies);
        throw new LogicException('Expected invalid catalogue rejection');
    } catch (ConfigurationException) {
        assertOperation($created === 0, 'Reject invalid catalogue before factory loading');
    }
}
foreach ([42, new stdClass(), 'stdClass', 'MissingFactoryClass', static fn () => new stdClass()] as $invalidFactory) {
    try {
        OperationRegistry::fromConfiguration([definition('archive')], ['ARCHIVE_ENABLED' => true],
            ['archive' => $invalidFactory], $dependencies);
        throw new LogicException('Expected invalid factory rejection');
    } catch (ConfigurationException $exception) {
        assertOperation(!str_contains($exception->getMessage(), 'stdClass'), 'Expose only controlled factory diagnostics');
    }
}
try {
    OperationRegistry::fromConfiguration([definition('archive')], ['ARCHIVE_ENABLED' => 'true'], $bindings, $dependencies);
    throw new LogicException('Enabled settings must be parsed booleans');
} catch (ConfigurationException) {
}

$autoloads = [];
$autoload = static function (string $class) use (&$autoloads): void { $autoloads[] = $class; };
spl_autoload_register($autoload);
try {
    $disabledCalls = 0;
    $empty = OperationRegistry::fromConfiguration([definition('absent'), definition('lazy')], [], [
        'absent' => 'AbsentPackageFactory',
        'lazy' => function () use (&$disabledCalls): OperationFactoryInterface { ++$disabledCalls; return factory(); },
    ], $dependencies);
    assertOperation($autoloads === [] && $disabledCalls === 0 && $empty->operations() === [], 'Disabled packages/providers are never loaded');
    assertOperation((new OperationRunner($empty))->run($note)->succeeded(), 'Empty enabled registry is a successful no-op');
} finally {
    spl_autoload_unregister($autoload);
}

// Destination-independent order, stable ties, and all validation before the first execution.
$events = [];
$rows = [definition('zeta', 300), definition('alpha', 100), definition('beta', 100)];
$bindings = [];
$flags = [];
foreach (['zeta', 'alpha', 'beta'] as $id) {
    $flags[strtoupper($id) . '_ENABLED'] = true;
    $bindings[$id] = factory(
        function (Note $input) use (&$events, $id, $note): OperationResult {
            assertOperation($input === $note, 'Every operation receives the same note');
            $events[] = 'execute:' . $id;
            return OperationResult::success(['destination' => $id]);
        }, function (Note $input) use (&$events, $id): void { $events[] = 'validate:' . $id; });
}
$registry = OperationRegistry::fromConfiguration($rows, $flags, $bindings, $dependencies);
assertOperation($events === [], 'Bootstrap never validates or executes destination work');
foreach ($bindings as $binding) {
    assertOperation($binding->calls === 1 && $binding->received === $dependencies, 'Inject shared infrastructure explicitly');
}
$report = (new OperationRunner($registry))->run($note);
assertOperation($events === ['validate:alpha', 'validate:beta', 'validate:zeta', 'execute:alpha', 'execute:beta', 'execute:zeta'], 'Deterministic execution and complete preflight');
assertOperation($report->succeeded() && $report->httpFailure() === null, 'Report full success');
foreach ($report->entries as $entry) {
    assertOperation($entry->durationMilliseconds >= 0 && $entry->startedAt instanceof DateTimeImmutable, 'Measure execution timing in reports');
    assertOperation(!property_exists($entry->result, 'durationMilliseconds'), 'Destination results carry no runner timing');
}

$events = [];
$badValidation = factory(validate: static function (): void {
    throw new OperationValidationException(new ValidationFailure('A transcription is required.'));
});
$unexpectedValidation = factory(validate: static function (): void { throw new RuntimeException('secret-token'); });
$lastValidation = factory(validate: function () use (&$events): void { $events[] = 'last validated'; });
$registry = OperationRegistry::fromConfiguration($rows, $flags,
    ['alpha' => $badValidation, 'beta' => $unexpectedValidation, 'zeta' => $lastValidation], $dependencies);
$report = (new OperationRunner($registry))->run($note);
assertOperation(count($report->validationFailures) === 2 && $events === ['last validated'] && $report->entries === [], 'Collect preflight failures and never execute writes');
assertOperation($report->skippedOperationIds === ['alpha', 'beta', 'zeta'] && !$report->succeeded(), 'Report all blocked executions');
assertOperation($report->httpFailure() === HttpFailure::InternalServerError, 'Classify unexpected preflight failures generically');
assertOperation(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'secret-token'), 'Unexpected validation diagnostics never leak exception messages');
$registry = OperationRegistry::fromConfiguration([definition('alpha')], $flags, ['alpha' => $badValidation], $dependencies);
assertOperation((new OperationRunner($registry))->run($note)->httpFailure() === HttpFailure::BadRequest, 'Expected validation remains HTTP 400');

$partial = OperationResult::failure(ErrorCategory::Remote, HttpFailure::BadGateway, 'Attachment failed.',
    ['stage' => 'metadata upload'], ['projectId' => 'project-1', 'taskId' => 'task-1'], ['commentsCreated' => 1], partial: true);
$registry = OperationRegistry::fromConfiguration($rows, $flags, [
    'alpha' => factory(static fn () => OperationResult::success(['folder' => '/private/saved'])),
    'beta' => factory(static fn () => $partial),
    'zeta' => factory(static function (): OperationResult { throw new RuntimeException('smtp-password'); }),
], $dependencies);
$report = (new OperationRunner($registry))->run($note);
assertOperation(count($report->entries) === 3 && $report->entries['alpha']->result->succeeded(), 'Continue failures without discarding successes');
assertOperation($report->entries['beta']->result === $partial && $partial->outcome === Outcome::Partial && !$partial->succeeded(), 'Retain partial IDs/progress and distinguish incomplete delivery');
assertOperation($partial->externalIds['taskId'] === 'task-1' && $partial->progress['commentsCreated'] === 1, 'Keep partial external state');
assertOperation($report->httpFailure() === HttpFailure::InternalServerError && !str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'smtp-password'), 'Unexpected execution exceptions become sanitized HTTP 500 results');

$registry = OperationRegistry::fromConfiguration([definition('alpha', 100), definition('beta', 200, 'abort'), definition('zeta', 300)], $flags, [
    'alpha' => factory(), 'beta' => factory(static function () use ($partial): OperationResult { throw new OperationExecutionException($partial); }),
    'zeta' => factory(static function (): OperationResult { throw new LogicException('Must never execute'); }),
], $dependencies);
$report = (new OperationRunner($registry))->run($note);
assertOperation(array_keys($report->entries) === ['alpha', 'beta'] && $report->skippedOperationIds === ['zeta'], 'Abort after a failed operation without losing prior success');
assertOperation($report->httpFailure() === HttpFailure::BadGateway && $report->entries['beta']->result === $partial, 'Adapt expected execution failures and classify HTTP 502');

// The bootstrap reads the project catalogue and honors existing feature switches.
$config = WebhookConfig::fromEnvironment(dirname(__DIR__), ['WEBHOOK_TOKEN' => 'test-token', 'APP_TIMEZONE' => 'UTC',
    'STORE_TO_FOLDER' => 'false', 'FORWARD_TO_MAIL' => 'false', 'SAVE_TO_TODOIST' => 'false']);
$registry = (new Bootstrap(dirname(__DIR__)))->prepareOperations($config, [], $dependencies);
assertOperation(array_map(static fn ($definition) => $definition->id, $registry->definitions) === ['local_storage', 'email', 'todoist'], 'Register built-in destinations in catalogue order');
assertOperation($registry->operations() === [], 'Disabled built-ins require no factory bindings');
$unusedStorage = sys_get_temp_dir() . '/pebble-operation-unused-' . bin2hex(random_bytes(6));
$config = WebhookConfig::fromEnvironment(dirname(__DIR__), ['WEBHOOK_TOKEN' => 'test-token', 'APP_TIMEZONE' => 'UTC',
    'STORE_TO_FOLDER' => 'true', 'STORAGE_DIRECTORY' => $unusedStorage, 'FORWARD_TO_MAIL' => 'false']);
$registry = (new Bootstrap(dirname(__DIR__)))->prepareOperations($config, ['local_storage' => factory()], $dependencies);
assertOperation(count($registry->operations()) === 1 && $registry->operations()[0]->definition->id === 'local_storage', 'Prepare only enabled built-in factory bindings');
assertOperation(!is_dir($unusedStorage), 'Registry bootstrap performs no destination writes');
echo "Operation checks passed: catalogue validation, lazy factories, deterministic order, complete preflight, generic failures, partial progress, and abort/continue policies.\n";
