<?php
declare(strict_types=1);

namespace Pebble\Operation;

/** Explicit shared infrastructure, without a container or destination settings. */
final readonly class OperationDependencies
{
    public function __construct(
        public \Psr\Log\LoggerInterface $logger,
        public RequestContext $requestContext,
    ) {
    }
}
