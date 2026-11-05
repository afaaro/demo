<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'maincore.php';

$action = new System\Engine\Action($registry);
$registry->set('action', $action);

try {
    $action->execute();
} catch (RuntimeException $e) {
    $code = (int) $e->getCode();

    // Log to the app logger so it appears in the admin log viewer.
    $log->error(sprintf(
        'RuntimeException [%d]: %s in %s:%d',
        $code,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ), ['trace' => $e->getTraceAsString()]);

    $handled = match ($code) {
        403 => static function () use ($registry, $e) {
            $forbiddenMessage = trim((string) $e->getMessage());
            if ($forbiddenMessage === '' || strcasecmp($forbiddenMessage, 'Forbidden') === 0) {
                $forbiddenMessage = "You don't have permission to view this page.";
            }
            if ($registry->get('request')->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $forbiddenMessage]);
            } else {
                http_response_code(403);
                include BASEDIR . 'themes/default/errors/403.php';
            }
        },
        404 => static function () {
            http_response_code(404);
            include BASEDIR . 'themes/default/errors/404.php';
        },
        419 => static function () use ($registry) {
            // 419 is unofficial; Apache/mod_fcgid remaps it to 500.
            http_response_code(403);
            if ($registry->get('request')->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Session expired, please refresh.']);
            } else {
                include BASEDIR . 'themes/default/errors/419.php';
            }
        },
        422 => static function () {
            http_response_code(422);
            include BASEDIR . 'themes/default/errors/422.php';
        },
        429 => static function () {
            http_response_code(429);
            header('Retry-After: 60');
            include BASEDIR . 'themes/default/errors/429.php';
        },
        default => null,
    };

    if ($handled !== null) {
        $handled();
    } else {
        // Log to app logger, not error_log(), so admins can read it.
        $log->error(sprintf(
            'Application error [%d]: %s in %s:%d',
            $code,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ), ['trace' => $e->getTraceAsString()]);

        http_response_code(500);
        include BASEDIR . 'themes/default/errors/500.php';
    }

    exit;

} catch (Throwable $e) {
    // Errors, TypeErrors, PDOExceptions, ArgumentCountErrors — all land here.
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }

    // Log to app logger FIRST, before any output.
    if (isset($log) && $log !== null) {
        $log->error(sprintf(
            '%s [%d]: %s in %s:%d',
            get_class($e),
            $code,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ), ['trace' => $e->getTraceAsString()]);
    }

    // Also write to PHP's native log as a backup.
    error_log(sprintf(
        'Unhandled %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    http_response_code(500);
    include BASEDIR . 'themes/default/errors/500.php';
    exit;
}