<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pebble\Http\NoteFactory;
use Pebble\Http\Request;
use Pebble\Note\AssetOwnership;
use Pebble\Note\AudioAsset;
use Pebble\Note\Note;
use Pebble\Services\RecordingMailer;
use Pebble\Services\RecordingStorage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

function assertNote(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/pebble-note-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$initialUmask = umask();
try {
    $timezone = new DateTimeZone('Europe/Rome');
    $factory = new NoteFactory($timezone);
    $post = ['recordedAt' => '1791536400999', 'transcription' => "Caffè / note\nSecond line", 'extra' => ['nested' => 'preserved']];
    $note = $factory->fromRequest(new Request([
        'HTTP_AUTHORIZATION' => 'Bearer must-not-appear', 'HTTP_X_PRIVATE' => 'must-not-appear',
        'CONTENT_TYPE' => 'multipart/form-data', 'HTTP_USER_AGENT' => 'Note lifecycle test',
    ], $post, []));
    $info = json_decode($note->infoText, true, flags: JSON_THROW_ON_ERROR);
    assertNote($note->transcription === $post['transcription'] && $note->postFields === $post, 'Normalize once, preserve complete POST fields');
    assertNote($note->recordedAt->getTimestamp() === 1791536400 && $note->recordedAt->getTimezone()->getName() === 'Europe/Rome', 'Preserve timestamp truncation and timezone');
    assertNote($note->receivedAt->getTimezone()->getName() === 'Europe/Rome', 'Use configured receipt timezone');
    assertNote(array_keys($info) === ['receivedAt', 'recordedAt', 'timezone', 'postFields', 'audio', 'headers'], 'Keep metadata schema and ordering');
    assertNote(!str_contains($note->infoText, 'must-not-appear') && str_contains($note->infoText, 'Caffè / note'), 'Preserve Unicode/slashes and exclude private headers');
    assertNote(str_ends_with($note->infoText, PHP_EOL) && str_contains($note->infoText, "\n    \"receivedAt\""), 'Preserve pretty JSON and trailing newline');
    foreach ([null, ['unexpected' => 'array']] as $transcription) {
        $unusual = $factory->fromRequest(new Request([], array_replace($post, ['transcription' => $transcription]), []));
        assertNote($unusual->transcription === '' && $unusual->postFields['transcription'] === $transcription, 'Normalize non-string text while preserving original fields');
    }
    try {
        $note->transcription = 'mutated';
        throw new RuntimeException('Note must be immutable');
    } catch (Error) {
    }

    $source = $root . '/source.m4a';
    file_put_contents($source, 'shared audio');
    $asset = new AudioAsset($source, 'original.m4a', 'audio/mp4', AssetOwnership::DurableFile);
    $audioNote = new Note($note->transcription, $note->postFields, $note->receivedAt,
        $note->recordedAt, $timezone, $note->headers, $asset);
    assertNote($asset->sizeBytes === 12 && !$asset->isTemporary(), 'Describe size and durable ownership');
    $storage = new RecordingStorage($root . '/recordings', $timezone);
    $first = $storage->save($audioNote);
    $second = $storage->save($audioNote);
    assertNote($first->folder !== $second->folder && file_get_contents($source) === 'shared audio', 'Repeated storage consumes the same intact source');
    assertNote($audioNote->audio === $asset && $asset->path === $source, 'Storage never replaces the note asset');
    assertNote(file_get_contents($first->audioPath) === 'shared audio' && file_get_contents($second->audioPath) === 'shared audio', 'Copies retain identical bytes');
    assertNote(file_get_contents($first->folder . '/info.txt') === $audioNote->infoText, 'Use the shared info.txt representation');
    assertNote((fileperms($first->audioPath) & 0777) === 0600 && (fileperms($first->folder) & 0777) === 0700, 'Keep stored audio private');
    assertNote(umask() === $initialUmask, 'Restore umask after success');

    $fake = new class implements MailerInterface {
        public array $messages = [];
        public function send(RawMessage $message, ?Envelope $envelope = null): void
        {
            // Serialize while synchronous assets are still available.
            $message->toString();
            $this->messages[] = $message;
        }
    };
    $email = new RecordingMailer($fake, 'from@example.com', 'to@example.com', 'Note');
    unlink($first->audioPath);
    $email->send($audioNote);
    assertNote(count($fake->messages[0]->getAttachments()) === 2, 'Email uses shared source independently of stored copies');
    assertNote($fake->messages[0]->getAttachments()[0]->getBody() === $audioNote->infoText, 'Email attaches identical info.txt bytes');
    file_put_contents($root . '/blocked', 'not a directory');
    try {
        @(new RecordingStorage($root . '/blocked', $timezone))->save($audioNote);
        throw new LogicException('Expected storage failure');
    } catch (RuntimeException) {
        assertNote(is_file($source) && file_get_contents($second->audioPath) === 'shared audio', 'Storage failure retains input and previous successful results');
        assertNote(umask() === $initialUmask, 'Restore umask after failure');
    }
    try {
        $factory->fromRequest(new Request([], $post, ['audio' => ['error' => UPLOAD_ERR_OK,
            'tmp_name' => $source, 'name' => 'source.m4a', 'type' => 'audio/mp4']]));
        throw new LogicException('Expected upload boundary rejection');
    } catch (RuntimeException) {
        assertNote(is_file($source), 'Reject arbitrary local file as a PHP upload without deleting it');
    }

    foreach ([AssetOwnership::PhpUpload, AssetOwnership::DurableFile, AssetOwnership::HubTemporary] as $ownership) {
        $path = $root . '/' . $ownership->name;
        file_put_contents($path, 'owned file');
        $owned = new AudioAsset($path, null, null, $ownership);
        try {
            throw new RuntimeException('Simulated consumer failure');
        } catch (RuntimeException) {
        } finally {
            $owned->cleanup();
        }
        assertNote(is_file($path) === ($ownership !== AssetOwnership::HubTemporary), 'Delete only hub-owned temporary files in finally');
        $owned->cleanup(); // Repeated cleanup is harmless.
    }
    echo "Note lifecycle checks passed: immutable data, metadata compatibility, shared copies, destination independence, upload boundary, and ownership cleanup.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
    umask($initialUmask);
}
