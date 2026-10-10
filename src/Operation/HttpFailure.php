<?php
declare(strict_types=1);

namespace Pebble\Operation;

enum HttpFailure: int
{
    case BadRequest = 400;
    case InternalServerError = 500;
    case BadGateway = 502;
}
