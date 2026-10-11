<?php
declare(strict_types=1);

namespace Pebble\Services;

use DateTimeZone;
use Pebble\Exception\TodoistException;
use Pebble\Note\Note;
use Throwable;
use Psr\Log\LoggerInterface;

final readonly class TodoistService
{
    public function __construct(
        private TodoistClient $client,
        private string $parentProjectId,
        private DateTimeZone $timezone,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function save(Note $note): array
    {
        $transcription = $note->transcription;
        if (trim($transcription) === '') {
            throw new TodoistException('Todoist requires a non-empty transcription.');
        }
        $context = ['stage' => 'monthly project lookup', 'commentsCreated' => 0];
        try {
            $month = $note->receivedAt->setTimezone($this->timezone)->format('F Y');
            $projectId = $this->monthlyProject($month);
            $context['projectId'] = $projectId;
            $context['stage'] = 'task creation';
            $task = $this->client->post('/tasks', ['content' => $transcription, 'project_id' => $projectId]);
            $taskId = $this->id($task);
            $context['taskId'] = $taskId;

            if ($note->audio !== null) {
                $context['stage'] = 'audio upload';
                $attachment = $this->client->uploadAudio($note->audio->path, $projectId);
                $context['stage'] = 'audio comment creation';
                $this->comment($taskId, 'Audio recording', $attachment);
                ++$context['commentsCreated'];
            }
            $context['stage'] = 'info.txt upload';
            $attachment = $this->client->uploadInfo($note->infoText, $projectId);
            $context['stage'] = 'info.txt comment creation';
            $this->comment($taskId, 'Webhook information', $attachment);
            ++$context['commentsCreated'];
            unset($context['stage']);
            SafeLog::write($this->logger, 'debug', 'Todoist note saved.', $context);
            return $context;
        } catch (Throwable $exception) {
            SafeLog::write($this->logger, 'debug', 'Todoist note saving failed.', $context);
            // Retain IDs so a partial task can be found instead of silently discarded.
            throw new TodoistException($exception->getMessage(), $context
                + ($exception instanceof TodoistException ? array_intersect_key($exception->context, ['providerStatus' => true]) : []), $exception);
        }
    }

    private function monthlyProject(string $name): string
    {
        // Serialize lookup/create across PHP workers on this server.
        $lockPath = sys_get_temp_dir() . '/pebble-todoist-' . hash('sha256', $this->parentProjectId) . '.lock';
        $previousUmask = umask(0077);
        try {
            $lock = @fopen($lockPath, 'c');
        } finally {
            umask($previousUmask);
        }
        if ($lock === false) {
            throw new TodoistException('Cannot open the monthly Todoist project lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new TodoistException('Cannot lock the monthly Todoist project lookup.');
            }
            $query = ['limit' => 200];
            $seenCursors = [];
            do {
                $page = $this->client->get('/projects', $query);
                if (!isset($page['results']) || !is_array($page['results'])) {
                    throw new TodoistException('Todoist returned an invalid project list.');
                }
                foreach ($page['results'] as $project) {
                    if (($project['name'] ?? null) === $name
                        && ($project['parent_id'] ?? null) === $this->parentProjectId
                        && !($project['is_archived'] ?? false) && !($project['is_deleted'] ?? false)) {
                        return $this->id($project);
                    }
                }
                $cursor = $page['next_cursor'] ?? null;
                if ($cursor !== null && (!is_string($cursor) || $cursor === '' || isset($seenCursors[$cursor]))) {
                    throw new TodoistException('Todoist returned an invalid pagination cursor.');
                }
                if ($cursor !== null) {
                    $seenCursors[$cursor] = true;
                    $query['cursor'] = $cursor;
                }
            } while ($cursor !== null);

            return $this->id($this->client->post('/projects', ['name' => $name, 'parent_id' => $this->parentProjectId]));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function comment(string $taskId, string $content, array $attachment): void
    {
        if (!isset($attachment['file_url']) || !is_string($attachment['file_url']) || $attachment['file_url'] === '') {
            throw new TodoistException('Todoist upload response is missing the file URL.');
        }
        $this->id($this->client->post('/comments', [
            'task_id' => $taskId, 'content' => $content, 'attachment' => $attachment,
        ]));
    }

    private function id(array $object): string
    {
        if (!isset($object['id']) || !is_string($object['id']) || $object['id'] === '') {
            throw new TodoistException('Todoist response is missing the object ID.');
        }
        return $object['id'];
    }
}
