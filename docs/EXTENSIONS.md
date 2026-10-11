# Adding a package operation

Phase 9 includes [a local receipt package](../examples/receipt-operation/README.md)
in its own `PebbleExample\Receipt` namespace. Install the package, add a registry
row and a lazy factory binding, then enable its switch. Application orchestration,
HTTP response mapping, logging, and alert aggregation need no destination branches.

Composer installation does not activate an operation. There is no package scan,
auto-discovery, global plugin directory, or Composer installation hook. Configuration
files are trusted application code and should be reviewed with the package.

## Install the example

From the hub's project root, configure a Composer path repository and install the
example library:

```bash
composer config repositories.receipt-example '{"type":"path","url":"examples/receipt-operation","options":{"symlink":false}}'
composer require pebble-example/receipt-operation:0.1.0
```

These commands intentionally update your application's composer.json/lock. Commit
those changes for deployment. The shipped hub does not require/install this example
by default. Copy the `examples/receipt-operation` directory along with the application
when resolving the path repository, or replace the repository with your private VCS
package source. The example uses a fixed development version for local installation;
remove its composer.json version field and use VCS tags before publishing it.

The library declares PHP 8.2+ and ordinary PSR-4 autoloading. It has no scripts,
Composer plugin type, or lifecycle hooks. Its host supplies the current hub contracts;
it is a private template paired with this checkout, not a published standalone API.

## Register explicitly

Add this one row to the array in `config/operations.php`:

```php
['id' => 'example_receipt', 'enabled' => 'EXAMPLE_RECEIPT_ENABLED',
 'factory' => 'example_receipt', 'order' => 400, 'failure' => 'continue'],
```

In `config/operation-factories.php`, return a callback that accepts the immutable
cached `Environment` and returns lazy bindings:

```php
<?php
declare(strict_types=1);

use Pebble\Config\Environment;
use PebbleExample\Receipt\{ReceiptConfig, ReceiptFactory};

return static function (Environment $environment): array {
    return [
        'example_receipt' => static fn () => new ReceiptFactory(
            ReceiptConfig::fromEnvironment($environment)
        ),
    ];
};
```

Add entries to the existing map when using several extensions. This callback must
only return bindings. Parse each destination's configuration and construct its
factory **inside** its lazy closure; no network calls, writes, or client creation
belong in the outer callback. Disabled closures are never invoked. A disabled
package may remain uninstalled, and its irrelevant settings can be missing or
invalid. Do not use getenv(), reload .env, or read request globals in package code.

Additional registry switch names are parsed strictly as booleans from the hub's
cached environment snapshot. Missing switches default to false. Built-in switch
values and factory bindings cannot be overwritten by extensions. A missing
operation-factories.php is allowed for older deployments; enabled binding IDs still
require a valid factory.

An explicit fully qualified factory class can also be the row's `factory` value:

```php
'factory' => Vendor\Package\NoSettingsFactory::class,
```

This variant is for factories with a side-effect-free no-argument constructor.
Only enabled class rows are autoloaded. Use the lazy binding file for typed settings
and constructor dependencies, as the receipt example does.

## Configure and exercise

Once installed and registered, set:

```dotenv
EXAMPLE_RECEIPT_ENABLED=true
EXAMPLE_RECEIPT_DIRECTORY='var/example-receipts'
EXAMPLE_RECEIPT_MODE=success
```

The directory is private and must be outside public, including existing symlinks.
The example's modes are deliberately simulated; do not treat them as a new
production destination or enable failure simulation against live Pebble traffic.

| Input/mode | Behavior | Result |
| --- | --- | --- |
| Empty/whitespace transcription | Preflight rejects before any operation executes. | 400 validation_failed |
| success | Creates receipt.json with request ID, receivedAt, transcription byte count, and audio byte count. | 200 success with receiptId |
| failure | Simulates a failure before writing. | 500 failure, no receipt ID |
| partial | Creates receipt.json, then simulates an acknowledgement failure. | 500 partial with retained receiptId and stage |

