# Pebble Index 01 Webhook Hub

Pebble Index 01 Webhook Hub is a PHP webhook receiver for notes created with
Pebble's Index 01 ring. When Pebble sends a new note, the hub authenticates and
validates the request, then forwards its transcription, audio, and POST data
to the destinations enabled in your configuration.

The hub currently supports three destinations:

| Destination | What it receives | Enable or disable in `.env` |
| --- | --- | --- |
| Local directory | A timestamped folder containing `audio.m4a` when provided and `info.txt` with POST fields and selected request metadata. | `STORE_TO_FOLDER=true` / `false` |
| Email through SMTP | The transcription as the email body, with `info.txt` and any audio attached. | `FORWARD_TO_MAIL=true` / `false` |
| Todoist | A task containing the transcription in a monthly subproject, with audio and `info.txt` attached to separate task comments. No due date is set. | `SAVE_TO_TODOIST=true` / `false` |

Every destination is optional. Enable any combination, including Todoist or
email without local storage. Credentials and destination-specific settings are
required only for enabled destinations. With all destinations disabled, the hub
still authenticates and validates the webhook, then acknowledges it without
saving or forwarding the note.

Destinations run synchronously during the webhook request. Email and Todoist are
attempted independently, so a failure in one does not prevent the other from
running. Request validation, configuration, and local storage failures stop
processing before forwarding. The hub currently has no queue or automatic retry.

## Development roadmap

See the [implementation plan](docs/IMPLEMENTATION_PLAN.md) for the planned
package-based extensions, Monolog log rotation, and failure notification emails.
Its checkboxes track implementation progress. Phases 1–8 are complete; the
[compatibility baseline](docs/COMPATIBILITY_BASELINE.md) records current behavior
and the regression checks that later refactoring must preserve.

The live webhook now runs storage, email, and Todoist through the operation
registry and their adapters/factories. `WebhookHub` coordinates requests, while the
response builder preserves legacy fields and adds per-operation results. Monolog
records structured lifecycle events with rotation and retention. Optional failure notification emails use separate SMTP settings and persistent
cooldown state. Package examples remain later-phase work.

## Requirements

Requires PHP 8.2+, Composer, and PHP OpenSSL support. Todoist also requires
the PHP cURL extension. The receiver uses Symfony
Mailer and Symfony Dotenv as standalone components; no Symfony application is
needed. Composer resolves dependencies against PHP 8.2 so the lock file also
works on PHP 8.2 when dependencies are updated on a newer PHP installation.

## Install

Extract the package into your project directory and run:

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Edit `.env` with your webhook token, destination switches, and the settings
for the destinations you enable.
Set your website's document root to this project's `public/` directory. The
endpoint is then `https://your-domain.example/webhook.php`. Keep `.env`,
`src/`, `vendor/`, `logs/`, and `recordings/` outside the public directory.

If you are adding this to an existing website, place `webhook.php` in its public
directory, put `src/` and the Composer files in the parent directory, install
the dependencies there, and put `.env` there. Adjust `$projectDirectory` if your existing project layout differs.
Dotenv respects environment variables that already exist on the server.

In Pebble, configure the HTTPS endpoint, choose both audio and transcription,
and add `Authorization: Bearer YOUR-WEBHOOK-TOKEN` as a custom header. The web
server must forward this header to PHP.

The web server's PHP user needs write access to the project directory to create
`recordings/`, or you can create it first and make it writable by that user.
`STORAGE_DIRECTORY` accepts an absolute path or a path relative to the project.

## Project structure

