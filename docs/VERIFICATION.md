# Phase 10 verification record

Recorded 2026-10-12. Local verification and migration preparation are complete;
production deployment, live alert inbox confirmation, and hosted destination checks
remain pending. No production credentials were loaded for these tests, no hosted
endpoint was called, and no real account email/task was created.

## Reproduce

```bash
PHP_BINARY=php8.2 python3 tests/verify.py
```

Observed interpreter: PHP 8.2.33. This workspace is already inside the existing
DDEV web container. The combined runner strips inherited destination/log/alert
settings before launching fixtures, so exported account settings cannot replace
the test .env values. Its PHP 8.4 DDEV configuration and untracked local Compose file
were not changed. The suite uses the separate PHP 8.2 interpreter for PHP commands,
HTTP servers, diagnostic CLI tests, and offline Composer example installation.

All eight PHP suites and five Python suites passed. Syntax checks passed for 85
PHP files, including application, config, CLI, tests, and example package files.
Root/example Composer manifests validate; platform requirements pass on PHP 8.2.33
including iconv. Advisory Composer warnings remain for missing license metadata
and the example's explicit private development version. No license choice or
publication claim was introduced as part of verification.

## Evidence matrix

| Suite | Verified behavior |
| --- | --- |
| tests/run.php | Original configuration/validation contract, metadata, note email body/attachments, local cleanup, SMTP credential redaction. |
| tests/config.php | Defaults, strict settings, private path/symlink validation, optional clients/settings, no delivery on construction, cached environment, prepared alert settings. |
| tests/note.php | Immutable note and stable metadata, ownership-aware audio cleanup, shared copies, storage-mode independence. |
| tests/operations.php | Catalogue validation, laziness, order/tie-breaking, whole-pipeline preflight, unknown exceptions, failure policies, partial results. |
| tests/destinations.php | Built-in factory/adapters, preflight, note SMTP diagnostic handling, outcome status files, debug policies, failed logger isolation. |
| tests/hub.php | Generic result mapping, legacy fields, private-output exclusion, all-disabled mode, correlation, config/auth ordering, silent/debug responses. |
| tests/logging.php | JSON/text, severity, daily/monthly/none, timezone/DST boundaries, observed retention including current, unlimited retention, permissions, recursive redaction, marked truncation, safe handler fallback. Active retention leaves legacy logs and cooldown files untouched. |
| tests/notifications.php | One alert batch, subject/body sanitization, partial IDs/results, stable signatures, cooldown expiry/zero, failed-send retry window, state permissions, lock contention, severity/debug/log-switch independence, safe invalid alert configuration. |
| tests/http.py | Public entry point, all eight destination combinations, optional credentials, actual multipart uploads, text-only delivery, audio readable across operations with/without storage, validation before side effects, abort/continue and partial Todoist delivery, monthly pagination/reuse, separate comments/no due date, silent production errors and sanitized debug diagnostics. |
| tests/bootstrap_http.py | Custom/unsafe log paths, malformed/missing .env, missing dependencies, safe early native fallback, correlation, no diagnostic secret leakage. |
| tests/logging_http.py | Actual lifecycle events, level filtering, debug opt-in and disabled sinks, request IDs, failure event uniqueness, successful delivery through log-write failures, guarded warnings/exceptions/fatals. Existing env without new alert settings remains compatible; undated legacy log survives. |
| tests/notifications_http.py | Verified SMTPS, certificate-hostname rejection, required STARTTLS, stalled SMTP timeout, status preservation, file-only alert outcomes, note-mail/alert switch combinations, separate alert transport after note SMTP failure. CLI --check is inert, --send sends one local diagnostic without cooldown state, invalid flags fail safely. Two independent PHP processes sharing cooldown state allow only one simulated send; a later process remains suppressed. |
| tests/extensions.py / extensions.php | Real offline Composer copied-path installation, package PSR-4 namespace, disabled/uninstalled laziness, typed settings, auth/preflight, success/failure/partial, continue/abort, shared audio, generic/private HTTP mapping, class bindings, malformed/conflicting providers. |

No recorded pass is a production inbox, API account, DNS/firewall, or web-server
configuration check. The local certificate is trusted only in disposable tests.
Tests never disable TLS verification to force delivery.

## Locked logging/notification APIs

composer.lock pins Monolog **3.12.1**. Implementation was checked against installed
source and exercised by the suites:

- RotatingFileHandler supports date format, timezone, maxFiles, permissions, and
  locking; StreamHandler supports locked append and permissions. Private wrappers
  preserve umask and exclude log-file symlinks. The boundary cleanup wrapper ensures
  maxFiles includes the newly written file during long-lived rotation.
- SymfonyMailerHandler accepts a Symfony mailer/transport plus Email template and
  sends a formatted batch. Alerts explicitly call handleBatch and close in guarded
  finalization; no destructor-triggered buffer delivery is required.
- DeduplicationHandler stores message/level before delivery and does not hold a lock
  over the complete read/check/send cycle. The application instead uses nonblocking
  flock plus atomic cooldown state, distinguishing failed attempts from SMTP
  acceptance. Alert transport failure never enters the notification pipeline again.

The SMTP diagnostic CLI and webhook alerts use the same AlertTransportFactory,
verified TLS policy, and per-socket timeout. The CLI explicitly bypasses webhook
threshold/debug/cooldown gates only when invoked with --send.

## Migration and remaining production steps

[Architecture](ARCHITECTURE.md), [extensions](EXTENSIONS.md), [logging](LOGGING.md),
[notifications](NOTIFICATIONS.md), README, and .env.example describe the final setup.
[Deployment](DEPLOYMENT.md) covers matched code/vendor releases, private backups,
permissions, keeping existing .env, undated-to-dated log migration, alert diagnosis,
controlled failures, hosted success checks, and rollback.

Existing real .env, recordings, undated/dated logs, and notification state were not
modified. There is no database migration. The optional example remains uninstalled
and unregistered in the application; alerts remain disabled by default.

Still pending for an explicitly requested deployment:

- One diagnostic alert through the configured production transport, followed by
  confirming receipt in every operator inbox.
- One controlled operational failure in an isolated deployment test configuration,
  checking a request-correlated alert and repeat suppression.
- Deploy the release and verify the hosted endpoint, request rejection behavior,
  private log correlation, and each enabled real destination.

These steps remain unchecked in IMPLEMENTATION_PLAN.md. Milestone E's local work
is complete; its rollout portion is not marked complete without production evidence.
