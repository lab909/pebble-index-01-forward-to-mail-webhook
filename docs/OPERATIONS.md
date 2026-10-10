# Operation contract and registry

Phase 4 introduces the extension contract and a tested registry/runner. The live
webhook continues to use its existing coordinator. Phase 5 implements the built-in
destination adapters/factories, and Phase 6 integrates the runner into HTTP handling.
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
or service locator. Logger failures must be handled by adapters so they do not
interrupt delivery; the logging integration is implemented in later phases.

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
