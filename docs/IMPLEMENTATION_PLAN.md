# Pebble Index 01 Webhook Hub — implementation plan

This checklist describes the proposed refactoring and logging features. It is a
plan, not a description of features already implemented. Complete phases in
order, mark individual tasks as they pass verification, and keep the hub usable
at each milestone. Record any changed decisions in this file before implementing
them.

## Scope and completion criteria

The goal is a small PHP 8.2 application with a thin HTTP entry point, a reusable
hub service, and optional operations that can be supplied by local classes or
ordinary Composer library packages. Existing destinations are local storage,
SMTP email, and Todoist. New features are structured Monolog logging,
configurable file rotation, and optional failure notification emails.

- [ ] Preserve PHP 8.2 compatibility and Composer's `platform.php=8.2.0` constraint.
- [ ] Preserve all existing destination switches, credentials, attachment names,
      and public response fields.
- [ ] Move destination setup, validation, execution, and diagnostics out of
      `public/webhook.php`.
- [ ] Add a destination through a class/package, one registry entry, and its
      configuration without editing the hub or HTTP entry point.
- [ ] Make logging and failure notifications independently configurable.
- [ ] Verify feature behavior with local stubs before testing external accounts.

Automatic task retries, a queue, an administration UI, dynamic package discovery,
and a general-purpose framework are outside this iteration. The design should
allow a worker or queue later without relying on HTTP globals in operations.

## Decisions to preserve

- The endpoint accepts authenticated multipart POST requests. Non-POST requests
  return 405; missing/incorrect authorization returns 401.
- `DEBUG_MODE=false` returns empty error bodies and private diagnostics.
  `DEBUG_MODE=true` returns sanitized JSON errors; file logging and notification
  emails stay off in debug unless their explicit debug settings are enabled.
- Local storage and SMTP default to enabled when their switches are absent;
  Todoist defaults to disabled. All three can be disabled.
- Destination credentials are validated only for enabled destinations.
- Request/configuration validation happens before destination side effects.
  Todoist's missing/empty transcription remains a 400 before saving or sending.
- Preserve the initial local-storage failure policy: a failed requested local save
  stops forwarding. SMTP and Todoist failures are independent and do not roll back
  successful destinations. The registry makes failure policy explicit.
- Preserve 500 for bootstrap/configuration/local-storage failures and 502 for
  SMTP/Todoist delivery failures. Preserve 200 for successful enabled operations,
  including when all destinations are disabled.
- Each Todoist delivery creates a task without a due date, using transcription
  as its content. Monthly projects use the receipt month in `APP_TIMEZONE`, not
  the note's recording month. Audio and `info.txt` use separate comments.
- Completed files and partial remote tasks remain available after downstream
  failures. Existing external IDs remain visible in debug responses/private logs.

## Proposed structure

```text
public/webhook.php                  # bootstrap, HTTP input/output boundary
config/operations.php               # explicit operation definitions and factories
src/Bootstrap.php                   # assembles config, logger, registry, and hub
src/Hub/WebhookHub.php               # request lifecycle and operation orchestration
src/Hub/OperationRunner.php          # execution, timing, failure policy, results
src/Hub/HubResult.php                # aggregate status and operation outcomes
src/Contracts/OperationInterface.php
src/Contracts/OperationFactoryInterface.php
src/Operations/OperationDefinition.php
src/Operations/OperationResult.php
src/Operations/LocalStorageOperation.php
src/Operations/EmailOperation.php
src/Operations/TodoistOperation.php
src/Note/Note.php                    # typed input and metadata
src/Note/AudioAsset.php              # validated audio reference and ownership
src/Note/NoteFactory.php             # normalized request -> Note
src/Config/                         # core and operation-specific settings
src/Logging/LoggerFactory.php
src/Logging/RedactionProcessor.php
src/Logging/FailureAlertHandler.php
src/Logging/BootstrapErrorReporter.php
src/Services/                       # focused storage, mail, and API clients
src/Http/                           # request, response, and response formatting
```

Names can be simplified if a class has no distinct responsibility. Controllers
are unnecessary for a single endpoint: the hub service provides the application
boundary. Existing service files can remain behind operation adapters initially.

