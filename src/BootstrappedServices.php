<?php
declare(strict_types=1);

namespace Pebble;

use Pebble\Config\WebhookConfig;
use Pebble\Operation\{OperationRegistry, OperationRunner, OutcomeRecorder};

final readonly class BootstrappedServices
{
    public function __construct(
        public WebhookConfig $config,
        public OperationRegistry $operations,
        public OperationRunner $runner,
        public OutcomeRecorder $outcomeRecorder,
    ) {
    }
}
