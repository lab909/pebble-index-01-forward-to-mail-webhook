<?php
declare(strict_types=1);

namespace Pebble\Operation;

interface OperationInterface
{
    /** Preflight must not perform destination writes. */
    public function validate(\Pebble\Note\Note $note): void;
    public function execute(\Pebble\Note\Note $note): OperationResult;
}
