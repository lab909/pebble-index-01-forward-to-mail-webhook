# Failure notification emails

Enable `FAILURE_EMAIL_ENABLED=true` to notify operators about authenticated webhook
configuration failures or failed operations. One request produces at most one email,
including failed operations/stages, timestamp/timezone, request ID, sanitized
provider diagnostics, partial successes, external IDs, and retained folder references.
Alerts never attach transcription, audio, info.txt, full POST data, or credentials.
Diagnostics use the same recursive redaction and size limits as file logs.

This switch is independent of `FORWARD_TO_MAIL` and `LOG_ENABLED`. Note SMTP settings
are never reused. Use a separate working provider if you want notification of note
SMTP outages. Hosting-wide outages or blocked outbound ports can affect both.

| Setting | Default | Meaning |
| --- | --- | --- |
| `FAILURE_EMAIL_ENABLED` | `false` | Enable operator alerts. |
| `FAILURE_EMAIL_LEVEL` | `error` | Minimum severity, using the same named levels as Monolog. |
| `FAILURE_EMAIL_IN_DEBUG` | `false` | Allow alerts in debug mode. Independent of LOG_IN_DEBUG. |
| `FAILURE_EMAIL_FROM` | required when active | Sender email address. |
| `FAILURE_EMAIL_TO` | required when active | Comma-separated email addresses; whitespace around each is trimmed. |
| `FAILURE_EMAIL_SUBJECT_PREFIX` | `Pebble Hub failure` | Subject text. |
| `FAILURE_EMAIL_COOLDOWN_SECONDS` | `300` | Suppression after successful SMTP acceptance; nonnegative; 0 disables success cooldown. |
| `FAILURE_EMAIL_STATE_DIRECTORY` | `var/alerts` | Private writable state path outside public, including symlink resolution. |
| `FAILURE_EMAIL_TIMEOUT_SECONDS` | `10` | Positive SMTP socket connect/read timeout, up to 300 seconds. |
| `FAILURE_EMAIL_SMTP_SCHEME` | required when active | smtp with required STARTTLS, or smtps with implicit TLS. |
| `FAILURE_EMAIL_SMTP_HOST` | required when active | SMTP hostname. |
| `FAILURE_EMAIL_SMTP_PORT` | required when active | Port 1–65535, normally 587 or 465. |
| `FAILURE_EMAIL_SMTP_USERNAME` | required when active | Separate alert SMTP username. |
| `FAILURE_EMAIL_SMTP_PASSWORD` | required when active | Separate alert SMTP password. |

Certificates and hostnames are verified. There is no insecure TLS override. Alert
SMTP has its own socket timeout without changing note-mail settings. The timeout
applies to network I/O, not a strict whole-request deadline; SMTP involves multiple
commands, and host DNS resolution follows the hosting resolver's timeout. Alerts
run synchronously before the response, so a failed transport can add latency.

## Eligibility and debug policy

Operation failures have error severity; application/configuration and unexpected
preflight failures have critical severity. Raising the threshold to critical
therefore excludes ordinary destination errors. Lowering it never enables alerts
for bad methods, bad/missing request tokens, malformed requests, or validation
failures. When only notification settings or state storage are broken, normal
request processing continues and a file-only configuration event is attempted.
The notification system never emails about its own configuration/transport failures.

`DEBUG_MODE=true` suppresses alerts unless `FAILURE_EMAIL_IN_DEBUG=true`. Disabled
or debug-suppressed alerts do not require SMTP credentials or create state files.
`LOG_IN_DEBUG` controls file logs, while `FAILURE_EMAIL_IN_DEBUG` controls emails.
A successful request never emails an alert. Invalid configuration before dependency
loading, missing/unparseable .env, unavailable Composer, or a missing server token
cannot reliably authenticate a request or construct this pipeline; those failures
remain in the bootstrap/PHP log. Runtime fatal recovery is documented in
[logging](LOGGING.md); shutdown handlers do not attempt network notifications.

## Cooldown and concurrent requests

Fingerprints contain operation ID, stage, provider HTTP code, and error category.
They exclude messages, timestamps, task/request IDs, and paths. New task IDs thus
cannot defeat suppression. If any reportable fingerprint is eligible, one email
contains the request report; only eligible fingerprints update their cooldown.
Different stages/provider codes remain independently eligible.

Cooldown persists across requests in `cooldown.json`, with `cooldown.lock` guarding
read/check/send/commit. A request encountering an active lock skips notification
without waiting for another SMTP exchange. New state directories/files use
0700/0600. State filenames cannot be symlinks and state is stored separately from
rotated logs. Keep the directory owned by PHP and its parents administratively
controlled. One shared writable filesystem is required for suppression across
multiple application instances; independent hosts need a shared store.

Before sending, the store reserves a 30-second retry window with `delivered=false`.
Failed attempts retain that short window, rather than the full success cooldown.
SMTP acceptance records `delivered=true` and applies the configured cooldown.
The reservation also limits retries after a process crash. State writes are atomic.
If state cannot be read/written safely, notification is skipped and delivery
continues. If SMTP accepts a message but the success-state commit fails, the prior
reservation remains; a later retry can produce a duplicate. SMTP acceptance does
not guarantee inbox delivery. This is suppression, not an exactly-once queue.

The locked Monolog DeduplicationHandler compares stable message/level values, but
writes its state before sending and does not lock the whole read/check/send cycle.
This application uses its own locked state to preserve failed-send retry semantics.
Monolog SymfonyMailerHandler formats and sends one sanitized batch with a stable
record message. Handlers and transport are explicitly closed in guarded finalization;
alerts do not rely on destructors or subscribe to the file logger.

`notification_sent`, `notification_suppressed`, `notification_failed`, and
`notification_configuration_failed` are written to the file-only notifications
channel when logging policy/level allows them. Failure events never re-enter alert
delivery. If logs are disabled, alerts can still send without these local events.

## Deployment diagnostic

`php bin/test-alert-email.php --check` validates the enabled alert settings without
network or state writes. `--send` explicitly sends one diagnostic email, bypassing
webhook debug/cooldown/threshold gates without consuming notification state.
Confirm inbox receipt separately. See [deployment checks](DEPLOYMENT.md).
