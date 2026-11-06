<?php

declare(strict_types=1);
namespace System\Engine;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

// =============================================================================
// Exceptions — so the HTTP layer can map codes correctly (FIX #10, #20)
// =============================================================================

class NotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Not Found', ?Throwable $previous = null)
    {
        parent::__construct($message, 404, $previous);
    }
}

class ForbiddenException extends RuntimeException
{
    public function __construct(string $message = 'Forbidden', ?Throwable $previous = null)
    {
        parent::__construct($message, 403, $previous);
    }
}

class MethodNotAllowedException extends RuntimeException
{
    /**
     * @param array<int, string> $allowedMethods
     */
    public function __construct(
        string $message = 'Method Not Allowed',
        public readonly array $allowedMethods = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 405, $previous);
    }
}


// =============================================================================
// Route value object
// =============================================================================

final class Route {
    /**
     * @param array<string, string>          $params
     * @param array<int, string>             $permissions
     * @param array<int, string>             $groups
     * @param array<int, string>             $methods  HTTP methods allowed (FIX #new)
     */
    public function __construct(
        public readonly string  $module,
        public readonly string  $url,            // matched pattern OR controller path (FIX #3)
        public readonly string  $pattern,        // NEW: original matched pattern for permissions
        public readonly string  $class,
        public readonly string  $file,
        public readonly string  $action         = 'index',
        public readonly array   $params         = [],
        public readonly bool    $auth           = false,
        public readonly ?string $permission     = null,
        public readonly array   $permissions    = [],
        public readonly string  $permissionMode = 'all',
        public readonly ?string $group          = null,
        public readonly array   $groups         = [],
        public readonly array   $methods        = ['GET'],   // FIX #new: method awareness
        public readonly bool    $csrf           = true,
    ) {
        // FIX #15: minimal validation — a Route must be dispatchable
        if ($this->module === '' || $this->class === '' || $this->file === '') {
            throw new InvalidArgumentException('Route requires non-empty module, class and file.');
        }
        if (!in_array($this->permissionMode, ['all', 'any'], true)) {
            throw new InvalidArgumentException("Invalid permissionMode: {$this->permissionMode}");
        }
        foreach ($this->methods as $m) {
            if (!in_array(strtoupper($m), ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'], true)) {
                throw new InvalidArgumentException("Invalid HTTP method: {$m}");
            }
        }
    }

    public function isAdmin(): bool
    {
        return str_contains($this->url, '/admin/') || str_ends_with($this->url, '/admin');
    }

    public function allowsMethod(string $method): bool
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') $method = 'GET';
        return in_array($method, array_map('strtoupper', $this->methods), true);
    }
}


// =============================================================================
// Router
// =============================================================================

class Action
{
    /** @var array<string, array<int, string>> Cache of controller files, keyed by module|path (FIX #16) */
    private array $controllerFiles = [];

    /** @var array<string, array> Cache of parsed route maps, keyed by module (FIX #5) */
    private array $routeMaps = [];

    public function __construct(readonly Registry $registry) {}

