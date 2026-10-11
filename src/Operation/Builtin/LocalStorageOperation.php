<?php
declare(strict_types=1);

namespace Pebble\Operation\Builtin;

use Pebble\Note\Note;
use Pebble\Operation\{OperationInterface, OperationResult, ErrorCategory, HttpFailure};
use Pebble\Services\RecordingStorage;
use Throwable;

final readonly class LocalStorageOperation implements OperationInterface
{
    public function __construct(private RecordingStorage $storage) {}

    public function validate(Note $note): void {}

    public function execute(Note $note): OperationResult
    {
        try {
            $recording = $this->storage->save($note);
            return OperationResult::success(['folder' => $recording->folder,
                'folderName' => $recording->folderName, 'audioPath' => $recording->audioPath]);
        } catch (Throwable) {
            return OperationResult::failure(ErrorCategory::Storage, HttpFailure::InternalServerError,
                'Unable to process webhook. Check recording data and storage directory permissions.');
        }
    }
}
