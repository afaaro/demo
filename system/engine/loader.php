<?php
declare(strict_types=1);

namespace System\Engine;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use System\Library\Config;
use System\Library\Database;
use System\Library\Logger;
use System\Library\Notify;
use System\Library\Request;
use System\Library\Session;
use System\Library\Url;
use System\Library\View;
use System\Services\User;

/**
 * Resolve module / system services without hard-coding FQCNs.
 *
 * From a StoreFeeder controller:
 *
 *   $orders   = $this->load->service('OrderRepository');
 *   $channels = $this->load->service('ChannelRepository');
 *   $factory  = $this->load->service('ChannelClientFactory');
 *
 * Cross-module / system:
 *
 *   $this->load->service('storefeeder/OrderRepository');
 *   $this->load->service('system/UserSessionService');
 *   $this->load->service('news/NewsRepository');
 *
 * Nested paths map to nested namespaces:
 *
 *   $this->load->service('channels/ebay/EbayFulfillmentClient');
 *   // → Module\StoreFeeder\Services\Channels\Ebay\EbayFulfillmentClient
 *
 * Pass constructor args explicitly when auto-wiring is not enough:
 *
 *   $this->load->service('CredentialsVault', [
 *       (string) $this->config->get('storefeeder.credentials_key', ''),
 *   ]);
 */
final class Loader
{
    private const SEGMENT = '/^[A-Za-z0-9_-]+$/';

    /** @var array<string, string> module folder (lower) → PSR-4 module segment */
    private static array $moduleNsCache = [];

    /** @var array<string, true> helpers already included */
    private array $helpersLoaded = [];

    /** @var list<string> FQCNs currently being constructed (cycle guard) */
    private array $resolving = [];

    /** @var array<string, object> optional per-request shared instances */
    private array $shared = [];

    /**
     * Registry key for a constructor type (when the type is a core service).
     *
     * @var array<string, string>
     */
    private const REGISTRY_TYPES = [
        Database::class => 'db',
        Session::class  => 'session',
        Config::class   => 'config',
        Request::class  => 'request',
        View::class     => 'view',
        Url::class      => 'url',
        Notify::class   => 'notify',
        Logger::class   => 'log',
        User::class     => 'user',
        Registry::class => '', // special: inject registry itself
        self::class     => 'load',
    ];

    public function __construct(
        private Registry $registry,
        private ?string $defaultModule = null,
    ) {
        if ($this->defaultModule !== null) {
            $this->defaultModule = strtolower(trim($this->defaultModule));
            if ($this->defaultModule === '') {
                $this->defaultModule = null;
            }
        }
    }

    /**
     * Create a loader scoped to another module (without changing this instance).
     */
    public function module(string $module): self
    {
        return new self($this->registry, $module);
    }

    /**
     * Instantiate a service class.
     *
     * @param string $route Class name, "module/Class", nested "a/b/Class", or "system/Class"
     * @param array<int|string, mixed> $args Positional constructor args (skip auto-wire when non-empty)
     * @param bool $shared Cache one instance per route for this request
     */
    public function service(string $route, array $args = [], bool $shared = false): object
    {
        $route = trim(str_replace('\\', '/', $route), '/');
        if ($route === '') {
            throw new InvalidArgumentException('Empty service route.');
        }

        $cacheKey = strtolower($route) . ($args === [] ? '' : ':' . md5(serialize($args)));
        if ($shared && isset($this->shared[$cacheKey])) {
            return $this->shared[$cacheKey];
        }

        $class = $this->resolveClass($route);
        $instance = $this->make($class, $args);

        if ($shared) {
            $this->shared[$cacheKey] = $instance;
        }

        return $instance;
    }

    /**
     * Include a module helper file once (e.g. sidebar.php, route.php).
     *
     *   $this->load->helper('sidebar');
     *   $this->load->helper('storefeeder/integrations');
     *
     * @return mixed Return value of the included file (often an array)
     */
    public function helper(string $route): mixed
    {
        [$module, $parts] = $this->parseRoute($route, requireClass: false);
        if ($parts === []) {
            throw new InvalidArgumentException("Helper route needs a file name: {$route}");
        }

        $relative = implode(DIRECTORY_SEPARATOR, $parts) . '.php';
        $key = $module . ':' . strtolower($relative);
        if (isset($this->helpersLoaded[$key])) {
            return $this->helpersLoaded[$key];
        }

        $base = get_module_path($module);
        if ($base === null) {
            throw new RuntimeException("Module not found for helper: {$module}");
        }

        $file = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . $relative;
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("Helper not found: {$file}");
        }

        $url = $this->registry->has('url') ? $this->registry->get('url') : null;
        $config = $this->registry->has('config') ? $this->registry->get('config') : null;