    public function execute(): void
    {
        $request = $this->registry->get('request');
        $config  = $this->registry->get('config');

        // 1. Parse URI and module
        $uri    = trim((string) $request->get('route', 'string', $config->get('meta.seo.default_home')), '/');
        $module = preg_replace('/[^a-zA-Z0-9_]/', '', explode('/', $uri)[0] ?? '') ?? '';

        if ($module === '') {
            throw new NotFoundException("Invalid route: '{$uri}'.");
        }
        
        // Modules can be disabled via the module manager. Protected modules
        // (tools, user) stay reachable so the admin can always recover.
        $protected = ['tools', 'user'];
        $authSvc = $this->registry->get('user');
        if (!in_array($module, $protected, true)
            && !$authSvc->can('tools.admin.module.index')
            && !$authSvc->can('*')
            && function_exists('get_enabled_module')
            && !get_enabled_module($module)) {
            throw new NotFoundException("Module '{$module}' is disabled.");
        }

        // 2. Resolve module path (FIX #7: verify it's a real, canonical directory)
        $modulePath = get_module_path($module) ?? null;
        if ($modulePath === null) {
            throw new NotFoundException("No route found for '{$uri}'.");
        }
        $realPath = realpath($modulePath);
        if ($realPath === false || !is_dir($realPath)) {
            throw new NotFoundException("Module path for '{$module}' is not a valid directory.");
        }
        $modulePath = $realPath;

        // 3. Resolve the route
        $route = $this->resolveMapped($uri, $module, $modulePath)
              ?? $this->resolveControllerFile($uri, $module, $modulePath)
              ?? $this->resolveHomeFallback($uri, $module, $modulePath);

        if ($route === null) {
            throw new NotFoundException("No route found for '{$uri}'.");
        }

        // 4. Method check (FIX #new)
        $method = $request->method();
        if (!$route->allowsMethod($method)) {
            $allowedMethods = array_map('strtoupper', $route->methods);

            // HEAD is supported automatically when GET is allowed.
            if (in_array('GET', $allowedMethods, true)
                && !in_array('HEAD', $allowedMethods, true)) {
                $allowedMethods[] = 'HEAD';
            }

            throw new MethodNotAllowedException(
                "Method {$method} not allowed for '{$uri}'.",
                $allowedMethods
            );
        }

        // 4b. CSRF — every state-changing request unless the route opts out
        $this->enforceCsrf($route, $method, $uri);

        // 5. Admin constant
        if ($route->isAdmin()) {
            defined('ADMIN_PANEL') || define('ADMIN_PANEL', true);
        } else {
            defined('ADMIN_PANEL') || define('ADMIN_PANEL', false);
        }

        // 6. Permissions
        $this->checkPermissions($route, $uri);

        // 6b. StoreFeeder multi-tenant context (token or membership)
        if (strtolower($module) === 'storefeeder') {
            $this->resolveStoreFeederTenant($uri);
            $this->injectStoreFeederTenantBar($uri);
        }

        // 7. Store on request and dispatch
        $request->setRouteParams($route->params);
        $request->setMatchedRoute($route);

        $this->dispatch($route);
    }

    /**
     * Bind TenantContext for StoreFeeder routes. Platform tenant-manager routes
     * may run without a tenant; everything else requires a resolved active tenant.
     */
    private function resolveStoreFeederTenant(string $uri): void
    {
        $uriNorm = trim($uri, '/');
        // Public webhooks + platform tenant manager: soft-resolve only.
        $softOnly = str_starts_with($uriNorm, 'storefeeder/ebay/')
            || str_starts_with($uriNorm, 'storefeeder/admin/tenants')
            || str_starts_with($uriNorm, 'storefeeder/admin/account-deletion');
        if ($softOnly) {
            try {
                $this->makeTenantResolver()->resolve();
            } catch (\Throwable) {
                \Module\StoreFeeder\Services\TenantContext::get()->clear();
            }
            return;
        }

        $resolver = $this->makeTenantResolver();
        try {
            $result = $resolver->resolve();
        } catch (\Throwable $e) {
            throw new ForbiddenException('Tenant resolution failed: ' . $e->getMessage());
        }

        $reason = (string) ($result['reason'] ?? '');
        // Hard failures — never fall back to platform home for these.
        if (empty($result['ok']) && in_array($reason, [
            'invalid_token', 'tenant_suspended', 'unauthenticated', 'no_membership',
        ], true)) {
            $msg = match ($reason) {
                'invalid_token'    => 'Invalid or revoked tenant token.',
                'tenant_suspended' => 'This tenant is suspended.',
                'no_membership'    => 'Your account is not assigned to a StoreFeeder tenant.',
                default            => 'Authentication required.',
            };
            throw new ForbiddenException($msg);
        }

        // Platform only: recover empty context onto platform home.
        if (empty($result['ok']) || !\Module\StoreFeeder\Services\TenantContext::get()->has()) {
            if ($resolver->isPlatformOwner()) {
                try {
                    $result = $resolver->forceDefaultContext();
                } catch (\Throwable $e) {
                    throw new ForbiddenException('Tenant resolution failed: ' . $e->getMessage());
                }
            }
        }

        if (empty($result['ok']) || !\Module\StoreFeeder\Services\TenantContext::get()->has()) {
            $reason = (string) ($result['reason'] ?? 'forbidden');
            $msg = match ($reason) {
                'no_membership' => 'Your account is not assigned to a StoreFeeder tenant.',
                'no_tenant'     => 'Default StoreFeeder tenant is missing.',
                default         => 'Your account is not assigned to a StoreFeeder tenant.',
            };
            throw new ForbiddenException($msg);
        }
    }

