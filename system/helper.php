<?php 
// /system/helper.php
function getRootDirectory(int $maxLevels = 7): string
{
    static $config_path = '';
    if ($config_path === '') {
        $basedir = "";
        $i = 0;
        while ($i <= $maxLevels and !file_exists($basedir . "config.php")) {
            $basedir .= "../";
            $i++;
        }
        $config_path = file_exists($basedir . "config.php") ? $basedir . "config.php" : '';
    }

    return $config_path;
}

function get_modules(?string $module = null): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ([BASEDIR . 'system/modules', BASEDIR . 'modules'] as $path) {
            if (!is_dir($path)) continue;
            foreach (scandir($path) ?: [] as $folder) {
                if ($folder === '.' || $folder === '..') continue;
                $full = $path . DIRECTORY_SEPARATOR . $folder;
                if (!is_dir($full)) continue;
                $key = strtolower($folder);
                $cache[$key] ??= rtrim($full, '/\\') . DIRECTORY_SEPARATOR;
            }
        }
    }

    if ($module === null) {
        return $cache;
    }

    if (isset($cache[$module])) {
        return [$module => $cache[$module]];
    }

    // Case-insensitive fallback (Linux-hosted CMS with mixed-case dirs).
    foreach ($cache as $name => $path) {
        if (strcasecmp($name, $module) === 0) {
            return [$name => $path];
        }
    }

    return [];
}

function get_module_path(string $module): ?string
{
    $modules = get_modules($module);
    // Return the FIRST match (case-insensitive fallback may include the real name).
    return $modules ? reset($modules) : null;
}

function get_enabled_module(string $name): bool
{
    $name = strtolower(trim($name));
    if ($name === '' || !preg_match('/^[a-z0-9_-]+$/', $name)) {
        return false;
    }

    static $cache = null;
    if ($cache === null) {
        // Load all enabled modules once
        $rows = registry('db')->query("SELECT name FROM #__modules WHERE enabled = 1")->rows;
        $cache = array_column($rows, 'name');
    }
    if (in_array($name, $cache, true)) {
        return true;
    }

    // System modules (tools/user/search/comments/…) are always considered enabled when present on disk.
    $systemPath = (defined('BASEDIR') ? BASEDIR : '') . 'system/modules/' . $name;
    return is_dir($systemPath);
}

function helper_debug_enabled(): bool
{
    if (defined('DEBUG') && DEBUG) {
        return true;
    }
    try {
        $config = registry('config');
        return (bool) $config->get('error.debug', false)
            || (bool) $config->get('app.debug', false);
    } catch (\Throwable) {
        return false;
    }
}

