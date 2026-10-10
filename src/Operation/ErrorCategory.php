<?php
declare(strict_types=1);

namespace Pebble\Operation;

enum ErrorCategory: string
{
    case Validation = 'validation';
    case Configuration = 'configuration';
    case Storage = 'storage';
    case Transport = 'transport';
    case Remote = 'remote';
    case Unexpected = 'unexpected';
}