    private function injectStoreFeederTenantBar(string $uri): void
    {
        if (!defined('ADMIN_PANEL') || !ADMIN_PANEL) {
            return;
        }
        try {
            if (!\Module\StoreFeeder\Services\TenantContext::get()->has()) {
                return;
            }
            $db = $this->registry->get('db');
            $tenants = new \Module\StoreFeeder\Services\TenantRepository($db);
            $resolver = $this->makeTenantResolver();
            $request = $this->registry->get('request');
            // Manage?id=N enters that tenant before the top bar is drawn.
            $uriNorm = trim($uri, '/');
            if ($resolver->isPlatformOwner() && $uriNorm === 'storefeeder/admin/tenants') {
                $manageId = (int) $request->get('id', 'int', 0);
                if ($manageId > 0) {
                    $resolver->selectTenantForUser($manageId);
                }
            }
            $html = \Module\StoreFeeder\Services\TenantBar::render(
                $this->registry->get('view'),
                $resolver,
                $request,
                $tenants,
                $uri,
            );
            if ($html !== '') {
                $this->registry->get('view')->block('admin_topbar', $html);
            }
        } catch (\Throwable) {
            // Never block admin pages on a chrome widget.
        }
    }

    private function makeTenantResolver(): \Module\StoreFeeder\Services\TenantResolver
    {
        $db = $this->registry->get('db');
        $tenants = new \Module\StoreFeeder\Services\TenantRepository($db);
        $tokens = new \Module\StoreFeeder\Services\TenantTokenRepository($db);
        return new \Module\StoreFeeder\Services\TenantResolver(
            $db,
            $this->registry->get('request'),
            $this->registry->get('session'),
            $this->registry->get('auth'),
            $tenants,
            $tokens,
        );
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

            // FIX #1: reject over-binding — segment count must match declared params
            $params = $this->extractParams($uri, $pattern, $def['params'] ?? []);
            if ($params === null) {
                continue; // too many segments for this pattern
            }

            return new Route(
                module:         $module,
                url:            $controllerPath,
                pattern:        $pattern,                 // FIX #3
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
                methods:        is_array($def['methods'] ?? null) ? $def['methods'] : ['GET','POST'],
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
                pattern: $controllerRoute,                // FIX #3
                class:   $this->controllerClass($controllerRoute),
                file:    $modulePath . $file,
                action:  $action,
                params:  $params,
                methods: ['GET','POST'],
            );
        }