function debug(mixed ...$args): void
{
    if (!helper_debug_enabled()) {
        return;
    }

    $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $called = $trace[1] ?? $trace[0] ?? [];

    $style = implode(';', [
        'background:#f5f5f5',
        'border:1px solid #ccc',
        'border-left:4px solid #f0ad4e',
        'padding:10px 14px',
        'margin:8px 0',
        'font-family:monospace',
        'font-size:13px',
        'white-space:pre-wrap',
        'word-break:break-all',
    ]);

    $location = htmlspecialchars(
        ($called['file'] ?? 'unknown') . ' @ line ' . ($called['line'] ?? '?'),
        ENT_QUOTES,
        'UTF-8'
    );

    echo "<pre style='{$style}'>";
    echo "<small style='color:#888;display:block;margin-bottom:6px'>{$location}</small>";

    $multi = count($args) > 1;

    foreach ($args as $i => $arg) {
        if ($multi) {
            echo "<strong style='color:#c0392b'>arg[{$i}]</strong>\n";
        }
        echo htmlspecialchars(
            print_r($arg, true),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        echo "\n";
    }

    echo "</pre>\n";
}

function dd(mixed ...$args): never
{
    if (!helper_debug_enabled()) {
        exit(1);
    }
    debug(...$args);
    exit(1);
}

function registry(?string $name = null, mixed $value = null): mixed
{
    $r = System\Engine\Registry::getInstance();

    if ($name === null) {
        return $r;
    }

    if (func_num_args() === 1) {
        return $r->get($name);
    }

    $r->set($name, $value);
    return $value;
}

function url(?string $route = null, array $params = [], bool $absolute = false, bool $seo = false): string
{
    return registry('url')->to($route, $params, $absolute, $seo);
}

/**
 * Console command registry.
 *
 * Usage:
 *   // Inside bin/console.php:
 *   console()->load();                 // scans modules/ and system/modules/
 *   $handler = console()->get('sf:sync-orders');
 *   $handler($registry, $args);
 *
 *   // Or more concise:
 *   $exit = console()->run($command, $args, $registry);
 */
function console(): \System\Engine\Console
{
    static $instance = null;
    return $instance ??= new \System\Engine\Console();
}

if (!function_exists('load_class')) {
    /**
     * Legacy module/system class loader. Prefer System\Engine\Loader in new code.
     * Call sites must not pass user-controlled $name / $module / $className.
     */
    function load_class(
        string $name,
        string $type = 'library',
        string $module = '',
        ?string $className = null
    ): object {
        $registry = System\Engine\Registry::getInstance();

        $studly = static function (string $value): string {
            return str_replace(
                ' ',
                '',
                ucwords(str_replace(['-', '_'], ' ', $value))
            );
        };

        $type = strtolower(trim($type));

        /*
         * Validate and convert a relative class path (no ".", "..", empty segments).
         */
        $validatePath = static function (
            string $name,
            string $type
        ): array {
            $name = trim(str_replace('\\', '/', $name), '/');

            if ($name === '' || !preg_match('/^[a-zA-Z0-9_\/-]+$/', $name)) {
                throw new InvalidArgumentException(
                    'Invalid module ' . $type . ' path.'
                );
            }

            $parts = explode('/', $name);
            if ($parts === []) {
                throw new InvalidArgumentException(
                    'Invalid module ' . $type . ' path.'
                );
            }

            foreach ($parts as $part) {
                if ($part === '' || $part === '.' || $part === '..'
                    || !preg_match('/^[a-zA-Z0-9_-]+$/', $part)
                ) {
                    throw new InvalidArgumentException(
                        'Invalid module ' . $type . ' path.'
                    );
                }
            }

            return $parts;
        };

        /**
         * Ensure $file resolves under $baseDir (blocks symlink escape).
         */
        $assertInside = static function (string $file, string $baseDir): string {
            $realBase = realpath($baseDir);
            if ($realBase === false || !is_dir($realBase)) {
                throw new RuntimeException('Invalid base directory.');
            }
            if (!is_file($file)) {
                throw new RuntimeException('File not found: ' . $file);
            }
            $realFile = realpath($file);
            if ($realFile === false || !is_readable($realFile)) {
                throw new RuntimeException('File not readable: ' . $file);
            }
            $prefix = $realBase . DIRECTORY_SEPARATOR;
            if (!str_starts_with($realFile, $prefix)) {
                throw new RuntimeException('Path escapes module directory.');
            }
            return $realFile;
        };

        /**
         * After require, only instantiate a class defined by that file.
         */
        $instantiateFromFile = static function (string $file, string $class, string $type): object {
            require_once $file;

            if (!class_exists($class, false)) {
                throw new RuntimeException(
                    ucfirst($type) . ' class not found: ' . $class
                );
            }

            $ref = new ReflectionClass($class);
            if ($ref->isInternal()) {
                throw new RuntimeException(
                    'Refusing to instantiate internal class via load_class.'
                );
            }
            $declared = $ref->getFileName();
            if ($declared === false
                || realpath($declared) !== realpath($file)
            ) {
                throw new RuntimeException(
                    'Class "' . $class . '" is not defined by the loaded file.'
                );
            }
            if ($ref->getConstructor() !== null
                && $ref->getConstructor()->getNumberOfRequiredParameters() > 0
            ) {
                throw new RuntimeException(
                    'Class "' . $class . '" requires constructor arguments; use Loader instead.'
                );
            }

            return $ref->newInstance();
        };

        /*
         * System library or engine.
         */
        if (in_array($type, ['library', 'engine'], true) && $module === '') {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
                throw new InvalidArgumentException(
                    'Invalid class name.'
                );
            }

            $class = $type === 'library'
                ? 'System\\Library\\' . $studly($name)
                : 'System\\Engine\\' . $studly($name);

            $key = $type . '.' . strtolower($name);

            if (!$registry->has($key)) {
                $registry->singleton(
                    $key,
                    static function () use ($class, $type) {
                        if (!class_exists($class)) {
                            throw new RuntimeException(
                                'Class not found: ' . $class
                            );
                        }
                        $ref = new ReflectionClass($class);
                        if ($ref->isInternal()) {
                            throw new RuntimeException(
                                'Refusing to instantiate internal class via load_class.'
                            );
                        }
                        $expectedNs = $type === 'library' ? 'System\\Library\\' : 'System\\Engine\\';
                        if (!str_starts_with($ref->getName(), $expectedNs)) {
                            throw new RuntimeException(
                                'Class namespace mismatch for load_class.'
                            );
                        }
                        if ($ref->getConstructor() !== null
                            && $ref->getConstructor()->getNumberOfRequiredParameters() > 0
                        ) {
                            throw new RuntimeException(
                                'Class "' . $class . '" requires constructor arguments; use registry/Loader.'
                            );
                        }
                        return $ref->newInstance();
                    }
                );
            }

            return $registry->get($key);
        }

        /*
         * Module library, model or controller.
         */
        if (in_array($type, ['library', 'model', 'controller'], true)) {

            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $module)) {
                throw new InvalidArgumentException(
                    'Invalid module name.'
                );
            }

            $modulePath = get_module_path($module);

            if ($modulePath === null) {
                throw new RuntimeException(
                    'Module not found: ' . $module
                );
            }

            $parts = $validatePath($name, $type);

            $directory = match ($type) {
                'library'    => 'libraries',
                'model'      => 'models',
                'controller' => 'controllers',
            };

            $baseDir = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . $directory;
            $file = $baseDir
                . DIRECTORY_SEPARATOR
                . implode(DIRECTORY_SEPARATOR, $parts)
                . '.php';

            $classParts = array_merge([$module], $parts);

            $class = $className ?? implode(
                '',
                array_map($studly, $classParts)
            );

            // Local class name only — no namespaces / no leading backslash.
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $class)) {
                throw new InvalidArgumentException(
                    'Invalid ' . $type . ' class name.'
                );
            }

            $key = $type . '.'
                . strtolower($module)
                . '.'
                . implode('/', $parts)
                . '.'
                . $class;

            $resolver = static function () use ($file, $baseDir, $class, $type, $assertInside, $instantiateFromFile): object {
                $realFile = $assertInside($file, $baseDir);
                return $instantiateFromFile($realFile, $class, $type);
            };

            if ($type === 'library') {
                if (!$registry->has($key)) {
                    $registry->singleton($key, $resolver);
                }
            } elseif (!$registry->has($key)) {
                $registry->factory($key, $resolver);
            }

            return $registry->get($key);
        }

        throw new InvalidArgumentException(
            'Unsupported class type: ' . $type
        );
    }
}

