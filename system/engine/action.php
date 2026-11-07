<?php
declare(strict_types=1);

namespace System\Engine;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * HTTP router: resolve URI → Route, enforce auth/CSRF, dispatch controller action.
 *
 * Modules may expose Services\RequestBootstrap::boot($uri) for per-request setup
 * (e.g. Store tenant context) without hard-coding module logic here.
 */
class Action
{
    /** @var array<string, array<int, string>> */
    private array $controllerFiles = [];

    /** @var array<string, array> */
    private array $routeMaps = [];

    public function __construct(readonly Registry $registry) {}

    public function execute(): void
    {
        $request = $this->registry->get('request');
        $config  = $this->registry->get('config');

        $uri    = trim((string) $request->get('route', 'string', $config->get('meta.seo.default_home')), '/');
        $module = preg_replace('/[^a-zA-Z0-9_]/', '', explode('/', $uri)[0] ?? '') ?? '';

        if ($module === '') {
            throw new NotFoundException("Invalid route: '{$uri}'.");
        }

        // Core system modules stay reachable even if missing from #__modules.
        $protected = ['tools', 'user', 'search'];
        $authSvc = $this->registry->get('user');
        if (!in_array($module, $protected, true)
            && !$authSvc->can('tools.admin.module.index')
            && !$authSvc->can('*')
            && function_exists('get_enabled_module')
            && !get_enabled_module($module)) {
            throw new NotFoundException("Module '{$module}' is disabled.");
        }

        $modulePath = get_module_path($module) ?? null;
        if ($modulePath === null) {
            throw new NotFoundException("No route found for '{$uri}'.");
        }
        $realPath = realpath($modulePath);
        if ($realPath === false || !is_dir($realPath)) {
            throw new NotFoundException("Module path for '{$module}' is not a valid directory.");
        }
        $modulePath = $realPath;

        $route = $this->resolveMapped($uri, $module, $modulePath)
              ?? $this->resolveControllerFile($uri, $module, $modulePath)
              ?? $this->resolveHomeFallback($uri, $module, $modulePath);

        if ($route === null) {
            throw new NotFoundException("No route found for '{$uri}'.");
        }

        $method = $request->method();
        if (!$route->allowsMethod($method)) {
            $allowed = array_map('strtoupper', $route->methods);
            if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            throw new MethodNotAllowedException(
                "Method {$method} not allowed for '{$uri}'.",
                $allowed
            );
        }

        $this->enforceCsrf($route, $method, $uri);

        if ($route->isAdmin()) {
            defined('ADMIN_PANEL') || define('ADMIN_PANEL', true);
        } else {
            defined('ADMIN_PANEL') || define('ADMIN_PANEL', false);
        }

        $this->checkPermissions($route, $uri);
        $this->bootModuleRequest($module, $uri);

        $request->setRouteParams($route->params);
        $request->setMatchedRoute($route);

        $this->dispatch($route);
    }