`public/` is the document root. Application code lives alongside it in `src/`,
autoloaded by Composer under the `Pebble\` namespace:

- `public/webhook.php`: captures the request, bootstraps the hub, and sends its response with minimal fallback handling.
- `src/WebhookHub.php`: coordinates authentication, configuration, typed note preparation, preflight, execution, outcome recording, and cleanup.
- `src/Http/ResponseBuilder.php` and `LegacyResultMapper.php`: format generic operation results and preserve existing response fields.
- `config/operations.php`: declares destination IDs, switches, order, and failure policy for the operation pipeline.
- `src/Operation/`: operation contracts, lazy registry, typed results, and destination-independent runner; see the [operation contract](docs/OPERATIONS.md).
- `src/Bootstrap.php`: loads the environment once and constructs enabled services without executing them.
- `src/Config/`: separate core, storage, email, Todoist, and logging settings with shared validation; `WebhookConfig` aggregates them. `AlertConfig` validates independent operator notification settings.
- `src/Notification/`: aggregates operator alerts and locks persistent cooldown state.
- `src/Logging/`: Monolog factories, private rotating handlers, redaction, and guarded PHP fallback logging.
- `src/Services/RequestValidator.php`: checks authentication and multipart payloads.
- `src/Services/RecordingStorage.php`: copies recordings and cleans incomplete writes.
- `src/Operation/Builtin/`: storage, email, and Todoist adapters/factories with destination validation and sanitized diagnostics.
- `src/Operation/OutcomeRecorder.php`: observes storage/email results and writes mail status independently of delivery.
- `src/Services/RecordingMailer.php`: builds and sends emails through Symfony Mailer.
- `src/Services/TodoistClient.php`: calls the Todoist API and uploads files.
- `src/Services/TodoistService.php`: finds or creates monthly projects, then creates tasks and attachment comments.
- `src/Exception/TodoistException.php`: preserves partial task IDs and failure stages.
- `src/Http/`: request and JSON response classes.
- `src/Note/`: immutable note data and audio assets with explicit ownership.
- `src/Http/NoteFactory.php`: converts validated HTTP input into a note and generates shared metadata.
- `src/Recording/StoredRecording.php`: local folder and file paths returned by storage.

After changing Composer autoload settings, run `composer dump-autoload --optimize`.
In DDEV, run Composer and PHP commands with `ddev composer` and `ddev php`.

## Optional actions

Set these independently in `.env`. Storage and email default to `true` when
omitted; Todoist defaults to `false`:

```dotenv
STORE_TO_FOLDER=true
FORWARD_TO_MAIL=true
SAVE_TO_TODOIST=false
```

`STORAGE_DIRECTORY` is required only for local storage. SMTP and `MAIL_*`
settings are required only for email. `TODOIST_API_TOKEN` and
`TODOIST_PARENT_PROJECT` are required only for Todoist. The webhook token and
timezone are always required.
The response includes `saved`, `emailSent`, and `todoistSaved`; `folder` is included only when
files were saved. Successful enabled actions return HTTP 200, including when
all three actions are disabled. Invalid boolean settings return HTTP 500.

## Todoist settings

Enable Todoist and configure a personal API token and parent project:

```dotenv
SAVE_TO_TODOIST=true
TODOIST_API_TOKEN='your-personal-api-token'
TODOIST_PARENT_PROJECT='your-parent-project-id'
```

The token and parent ID are required only when Todoist is enabled. Use the v1
project ID from Todoist's API, which can contain letters as well as digits.
This uses API-token authentication rather than OAuth and the
[Todoist API v1](https://developer.todoist.com/api/v1/).

On each valid webhook, the service searches all active projects, following
pagination, for a child of `TODOIST_PARENT_PROJECT` named for the receipt month
in `APP_TIMEZONE` (for example, `April 2026`). It creates that child on first use
and reuses it for subsequent requests. No scheduler is needed. The note's
`recordedAt` timestamp does not determine the project month. Project lookup and
creation are serialized across PHP workers on the same server using a lock in
the system temporary directory. Multiple servers do not share that lock.

Each request creates a new task with the full transcription as `content` and
no due date. A non-empty string transcription is required when Todoist is
enabled; missing or invalid transcription returns HTTP 400 before any storage,
email, or API calls. The service uploads `audio.m4a` when present and `info.txt`
to Todoist. Each file is attached to its own task comment because the API accepts
one attachment per comment. With local storage disabled, audio comes directly
from PHP's temporary upload and `info.txt` is uploaded from memory.

Successful delivery adds `todoistProjectId` and `todoistTaskId` to the response
and sets `todoistSaved=true` after all attachment comments are created. Todoist
and email are attempted independently: an SMTP failure does not prevent Todoist
saving, and a Todoist failure does not undo an accepted email. Either failure
returns HTTP 502; completed local recordings are retained. In production the
error body is empty and sanitized details are logged. Debug mode exposes
`todoistDebug` with the failed stage, API message, and partial task IDs, with the
API token redacted. A task created before an upload/comment failure is retained,
and its ID is included in debug output and the private log.

The integration is synchronous and has no automatic retry. Repeated webhook
deliveries create new tasks, including after partial failures. Todoist account
limits apply to projects, comments, and upload sizes; check partial task IDs
before resending a failed request. Allow enough PHP/web-server execution time
for the audio upload; the outbound upload timeout is five minutes.

## SMTP settings

Use `SMTP_SCHEME='smtp'` and `SMTP_PORT='587'` for STARTTLS, or
`SMTP_SCHEME='smtps'` and `SMTP_PORT='465'` for implicit TLS. STARTTLS is required
with `smtp`; the script never disables certificate verification.
The SMTP username/password are URL-encoded by the script: put the original
values in `.env`. Use single quotes around values containing `$` or `#`.
For a literal single quote inside a value, use Symfony Dotenv's supported
quoting/escaping syntax. The sender must be permitted by your SMTP provider.