function random_string(int $length = 10, string $extra = '-_&$%^'): string
{
    if ($length < 1) {
        throw new InvalidArgumentException(
            'The length must be greater than zero.'
        );
    }

    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $all = $letters . '0123456789' . $extra;

    $out = [];

    for ($i = 0; $i < $length; $i++) {
        $pool = ($i === 0 || $i === $length - 1)
            ? $letters
            : $all;

        $out[] = $pool[random_int(0, strlen($pool) - 1)];
    }

    return implode('', $out);
}

function route_url(string $route, array $params = [], bool $absolute = false, bool $seo = false): string {
    $url = registry('url')->to($route, $params, $absolute, $seo);
    return $url;
}

function redirect_to(string $route = '', array $params = []): never {
    $target = route_url($route, $params);
    // route_url() builds internal paths only; still strip CR/LF from Location.
    $target = str_replace(["\r", "\n"], '', $target);
    if (!headers_sent()) {
        header('Location: ' . $target);
    }
    exit;
}

/**
 * Redirect to an external URL (OAuth providers, etc.).
 * Only allows a whitelist of trusted hosts.
 */
function redirect_external(string $url, int $code = 302): never
{
    static $allowedHosts = [
        'auth.ebay.com',
        'auth.sandbox.ebay.com',
        'signin.ebay.com',
        'signin.sandbox.ebay.com',
        'accounts.shopify.com',
        'www.shopify.com',
    ];

    $parsed = parse_url($url);

    if ($parsed === false
        || !isset($parsed['scheme'], $parsed['host'])
        || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)
        || !in_array(strtolower($parsed['host']), $allowedHosts, true)
    ) {
        registry('log')->error('[redirect_external] Blocked: ' . $url);
        throw new RuntimeException('Invalid external redirect target.');
    }

    if (!headers_sent()) {
        header('Location: ' . $url, true, $code);
    }
    exit;
}

