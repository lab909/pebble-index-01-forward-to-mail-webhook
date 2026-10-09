# Pebble webhook with SMTP email

Requires PHP 8.2+, Composer, and PHP OpenSSL support. The receiver uses Symfony
Mailer and Symfony Dotenv as standalone components; no Symfony application is
needed. Composer resolves dependencies against PHP 8.2 so the lock file also
works on PHP 8.2 when dependencies are updated on a newer PHP installation.

## Install

Extract the package into your project directory and run:

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Edit `.env` with your webhook token, SMTP credentials, sender and recipient.
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

- `public/webhook.php`: loads configuration and coordinates validation, storage, and email.
- `src/Config/WebhookConfig.php`: validates environment settings.
- `src/Services/FileLogger.php`: writes private error logs.
- `src/Services/RequestValidator.php`: checks authentication and multipart payloads.
- `src/Services/RecordingStorage.php`: saves recordings, cleans incomplete writes, and records mail status.
- `src/Services/RecordingMailer.php`: builds and sends emails through Symfony Mailer.
- `src/Http/`: request and JSON response classes.
- `src/Recording/Recording.php`: recording metadata and audio path passed between services.

After changing Composer autoload settings, run `composer dump-autoload --optimize`.
In DDEV, run Composer and PHP commands with `ddev composer` and `ddev php`.

## Optional actions

Set these independently in `.env` (both default to `true` when omitted):

```dotenv
STORE_TO_FOLDER=true
FORWARD_TO_MAIL=true
```

| STORE_TO_FOLDER | FORWARD_TO_MAIL | Behavior |
| --- | --- | --- |
| true | true | Save locally and send email. |
| true | false | Save locally; no SMTP configuration is needed. |
| false | true | Send email using in-memory metadata and PHP's temporary upload; no recording folder is created. |
| false | false | Validate and acknowledge the webhook without saving or emailing. |

`STORAGE_DIRECTORY` is required only for local storage. SMTP and `MAIL_*`
settings are required only for email. The token and timezone are always required.
The response includes `saved` and `emailSent`; `folder` is included only when
files were saved. Successful enabled actions return HTTP 200, including when
both actions are disabled. Invalid boolean settings return HTTP 500.

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

## Error logging

With `DEBUG_MODE=false` (the default), webhook errors return only the HTTP status
code with an empty response body.
Non-POST requests return 405; missing or incorrect tokens return 401. Successful
requests still return JSON. Error details are written to `logs/webhook.log`,
outside the public directory, including validation, configuration, storage, and
SMTP failures. Tokens and SMTP credentials are excluded from the log.

Set `DEBUG_MODE=true` in `.env` to return meaningful JSON errors instead of
writing error messages to `logs/webhook.log` or failure messages to
`mail-status.txt`. Status codes stay the same. Debug responses include validation
messages and guidance for configuration, storage, and SMTP errors; they exclude
stack traces, tokens, and SMTP credentials. SMTP failures also include
`smtpDebug.exceptions` with the full exception messages, types, codes, nested
causes, and available SMTP transcripts. Authentication payloads and configured
credentials are redacted. Connection failures may have an empty transcript. Successful
responses and SMTP acceptance status files behave the same in both modes.

The PHP user needs permission to create and append to `logs/webhook.log`.
New log directories and files use permissions 0700 and 0600. If the private log
cannot be written, messages fall back to the server's PHP error log.

## Manual check

First check the syntax:

```bash
find src public tests -name '*.php' -exec php -l {} \;
php tests/run.php
python3 tests/http.py
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
Python 3 and OpenSSL and use a local TLS SMTP stub. They cover all four setting
combinations, validation, file cleanup, attachments, and SMTP failures.
SMTP delivery still needs to be verified against your configured provider.
