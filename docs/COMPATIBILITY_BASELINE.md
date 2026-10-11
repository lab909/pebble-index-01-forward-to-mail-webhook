# Phase 1 — compatibility baseline

Recorded on 2026-10-11 for Pebble Index 01 Webhook Hub, before the planned
operation-pipeline refactoring. This document describes current behavior to
preserve during later phases. It does not describe the proposed Monolog or
notification features.

## Reproducible verification

Run from the project root:

```bash
php8.2 tests/run.php
PHP_BINARY=php8.2 python3 tests/http.py
```

Both suites passed before and after the Phase 1 coverage additions on PHP 8.2.33.
The HTTP suite requires Python 3, curl, OpenSSL, and PHP cURL. It creates a
private temporary project, local TLS SMTP stub, and local Todoist API stub. It
uses test credentials and does not load the project's real `.env`, send real
emails, or contact Todoist. These results are local verification, not a new
production deployment test.

| Evidence | Coverage |
| --- | --- |
| `tests/run.php` | Configuration defaults and disabled settings; authentication fallback; timestamp/upload validation; text-only metadata; email body and attachments; local cleanup; SMTP diagnostic redaction. |
| `tests/http.py` | The public endpoint; all eight switch combinations; successful response schemas; real PHP multipart upload handling; metadata; preflight and storage abort policies; debug/production errors; destination independence; partial Todoist outcomes. |
| `tests/todoist_stub.py` | Local API responses, pagination, file uploads, task/comment creation, and controlled failures including the second attachment comment. |

Assertions use response structure, flag values, parsed timestamps, file contents,
and side effects. Receipt timestamps, random folder suffixes, and remote IDs are
not fixed snapshots.

## Configuration contract

| Variable | Default when absent | Requirement |
| --- | --- | --- |
| `STORE_TO_FOLDER` | `true` | Enables local recording storage. |
| `FORWARD_TO_MAIL` | `true` | Enables note email. |
| `SAVE_TO_TODOIST` | `false` | Enables Todoist task and attachment creation. |
| `DEBUG_MODE` | `false` | Enables sanitized public error JSON and disables private error logging. |
| `WEBHOOK_TOKEN` | None | Required, non-empty, and must not be the example placeholder. |
| `APP_TIMEZONE` | None | Required and must be a valid PHP timezone. |
| `STORAGE_DIRECTORY` | None | Required only for enabled local storage; relative paths resolve against the project root. |
| `SMTP_*`, `MAIL_*` | None | Required only for enabled note email. |
| `TODOIST_API_TOKEN`, `TODOIST_PARENT_PROJECT` | None | Required only for enabled Todoist; PHP cURL is also required. |

Invalid or empty switch values fail configuration with HTTP 500. Invalid
storage paths, SMTP schemes/ports/addresses, and Todoist credentials are ignored
when their destination is disabled. The tests cover both missing settings and
explicitly invalid settings for disabled destinations.

## Destination matrix

Each row is exercised through the public HTTP endpoint with successful stubs.
These cases are also explicit expectations in `FEATURE_COMBINATIONS` in
`tests/http.py`.

| STORE_TO_FOLDER | FORWARD_TO_MAIL | SAVE_TO_TODOIST | saved | emailSent | todoistSaved |
| --- | --- | --- | --- | --- | --- |
| false | false | false | false | false | false |
| false | true | false | false | true | false |
| true | false | false | true | false | false |
| true | true | false | true | true | false |
| false | false | true | false | false | true |
| false | true | true | false | true | true |
| true | false | true | true | false | true |
| true | true | true | true | true | true |

All successful rows return HTTP 200 and `ok: true`. Disabled destinations make
no external requests and require no credentials. All-disabled mode still checks
authentication and the generic request payload but creates no recording and
sends nothing. It does not require transcription or prepare metadata for an
unused destination.

## Request validation and status contract

The normal request is authenticated multipart POST. The tables below assume
Composer and Dotenv bootstrap succeed; startup/environment failures may prevent
authentication or payload validation and return 500. Non-POST requests remain
405 after the endpoint's environment-loading error handling when autoloading is
available.

