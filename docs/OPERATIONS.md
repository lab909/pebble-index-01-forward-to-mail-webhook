# Operation contract and registry

Phases 4 and 5 provide the operation contract, registry/runner, and built-in
destination adapters/factories. The live webhook uses this pipeline with its
existing response fields. Phase 6 extracts the remaining HTTP coordination and
legacy response mapping into dedicated classes.
Monolog and failure notifications remain later phases.

## Registration and construction

`config/operations.php` is an explicit catalogue. Each row declares:

| Field | Meaning |
| --- | --- |
| `id` | Unique stable identifier, such as `local_storage`. |
| `enabled` | Name of an already parsed boolean setting. Missing settings disable operations. |
| `factory` | Binding ID supplied by bootstrap, rather than automatic package discovery. |
| `order` | Nonnegative integer; ascending execution order, with ID as a deterministic tie breaker. |
| `failure` | `abort` stops later operations; `continue` allows independent delivery. |

The built-in rows retain storage first (`abort`), email next (`continue`), and
Todoist next (`continue`). Extension registration must be deliberate. Composer
installation alone never enables or executes a package.

`Bootstrap::prepareOperations()` loads this catalogue and supplies the existing
feature switches. Additional parsed switches may be passed explicitly; they cannot
override the built-in switches. Custom callers may use
`OperationRegistry::fromConfiguration()` with their own catalogue and switches.

A factory binding is an `OperationFactoryInterface` instance, a no-argument factory
class name, or a lazy closure returning a factory. Use lazy providers when parsing
destination credentials or constructing clients. Disabled providers and factory
classes are never called or autoloaded, and disabled operations need no bindings.
Factory instances supplied by callers are already constructed by those callers.

The registry validates the whole catalogue before loading providers, resolves every
enabled factory binding before calling `create()`, then constructs operations in
registry order. Duplicate IDs, malformed definitions, unsupported policies,
nonboolean switches, and invalid enabled factories cause safe configuration errors
before any operation validates or executes. Factories must not perform destination
writes, send messages, or make delivery API calls during construction.

Factories own destination settings and clients. Their `create()` method receives
`OperationDependencies`: a PSR-3 logger and immutable `RequestContext` with request
ID, receipt time, and debug mode. It receives no global container, environment,
or service locator. Built-in services use `SafeLog` so logger failures cannot interrupt delivery.
Their factories supply a null logger in debug mode to suppress destination log
writes. Bootstrap currently defaults to a null PSR-3 logger while `ErrorResponder`
keeps the existing private error log. Monolog configuration arrives in Phase 7.

## Validation and execution

`OperationInterface::validate(Note $note)` performs side-effect-free preflight.
Throw `OperationValidationException` containing a `ValidationFailure` for expected
invalid input. The runner checks **every enabled operation** before executing any
of them. Any preflight failure blocks all execution and is reported by operation ID.
Unexpected validation exceptions become generic internal errors without their raw
messages or stack traces.

`execute(Note $note)` returns `OperationResult`. Every operation receives the same
immutable note and must respect its audio ownership. There is no automatic retry,
rollback, or deletion of successful external results. Cleanup remains owned by the
hub's request lifecycle, not by individual operations.

For example, an adapter can retain a partially created task:

```php
return OperationResult::failure(
    ErrorCategory::Remote,
    HttpFailure::BadGateway,
    'Task created, but metadata upload failed.',
    diagnostics: ['stage' => 'metadata upload'],
    externalIds: ['projectId' => $projectId, 'taskId' => $taskId],
    progress: ['commentsCreated' => 1],
    partial: true,
);
```

Results distinguish `success`, `failure`, and `partial`. Partial delivery is a
failure for aggregation and policy purposes; retained IDs and progress remain
available. Successful results may carry destination outputs and external IDs.
Expected failures can also be raised as `OperationExecutionException` containing
an already sanitized failure result. Unexpected execution exceptions become generic
HTTP 500 failure results, and the configured failure policy still applies.

Messages and diagnostics in typed results and validation failures are trusted
adapter output: adapters must redact tokens, passwords, SMTP authentication data,
and raw request/transport content **before** creating them. Never put raw exceptions
in results. The runner deliberately discards unexpected exception details rather
than attempting to infer which substrings are credentials.

## Reports and HTTP classification

`OperationRunner::run()` returns an `ExecutionReport` with validation failures,
execution entries keyed by operation ID, and skipped operation IDs. Each executed
entry includes start time and monotonic duration in milliseconds. Destination
results do not include runner timing.

Independent failures never discard earlier successful or partial results. An
`abort` failure marks later operations skipped. An empty enabled registry is a
successful no-op.

Results carry `HttpFailure` explicitly: bad input (400), internal failure (500), or
upstream failure (502). Aggregate classification prioritizes 500, then 502, then
400. The runner has no email, storage, or Todoist branches. Legacy HTTP response
mapping remains outside the runner and is added during the HTTP integration phase.

Run the isolated contract tests with `php tests/operations.php`. They use fake
operations and no production credentials, network destinations, or real delivery.

## Built-in adapters and outcome recording

`LocalStorageFactory`, `EmailFactory`, and `TodoistFactory` construct their own
services and clients. Bootstrap supplies these as lazy bindings. Disabled
factories never construct clients, and client construction performs no delivery.
Test callers can inject mailers and API clients through their respective factories.

The storage adapter returns folder, folder name, and saved audio path in result
outputs; it never replaces the input note. Its failures remain HTTP 500 and abort
later operations. Email preserves the transcription-only body and audio/metadata
attachments; failures remain HTTP 502 with detailed redacted SMTP diagnostics only
in debug mode. Todoist owns its transcription preflight, monthly project behavior,
and attachment handling; partial failures retain project/task IDs, comment count,
and sanitized stage information.

`OutcomeRecorder` observes storage/email execution results after delivery. It writes
`mail-status.txt` only if storage succeeded and email actually executed. SMTP
acceptance is recorded in both modes, while failure messages are suppressed in
debug mode. Email has no storage dependency. Filesystem or logger failures in this
recorder cannot change the delivery results. `webhook.php` currently preserves the
legacy result fields and error wording; that compatibility mapping moves into a
response builder in Phase 6.

Run `php tests/destinations.php` for isolated adapter checks. The HTTP regression
suite covers all feature combinations, actual local SMTP/API stubs, partial Todoist
outcomes, shared uploads, and deliberately failing injected service loggers.