## What is saved and emailed

With `STORE_TO_FOLDER=true`, each valid request creates a folder named after receipt time in `APP_TIMEZONE`,
with milliseconds and a random suffix to prevent collisions. It contains:

- `audio.m4a`, when audio is supplied;
- `info.txt`, containing all POST fields and selected request/upload metadata;
- `mail-status.txt`, recording SMTP acceptance or sending failure when email is enabled.

With `FORWARD_TO_MAIL=true`, the email body contains only `postFields.transcription`
(or is empty when transcription is absent). `info.txt` and, when supplied,
`audio.m4a` are attached. Credentials and the Authorization header are excluded.
Text-only and audio-only webhook settings also work.

A successful SMTP send returns HTTP 200. This means the SMTP server accepted
the message, not that inbox delivery is guaranteed. An SMTP failure returns
HTTP 502 and retains local files when storage is enabled. Failed/incomplete local writes return
HTTP 500 and remove that incomplete recording folder. Configuration failures
return HTTP 500 before creating a folder.

This simple receiver sends synchronously and has no queue or automatic email
retry. Repeated webhook deliveries may create separate folders and send duplicate
emails, depending on the enabled actions. Review `mail-status.txt` after SMTP failures. A network timeout can make
SMTP acceptance uncertain even when the endpoint reports failure.

Increase PHP `upload_max_filesize` and `post_max_size`, and any web server request
limits, as needed. Your SMTP provider's message-size limit must also accommodate
the audio attachment (base64 attachment encoding adds about one third to its size).

## Note and audio lifecycle

After authentication and request validation, the hub creates one immutable `Note`
for the enabled destinations. It contains the transcription, complete POST data,
receipt and recording times, timezone, selected headers, and optional `AudioAsset`.
All destinations use the same generated `info.txt` content; email and Todoist read
the transcription directly from the note. Authorization is excluded from metadata.

Local storage copies the validated PHP upload to `audio.m4a`; the shared source
remains readable until all synchronous destinations finish. Storage returns a
separate `StoredRecording`, leaving the note unchanged. Failed local writes remove
only their incomplete folder and copies. The current storage failure policy still
stops forwarding, and successful stored files survive later destination failures.

PHP removes PHP-owned uploads when the request ends. The hub cleans up only assets
explicitly marked as hub-owned temporary files, using `finally`; durable files stay
with their owner. There is no queue in this phase. Future queued operations must
first persist audio durably and must never enqueue a temporary upload path.

## Responses and request correlation

Existing success fields remain: `ok`, `saved`, `emailSent`, `todoistSaved`, optional
`folder`, and Todoist project/task IDs. Responses now add `requestId` and an
`operations` object whenever the operation pipeline produces a report. Disabled
operations are omitted; with all destinations disabled, `operations` is `{}`.
Operation statuses are `success`, `failure`, `partial`, `skipped`, or
`validation_failed`. Results retain external IDs and partial progress. Debug failure
results include sanitized diagnostics. Private output paths and execution timing
are excluded from the public map.

For example, a successful request with all destinations disabled returns:

```json
{
  "ok": true,
  "saved": false,
  "emailSent": false,
  "todoistSaved": false,
  "operations": {},
  "requestId": "0123456789abcdef0123456789abcdef"
}
```

Every webhook response includes `X-Request-Id`, generated internally for that
request. JSON bodies include the same value as `requestId`. Incoming request or
correlation ID headers are ignored. Production error bodies remain empty; use the
response header to find the matching request in the private log or early PHP server
log. Early HTTP/configuration errors have no operation report, so their debug body
contains `error` and `requestId`. The existing HTTP status codes and legacy SMTP/
Todoist diagnostic fields are preserved.