| Condition | HTTP status | Side effects |
| --- | --- | --- |
| Method other than POST | 405, with `Allow: POST` | No recording, SMTP send, or Todoist request. |
| Missing or incorrect bearer token | 401 | No destination effects. |
| Content type other than multipart/form-data | 415 | No destination effects. |
| Empty POST/files | 400 | No destination effects. |
| Missing, non-string, non-digit, negative, fractional, or more than 13-digit `recordedAt` | 400 | No destination effects. |
| Invalid audio structure, PHP upload error, or file not uploaded through PHP | 400 | No destination effects. |
| Todoist enabled with missing/non-string/blank transcription | 400 | Stops before local storage, SMTP, or Todoist API calls. |
| Invalid required configuration | 500 | No destination effects. |
| Local preparation/save failure | 500 | Forwarding stops; incomplete recording folders are cleaned up where created. |
| SMTP or Todoist delivery failure | 502 | Successful independent effects and completed local recordings remain. |
| Successful enabled operations or all disabled | 200 | JSON reports completed destinations. |

Authentication reads `HTTP_AUTHORIZATION`, falling back to
`REDIRECT_HTTP_AUTHORIZATION`, and compares the exact `Bearer <token>` value.
The timestamp is a string containing 1–13 digits, including `0`, interpreted as
Unix milliseconds. Audio is optional. Generic validation permits a note without
transcription; enabling Todoist adds its non-empty transcription requirement.

Configuration checks for optional destinations happen after generic request
validation. A valid token is therefore rejected/accepted before optional SMTP
configuration is considered, but startup configuration must still load first.

## Success responses

The exact top-level success schema is asserted for every switch combination:

```json
{
  "ok": true,
  "saved": false,
  "emailSent": false,
  "todoistSaved": false
}
```

- `folder` is present only when storage succeeds. Its shape is
  `YYYY-MM-DD_HH-mm-ss-SSS_<six lowercase hexadecimal characters>`.
- `todoistProjectId` and `todoistTaskId` are present after a successful Todoist
  delivery and are non-empty IDs returned by the API.
- Successful responses are `application/json; charset=utf-8` in both debug modes.
- Success does not add error or debug fields.

A full delivery consequently includes the four base fields plus `folder`,
`todoistProjectId`, and `todoistTaskId`. IDs and folder names are checked by shape
and relationship to actual results rather than fixed values.

## Metadata and attachments

`info.txt` is pretty-printed UTF-8 JSON with unescaped Unicode and slashes and a
trailing newline. It has these top-level fields, in this order:

```text
receivedAt
recordedAt
timezone
postFields
audio
headers
```

- `receivedAt` is the receipt date with milliseconds and timezone offset.
- `recordedAt` is the input milliseconds converted to whole Unix seconds and
  formatted in the configured timezone using the ATOM representation.
- `postFields` preserves the complete incoming POST structure, including unknown
  fields and nested arrays. HTTP tests include `client` and a nested extra field.
- `audio` is null for text-only notes. For audio it contains exactly `savedAs`,
  `originalFilename`, `reportedContentType`, and `sizeBytes`.
- `headers` contains only `Content-Type`, `X-Audio-Size`, and `User-Agent`;
  absent selected headers are null. Authorization and other headers are excluded.
- The stored/attached audio name is `audio.m4a`; its bytes match the upload.

Local storage currently moves the PHP-uploaded file into the recording folder.
Later forwarding uses that resulting path. Preserving readable audio across all
operations is an explicit requirement for the upcoming typed-note refactoring.

The email body is exactly the transcription, or empty if it is absent/non-string.
Email includes `info.txt` and optional audio. Its subject contains the configured
prefix and the timezone-adjusted recording date. A successful send means SMTP
acceptance, not independently verified inbox delivery.

Todoist uses the receipt month in `APP_TIMEZONE`, including at month boundaries;
it does not use `recordedAt` to choose the monthly project. It reuses an active
matching child of the configured parent, follows pagination, and ignores
matching names under other parents or archived projects. The task content is
the transcription, with no due fields. Audio and metadata are attached to
separate comments. Text-only notes create only the metadata comment.