## Phase 1 — establish the compatibility baseline

- [x] Record current request validation, switch defaults, result fields, HTTP
      statuses, debug behavior, and destination failure policies.
- [x] Run `php8.2 tests/run.php` and
      `PHP_BINARY=php8.2 python3 tests/http.py`; fix baseline failures first.
- [x] Add targeted missing coverage for all-disabled mode, invalid credentials for
      disabled destinations, and local-save failure stopping forwarding.
- [x] Document all eight destination switch combinations in test expectations.
- [x] Capture representative `info.txt` structure and existing JSON responses as
      behavior assertions rather than brittle timestamp snapshots.
- [x] Record partial Todoist outcomes: created task, completed comments, failed
      stage, and sanitized error information.

**Done when:** the current behavior has a reproducible baseline on PHP 8.2.

**Completed 2026-10-11 on PHP 8.2.33.** See
[the compatibility baseline](COMPATIBILITY_BASELINE.md) for the recorded contract,
all eight feature combinations, partial Todoist outcomes, and verification
commands. Initial and expanded local suites passed. Runtime application code
was not changed in Phase 1.

## Phase 2 — separate configuration and bootstrap

- [x] Introduce core settings for authentication, timezone, and debug behavior.
- [x] Introduce separate local-storage, note-email, Todoist, logging, and alert
      configuration classes.
- [x] Share strict boolean, integer, path, and required-string parsing helpers.
- [x] Keep switch defaults and variable names compatible with existing `.env` files.
- [x] Resolve relative private paths against the project root and reject log/state
      paths under `public/`.
- [x] Instantiate destination configurations and clients only when enabled.
- [x] Resolve configuration from the environment/Dotenv once, then pass settings
      into constructors; operations must not read `$_ENV` or `$_SERVER` directly.
- [x] Add `Bootstrap` to assemble dependencies without executing destination work.
- [x] Keep a minimal fallback for missing autoload files, malformed `.env`, or
      invalid logging configuration before the normal logger is available.
- [x] Update Composer's project description to describe the Webhook Hub. Treat any
      package-name change as a separate compatibility decision.

**Done when:** disabled destinations need no credentials and bootstrap remains
safe before Monolog or destination clients are available.

**Completed 2026-10-11 on PHP 8.2.33.** Core and destination settings now use
focused immutable classes and shared validation. `Bootstrap` caches the Dotenv
snapshot and assembles only enabled services without performing destination work.
The existing `WebhookConfig` properties remain available during migration.

`LOG_DIRECTORY` and `LOG_FILENAME` now configure the existing private file logger.
Invalid private paths (including existing symlinks into `public/`) are rejected.
Early failures fall back to the PHP server log; debug responses suppress logging.
`AlertConfig` is preparatory and is not wired into runtime delivery until Phase 8.
Monolog, rotation, alert sending, and plugin execution remain later-phase work.

Validation: configuration/assembly and bootstrap HTTP suites, Phase 1 regression
suites, PHP syntax checks, Composer validation/platform checks, and diff checks.
Tests use isolated temporary settings and local SMTP/API stubs, without loading
production credentials or contacting destinations.

## Phase 3 — introduce the typed note and audio lifecycle

- [ ] Add an immutable `Note` with transcription, all POST fields, receipt time,
      recording time, timezone, selected request metadata, and optional audio.
- [ ] Add `NoteFactory` so input normalization occurs once after authentication.
- [ ] Preserve the complete POST data even when a destination needs only text.
- [ ] Generate `info.txt` consistently from the note; preserve current names,
      JSON formatting, metadata selection, and exclusion of authorization.
- [ ] Replace repeated `json_decode($recording->text)` calls in destination services
      with typed note access.
- [ ] Model `AudioAsset` with path, size, original filename, reported MIME type,
      and ownership/lifetime information.
- [ ] Validate PHP uploads at the HTTP boundary; operations receive only validated
      assets, never raw `$_FILES` entries.
- [ ] Keep the original audio readable throughout synchronous execution. Local
      storage should copy the validated upload instead of moving the shared source
      out from under other operations.
