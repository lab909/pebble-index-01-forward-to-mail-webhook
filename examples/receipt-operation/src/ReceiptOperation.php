<?php
declare(strict_types=1);
namespace PebbleExample\Receipt;

use Pebble\Note\Note;
use Pebble\Operation\{ErrorCategory, HttpFailure, OperationInterface, OperationResult,
    OperationValidationException, RequestContext, ValidationFailure};
use RuntimeException;
use Throwable;

/** A deliberately local demonstration; it never modifies the shared note/audio. */
final readonly class ReceiptOperation implements OperationInterface
{
    public function __construct(private ReceiptConfig $config, private RequestContext $context) {}

    public function validate(Note $note): void
    {
        if (trim($note->transcription) === '') {
            throw new OperationValidationException(new ValidationFailure('The receipt example requires a transcription.'));
        }
    }

    public function execute(Note $note): OperationResult
    {
        if ($this->config->mode === 'failure') {
            return OperationResult::failure(ErrorCategory::Storage, HttpFailure::InternalServerError,
                'Simulated receipt creation failure.', diagnostics: ['stage' => 'create_receipt']);
        }
        $id = bin2hex(random_bytes(12));
        $folder = $this->config->directory . '/' . $id;
        $created = false;
        $folderCreated = false;
        $mask = umask(0077);
        try {
            if (!is_dir($this->config->directory) && !@mkdir($this->config->directory, 0700, true)
                && !is_dir($this->config->directory)) {
                throw new RuntimeException('Cannot create example directory.');
            }
            if (!@mkdir($folder, 0700)) { throw new RuntimeException('Cannot create receipt folder.'); }
            $folderCreated = true;
            $receipt = json_encode(['requestId' => $this->context->requestId,
                'receivedAt' => $this->context->receivedAt->format('c'),
                'transcriptionBytes' => strlen($note->transcription), 'audioBytes' => $note->audio?->sizeBytes],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
            if (file_put_contents($folder . '/receipt.json', $receipt, LOCK_EX) !== strlen($receipt)) {
                throw new RuntimeException('Cannot write receipt.');
            }
            $created = true;
            // Simulate a second step failing after a durable receipt already exists.
            if ($this->config->mode === 'partial') {
                return OperationResult::failure(ErrorCategory::Storage, HttpFailure::InternalServerError,
                    'Receipt created, but the simulated acknowledgement step failed.',
                    diagnostics: ['stage' => 'acknowledge'], externalIds: ['receiptId' => $id],
                    progress: ['receiptCreated' => true, 'stage' => 'acknowledge'], outputs: ['folder' => $folder], partial: true);
            }
            return OperationResult::success(outputs: ['folder' => $folder], externalIds: ['receiptId' => $id],
                progress: ['receiptCreated' => true]);
        } catch (Throwable) {
            return OperationResult::failure(ErrorCategory::Storage, HttpFailure::InternalServerError,
                'Unable to save example receipt.', diagnostics: ['stage' => 'create_receipt'],
                externalIds: $created ? ['receiptId' => $id] : [], outputs: $created ? ['folder' => $folder] : [], partial: $created);
        } finally {
            if ($folderCreated && !$created) { @unlink($folder . '/receipt.json'); @rmdir($folder); }
            umask($mask);
        }
    }
}