## Failure ordering and independence

The current sequence is request validation, destination configuration,
Todoist-specific transcription validation, recording preparation/local save,
SMTP, then Todoist.

- A local-save failure stops both SMTP and Todoist. HTTP tests enable all three
  destinations, point storage at a regular file instead of a directory, and
  confirm no new emails or API requests occur in either debug mode.
- SMTP failure does not prevent Todoist delivery.
- Todoist failure does not undo or suppress an accepted email.
- Either or both forwarding failures produce 502 and omit `ok`.
- Completed local audio/metadata remain after downstream failure.
- There is no transaction across destinations, queue, deduplication of webhook
  deliveries, or automatic retry. Repeated requests may repeat external effects.

## Debug mode and private diagnostics

| Behavior | DEBUG_MODE=false | DEBUG_MODE=true |
| --- | --- | --- |
| Error response body | Empty | Meaningful JSON. |
| Intended HTTP status | Preserved | Preserved. |
| `logs/webhook.log` | Appends sanitized errors. | No new application error messages. |
| SMTP failure diagnostics | Generic private error log; failure status file when stored. | `smtpDebug.exceptions` with sanitized messages, codes, causes, and available transcript. |
| Todoist failure diagnostics | Sanitized details and partial IDs in private log. | `todoistDebug` with message, failed stage, and partial progress. |
| `mail-status.txt` on SMTP success with storage | SMTP acceptance message. | SMTP acceptance message. |
| `mail-status.txt` on SMTP failure with storage | Failure message. | No failure status message written. |

Error bodies do not expose raw PHP warnings. Tokens, SMTP passwords, and SMTP
authentication payloads are redacted from diagnostic messages/transcripts.
New private log directories/files use 0700/0600 permissions; a file-write failure
falls back to native PHP error logging. Monolog rotation and operator alert
emails are future phases and are not part of this baseline.

## Partial Todoist outcomes

The controlled attachment failures create exactly one task and preserve it.
HTTP tests assert returned IDs, actual comments belonging to that task, retained
local audio, redacted API messages, and no debug-mode private log append.

| Controlled failure | Failed stage | Task retained | Completed comments |
| --- | --- | --- | --- |
| Audio upload | `audio upload` | Yes | 0 |
| First/audio comment | `audio comment creation` | Yes | 0 |
| Metadata upload | `info.txt upload` | Yes | 1 (audio) |
| Second/metadata comment | `info.txt comment creation` | Yes | 1 (audio) |
| Task creation rate limit | `task creation` | No known created task | 0 |
| Project lookup denied | `monthly project lookup` | No created task | 0 |

For an attachment failure, the debug error includes `saved`, `emailSent`,
`todoistSaved: false`, any stored `folder`, and `todoistProjectId`/`todoistTaskId`.
`todoistDebug` contains `message`, `stage`, `commentsCreated`, `projectId`, and
`taskId`. Production responses are empty; the private log retains the sanitized
partial task ID and stage, demonstrated with a metadata-upload failure.

A partial task is never reported as `todoistSaved: true`. No task/comment cleanup
or automatic retry is performed. These retained results must survive the future
operation-runner migration.

## Phase 1 verification record

- Initial baseline: both existing suites passed.
- Expanded baseline: both suites passed on PHP 8.2.33.
- PHP syntax checks for `src/`, `public/`, and `tests/`: passed.
- Composer validation/platform checks: passed; Composer retains the existing
  advisory that no package license is declared.
- `git diff --check`: passed.
- Changes in this phase: tests, stub failure controls, and documentation.

## Phase 7 logging update

This baseline predates structured logging. Phase 7 preserves response and delivery
behavior but replaces the error-only file with configurable Monolog lifecycle logs.
The default is daily JSON rotation with 30 files, including the current file.
`LOG_ENABLED=false` disables application logs; debug mode still suppresses them
unless `LOG_IN_DEBUG=true`. See [logging settings](LOGGING.md). Failure notification
emails remain outside this baseline and are not implemented yet.
