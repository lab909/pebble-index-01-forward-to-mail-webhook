<?php
declare(strict_types=1);

// Factory IDs are explicit bindings, not package discovery or automatic activation.
// Phase 5 supplies the built-in destination factories to Bootstrap::prepareOperations().
return [
    ['id' => 'local_storage', 'enabled' => 'STORE_TO_FOLDER', 'factory' => 'local_storage', 'order' => 100, 'failure' => 'abort'],
    ['id' => 'email', 'enabled' => 'FORWARD_TO_MAIL', 'factory' => 'email', 'order' => 200, 'failure' => 'continue'],
    ['id' => 'todoist', 'enabled' => 'SAVE_TO_TODOIST', 'factory' => 'todoist', 'order' => 300, 'failure' => 'continue'],
];
