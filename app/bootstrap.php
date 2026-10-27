<?php

declare(strict_types=1);

$appEnvironment = getenv('APP_ENV') ?: 'production';
$debugEnabled = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$isProduction = strtolower($appEnvironment) === 'production';

ini_set('display_errors', $debugEnabled && !$isProduction ? '1' : '0');
ini_set('display_startup_errors', $debugEnabled && !$isProduction ? '1' : '0');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $exception) use ($isProduction, $debugEnabled): void {
    error_log(sprintf('Unhandled %s in %s:%d', get_class($exception), $exception->getFile(), $exception->getLine()));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    if ($debugEnabled && !$isProduction) {
        echo '<h1>Application error</h1><pre>' .
            htmlspecialchars($exception->__toString(), ENT_QUOTES, 'UTF-8') .
            '</pre>';
        return;
    }

    echo '<h1>Application error</h1><p>An unexpected error occurred.</p>';
});

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log(sprintf('Fatal PHP error of type %d in %s:%d', $error['type'], $error['file'], $error['line']));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<h1>Application error</h1><p>An unexpected error occurred.</p>';
});

set_error_handler(static function (
    int $severity,
    string $message,
    string $file,
    int $line
): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});
