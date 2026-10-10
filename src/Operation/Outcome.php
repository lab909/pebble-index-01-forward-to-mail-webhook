<?php
declare(strict_types=1);

namespace Pebble\Operation;

enum Outcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Partial = 'partial';
}