        return null;
    }

    private function resolveHomeFallback(string $uri, string $module, string $modulePath): ?Route
    {
        $type = $this->isAdminRoute($uri) ? 'admin/home' : 'home';
        $file = $modulePath . DS . 'controllers' . DS . str_replace('/', DS, $type) . '.php';

        if (!is_file($file)) {
            return null;
        }

        return new Route(
            module:  $module,
            url:     $module . '/' . $type,
            pattern: $uri,                                // FIX #3: preserve original URI
            class:   $this->controllerClass($module . '/' . $type),
            file:    $file,
            methods: ['GET','POST'],
        );
    }

    // -------------------------------------------------------------------------
    // Permission checking
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

        // Single declared permission key.
        if ($route->permission !== null && !$auth->can($route->permission)) {
            $this->deny();
        }

        // permission_mode applies to the permissions[] list only (not groups).
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

        // groups[] is always OR (member of any listed group).
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

        // Auth-only routes: sole permission gate for the app.
        // Keys match the Group editor (module.controller.action).
        // Controllers must not re-check hasGroup / canModule / *.view.
        if (!$explicitPermission && !$explicitGroup) {
            $derived = $this->derivePermission($route);
            if ($derived !== null && !$auth->can($derived)) {
                $this->deny();
            }
        }
    }

    /**
     * Build the permission key to match UserAdminGroups::availablePermissions:
     *   {module}.{controllerPathDots}.{action}
     * e.g. storefeeder.admin.products.index
     */
    private function derivePermission(Route $route): ?string
    {
        $module = $this->permSegment($route->module);
        $action = $this->permSegment($route->action);
        if ($module === '' || $action === '') {
            return null;
        }

        // Prefer controller path ($route->url), not the URL pattern — patterns
        // can include hyphenated segments that do not match the group UI keys.
        $path  = trim($route->url, '/');
        $parts = array_values(array_filter(explode('/', $path), fn(string $p) => $p !== ''));

        if ($parts !== [] && $this->permSegment($parts[0]) === $module) {
            array_shift($parts);
        }

        $controllerKey = implode('.', array_map(
            fn(string $p) => $this->permSegment($p),
            $parts
        ));

        $key = $controllerKey !== ''
            ? "{$module}.{$controllerKey}.{$action}"
            : "{$module}.{$action}";

        return $key;
    }

    private function permSegment(string $value): string
    {
        // Match groups UI keys: lowercase, keep [a-z0-9_], drop other chars.
        // Do not turn _/- into dots — that diverged from availablePermissions().
        $value = strtolower($value);
        return preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
    }

    private function deny(): never
    {
        throw new ForbiddenException('Forbidden');   // FIX #10
    }

    /**
     * Reject state-changing requests that do not carry a valid CSRF token.
     * Routes can opt out with `'csrf' => false` (webhooks / signed callbacks).
     */
    private function enforceCsrf(Route $route, string $method, string $uri): void
    {
        $method = strtoupper($method);
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        if (!$route->csrf) {
            return;
        }

        foreach (['storefeeder/ebay/'] as $prefix) {
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

        // FIX #9: require (not require_once) so a controller re-included in tests works.
        // We still guard class_exists to avoid redeclaration fatals.
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

    /**
     * Best-effort page view tracking (GET/HEAD only). Never blocks the request.
     */
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

    /**
     * FIX #5: parse + cache route maps per module so we don't re-include the file.
     */
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

        // Longest pattern first so 'news/view' wins over 'news'
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
                    'methods'         => ['GET','POST'],
                ];
            }
        }

        return $out;
    }

    /**
     * FIX #1: return null when the URI has more segments than declared params,
     * so the caller can try a more specific pattern instead of silently mis-binding.
     *
     * @return array<string, string>|null
     */
    private function extractParams(string $uri, string $pattern, array $paramNames): ?array
    {
        $remainder = trim(substr($uri, strlen($pattern)), '/');
        $segments  = array_values(array_filter(explode('/', $remainder), fn($s) => $s !== ''));

        if (count($segments) > count($paramNames)) {
            return null; // over-bound
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
        // FIX #16: key by module + path
        $cacheKey = $module . '|' . $modulePath;
        if (isset($this->controllerFiles[$cacheKey])) {
            return $this->controllerFiles[$cacheKey];
        }

        $dir = $modulePath . DS . 'controllers' . DS;
        if (!is_dir($dir)) {
            return $this->controllerFiles[$cacheKey] = [];
        }

        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $prefixLen = strlen($modulePath);
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // FIX #18: substr instead of str_replace
                $files[] = substr($file->getPathname(), $prefixLen);
            }
        }

        usort($files, fn(string $a, string $b) => strlen($b) <=> strlen($a));

        return $this->controllerFiles[$cacheKey] = $files;
    }

    private function fileToRoute(string $file, string $module): string
    {
        // FIX #19: strip only the leading 'controllers/' segment
        $relative   = preg_replace(
            '/^[\\\\\/]?controllers[\\\\\/]/',
            '',
            ltrim($file, '/\\')
        );
        $withoutExt = preg_replace('/\.php$/', '', (string) $relative);
        return $module . '/' . ltrim((string) $withoutExt, '/\\');
    }

    /**
     * FIX #4 (partial): `controllerClass()` is stable, but module namespacing
     * is the caller's responsibility. We at least guarantee a valid identifier.
     */
    private function controllerClass(string $path): string
    {
        // Normalise separators to spaces, title-case, strip spaces.
        $spaced = str_replace(['_', '-', '/'], ' ', $path);
        $class  = str_replace(' ', '', ucwords($spaced));
        // Guard against leading digit / empty
        $class  = preg_replace('/[^A-Za-z0-9_]/', '', $class) ?? '';
        if ($class === '' || ctype_digit($class[0])) {
            $class = 'C' . $class;
        }
        return $class;
    }

    /**
     * FIX #2: handle controller routes without a '/' safely.
     */
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
            return false;   // FIX: empty prefix would match everything
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

    /**
     * FIX #8: strip path traversal, keep it a single safe segment.
     */
    private function sanitizeParam(string $value): string
    {
        // Decode first (defence in depth), then strip dangerous chars.
        $value = rawurldecode($value);
        $value = preg_replace('/[^a-zA-Z0-9_\-~]/', '', $value) ?? '';
        // Reject any residual traversal sequences.
        if (str_contains($value, '..')) {
            return '';
        }
        return $value;
    }
}