- [ ] Remove incomplete stored copies on local-write failure without deleting
      the shared input or successful results from other operations.
- [ ] Rely on PHP to clean up PHP-owned upload files; clean up only hub-owned
      temporary files in `finally` blocks.
- [ ] Document that queued execution will require durable assets before the
      request ends; this iteration must not enqueue temporary upload paths.

**Done when:** each destination can consume the same note independently, regardless
of whether local storage is enabled.

## Phase 4 — define the operation contract and registry

Suggested contracts:

```php
interface OperationInterface
{
    public function validate(Note $note): void;
    public function execute(Note $note): OperationResult;
}

interface OperationFactoryInterface
{
    public function create(OperationDependencies $dependencies): OperationInterface;
}
```

`OperationDependencies` supplies explicit shared infrastructure such as a PSR-3
logger and request context. Destination settings/clients belong in their own
factories. Avoid giving every operation a global service locator.

- [ ] Define `OperationDefinition` with a unique ID, enabled setting, factory,
      deterministic order, and failure policy (`abort` or `continue`).
- [ ] Define typed validation failures and `OperationResult` outcomes with stable
      error categories, sanitized diagnostics, external IDs, and partial progress.
- [ ] Include an explicit HTTP failure classification in results; the runner must
      not contain `if todoist` or `if email` branches.
- [ ] Keep runtime/timing metadata in execution reports rather than service return
      values where the runner can measure it consistently.
- [ ] Register local storage, email, and Todoist in `config/operations.php`.
- [ ] Reject duplicate IDs, invalid factories, and unsupported failure policies
      during bootstrap before any destination runs.
- [ ] Load only enabled factories; installing a package must not enable it.
- [ ] Validate every enabled operation's note requirements before execution begins.
- [ ] Execute in registry order: local storage first, email next, Todoist next.
- [ ] Adapt expected destination failures into results and catch unexpected
      exceptions at the runner boundary.
- [ ] Aggregate independent failures without discarding successful operation results.
- [ ] Preserve partial task/comment IDs and distinguish full success from partial
      delivery.

**Done when:** the runner handles an arbitrary registered operation without knowing
its destination-specific API or configuration.

## Phase 5 — migrate the existing destinations

- [ ] Wrap `RecordingStorage` in `LocalStorageOperation`; return the stored folder
      and paths through an operation result rather than a replacement input note.
- [ ] Wrap `RecordingMailer` in `EmailOperation`; preserve transcription-only body
      and audio/metadata attachments.
- [ ] Wrap `TodoistService` in `TodoistOperation`; preserve project pagination,
      same-server monthly creation lock, upload behavior, and comments.
- [ ] Move Todoist-specific transcription validation out of `webhook.php`.
- [ ] Move all SMTP/Todoist client construction into their operation factories.
- [ ] Move sanitized destination diagnostics into their adapters/shared formatter;
      HTTP code must not know SMTP exception types or Todoist exception context.
- [ ] Preserve `mail-status.txt` through a core outcome recorder that observes
      storage/email results. Email must not depend on a storage service instance.
- [ ] Preserve the debug-mode suppression of failure status messages in that file.
- [ ] Update services to accept injected loggers; do not let service logging errors
      interrupt actual delivery.
- [ ] Remove obsolete `Recording` plumbing after all consumers migrate.

**Done when:** storage, email, and Todoist behave as before behind the shared contract.

## Phase 6 — thin entry point and stable HTTP responses

- [ ] Implement `WebhookHub` to coordinate validation, note preparation, enabled
      operation preflight, execution, and aggregate results.
- [ ] Move legacy result mapping into a response builder: preserve `saved`,
      `emailSent`, `todoistSaved`, `folder`, and Todoist IDs for existing clients.
- [ ] Add an `operations` result map for new integrations without introducing new
      destination branches in the entry point. Keep existing top-level fields
      through an explicit compatibility mapper.
- [ ] Preserve silent error bodies in production and sanitized debug JSON.
- [ ] Include an internally generated request ID in responses/headers and logs;
      do not trust an incoming correlation header as the internal identifier.
- [ ] Reduce `webhook.php` to bootstrap, request capture, one hub call, response
      formatting/sending, and minimal bootstrap failure handling.
