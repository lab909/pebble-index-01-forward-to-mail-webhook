<?php
declare(strict_types=1);

namespace Pebble\Logging;

use Pebble\Http\{ErrorResponder, Response};
use Pebble\Services\SafeLog;
use Psr\Log\LoggerInterface;
use Throwable;

/** Raw PHP error strings may contain input; bridge metadata only, without traces/args. */
final class RuntimeErrorBridge
{
    public static function install(LoggerInterface $logger, ErrorResponder $responder): void
    {
        set_error_handler(static function (int $code, string $message, string $file, int $line) use ($logger, $responder): bool {
            if (!(error_reporting() & $code)) {
                return false;
            }
            if ($code === E_USER_ERROR) {
                SafeLog::write($logger, 'critical', 'Fatal PHP error.',
                    ['event' => 'fatal_error', 'phpErrorCode' => $code, 'file' => basename($file), 'line' => $line]);
                $responder->markHandled();
                if (!headers_sent()) {
                    $responder->send(new Response(500, ['error' => 'Fatal application error.']));
                }
                exit;
            }
            SafeLog::write($logger, 'warning', 'PHP runtime error.',
                ['event' => 'php_error', 'phpErrorCode' => $code, 'file' => basename($file), 'line' => $line]);
            return $code !== E_USER_ERROR;
        });
        set_exception_handler(static function (Throwable $exception) use ($logger, $responder): void {
            SafeLog::write($logger, 'critical', 'Unhandled application exception.',
                ['event' => 'uncaught_exception', 'exceptionClass' => get_class($exception)]);
            $responder->markHandled();
            if (!headers_sent()) {
                $responder->send(new Response(500, ['error' => 'Unhandled application error.']));
            }
        });
        $reserve = str_repeat(' ', 32768);
        register_shutdown_function(static function () use ($logger, $responder, &$reserve): void {
            $reserve = null;
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            SafeLog::write($logger, 'critical', 'Fatal PHP error.', ['event' => 'fatal_error',
                'phpErrorCode' => $error['type'], 'file' => basename($error['file']), 'line' => $error['line']]);
            $responder->markHandled();
            if (!headers_sent()) {
                $responder->send(new Response(500, ['error' => 'Fatal application error.']));
            }
        });
        // The bridge owns post-bootstrap diagnostics; prevent unsanitized duplicate PHP logging.
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
    }
}
