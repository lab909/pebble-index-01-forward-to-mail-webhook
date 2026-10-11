<?php
declare(strict_types=1);

// Factory IDs are explicit bindings, not package discovery or automatic activation.
// Bootstrap supplies lazy built-in factory bindings; only enabled ones are constructed.
return [
    ['id' => 'local_storage', 'enabled' => 'STORE_TO_FOLDER', 'factory' => 'local_storage', 'order' => 100, 'failure' => 'abort'],
    ['id' => 'email', 'enabled' => 'FORWARD_TO_MAIL', 'factory' => 'email', 'order' => 200, 'failure' => 'continue'],
    ['id' => 'todoist', 'enabled' => 'SAVE_TO_TODOIST', 'factory' => 'todoist', 'order' => 300, 'failure' => 'continue'],
];
