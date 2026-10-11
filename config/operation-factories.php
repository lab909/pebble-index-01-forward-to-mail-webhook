<?php
declare(strict_types=1);

use Pebble\Config\Environment;

// Explicit package bindings. Return lazy factory providers; do not parse optional
// destination settings, construct clients, or perform delivery in this callback.
// See docs/EXTENSIONS.md for a complete registration example.
return static function (Environment $environment): array {
    return [];
};
