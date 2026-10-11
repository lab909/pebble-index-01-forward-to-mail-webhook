# Receipt operation example

An ordinary Composer library with a separate PSR-4 namespace. This local operation
writes a private receipt with request ID, receipt timestamp, transcription byte
count, and audio byte count. It never stores note text/audio or calls a remote API.

See the hub's [extension guide](../../docs/EXTENSIONS.md) for installation, explicit
registration, configuration, and supported contracts. This is a private example
package, not a published Packagist dependency. Its host supplies `Pebble\Note`,
`Pebble\Operation`, and `Pebble\Config` contracts; it cannot run independently.

Modes demonstrate real result handling:

- `success`: create a receipt and return its ID.
- `failure`: return a simulated failure before writing anything.
- `partial`: create a receipt, then report a simulated acknowledgement failure,
  retaining the receipt ID/path so successful work is not lost.

Empty transcription is a preflight validation failure in every mode. The example
registers with `continue`, so independent later operations can still run after an
execution failure. Preflight failures always prevent all operations from executing.
