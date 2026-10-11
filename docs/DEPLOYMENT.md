# Deployment and migration

Local verification and production verification are separate. Phase 10's test suite
uses disposable projects and local SMTP/API stubs. It does not prove production
network access, mailbox delivery, Todoist permissions, or the hosting PHP configuration.

## Prepare a release

1. Keep the previous application release/lock file and privately back up `.env`,
   recordings, existing logs, and `var/alerts` before migration. Do not put credentials
   or private data into version control or a public backup directory.
2. Run the isolated verification command on PHP 8.2:

   ```bash
   PHP_BINARY=php8.2 python3 tests/verify.py
   ```

   Python 3, curl, OpenSSL, Composer, PHP OpenSSL, and PHP cURL are needed for all
   checks. The runtime platform check reports installed locked-package requirements.
   The current Composer manifests validate, with advisory warnings for missing
   license metadata and the private example's explicit development version.
3. Build dependencies from the committed lock file with the target PHP environment:

   ```bash
   composer install --no-dev --optimize-autoloader --no-interaction
   composer check-platform-reqs --no-dev
   ```

   Use `install`, not `update`, during deployment. If an example/extension path
   repository is installed, include its source during dependency resolution. Package
   installation does not automatically register or enable an operation.
4. Deploy `public/`, `src/`, `config/`, `bin/`, vendor, and the Composer files together.
   Set the HTTP document root to `public/`. Keep `.env`, recordings, logs, and alert
   state outside web aliases as well. `bin/` is CLI-only and must not be exposed.
5. Preserve the existing `.env`. Add only the settings you need from `.env.example`;
   do not overwrite real credentials with placeholders. Ensure the web server
   forwards Authorization and permits your audio upload size. Keep hosting-level
   display_errors disabled.
6. Give the PHP process ownership/write access to configured storage, log, and
   notification-state paths. New private directories/files use 0700/0600; existing
   parent ownership/permissions must be checked separately. Avoid world-writable
   permissions. Persist recordings and cooldown state across application releases.
7. Confirm the host permits outbound SMTP ports and HTTPS access to enabled APIs.
   Use verified TLS. Separate alert SMTP can report a note-provider outage, but a
   host-wide networking outage can affect both transports.

In DDEV, run commands from the host with `ddev exec`/`ddev composer`. This workspace
was tested inside the running web container using its PHP 8.2 binary; its existing
DDEV PHP 8.4 setting was not changed. For the compatibility suite, the interpreter
must actually be PHP 8.2, rather than relying only on Composer's platform setting.

## Existing configuration and logs

The three destination defaults remain unchanged: local storage and note email on,
Todoist off. Existing `.env` files need no new variables. Operator failure email and
the example package remain off until explicitly configured. Extra package switches
are false when absent and do not auto-discover installed libraries.

Logging now defaults to JSON info events, daily rotation, and 30 retained files:
`logs/webhook-YYYY-MM-DD.log`, dated using APP_TIMEZONE. The former undated
`logs/webhook.log` is left untouched; it is not migrated, reformatted, or included in
the dated retention pattern. Archive/remove old logs only as a separate deliberate
retention task. New retention counts files including the current file, not elapsed
days, and cleanup happens on logging activity. Changing rotation/filename leaves
other filename patterns intact. `LOG_ROTATION=none` uses the base filename; choose
a fresh base filename if you want to avoid mixing old plain-text and new JSON lines.

Operator cooldown state lives in `var/alerts` by default, separate from rotated
logs. Preserve it across releases to avoid burst alerts during rollout. If state is
shared by multiple servers, they need one filesystem supporting the same locking
semantics. The hub has no database migration or queue migration.

## Explicit alert transport diagnostic

When configuring alerts on the deployed host, use separate `FAILURE_EMAIL_SMTP_*`
credentials, sender, and recipients and set FAILURE_EMAIL_ENABLED=true. Run:

```bash
php bin/test-alert-email.php --check
php bin/test-alert-email.php --send
```

`--check` validates configuration and constructs the transport without a network
connection or state write. `--send` explicitly sends one diagnostic email using the
same verified-TLS transport and socket timeout as hub alerts. It bypasses webhook
debug/threshold/cooldown gates and does not create cooldown state. The diagnostic
contains no note or attachments. Each invocation sends to every configured operator
recipient; SMTP acceptance must be followed by an inbox check. Failed output is
generic to avoid printing transport credentials. Run it from CLI, never a web URL.

After confirming receipt, verify a controlled, authenticated operational failure in
an isolated deployment test configuration. For example, temporarily use a private
storage path whose parent is a regular file with STORE_TO_FOLDER=true; this produces
500 before note forwarding. Keep that temporary configuration separate from the live
receiver so real notes are not lost. Verify one alert, matching request ID, and a
repeat suppressed by cooldown. Restore the intended storage settings before success
testing. Do not simulate failures by changing production account credentials or
replaying ordinary notes that already created tasks/emails.

## Hosted endpoint checks after deployment

Perform one intentionally named diagnostic note with a fresh Unix-millisecond
recordedAt and a sample audio file. Use the configured token without printing it in
logs, tickets, or screenshots. A valid POST really creates work in each enabled
destination; repeated webhook calls can produce duplicate tasks/emails.

Verify these outcomes against the chosen switches:

- HTTP 200 JSON, generated X-Request-Id, and per-operation success.
- Local folder has audio.m4a when provided and info.txt with expected fields.
- Note email has transcription-only body, metadata attachment, and optional audio.
- Todoist task is in the correct monthly child project, has transcription as content,
  audio and info.txt in comments, and no due date.
- A non-POST request returns 405; missing/wrong token returns 401; errors have empty
  bodies in production and these rejections create no delivery or operator alerts.
- Private logs correlate request/operation IDs and omit note text, audio, POST bodies,
  and credentials. Enabled alerts record file-only outcomes when logging allows them.

These live checks remain pending until deployment is requested and performed. Local
stub success is not an inbox check or a new hosted-endpoint verification.

## Rollback

Restore the previous application and its matching vendor/lock/config files together.
Preserve recordings and old/new logs. Disable new package switches and alert delivery
if rolling back to a release without those features; keep credential backups private.
Do not replay notes that already produced successful or partial external work without
reviewing the retained IDs. Rollback cannot unsend mail or undo Todoist tasks.