## Error logging

With `DEBUG_MODE=false` (the default), errors return their HTTP status with an
empty body. Non-POST requests return 405; missing or incorrect tokens return 401.
Successful requests still return JSON.

Monolog records accepted/rejected requests, operation starts and outcomes, and
request completion. The default is JSON, minimum severity `info`, daily rotation,
and 30 retained files in `logs/`, outside `public/`. For example,
`webhook-2026-10-11.log`. All events carry the generated request ID used in the
`X-Request-Id` response header. Operation outcomes include duration, status,
error category, provider HTTP status when available, and external IDs. Credentials,
note transcription, audio, and full POST data are excluded; provider diagnostics
are redacted and limited in size.

`LOG_ENABLED=false` disables application logging. `LOG_LEVEL` filters severity,
`LOG_FORMAT` selects `json` or `text`, and `LOG_ROTATION` selects `daily`, `monthly`,
or `none`. `LOG_MAX_FILES` counts retained files including the current file; `0`
means unlimited and has no effect with `none`. Rotation and cleanup happen on
writes using `APP_TIMEZONE`. `LOG_DIRECTORY` and `LOG_FILENAME` change the private
location. New directories/files use 0700/0600 permissions and locked writes.

`DEBUG_MODE=true` returns meaningful JSON errors with redacted SMTP/Todoist
diagnostics and suppresses logging by default. Set `LOG_IN_DEBUG=true` to also
record events in debug mode; `LOG_ENABLED` and `LOG_LEVEL` still apply. SMTP debug
responses include exception messages, nested causes, and available transcripts;
authentication payloads are redacted. Connection failures can have empty transcripts.
Debug mode continues to suppress failure messages in `mail-status.txt`.

Failed log writes use sanitized native PHP logging without changing delivery or
HTTP responses. Missing dependencies or malformed configuration also use a minimal
bootstrap fallback. See [logging settings and limitations](docs/LOGGING.md),
including runtime error handling and errors before bootstrap.

## Failure notification emails

Set `FAILURE_EMAIL_ENABLED=true` to send one aggregated operator alert for
reportable configuration or operation failures. Configure the separate
`FAILURE_EMAIL_SMTP_*`, sender, and comma-separated recipients in `.env.example`.
Alerts use verified TLS, exclude note content and credentials, and include request
ID, failed stages, partial results, external IDs, and retained-folder references.

Alerts work independently of note email and file logging. Debug mode suppresses
them unless `FAILURE_EMAIL_IN_DEBUG=true`. Bad tokens, non-POST requests, and
malformed input never trigger alerts. Repeated failures use a persistent cooldown
(default 300 seconds); failed SMTP attempts get a shorter 30-second retry window.
Notification failures preserve the original HTTP status and operation results.
Use a separate provider to report note SMTP outages. See [notification settings
and limitations](docs/NOTIFICATIONS.md), including synchronous delivery latency.

## Manual check

First check the syntax:

```bash
find src public config tests -name '*.php' -exec php -l {} \;
php tests/run.php
php tests/config.php
php tests/note.php
php tests/operations.php
php tests/destinations.php
php tests/hub.php
php tests/logging.php
php tests/notifications.php
python3 tests/http.py
python3 tests/bootstrap_http.py
python3 tests/logging_http.py
python3 tests/notifications_http.py
```

Then use Pebble or a multipart request such as this (use your actual token,
URL, timestamp, and a local M4A file):

```bash
curl --fail-with-body 'https://your-domain.example/webhook.php' \
  -H 'Authorization: Bearer YOUR-WEBHOOK-TOKEN' \
  -F 'client=ring' \
  -F 'recordedAt=1791536400000' \
  -F 'transcription=Testing the webhook email' \
  -F 'audio=@sample.m4a;type=audio/mp4'
```

Confirm that the timestamped folder contains the saved files and that the email
arrives with both attachments. Omit the audio field to test text-only delivery.
Invalid tokens should return HTTP 401 without saving or sending anything.

The regression checks use temporary storage and test mailers. HTTP checks require
Python 3 and OpenSSL and use local SMTP and Todoist API stubs. They cover
feature switches, validation, file cleanup, attachments, SMTP failures, monthly
project pagination/reuse, timezone boundaries, and partial Todoist failures.
Live Todoist delivery still needs to be verified with your configured account.
