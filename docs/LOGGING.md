# Logging operations

Phase 7 uses Monolog 3 through PSR-3. All settings are optional and validated;
configuration never accepts arbitrary date patterns or format strings.

| Setting | Default | Meaning |
| --- | --- | --- |
| `LOG_ENABLED` | `true` | Enables the application log sink. |
| `LOG_LEVEL` | `info` | Minimum severity: debug, info, notice, warning, error, critical, alert, emergency. |
| `LOG_DIRECTORY` | `logs` | Private path; relative to project root, outside public including existing symlinks. |
| `LOG_FILENAME` | `webhook.log` | Base filename without directory components. |
| `LOG_FORMAT` | `json` | One JSON object per line, or `text`. |
| `LOG_ROTATION` | `daily` | `daily`, `monthly`, or `none`. |
| `LOG_MAX_FILES` | `30` | Rotated files including current; 0 unlimited; ignored for none. |
| `LOG_IN_DEBUG` | `false` | Allows logs during debug mode when logging is enabled. |

Daily files use `webhook-YYYY-MM-DD.log`; monthly files use `webhook-YYYY-MM.log`.
Unrotated logs use the base filename. Rotation dates and timestamps use
`APP_TIMEZONE`. Cleanup happens on writes, so idle installations do not prune
without logging activity. Retention counts files, not elapsed days. Changing a
filename or rotation policy does not migrate or remove old files using another
pattern. Zero means unlimited storage; unrotated logs also grow without a size cap.
Future notification cooldown state is independent of rotated log files.

New directories use 0700 and files 0600, with write locking. Configure the PHP
process user to own the log directory; existing directory permissions are not
rewritten. Generated log-file symlinks are rejected. Keep parent directories under
trusted administrative ownership. Do not serve private paths through web aliases.

## Events and correlation

`hub` logs request acceptance, rejection, bootstrap failure, and completion.
`operations` logs operation start, completion, preflight rejection, and failure.
The logger factory also supports a `notifications` channel for Phase 8; no email
handler or notification state writer is activated in this phase.

Each event includes the internally generated `requestId`. Incoming request IDs
are ignored. Search for the `X-Request-Id` response header to follow one request.
Operation outcomes add `operationId`, `durationMs`, `outcome`, `httpStatus`,
`errorCategory`, `providerStatus` when available, external IDs, progress, and
retained-file paths. Successful operations use info, request/preflight rejection
uses warning, operation failures use error, and bootstrap/runtime failures use
critical. Each operation failure has one central error event; service details use
debug severity. Aggregate completion is an info event even when a delivery failed.

JSON records contain `datetime`, `channel`, `level_name`, `message`, `context`, and
`extra`. Provider diagnostics are recursively sanitized before formatting. Known
secrets and encoded variants, DSN credentials, Authorization values, and SMTP
authentication exchanges are redacted. Note transcription, audio, full POST data,
and exception traces/arguments are excluded. Strings are limited to 2048 bytes;
arrays and nesting are bounded. `extra.diagnosticsTruncated=true` marks truncation.
Objects are not serialized. Extension authors must use static messages and safe
metadata; do not intentionally put note content or secrets in logs.

## Debug mode and recovery

By default debug mode returns meaningful redacted JSON without application logs.
`LOG_IN_DEBUG=true` enables both success and failure events, subject to LOG_ENABLED
and LOG_LEVEL. This setting does not enable failure `mail-status.txt` writes in
debug mode. `LOG_ENABLED=false` disables logging, not HTTP error handling or delivery.

Handler failures fall back to sanitized native PHP logging; they cannot change an
operation result or HTTP response. The fallback preserves request correlation and
uses the hosting provider's PHP error-log destination. Missing Composer dependencies,
malformed `.env`, or invalid logging configuration use a minimal native fallback
before the managed logger exists. Settings cannot be honored before they are parsed;
for example a missing `.env` cannot supply a logging-disable flag.

After successful HTTP bootstrap, a guarded runtime bridge logs warnings and
uncaught exceptions using metadata only. User fatal errors are intercepted; shutdown
handling attempts to record other fatal errors and return a generic 500 response.
It avoids raw exception strings and traces. After headers are sent it cannot replace
a response. Syntax errors before bootstrap, startup errors, memory exhaustion that
prevents recovery, and hosting/SAPI-generated diagnostics may remain in the provider's
own PHP/server logs. Those logs and access logs are outside application sink policy;
keep PHP display_errors disabled in hosting configuration as well.

Failure notification emails, severity thresholds for alerts, and cooldown/state
handling are the next phase, independent of note forwarding through SMTP.
