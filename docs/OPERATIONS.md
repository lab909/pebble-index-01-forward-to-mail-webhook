# Operation contract and registry

Phases 4–6 provide the operation contract, registry/runner, built-in destination
adapters/factories, and HTTP integration. `WebhookHub` coordinates the request, and
`ResponseBuilder` formats execution reports with an explicit `LegacyResultMapper`.
The public entry point has no destination-specific branches.
Monolog lifecycle logging is implemented; failure notifications remain Phase 8.

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
Bootstrap supplies request-scoped Monolog loggers, or null loggers when logging is
disabled or debug mode is active without `LOG_IN_DEBUG=true`. The runner owns the
single error event for each operation failure; service details use debug severity.
Use static messages and metadata, never note text, full POST data, audio, or secrets.
See [logging](LOGGING.md) for sink policy and redaction.

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
mapping remains outside the runner in `LegacyResultMapper`.

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
recorder cannot change the delivery results. `ResponseBuilder` and `LegacyResultMapper` preserve the
legacy result fields and error wording, adding a generic `operations` object.
Unknown operation diagnostics stay scoped to their operation in debug mode.
Private filesystem outputs and timing are excluded from public results.

Run `php tests/destinations.php` for isolated adapter checks. The HTTP regression
suite covers all feature combinations, actual local SMTP/API stubs, partial Todoist
outcomes, shared uploads, and deliberately failing injected service loggers.

## Hub and request correlation

The entry point generates a random request ID before Composer bootstrap. The hub
uses this trusted ID in `RequestContext`, all response bodies, and `X-Request-Id`.
Incoming correlation headers never supply the internal ID. `RequestLogger` adds
the ID to injected PSR-3 log context and isolates logger failures; private error
logs and early server-log fallbacks include it as well. Debug mode suppresses logs.

The hub loads environment settings once, validates the method and authentication
before optional destination configuration, creates one typed note with the receipt
time from request context, completes operation preflight, executes the pipeline,
records outcomes, and releases hub-owned audio in `finally`. All-disabled requests
remain successful no-ops. Production errors retain empty bodies, including bootstrap
errors; debug JSON contains safe messages and the same request ID as the header.

Run `php tests/hub.php` for arbitrary-operation mapping, correlation, configuration
precedence, debug suppression, and silent response checks. HTTP suites additionally
verify unique IDs, ignored incoming IDs, correlated private/server logs, empty map
serialization, every feature combination, and partial/skipped operation statuses.