function get_base_url(): string
{
    if (isset($GLOBALS['registry'])) {
        try {
            return registry('request')->baseUrl();
        } catch (\Throwable) {
            // fall through to manual computation
        }
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        ? 'https://' : 'http://';

    $rawHost = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $rawHost) ?? '';
    if ($host === '' || !preg_match('/^(\[[a-fA-F0-9:]+\]|[a-zA-Z0-9.-]+)(:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    $basePath = $scriptName !== '' ? rtrim(dirname($scriptName), '/\\') : '';

    if ($basePath === '.' || $basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }

    if (isset($_SERVER['SERVER_PORT'])
        && (int) $_SERVER['SERVER_PORT'] !== 80
        && (int) $_SERVER['SERVER_PORT'] !== 443
        && !str_contains($host, ':')) {
        $host .= ':' . (int) $_SERVER['SERVER_PORT'];
    }

    return $protocol . $host . $basePath;
}

/**
 * Redirects the user to a new location with security hardening.
 */
function redirect(string $location, int|false $delay = false, bool $script = false, int $code = 302): void
{
    // Normalise input
    $location = trim(str_replace(['&amp;', "\r", "\n"], ['&', '', ''], htmlspecialchars_decode($location, ENT_QUOTES)));

    // Reject protocol-relative and backslash-escaped URLs
    if (str_starts_with($location, '//') || str_starts_with($location, '\\\\')) {
        $location = '/';
    } else {
        $parsed = parse_url($location);
        if ($parsed === false) {
            $location = '/';
        } elseif (isset($parsed['scheme'])) {
            $scheme = strtolower((string) $parsed['scheme']);
            if (!in_array($scheme, ['http', 'https'], true)) {
                $location = '/';
            } else {
                $targetHost  = strtolower((string) ($parsed['host'] ?? ''));
                $allowedHost = '';
                if (isset($GLOBALS['registry'])) {
                    try {
                        $allowedHost = strtolower((string) registry('config')->get('app.host', ''));
                    } catch (\Throwable) {}
                }
                if ($allowedHost === '') {
                    // No configured host → refuse absolute redirects entirely.
                    $location = '/';
                } elseif ($targetHost === '' || $targetHost !== $allowedHost) {
                    $location = '/';
                }
            }
        }
    }

    $safeAttr = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');
    $safeJs   = json_encode($location, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

    if ($delay !== false && is_numeric($delay)) {
        echo "<meta http-equiv='refresh' content='" . (int) $delay . "; url={$safeAttr}' />";
        if ($script) {
            $ms = ((int) $delay) * 1000;
            echo "<script>setTimeout(function(){window.location.href={$safeJs};}, {$ms});</script>";
        }
    } else {
        if (!$script && !headers_sent()) {
            set_status_header($code);
            header("Location: {$location}", true, $code);
        } else {
            echo "<script>window.location.href={$safeJs};</script>\n";
            echo "<noscript><meta http-equiv='refresh' content='0; url={$safeAttr}' /></noscript>";
        }
    }
    exit;
}

/**
 * Sets the HTTP status header with a clean lookup table.
 */
function set_status_header(int $code): void
{
    $status_codes = [
        200 => 'OK', 201 => 'Created', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        304 => 'Not Modified', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict',
        410 => 'Gone', 419 => 'Authentication Timeout', 422 => 'Unprocessable Entity',
        429 => 'Too Many Requests', 500 => 'Internal Server Error',
        502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
    $text     = $status_codes[$code] ?? '';

    if ($text === '') {
        // Let PHP choose the reason phrase for unknown codes.
        header("{$protocol} {$code}", true, $code);
    } else {
        header("{$protocol} {$code} {$text}", true, $code);
    }
}

function csrf_field(?string $formId = null): string
{
    return registry('request')->csrfField((string) ($formId ?? ''));
}

function old_input(string $key, mixed $default = ''): mixed
{
    return registry('form')->oldInput($key, $default);
}

/**
 * Recursively escapes a value for safe HTML output.
 */
function escape(mixed $value, string $encoding = 'UTF-8'): mixed
{
    if (is_array($value)) {
        return array_map(fn($v) => escape($v, $encoding), $value);
    }

    if (is_object($value)) {
        if (method_exists($value, '__toString')) {
            return htmlspecialchars((string) $value, ENT_QUOTES, $encoding);
        }
        $type = function_exists('get_debug_type') ? get_debug_type($value) : get_class($value);
        return '[Object ' . $type . ']';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES, $encoding);
}

function truncate(?string $text, int $maxLength = 180, string $suffix = '…', string $encoding = 'UTF-8'): string
{
    $clean = strip_tags((string) ($text ?? ''));
    // NOTE: intentionally NOT calling html_entity_decode().

    if (mb_strlen($clean, $encoding) > $maxLength) {
        $clean = rtrim(mb_substr($clean, 0, $maxLength, $encoding)) . $suffix;
    }

    return $clean;
}


#####################################################
# Site Output
#####################################################
function opentable($title = '', $class = '') {
    $html = '<div class="'.(!empty($class) ? $class . " " : "").'card mb-3">';
    if (!empty($title)) {
        $html .= '<div class="card-header"><h5 class="mb-0">' . htmlspecialchars($title) . '</h5></div>';
    }
    $html .= '<div class="card-body">';
    return $html;
}

function closetable($footer = '') {
    $html = '</div>'; // close card-body
    if ($footer !== null && $footer !== '') {
        $html .= '<div class="card-footer text-muted">'
            . htmlspecialchars((string) $footer, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
    $html .= '</div>'; // close card
    return $html;
}

function openslide($title = '', $state = "on", $id = null)
{
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($id ?: ('slide_' . uniqid()))) ?: ('slide_' . uniqid());
    $showClass = ($state === 'on') ? 'show' : '';
    $expanded = ($state === 'on') ? 'true' : 'false';
    $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');

    $html = '<div class="card mb-3">';
    if (!empty($title)) {
        $html .= '<div class="card-header" id="heading_' . $safeId . '">';
        $html .= '<h5 class="mb-0">';
        $html .= '<button class="btn btn-link text-decoration-none p-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_' . $safeId . '" aria-expanded="' . $expanded . '">';
        $html .= htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8');
        $html .= '</button>';
        $html .= '</h5>';
        $html .= '</div>';
    }
    $html .= '<div id="collapse_' . $safeId . '" class="collapse ' . $showClass . '">';
    $html .= '<div class="card-body">';
    return $html;
}

function closeslide($footer = '')
{
    $html = '</div>'; // close card-body
    if ($footer !== null && $footer !== '') {
        $html .= '<div class="card-footer text-muted">'
            . htmlspecialchars((string) $footer, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
    $html .= '</div>'; // close collapse div
    $html .= '</div>'; // close card
    return $html;
}

#####################################################
# Slug
#####################################################
function slug(?string $string = null, string $separator = '-', int $length = 60): string
{
    if (!is_string($string) || $string === '') return '';

    // Normalize entities and drop apostrophes early.
    $string = html_entity_decode($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $string = str_replace(["'", '’'], '', $string);

    // Lowercase + trim.
    $string = mb_strtolower(trim($string), 'UTF-8');

    // Transliterate accented characters via entity names.
    $string = htmlentities($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $string = preg_replace(
        '~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml|nbsp|szlig);~i',
        '$1',
        $string
    );
    $string = preg_replace('/&.+?;/', '', $string);

    // Replace runs of non-letter/non-digit with the separator.
    $string = preg_replace('~[^\pL\d]+~u', $separator, $string);

    // Collapse duplicate separators.
    $string = preg_replace('/' . preg_quote($separator, '/') . '{2,}/u', $separator, $string);

    // Truncate.
    if ($length > -1 && mb_strlen($string, 'UTF-8') > $length) {
        $string = mb_substr($string, 0, $length, 'UTF-8');
    }

    // Trim separators from both ends.
    return trim($string, $separator);
}
