<?php
declare(strict_types=1);

namespace Pebble\Http;

use Pebble\Operation\ExecutionReport;

/** Compatibility fields for clients predating the operation pipeline. */
final readonly class LegacyResultMapper
{
    public function map(ExecutionReport $report): array
    {
        $stored = $report->entries['local_storage']->result ?? null;
        $email = $report->entries['email']->result ?? null;
        $todoist = $report->entries['todoist']->result ?? null;
        $body = ['saved' => $stored?->succeeded() ?? false,
            'emailSent' => $email?->succeeded() ?? false, 'todoistSaved' => $todoist?->succeeded() ?? false];
        if (isset($stored->outputs['folderName'])) {
            $body['folder'] = $stored->outputs['folderName'];
        }
        foreach (['projectId' => 'todoistProjectId', 'taskId' => 'todoistTaskId'] as $key => $field) {
            if (isset($todoist->externalIds[$key])) {
                $body[$field] = $todoist->externalIds[$key];
            }
        }
        $failures = [];
        foreach ($report->entries as $id => $entry) {
            if (!$entry->result->succeeded()) {
                $failures[] = $id === 'email' && ($stored?->succeeded() ?? false)
                    ? 'Saved locally, but SMTP sending failed.' : $entry->result->message;
                // Only historical destination fields belong at the top level.
                $field = match ($id) {
                    'email' => 'smtpDebug',
                    'todoist' => 'todoistDebug',
                    default => null,
                };
                if ($field !== null && isset($entry->result->diagnostics[$field])) {
                    $body[$field] = $entry->result->diagnostics[$field];
                }
            }
        }
        if ($failures !== []) {
            $body['error'] = implode(' ', $failures);
        }
        return $body;
    }
}