    /**
     * Optional module hook: Module\{Ns}\Services\RequestBootstrap::boot($uri).
     * Only attempts load when services/RequestBootstrap.php exists (avoids autoload noise).
     */
    private function bootModuleRequest(string $module, string $uri): void
    {
        $path = get_module_path($module);
        if ($path === null) {
            return;
        }
        $file = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'RequestBootstrap.php';
        if (!is_file($file)) {
            return;
        }

        try {
            $boot = $this->registry->get('load')->module($module)->service('RequestBootstrap');
        } catch (Throwable) {
            return;
        }

        if (is_object($boot) && method_exists($boot, 'boot')) {
            $boot->boot($uri);
        }
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    private function resolveMapped(string $uri, string $module, string $modulePath): ?Route
    {
        $maps = $this->loadRouteMaps($module, $modulePath);
        if ($maps === []) {
            return null;
        }

        foreach ($maps as $pattern => $def) {
            if (!$this->matchesPrefix($uri, $pattern)) {
                continue;
            }

            $controllerPath = $def['controller']
                ?? ($this->isAdminRoute($pattern) ? "$module/admin/home" : "$module/home");

            $controllerFile = $this->controllerFilePath($modulePath, $controllerPath);
            if (!is_file($controllerFile)) {
                continue;
            }

            $params = $this->extractParams($uri, $pattern, $def['params'] ?? []);
            if ($params === null) {
                continue;
            }

            return new Route(
                module:         $module,
                url:            $controllerPath,
                pattern:        $pattern,
                class:          $this->controllerClass($controllerPath),
                file:           $controllerFile,
                action:         $this->sanitizeAction((string) ($def['action'] ?? 'index')),
                params:         $params,
                auth:           (bool) ($def['auth'] ?? false),
                permission:     $def['permission'] ?? null,
                permissions:    is_array($def['permissions'] ?? null) ? $def['permissions'] : [],
                permissionMode: (string) ($def['permission_mode'] ?? 'all'),
                group:          $def['group'] ?? null,
                groups:         is_array($def['groups'] ?? null) ? $def['groups'] : [],
                methods:        is_array($def['methods'] ?? null) ? $def['methods'] : ['GET', 'POST'],
                csrf:           (bool) ($def['csrf'] ?? true),
            );
        }

        return null;
    }

    private function resolveControllerFile(string $uri, string $module, string $modulePath): ?Route
    {
        foreach ($this->getControllerFiles($module, $modulePath) as $file) {
            $controllerRoute = $this->fileToRoute($file, $module);

            if (!$this->matchesPrefix($uri, $controllerRoute)) {
                continue;
            }

            $remaining = trim(substr($uri, strlen($controllerRoute)), '/');
            $segments  = array_values(array_filter(explode('/', $remaining), fn($s) => $s !== ''));

            $action = $this->sanitizeAction(array_shift($segments) ?? 'index');

            $params = [];
            foreach ($segments as $i => $segment) {
                $params['arg' . $i] = $this->sanitizeParam($segment);
            }

            return new Route(
                module:  $module,
                url:     $controllerRoute,
                pattern: $controllerRoute,
                class:   $this->controllerClass($controllerRoute),
                file:    $modulePath . $file,
                action:  $action,
                params:  $params,
                methods: ['GET', 'POST'],
            );
        }

        return null;
    }

    private function resolveHomeFallback(string $uri, string $module, string $modulePath): ?Route
    {
        // Exact module root only (e.g. "search", "news") — not nested paths.
        if ($uri !== $module) {
            return null;
        }

        $candidates = $this->isAdminRoute($uri)
            ? ['admin/home', 'admin/index']
            : ['home', 'index'];

        foreach ($candidates as $type) {
            $file = $modulePath . DS . 'controllers' . DS . str_replace('/', DS, $type) . '.php';
            if (!is_file($file)) {
                continue;
            }

            return new Route(
                module:  $module,
                url:     $module . '/' . $type,
                pattern: $uri,
                class:   $this->controllerClass($module . '/' . $type),
                file:    $file,
                methods: ['GET', 'POST'],
            );
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Permissions & CSRF
    // -------------------------------------------------------------------------

    private function checkPermissions(Route $route, string $currentUri): void
    {
        if (in_array($currentUri, ['user/login', 'user/register'], true)) {
            return;
        }
        if (!$route->auth) {
            return;
        }

        $auth   = $this->registry->get('auth');
        $config = $this->registry->get('config');

        if (!$auth->check()) {
            $next = $currentUri !== '' ? $currentUri : 'user/account';
            redirect_to((string) $config->get('meta.login_route', 'user/login'), ['next' => $next]);
            throw new ForbiddenException('Authentication required.');
        }

        $explicitPermission = $route->permission !== null || $route->permissions !== [];
        $explicitGroup      = $route->group !== null || $route->groups !== [];

        if ($route->permission !== null && !$auth->can($route->permission)) {
            $this->deny();
        }

        if (!empty($route->permissions)) {
            $allowed = $route->permissionMode === 'any'
                ? $auth->canAny($route->permissions)
                : $auth->canAll($route->permissions);
            if (!$allowed) {
                $this->deny();
            }
        }

        if ($route->group !== null && !$auth->hasGroup($route->group)) {
            $this->deny();
        }

        if (!empty($route->groups)) {
            $inGroup = false;
            foreach ($route->groups as $g) {
                if ($auth->hasGroup($g)) {
                    $inGroup = true;
                    break;
                }
            }
            if (!$inGroup) {
                $this->deny();
            }
        }

        // Auth-only routes: derive key to match Group editor (module.controller.action).
        if (!$explicitPermission && !$explicitGroup) {
            $derived = $this->derivePermission($route);
            if ($derived !== null && !$auth->can($derived)) {
                $this->deny();
            }
        }
    }

    /**
     * Permission key shape: {module}.{controllerPathDots}.{action}
     * e.g. store.admin.products.index
     */
    private function derivePermission(Route $route): ?string
    {
        $module = $this->permSegment($route->module);
        $action = $this->permSegment($route->action);
        if ($module === '' || $action === '') {
            return null;
        }

        $path  = trim($route->url, '/');
        $parts = array_values(array_filter(explode('/', $path), fn(string $p) => $p !== ''));

        if ($parts !== [] && $this->permSegment($parts[0]) === $module) {
            array_shift($parts);
        }

        $controllerKey = implode('.', array_map(
            fn(string $p) => $this->permSegment($p),
            $parts
        ));

        return $controllerKey !== ''
            ? "{$module}.{$controllerKey}.{$action}"
            : "{$module}.{$action}";
    }

    private function permSegment(string $value): string
    {
        $value = strtolower($value);
        return preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
    }

    private function deny(): never
    {
        throw new ForbiddenException('Forbidden');
    }

    private function enforceCsrf(Route $route, string $method, string $uri): void
    {
        $method = strtoupper($method);
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        if (!$route->csrf) {
            return;
        }

        // Signed / webhook callbacks that opt out via route map, plus legacy ebay prefixes.
        foreach (['store/ebay/'] as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return;
            }
        }

        $this->registry->get('request')->enforceCsrf();
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    private function dispatch(Route $route): void
    {
        if (!is_file($route->file)) {
            throw new NotFoundException("Controller file not found: {$route->file}");
        }

        if (!class_exists($route->class, false)) {
            require $route->file;
        }

        if (!class_exists($route->class)) {
            throw new RuntimeException("Controller class not found: {$route->class}");
        }

        $controller = new $route->class($this->registry);
        $method     = $this->sanitizeAction($route->action) . 'Action';

        if (!is_callable([$controller, $method])) {
            throw new NotFoundException("Action '{$method}' not found in {$route->class}");
        }

        $this->recordPageAccess($route);

        try {
            $controller->$method();
        } finally {
            $controller->complete();
        }
    }

    private function recordPageAccess(Route $route): void
    {
        try {
            $request = $this->registry->get('request');
            $method = $request->method();
            if ($method !== 'GET' && $method !== 'HEAD') {
                return;
            }

            $auth = $this->registry->get('auth');
            $db = $this->registry->get('db');

            $userId = null;
            if (is_object($auth) && method_exists($auth, 'check') && $auth->check()) {
                $userId = method_exists($auth, 'id') ? $auth->id() : null;
            }

            $path = '/';
            if (is_object($request) && method_exists($request, 'path')) {
                $path = (string) $request->path();
            } elseif (isset($_SERVER['REQUEST_URI'])) {
                $path = (string) (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
            }

            $ip = '';
            if (is_object($request) && method_exists($request, 'ip')) {
                $ip = (string) $request->ip();
            } else {
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            }

            (new \System\Services\PageAccessService($db))->record(
                $route,
                is_int($userId) ? $userId : null,
                $method,
                $path,
                $ip
            );
        } catch (Throwable) {
            // Stats must never break page delivery.
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function loadRouteMaps(string $module, string $modulePath): array
    {
        if (isset($this->routeMaps[$module])) {
            return $this->routeMaps[$module];
        }

        $mapFile = $modulePath . DS . 'helpers' . DS . 'route.php';
        if (!is_readable($mapFile)) {
            return $this->routeMaps[$module] = [];
        }

        $maps = $this->normalizeMaps(include $mapFile);
        uksort($maps, fn($a, $b) => strlen($b) <=> strlen($a));

        return $this->routeMaps[$module] = $maps;
    }

    private function normalizeMaps(array $maps): array
    {
        $out = [];

        foreach ($maps as $pattern => $def) {
            $key = trim((string) (is_int($pattern) && is_string($def) ? $def : $pattern), '/');

            if (is_string($def)) {
                $out[$key] = ['action' => $def, 'params' => []];
            } elseif (array_is_list($def)) {
                $out[$key] = ['action' => 'index', 'params' => $def];
            } else {
                $out[$key] = $def + [
                    'controller'      => null,
                    'action'          => 'index',
                    'params'          => [],
                    'auth'            => false,
                    'permission'      => null,
                    'permissions'     => [],
                    'permission_mode' => 'all',
                    'group'           => null,
                    'groups'          => [],
                    'methods'         => ['GET', 'POST'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>|null null when URI has more segments than declared params
     */
    private function extractParams(string $uri, string $pattern, array $paramNames): ?array
    {
        $remainder = trim(substr($uri, strlen($pattern)), '/');
        $segments  = array_values(array_filter(explode('/', $remainder), fn($s) => $s !== ''));

        if (count($segments) > count($paramNames)) {
            return null;
        }

        $params = [];
        foreach ($paramNames as $i => $name) {
            if (isset($segments[$i])) {
                $params[$name] = $this->sanitizeParam(
                    html_entity_decode($segments[$i], ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return $params;
    }

    private function getControllerFiles(string $module, string $modulePath): array
    {
        $cacheKey = $module . '|' . $modulePath;
        if (isset($this->controllerFiles[$cacheKey])) {
            return $this->controllerFiles[$cacheKey];
        }

        $dir = $modulePath . DS . 'controllers' . DS;
        if (!is_dir($dir)) {
            return $this->controllerFiles[$cacheKey] = [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $prefixLen = strlen($modulePath);
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), $prefixLen);
            }
        }

        usort($files, fn(string $a, string $b) => strlen($b) <=> strlen($a));

        return $this->controllerFiles[$cacheKey] = $files;
    }

    private function fileToRoute(string $file, string $module): string
    {
        $relative = preg_replace(
            '/^[\\\\\/]?controllers[\\\\\/]/',
            '',
            ltrim($file, '/\\')
        );
        $withoutExt = preg_replace('/\.php$/', '', (string) $relative);
        return $module . '/' . ltrim((string) $withoutExt, '/\\');
    }

    private function controllerClass(string $path): string
    {
        $spaced = str_replace(['_', '-', '/'], ' ', $path);
        $class  = str_replace(' ', '', ucwords($spaced));
        $class  = preg_replace('/[^A-Za-z0-9_]/', '', $class) ?? '';
        if ($class === '' || ctype_digit($class[0])) {
            $class = 'C' . $class;
        }
        return $class;
    }

    private function controllerFilePath(string $modulePath, string $controllerRoute): string
    {
        $pos      = strpos($controllerRoute, '/');
        $relative = $pos === false ? $controllerRoute : substr($controllerRoute, $pos + 1);
        return $modulePath . DS . 'controllers' . DS
             . str_replace('/', DS, $relative) . '.php';
    }

    private function matchesPrefix(string $uri, string $prefix): bool
    {
        $uri    = trim($uri, '/');
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return false;
        }
        return $uri === $prefix || str_starts_with($uri, $prefix . '/');
    }

    private function isAdminRoute(string $pattern): bool
    {
        return str_contains($pattern, '/admin/') || str_ends_with($pattern, '/admin');
    }

    private function sanitizeAction(string $input): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace('-', '_', $input));
        return ($clean !== null && $clean !== '') ? $clean : 'index';
    }

    private function sanitizeParam(string $value): string
    {
        $value = rawurldecode($value);
        $value = preg_replace('/[^a-zA-Z0-9_\-~]/', '', $value) ?? '';
        if (str_contains($value, '..')) {
            return '';
        }
        return $value;
    }
}