New receipt directories/files use 0700/0600 permissions. The package never copies,
moves, deletes, or closes the shared audio; later operations can still read it.
Internal folder paths stay out of public results. In debug mode the example appears
under `operations.example_receipt`, including its safe stage diagnostics. Production
error bodies stay empty. The configured `continue` policy permits later operations
after an execution failure. Preflight failure always blocks the entire pipeline.

The example's ID/progress are preserved by the generic response builder. The runner
owns duration and lifecycle logs, and operator alerts use the same generic failure
report. An extension must not send its own hub failure notifications or add cases to
LegacyResultMapper; that mapper only preserves existing built-in response fields.

## Factory and operation responsibilities

A factory implements `OperationFactoryInterface::create(OperationDependencies)`.
It receives explicit request context and guarded PSR-3 logging, without a global
container or complete environment. Constructor arguments hold typed destination
configuration and optional injectable clients. Creating a client must not perform
remote delivery. Account APIs, TLS/timeout behavior, API pagination, and destination
rules belong to the package, not WebhookHub.

An operation implements side-effect-free `validate(Note)` and `execute(Note)`.
Use OperationValidationException/ValidationFailure for bad input. Return a typed
OperationResult for success/failure/partial, with an explicit error category and HTTP
classification. Retain successful external IDs and progress when a later step fails;
do not automatically roll back other destinations or successful remote work. Expected
failures may also use OperationExecutionException containing a failure result.

Never put raw exceptions, credentials, full POST/audio data, or note text into result
diagnostics or logs. Adapters must sanitize provider messages before creating results,
because debug HTTP output does not run through the log processor. Use stable stage
labels and providerStatus for remote errors; notification fingerprints use operation,
stage, provider code, and category. Keep success logs at debug severity and let the
runner own the one central error event for a failure.

The hub owns authentication, complete preflight, order, abort/continue policy,
request IDs, duration, public formatting, lifecycle logging, notification aggregation,
and hub-owned audio cleanup. An operation must honor AudioAsset ownership and leave
the input readable for every later operation. See [the complete operation contract](OPERATIONS.md).

## Compatibility and future contract packaging

The example and hub require PHP 8.2+. Avoid features introduced after 8.2 in shared
contracts and package code. Test dependencies against Composer's 8.2 platform as
well as the real PHP 8.2 interpreter.

Currently `Pebble\Operation`, `Pebble\Note`, and the configuration helpers live in
this application. They are not a separately published, semantically versioned
Composer API. The example's 0.1.0 version describes the example only; it does not
promise compatibility with arbitrary hub releases. Pin and test the package with
the hub release/commit you deploy. Do not declare a dependency on an invented or
unpublished contracts package.

Before publishing reusable contracts, extract the minimal interfaces, immutable
note/result/context types, enums, exceptions, and any intended configuration API
into an actual Composer library. Define a supported contract version and migration
policy; package manifests can then require the real package using a bounded compatible
range. Renaming fields, changing signatures, adding required constructor arguments,
or changing ownership/failure semantics should be treated as breaking changes.
Keep logging and notification implementations application-owned. Verify old packages
against new contracts before widening compatibility claims or publishing a release.

## Isolated verification

```bash
PHP_BINARY=php8.2 python3 tests/extensions.py
```

This installs a **copied** path package with real Composer in a disposable project,
disables network repositories/plugins/scripts, and exercises the application hub
and runner through the installed PSR-4 namespace. It checks disabled laziness, typed
settings, auth/preflight, success/failure/partial, continue/abort, shared audio,
private outputs, silent errors, and conflicting/missing bindings. It uses no real
account credentials and makes no destination API calls. COMPOSER_BINARY can override
the Composer path; PHP_BINARY selects the PHP interpreter.

For the combined verification command and deploying installed extensions with the
matching application/vendor files, see [deployment and migration](DEPLOYMENT.md).
