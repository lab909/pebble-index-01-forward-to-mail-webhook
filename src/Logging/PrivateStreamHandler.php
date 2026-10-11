<?php
declare(strict_types=1);

namespace Pebble\Logging;

use Monolog\Handler\StreamHandler;

final class PrivateStreamHandler extends StreamHandler
{
    use PrivateWrite;
}
