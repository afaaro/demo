<?php
declare(strict_types=1);

use System\Engine\Autoloader;
use System\Engine\Loader;
use System\Engine\Registry;
use System\Library\AdminNav;
use System\Library\Block;
use System\Library\Cache;
use System\Library\Config;
use System\Library\Database;
use System\Library\ErrorHandler;
use System\Library\Form;
use System\Library\Logger;
use System\Library\Mail;
use System\Library\MB;
use System\Library\Notify;
use System\Library\Ram;
use System\Library\Request;
use System\Library\Session;
use System\Library\Toolbar;
use System\Library\Url;
use System\Library\View;
use System\Services\AdminToolbar;
use System\Services\User;

ob_start();

define('DS', str_replace('\\', '/', DIRECTORY_SEPARATOR));
// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . DS . 'system' . DS . 'helper.php';

if (!defined('BASEDIR')) {
    define("BASEDIR", strpos(getRootDirectory(), '/') === FALSE ? '' : dirname(getRootDirectory()) . '/');
}
define('BASEURL', parse_url(get_base_url(), PHP_URL_PATH));
require_once BASEDIR . 'system' . DS . 'engine' . DS . 'autoloader.php';

$loader = new Autoloader();
$loader->addNamespace('System\\', BASEDIR . 'system');
$loader->addNamespace('Module\\', BASEDIR . 'modules');
$loader->addNamespace('Module\\Comments\\', BASEDIR . 'system' . DS . 'modules' . DS . 'comments');
$loader->register();

MB::Initialize();
Ram::clear();

$registry = Registry::getInstance();

$registry->singleton('config', static fn() => new Config(BASEDIR . 'system' . DS . 'config' . DS));
$config = $registry->get('config');

if (!defined('DEBUG')) {
    define('DEBUG', (bool) $config->get('app.debug', false));
}
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$registry->singleton('db', static fn(Registry $r) => new Database($r->get('config')->get('database')));
$registry->singleton('block', static fn(Registry $r) => new Block());
$registry->singleton('request', static fn(Registry $r) => new Request());
$registry->singleton('view', static fn(Registry $r) => new View($r));
$registry->singleton('url', static fn(Registry $r) => new Url($r));
$registry->singleton('form', static fn(Registry $r) => new Form($r));
$registry->singleton('notify', static fn(Registry $r) => new Notify($r));
$registry->singleton('cache', static function (Registry $r): Cache {
    $cfg = $r->get('config');
    if (!defined('CACHE_PREFIX')) {
        define('CACHE_PREFIX', (string) $cfg->get('cache.prefix', 'app_'));
    }
    return new Cache(
        (string) $cfg->get('cache.engine', 'file'),
        (int) $cfg->get('cache.expire', 3600)
    );
});
$registry->singleton('mail', static fn(Registry $r) => new Mail($r->get('config')));
$registry->singleton('load', static fn(Registry $r) => new Loader($r));

$session = new Session([
    'cookie_name'      => 'afaaro',
    'namespace'        => 'afaaro',
    'idle_timeout'     => 1800,
    'bind_fingerprint' => true,
    'start'            => false,
]);
$session->start(array_filter([
    'secure'   => $config->get('session.secure', null),
    'samesite' => $config->get('session.samesite', 'Lax'),
    'lifetime' => $config->get('session.lifetime', 0),
    'domain'   => $config->get('session.domain', ''),
], static fn(mixed $value): bool => $value !== null));
$registry->instance('session', $session);

User::boot($registry);

$registry->singleton('log', static fn(Registry $r) => new Logger([
    'dir'           => $r->get('config')->get('log.directory', BASEDIR . 'storage/logs'),
    'channel'       => $r->get('config')->get('log.channel', 'app'),
    'level'         => $r->get('config')->get('log.level', 'debug'),
    'max_file_size' => (int) $r->get('config')->get('log.max_file_size', 10 * 1024 * 1024),
    'keep_days'     => (int) $r->get('config')->get('log.keep_days', 30),
    'format'        => (string) $r->get('config')->get('log.format', 'plain'),
    'enabled'       => (bool) $r->get('config')->get('log.enabled', true),
]));
$registry->singleton('toolbar', static fn(Registry $r) => new Toolbar($r->get('url'), $r->get('config')));
$registry->singleton('adminNav', static fn(Registry $r) => (new AdminNav($r->get('url'), $r->get('config')))->load());
$registry->singleton('adminToolbar', static fn(Registry $r) => new AdminToolbar($r->get('toolbar')));

$registry->singleton('menu', fn() => new \System\Library\Menu());
$menu = $registry->get('menu');
foreach (glob(BASEDIR . 'modules/*/bootstrap.php') ?: [] as $file) {
    try {
        include_once $file;
    } catch (\Throwable $e) {
        error_log('Module bootstrap failed: ' . $file . ' — ' . $e->getMessage());
    }
}

ErrorHandler::register();
$log = $registry->get('log');
$log->withContext([
    'req'  => bin2hex(random_bytes(4)),
    'ip'   => $registry->get('request')->ip(),
    'user' => User::id(),
]);
if (random_int(1, 1000) === 1) {
    $log->prune();
}

if (User::canAccessAdminUiCurrent()) {
    $registry->get('adminToolbar')->populate();
}

$registry->get('view')->setTitle((string) $config->get(
    'meta.site.name',
    $config->get('meta.site_name', 'soomaaali')
));

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
        405 => static function () use ($e) {
            http_response_code(405);

            if ($e instanceof \System\Engine\MethodNotAllowedException
                && $e->allowedMethods !== []) {
                header('Allow: ' . implode(', ', $e->allowedMethods));
            }

            include BASEDIR . 'themes/default/errors/405.php';
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