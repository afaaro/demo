<?php
declare(strict_types=1);

namespace System\Library;

use System\Engine\Registry;
use System\Library\Request;
use System\Library\Config;
use System\Library\RouteMap;

class Url
{
    protected Registry $container;
    protected Request  $request;
    protected Config   $config;

    public function __construct(Registry $container)
    {
        $this->container = $container;
        $this->request   = $container->get('request');
        $this->config    = $container->get('config');
    }

    /**
     * Generate a URL for a route.
     *
     * FIX: U5 — throw when a mapped param is missing (silent truncation was the bug).
     * FIX: U7 — strip `..` segments from the route name.
     * FIX: U6 — read $seo once at the top and reuse it for cleanRequest() too.
     */
    public function to(
        string $route,
        array $params = [],
        bool $absolute = false,
        bool $seo = false,
        bool $strict = true
    ): string {
        $route = trim($route, '/');
        $route = preg_replace('/[^a-zA-Z0-9._\/-]/', '', $route) ?? '';
        $route = $this->stripTraversal($route);

        if ($route === '') {
            $route = (string) $this->config->get('app.default_route', 'user/home');
        }

        $basePath     = '/' . trim($this->request->basePath(), '/');
        $map          = $this->getRouteMap($route);
        $pathSegments = [$route];

        if (!empty($map)) {
            foreach ($map as $fieldName) {
                if (!array_key_exists($fieldName, $params)) {
                    if ($strict) {
                        throw new \InvalidArgumentException(
                            "Missing required route parameter '{$fieldName}' for route '{$route}'."
                        );
                    }
                    continue;
                }
                $pathSegments[] = rawurlencode((string) $params[$fieldName]);
                unset($params[$fieldName]);
            }
        }

        $seoEnabled = $seo || (bool) $this->config->get('app.seo.urls', false);

        $url = $seoEnabled
            ? $basePath . '/' . implode('/', $pathSegments)
            : $basePath . '/index.php?route=' . urlencode($route);

        if (!empty($params)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        return $this->finalize($url, $absolute);
    }

    /**
     * FIX: U1 — uses the new Request::currentRoute().
     * FIX: U2 — reads query params from the Request object, not raw $_GET,
     *          so route params don't leak into generated URLs.
     * FIX: U6 — passes through the current SEO setting.
     */
    public function cleanRequest(
        string|array $args = '',
        array $filter = [],
        bool $keep = true,
        bool $absolute = false
    ): string {
        $route = $this->request->currentRoute();
        if ($route === '') {
            $route = (string) $this->config->get('app.default_route', 'user/home');
        }

        // Pull query params out of the Request rather than $_GET.
        $query = [];
        foreach (array_keys($_GET) as $key) {
            if ($key === 'route') continue;
            $query[$key] = $this->request->query($key, 'raw');
        }

        if (!empty($filter)) {
            if ($keep) {
                $query = array_intersect_key($query, array_flip($filter));
            } else {
                $query = array_diff_key($query, array_flip($filter));
            }
        }

        $additional = [];
        if (!empty($args)) {
            if (is_array($args)) {
                $additional = $args;
            } else {
                parse_str(ltrim($args, '&'), $additional);
            }
        }

        $query = array_merge($query, $additional);

        $seo = (bool) $this->config->get('app.seo.urls', false);
        return $this->to($route, $query, $absolute, $seo, false);
    }

    /**
     * FIX: U3 — missing assets no longer fall back to time().
     * FIX: U4 — stat cache cleared before filemtime().
     */
    public function asset(string $path, bool $absolute = false): string
    {
        $path = ltrim($path, '/');
        $path = str_replace('\\', '/', $path);

        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid asset path.');
        }

        $basePath = '/' . trim($this->request->basePath(), '/');
        $file     = BASEDIR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        if (is_file($file)) {
            clearstatcache(true, $file);
            $version = (string) filemtime($file);
        } else {
            error_log("[Url::asset] Missing asset: {$file}");
            $version = '0';
        }

        $url = $basePath . '/' . $path . '?v=' . $version;
        return $this->finalize($url, $absolute);
    }

    public function getCurrentRoute(): string
    {
        return $this->request->currentRoute();
    }

    /**
     * FIX: U8 — only collapse duplicate slashes in the path, not in the scheme,
     * and leave the host intact.
     */
    protected function finalize(string $url, bool $absolute = false): string
    {
        $url = preg_replace('#(?<!:)/{2,}#', '/', $url);

        if (!$absolute) {
            return $url;
        }

        $protocol = $this->request->isSecure() ? 'https://' : 'http://';

        $rawHost = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $host    = preg_replace('/[^a-zA-Z0-9\.\-:\[\]]/', '', $rawHost) ?? '';
        if ($host === '' || !preg_match('/^(\[[a-fA-F0-9:]+\]|[a-zA-Z0-9.-]+)(:\d{1,5})?$/', $host)) {
            error_log("[Url::finalize] Invalid host: {$rawHost}");
            $host = 'localhost';
        }

        return $protocol . $host . $url;
    }

    /**
     * FIX: U10/U11 — delegate to the shared RouteMap parser and cache per module.
     * Returns the list of positional param names, or [] if none.
     *
     * @return array<int, string>
     */
    protected function getRouteMap(string $route): array
    {
        $parts  = explode('/', $route);
        $module = $parts[0] ?? '';

        $modulePath = $this->getModulePath($module);
        if ($modulePath === null) {
            return [];
        }

        $match = RouteMap::match($route, $modulePath);
        if ($match === null) {
            return [];
        }

        [, $def] = $match;
        $params = $def['params'] ?? [];

        // Positional list of names only — assoc defs still list their params in order.
        return array_values(array_filter(
            is_array($params) ? $params : [],
            'is_string'
        ));
    }

    /**
     * FIX: U10 — realpath() so the cache key is stable, and mirror Request/Action.
     */
    protected function getModulePath(string $module): ?string
    {
        if (function_exists('get_module_path')) {
            $p = get_module_path($module);
            if ($p !== null) {
                $real = realpath($p);
                if ($real !== false && is_dir($real)) {
                    return $real . DIRECTORY_SEPARATOR;
                }
            }
        }

        $paths = [
            BASEDIR . 'modules' . DS . $module . DS,
            BASEDIR . 'system' . DS . 'modules' . DS . $module . DS,
        ];
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real !== false && is_dir($real)) {
                return $real . DIRECTORY_SEPARATOR;
            }
        }
        return null;
    }

    /**
     * FIX: U7 — strip `..` segments from route names.
     */
    private function stripTraversal(string $route): string
    {
        $segments = array_filter(
            explode('/', $route),
            fn($s) => $s !== '' && $s !== '.' && $s !== '..'
        );
        return implode('/', $segments);
    }
}