- [ ] Keep default PHP warning/error display disabled in both modes so raw warnings
      cannot corrupt JSON or leak credentials.
- [ ] Ensure operations depend on typed input rather than PHP request globals.

**Done when:** no destination switch, API call, or destination error branch remains
in `webhook.php`.

## Phase 7 — Monolog structured logging and rotation

Use Monolog 3 with `Psr\Log\LoggerInterface`. Its file and email handlers provide
building blocks for this feature; application policy belongs in the hub.
See [Monolog handlers](https://github.com/Seldaek/monolog/blob/main/doc/02-handlers-formatters-processors.md).

Proposed settings, to be added during implementation:

```dotenv
LOG_ENABLED=true
LOG_LEVEL=info
LOG_DIRECTORY='logs'
LOG_FILENAME='webhook.log'
LOG_FORMAT=json
LOG_ROTATION=daily
LOG_MAX_FILES=30
LOG_IN_DEBUG=false
```

- `LOG_FORMAT`: `json` or `text`; JSON produces one structured record per line.
- `LOG_ROTATION`: `daily`, `monthly`, or `none` for the first release.
- `LOG_MAX_FILES`: retained rotated files including the current file; `0` means
  unlimited. This counts files, not elapsed days. It has no effect for `none`.
- `LOG_LEVEL`: a supported Monolog severity; it controls file output only.
- Rotation uses `APP_TIMEZONE` consistently and is triggered by logging activity,
  not a background scheduler. Retention cleanup is therefore write-driven.

- [ ] Add `monolog/monolog:^3` while resolving against PHP 8.2; inspect the actual
      locked handler APIs rather than relying on a newer development branch.
- [ ] Build a `LoggerFactory` with `RotatingFileHandler` for daily/monthly rotation
      and `StreamHandler` for unrotated logs.
- [ ] Translate named rotation settings into validated handler date formats;
      disallow arbitrary filename/date patterns in the initial configuration.
- [ ] Validate severity, format, retention count, directory, and base filename.
- [ ] Set private directory/file permissions (0700/0600) and enable write locking.
- [ ] Keep notification state separate from rotated logs so retention cannot delete
      alert cooldown data.
- [ ] Add channels/context for hub, operations, and notification infrastructure.
- [ ] Add request ID, event name, operation ID, duration, outcome, HTTP/provider
      status, stable error category, and available external IDs.
- [ ] Log lifecycle events: accepted request, validation rejection, operation start,
      operation completion/failure, and aggregate hub completion.
- [ ] Use `warning` for rejected requests, `info` for successful delivery, `error`
      for operational failure, and `critical` for application/bootstrap failures.
- [ ] Record operational failures once centrally; avoid duplicate alerts caused by
      logging the same exception in both service and hub.
- [ ] Add a recursive redaction processor before any formatter or handler receives
      messages/context. Cover tokens, passwords, DSNs, Authorization, encoded
      authentication exchanges, nested exceptions, and exception arguments.
- [ ] Exclude note text, audio content, complete POST data, and credentials from
      normal lifecycle logs. Log metadata sufficient to locate retained files.
- [ ] Sanitize/truncate provider responses and mark truncated diagnostics.
- [ ] Replace the current `FileLogger`; retain a minimal sanitized native PHP
      fallback if Composer, Monolog, or the configured log directory fails.
- [ ] Isolate handler failures so an unwritable log cannot change a completed
      delivery outcome or the intended HTTP response.
- [ ] Bridge uncaught exceptions/fatal errors with guarded PHP handlers where
      feasible; document errors occurring before bootstrap that remain in the
      hosting provider's PHP log.
- [ ] Preserve no file logging in debug by default; allow `LOG_IN_DEBUG=true`
      explicitly. Successful and failure events obey the same sink policy.

**Done when:** operators can correlate a request and each operation, control
rotation/retention, and diagnose failures without logging credentials or notes.

## Phase 8 — failure notification emails

Notifications are an infrastructure feature, independent of `FORWARD_TO_MAIL`.
Use a separate alert SMTP transport so a broken note-email transport can be
reported through a different provider. Do not automatically reuse note credentials.
Monolog's [`SymfonyMailerHandler`](https://github.com/Seldaek/monolog/blob/main/src/Monolog/Handler/SymfonyMailerHandler.php)
and [`DeduplicationHandler`](https://github.com/Seldaek/monolog/blob/main/src/Monolog/Handler/DeduplicationHandler.php)
can supply the transport and buffering/suppression primitives.

Proposed settings:

```dotenv
FAILURE_EMAIL_ENABLED=false
FAILURE_EMAIL_LEVEL=error
FAILURE_EMAIL_IN_DEBUG=false
FAILURE_EMAIL_FROM='hub-alerts@example.com'
FAILURE_EMAIL_TO='operator@example.com'
FAILURE_EMAIL_SUBJECT_PREFIX='Pebble Hub failure'
FAILURE_EMAIL_COOLDOWN_SECONDS=300
FAILURE_EMAIL_STATE_DIRECTORY='var/alerts'
FAILURE_EMAIL_TIMEOUT_SECONDS=10

FAILURE_EMAIL_SMTP_SCHEME='smtp'
FAILURE_EMAIL_SMTP_HOST='smtp.alert-provider.example'
FAILURE_EMAIL_SMTP_PORT=587
FAILURE_EMAIL_SMTP_USERNAME='alert-smtp-username'
FAILURE_EMAIL_SMTP_PASSWORD='alert-smtp-password'
```

- [ ] Parse/validate alert settings only when enabled. Validate recipients, SMTP
      scheme/port, non-negative cooldown, positive timeout, severity, and private
      writable state directory before delivery.
- [ ] Support one or more recipients with a documented comma-separated format.
- [ ] Require verified TLS: SMTP with STARTTLS or SMTPS with implicit TLS.
- [ ] Gate alerts independently from file logging: `LOG_ENABLED=false` may still
      allow failure emails when their switch is enabled.
- [ ] Keep alerts disabled in debug unless `FAILURE_EMAIL_IN_DEBUG=true`.
- [ ] Restrict alerts to operational/configuration failures, even if the configured
      threshold is lowered. Invalid methods, bad tokens, and malformed requests
      must not trigger operator emails.
- [ ] Aggregate reportable failures into at most one alert email per webhook.
- [ ] Include request ID, failed operations/stages, timestamp/timezone, sanitized
      diagnostics, partial success, external IDs, and retained folder reference.
- [ ] Do not attach audio, metadata files, transcription, raw POST data, or secrets
      to alerts by default.
- [ ] Keep stable failure fingerprints separate from changing IDs/timestamps:
      derive them from operation, stage, provider code, and error category.
- [ ] Use stable notification record messages for deduplication and keep unique
      request details in context. Monolog's duplicate matching must not be defeated
      by a timestamp/task ID embedded in the message.
- [ ] Persist cooldown state across requests in private files and guard concurrent
      access. Verify the locked Monolog handler's behavior; add application-level
      locking when necessary. Do not use per-request memory for suppression.
- [ ] Record sent/suppressed/failed notification outcomes in the file-only logger
      when allowed, without sending another notification about that same event.
- [ ] Guard against recursion and catch alert handler/transport exceptions. An
      alert failure must never re-enter the alert pipeline.
- [ ] Flush and close handlers explicitly in a guarded finalization step before
      sending the HTTP response; do not depend on destructor execution for alerts.
- [ ] Bound alert network time and leave the original operation results and HTTP
      status unchanged if an alert cannot be sent.
- [ ] Define failed-send cooldown behavior: apply a short retry suppression window
      to avoid repeated failed SMTP attempts, but do not mark an alert as delivered.
- [ ] If alert configuration is broken, use the bootstrap/file-only fallback and
      preserve the operational request path where possible; do not create a loop
      trying to email a notification about invalid notification credentials.
- [ ] Document that SMTP outage notifications need an independently working
      transport; blocked outbound ports or a host-wide outage can affect both.
- [ ] Preserve conditional credential requirements when note email is disabled but
      failure notification email is enabled, and vice versa.

**Done when:** a real operational failure produces a bounded, sanitized alert,
repeated identical failures are suppressed, and notification failure cannot
break successful delivery or cause recursive emails.

## Phase 9 — package-based extension example

- [ ] Add a documented minimal example operation and factory, with `.env` switch,
      typed configuration, and one explicit registry entry.
- [ ] Demonstrate validation failure, successful result, partial failure, and
      declared continuation policy using a fake/local operation.
- [ ] Demonstrate installation as an ordinary Composer library with PSR-4
      autoloading; application operations do not need Composer lifecycle hooks.
- [ ] Keep registration explicit; do not scan `vendor/` or execute discovered code
      automatically.
- [ ] Ensure registry entries can load factories from external namespaces without
      requiring changes to the hub or response builder.
- [ ] Document that extension authors own destination APIs while the hub owns
      lifecycle timing, HTTP formatting, failure aggregation, and notification policy.
- [ ] Document PHP compatibility and contract versioning before publishing the
      contracts as a separate package.

**Done when:** an example external operation can be installed and registered without
editing application orchestration.

## Phase 10 — verification and migration

- [ ] Run PHP 8.2 syntax, Composer validation, and platform compatibility checks.
- [ ] Run existing note storage/email/Todoist tests through the new hub pipeline.
- [ ] Verify all eight switch combinations, all-disabled mode, and missing optional
      credentials.
- [ ] Verify auth/method/payload rejection occurs before side effects or alerts.
- [ ] Verify operation preflight, deterministic order, abort/continue policies,
      unknown exceptions, and partial result aggregation.
- [ ] Verify audio stays readable across operations in both storage modes.
- [ ] Verify receipt timezone boundaries, paginated project reuse, separate
      Todoist comments, and absence of task due dates.
- [ ] Verify production error bodies are empty and debug diagnostics are sanitized.
- [ ] Test JSON/text formatting, level filtering, daily/monthly/unrotated modes,
      retention limits, rotation boundaries, and unlimited retention in temporary
      directories. Assert observed retention rather than assuming days equal files.
- [ ] Test logging under an unwritable directory and malformed configuration.
- [ ] Test secret redaction in messages, nested context, SMTP transcripts, provider
      responses, alert subjects/bodies, and bootstrap fallbacks.
- [ ] Test failure email enabled/disabled with note email enabled/disabled, debug
      overrides, and file logging disabled.
- [ ] Test alert batching, cooldown across separate requests, concurrent duplicate
      failures, different failure signatures, and failed notification SMTP.
- [ ] Verify one failed alert never triggers another alert or changes the response.
- [ ] Exercise the external example operation using the same runner contract.
- [ ] Verify existing `.env` files work without new variables; notification emails
      remain disabled until explicitly configured.
- [ ] Update README, `.env.example`, architecture diagram, extension guide, and
      the deployment instructions.
- [ ] Explain the transition from `logs/webhook.log` to dated files; leave existing
      log files untouched unless a separate retention migration is performed.
- [ ] Keep tests and temporary state isolated from real account credentials.
- [ ] Validate a configured alert transport with one explicit diagnostic test email
      during deployment, then verify one controlled operational failure.
- [ ] Deploy only when requested; verify the hosted endpoint and all enabled
      destinations after deployment.

**Done when:** existing behavior is preserved, extension setup is demonstrated,
and logging/notification failure paths are tested on PHP 8.2.

## Suggested implementation milestones

- [ ] **Milestone A:** compatibility baseline, configuration, and typed note
      (phases 1–3).
- [ ] **Milestone B:** operation registry, migrated destinations, thin entry point
      (phases 4–6).
- [ ] **Milestone C:** Monolog events and rotating logs (phase 7).
- [ ] **Milestone D:** failure notification emails and suppression (phase 8).
- [ ] **Milestone E:** package example, documentation, regression checks, rollout
      (phases 9–10).

## Implementation notes

Record deviations, unresolved constraints, and verification evidence here as the
checklist is executed. Keep implementation decisions separate from claims that
were verified against a live hosted endpoint or external account.

- [ ] Record the locked Monolog version and verified rotation/notification APIs.
- [ ] Record commands and outcomes at each completed milestone.
- [ ] Record production verification separately from local stub test results.
