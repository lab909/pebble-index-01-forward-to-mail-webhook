<?php
declare(strict_types=1);

namespace Pebble;

use Pebble\Config\WebhookConfig;
use Pebble\Services\RecordingStorage;
use Pebble\Services\RecordingMailer;
use Pebble\Services\TodoistService;

final readonly class BootstrappedServices
{
    public function __construct(
        public WebhookConfig $config,
        public ?RecordingStorage $storage,
        public ?RecordingMailer $mailer,
        public ?TodoistService $todoist,
    ) {
    }
}