        return $this->helpersLoaded[$key] = (static function () use ($file, $url, $config) {
            return include $file;
        })();
    }

    /**
     * Not implemented — use redirect_to() / Action routing instead.
     *
     * @param array<string, mixed> $params
     */
    public function controller(string $route, array $params = []): never
    {
        throw new RuntimeException(
            "Loader::controller() is not implemented. Use redirect_to('{$route}') "
            . 'or invoke the action through the front controller.'
        );
    }

    /**
     * Reserved for future language packs.
     */
    public function language(string $route = ''): never
    {
        throw new RuntimeException('Loader::language() is not implemented yet.');
    }

    // =========================================================================
    // Resolution
    // =========================================================================

    private function resolveClass(string $route): string
    {
        [$module, $parts] = $this->parseRoute($route, requireClass: true);
        $classShort = array_pop($parts);
        if ($classShort === null || $classShort === '') {
            throw new InvalidArgumentException("Service route needs a class name: {$route}");
        }

        $classShort = $this->studly($classShort);
        $nsParts = array_map(fn(string $p) => $this->studly($p), $parts);

        if ($module === 'system') {
            $fqcn = 'System\\Services';
            if ($nsParts !== []) {
                $fqcn .= '\\' . implode('\\', $nsParts);
            }
            $fqcn .= '\\' . $classShort;
        } else {
            $modNs = $this->moduleNamespace($module);
            $fqcn = 'Module\\' . $modNs . '\\Services';
            if ($nsParts !== []) {
                $fqcn .= '\\' . implode('\\', $nsParts);
            }
            $fqcn .= '\\' . $classShort;
        }

        if (!class_exists($fqcn)) {
            throw new RuntimeException("Service class not found for '{$route}': {$fqcn}");
        }

        return $fqcn;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function parseRoute(string $route, bool $requireClass): array
    {
        $parts = array_values(array_filter(explode('/', $route), static fn($p) => $p !== ''));
        foreach ($parts as $part) {
            if (!preg_match(self::SEGMENT, $part)) {
                throw new InvalidArgumentException("Invalid route segment '{$part}' in '{$route}'.");
            }
        }

        if ($parts === []) {
            throw new InvalidArgumentException('Empty route.');
        }

        $first = strtolower($parts[0]);

        // system/UserSessionService  or  @UserSessionService
        if ($parts[0] === '@' || $first === 'system') {
            if ($parts[0] === '@') {
                array_shift($parts);
            } elseif ($first === 'system') {
                array_shift($parts);
            }
            if ($parts === [] && $requireClass) {
                throw new InvalidArgumentException('System service route needs a class name.');
            }
            return ['system', $parts];
        }

        // Explicit module: storefeeder/OrderRepository
        if (count($parts) >= 2 && get_module_path($parts[0]) !== null) {
            $module = array_shift($parts);
            return [strtolower((string) $module), $parts];
        }

        // Relative to default module (controller's module)
        if ($this->defaultModule !== null) {
            return [$this->defaultModule, $parts];
        }

        throw new InvalidArgumentException(
            "Ambiguous service route '{$route}'. Prefix with the module "
            . "(e.g. storefeeder/OrderRepository) or call from a controller."
        );
    }

    private function moduleNamespace(string $module): string
    {
        $key = strtolower($module);
        if (isset(self::$moduleNsCache[$key])) {
            return self::$moduleNsCache[$key];
        }

        $path = get_module_path($module);
        if ($path === null) {
            throw new RuntimeException("Module not found: {$module}");
        }

        $servicesDir = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'services';
        if (is_dir($servicesDir)) {
            foreach (scandir($servicesDir) ?: [] as $file) {
                if (!str_ends_with(strtolower($file), '.php')) {
                    continue;
                }
                $src = @file_get_contents($servicesDir . DIRECTORY_SEPARATOR . $file);
                if (!is_string($src)) {
                    continue;
                }
                if (preg_match('/namespace\s+Module\\\\([A-Za-z0-9_]+)\\\\Services\b/', $src, $m)) {
                    return self::$moduleNsCache[$key] = $m[1];
                }
            }
        }

        // Fallback: storefeeder → Storefeeder (may not match StoreFeeder)
        return self::$moduleNsCache[$key] = $this->studly($module);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function make(string $class, array $args = []): object
    {
        if (in_array($class, $this->resolving, true)) {
            throw new RuntimeException('Circular service dependency: ' . implode(' → ', [...$this->resolving, $class]));
        }

        $this->resolving[] = $class;
        try {
            // StoreFeeder vault: HKDF per tenant from master key.
            if ($args === [] && str_ends_with($class, '\\CredentialsVault')) {
                $key = '';
                if ($this->registry->has('config')) {
                    $key = (string) $this->registry->get('config')->get('storefeeder.credentials_key', '');
                }
                if (method_exists($class, 'forTenant')) {
                    return $class::forTenant($key !== '' ? $key : null);
                }
                return new $class($key !== '' ? $key : null);
            }

            if ($args !== []) {
                return new $class(...array_values($args));
            }

            $ref = new ReflectionClass($class);
            $ctor = $ref->getConstructor();
            if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
                return new $class();
            }

            $params = [];
            foreach ($ctor->getParameters() as $param) {
                $params[] = $this->resolveParameter($param);
            }

            return $ref->newInstanceArgs($params);
        } finally {
            array_pop($this->resolving);
        }
    }

    private function resolveParameter(ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            if ($typeName === Registry::class) {
                return $this->registry;
            }

            if (isset(self::REGISTRY_TYPES[$typeName])) {
                $key = self::REGISTRY_TYPES[$typeName];
                if ($key === '') {
                    return $this->registry;
                }
                if ($key === 'load') {
                    return $this;
                }
                if ($this->registry->has($key)) {
                    return $this->registry->get($key);
                }
            }

            // Auto-wire other Module\* / System\Services\* classes.
            if (
                str_starts_with($typeName, 'Module\\')
                || str_starts_with($typeName, 'System\\Services\\')
            ) {
                if (class_exists($typeName)) {
                    return $this->make($typeName);
                }
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return null;
        }

        $hint = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
        throw new RuntimeException(
            "Cannot auto-wire \${$param->getName()} ({$hint}) for "
            . ($param->getDeclaringClass()?->getName() ?? 'service')
            . '. Pass constructor args to load->service().'
        );
    }

    private function studly(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        return str_replace(' ', '', ucwords($value));
    }
}
