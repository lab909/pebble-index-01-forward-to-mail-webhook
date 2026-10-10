<?php
declare(strict_types=1);

namespace Pebble\Operation;

enum FailurePolicy: string
{
    case Abort = 'abort';
    case Continue = 'continue';
}
