<?php
declare(strict_types=1);

namespace System\Library;

use ErrorException;
use Throwable;

class ErrorHandler
{
    private static bool $registered = false;
    private static bool $handled    = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        error_reporting(E_ALL);

        set_error_handler([self::class, 'native']);
        set_exception_handler([self::class, 'exception']);
        register_shutdown_function([self::class, 'shutdown']);
    }

    /**
     * set_error_handler callback. Returns true when the error was handled
     * (PHP should not run its default handler), false otherwise.
     */
    public static function native(int $code, string $message, string $file, int $line): bool
    {
        // Respect @-suppression and error_reporting()
        if (!(error_reporting() & $code)) {
            return false;
        }

        $debug = self::isDebug();

        // Deprecations: always log, never throw.
        if (in_array($code, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            self::logLine($code, $message, $file, $line);
            return true;
        }

        // Notices and warnings: log in production, throw in debug.
        if (!$debug && in_array($code, [
            E_NOTICE, E_USER_NOTICE,
            E_WARNING, E_USER_WARNING,
        ], true)) {
            self::logLine($code, $message, $file, $line);
            return true;
        }

        self::exception(new ErrorException($message, 0, $code, $file, $line));
        return true; // unreachable — exception() exits
    }

    public static function exception(Throwable $exception): void
    {
        if (self::$handled) {
            return; // prevent recursion and double-logging from shutdown()
        }
        self::$handled = true;

        try {
            self::render($exception);
        } catch (Throwable $nested) {
            error_log('ErrorHandler failed: ' . $nested->getMessage());
            error_log('Original error:   ' . $exception->getMessage());
        }

        $status = (int) $exception->getCode();
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        if (!headers_sent()) {
            http_response_code(self::httpStatus($status));
        }

        // Fallback body if render() produced nothing (e.g. output buffer was empty)
        if (ob_get_length() === 0 || ob_get_length() === false) {
            echo '<!doctype html><html><body>';
            echo '<h1>500 Internal Server Error</h1>';
            echo '<p>An unexpected error occurred. Please try again later.</p>';
            echo '</body></html>';
        }

        exit(1);
    }

    public static function shutdown(): void
    {
        if (self::$handled) {
            return;
        }

        $error = error_get_last();
        if ($error === null) {
            return;
        }

        // Only fatal error types reach shutdown() — non-fatal ones go through native().
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
        if (!in_array($error['type'], $fatal, true)) {
            return;
        }

        self::exception(new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        ));
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Map unofficial codes Apache/mod_fcgid would otherwise send as 500.
     */
    private static function httpStatus(int $status): int
    {
        return $status === 419 ? 403 : $status;
    }

    /**
     * Safely resolve a config value, even if the registry isn't ready yet.
     */
    private static function configValue(string $key, mixed $default = null): mixed
    {
        try {
            $config = registry('config');
            if ($config instanceof Config) {
                return $config->get($key, $default);
            }
        } catch (Throwable) {
            // Config not registered yet (e.g. very early bootstrap fatal).
        }
        return $default;
    }

    private static function isDebug(): bool
    {
        if ((bool) self::configValue('error.debug', false)) {
            return true;
        }
        if ((bool) self::configValue('app.debug', false)) {
            return true;
        }
        return defined('DEBUG') && DEBUG;
    }

    /**
     * Log a single-line JSON record to the PHP error log.
     */
    private static function logLine(int $code, string $message, string $file, int $line): void
    {
        $record = [
            'date'     => date('Y-m-d H:i:s'),
            'severity' => self::severityName($code),
            'message'  => $message,
            'file'     => $file,
            'line'     => $line,
        ];

        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            // Fallback: strip newlines from the raw message and write it manually.
            $message = str_replace(["\r", "\n"], ' ', $message);
            error_log(sprintf(
                '[%s] [%s] %s in %s:%d',
                $record['date'],
                $record['severity'],
                $message,
                $file,
                $line
            ));
            return;
        }

        error_log($encoded);
    }

    private static function render(Throwable $exception): void
    {
        $debug   = self::isDebug();
        $display = (bool) self::configValue('error.display', $debug);
        $status  = (int) $exception->getCode();
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        self::logException($exception, $status);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
            fwrite(STDERR, $exception->getFile() . ':' . $exception->getLine() . PHP_EOL);
            if ($debug) {
                fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
            }
            return;
        }

        if (!headers_sent()) {
            http_response_code(self::httpStatus($status));
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();

        if ($debug && $display) {
            self::appendDebugLog($exception);
            $escape = static fn(string $s): string
                => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
            echo '<pre style="padding:1rem;background:#fee;color:#900;white-space:pre-wrap;">';
            echo $escape((string) $exception);
            echo '</pre>';
            return;
        }

        try {
            $view = registry('view');
            if ($view instanceof View) {
                $page = match ($status) {
                    403 => '403',
                    404 => '404',
                    419 => '419',
                    422 => '422',
                    429 => '429',
                    default => '500',
                };
                echo $view->renderPath('errors/' . $page, [
                    'message' => $status === 404 ? 'Page not found' : 'Something went wrong',
                ]);
                return;
            }
        } catch (Throwable) {
            // Fall through to the plain HTML page.
        }

        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $status . '</title></head><body>';
        echo '<h1>' . $status . '</h1>';
        echo '<p>An error occurred. Please try again later.</p>';
        echo '</body></html>';
    }

    private static function logException(Throwable $exception, int $status): void
    {
        try {
            $log = registry('log');
            if ($log instanceof Logger) {
                $log->exception($exception, $status >= 500 ? 'error' : 'warning');
                return;
            }
        } catch (Throwable) {
            // Logger not ready — write to PHP's error log.
        }

        $record = [
            'date'    => date('Y-m-d H:i:s'),
            'type'    => get_class($exception),
            'message' => $exception->getMessage(),
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
            'trace'   => $exception->getTraceAsString(),
        ];
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log($encoded === false ? '{"error":"json_encode failed"}' : $encoded);
    }

    private static function appendDebugLog(Throwable $exception): void
    {
        $logPath = (defined('BASEDIR') ? rtrim((string) BASEDIR, '/\\') . DIRECTORY_SEPARATOR : '')
            . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'errors.log';
        $dir     = dirname($logPath);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            error_log('ErrorHandler: cannot create log directory ' . $dir);
            return;
        }

        $payload = [
            'date'    => date('Y-m-d H:i:s'),
            'type'    => get_class($exception),
            'message' => $exception->getMessage(),
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            error_log('ErrorHandler: json_encode failed for debug log payload');
            return;
        }

        if (@file_put_contents($logPath, $encoded . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('ErrorHandler: cannot write to ' . $logPath);
        }
    }

    private static function isAdminSafe(): bool
    {
        try {
            return \System\Services\User::isAdminCurrent();
        } catch (Throwable $e) {
            error_log('ErrorHandler: User::isAdminCurrent() failed: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * Trim the project base directory from an absolute file path so
     * debug output doesn't leak the server's directory layout.
     */
    private static function shortFile(string $file): string
    {
        if (!defined('BASEDIR') || BASEDIR === '') {
            return $file;
        }

        $base = str_replace('\\', '/', BASEDIR);
        $path = str_replace('\\', '/', $file);

        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $file;
    }

    private static function severityName(int $code): string
    {
        return match ($code) {
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_PARSE             => 'E_PARSE',
            E_NOTICE            => 'E_NOTICE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_CORE_WARNING      => 'E_CORE_WARNING',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
            default             => 'E_' . $code,
        };
    }
}