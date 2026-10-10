<?php
declare(strict_types=1);

namespace Pebble\Operation;

/** Message and diagnostics must be sanitized by the adapter before construction. */
final readonly class ValidationFailure
{
    public function __construct(
        public string $message,
        public HttpFailure $httpFailure = HttpFailure::BadRequest,
        public ErrorCategory $category = ErrorCategory::Validation,
        public array $diagnostics = [],
    ) {
    }
}
