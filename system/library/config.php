<?php
declare(strict_types=1);

namespace System\Library;

use InvalidArgumentException;
use RuntimeException;

final class Config
{
    /** @var array<string, array{path: string, data: array<string, mixed>}> */
    private static array $loaded = [];

    private static ?\stdClass $sentinel = null;

    private string $basePath;

    /**
     * Module directories.
     *
     * Supports:
     * modules/<module>/config/
     * system/modules/<module>/config/
     */
    protected array $modulePaths = [];

    public function __construct(?string $basePath = null, ?array $modulePaths = null)
    {
        $basePath = $basePath ?? (defined('BASEDIR') ? BASEDIR . 'system/config/' : '');
        $this->basePath = $this->normalizeBasePath($basePath);

        $root = defined('BASEDIR') ? BASEDIR : './';

        $this->modulePaths = $modulePaths ?? [
            $root . 'modules/',
            $root . 'system/modules/',
        ];


        $this->modulePaths = array_map(
            fn($path) => $this->normalizeBasePath($path),
            $this->modulePaths
        );

        if ($this->basePath !== '' && !is_dir($this->basePath)) {
            throw new RuntimeException("Config directory does not exist: {$this->basePath}");
        }
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    // =========================================================================
    // Read
    // =========================================================================

    /**
     * Retrieve a value using dot notation: 'app.debug', 'database.host'.
     * First segment is the file key; the rest is the path inside it.
     *
     * Module shortcut: modules/{module}/config/* loads as a bundle
     * (app.php at top level; ebay.php → store.ebay, etc.). When
     * system/config/{module}.php also exists, it overlays the bundle
     * so platform secrets can live in system/config without hiding
     * module ebay/amazon files.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        [$cacheKey, $subKey] = $this->resolveFilePath($key);

        if ($subKey === null) {
            return self::$loaded[$cacheKey]['data'];
        }

        $value = self::$loaded[$cacheKey]['data'];

        foreach (explode('.', $subKey) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * True when a key exists (including dot notation).
     */
    public function has(string $key): bool
    {
        self::$sentinel ??= new \stdClass();
        return $this->get($key, self::$sentinel) !== self::$sentinel;
    }

    /**
     * Return the full contents of a config file.
     *
     * @return array<string, mixed>
     */
    public function all(string $fileKey): array
    {
        [$cacheKey, ] = $this->resolveFilePath($fileKey);
        return self::$loaded[$cacheKey]['data'];
    }

    // =========================================================================
    // Write
    // =========================================================================

    /**
     * Set a value using dot notation and write to disk immediately.
     */
    public function set(string $key, mixed $value): void
    {
        if (is_object($value)) {
            throw new InvalidArgumentException(
                "Config values must be scalar or array, object given for key '{$key}'."
            );
        }

        [$cacheKey, $subKey] = $this->resolveFilePath($key);
        $ref = &self::$loaded[$cacheKey]['data'];

        if ($subKey === null) {
            if (!is_array($value)) {
                throw new InvalidArgumentException(
                    "Setting the root of '{$key}' requires an array value."
                );
            }
            $ref = $value;
        } else {
            if (!is_array($ref)) {
                $ref = [];
            }
            $segments = explode('.', $subKey);
            $last     = array_pop($segments);

            foreach ($segments as $segment) {
                if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                    $ref[$segment] = [];
                }
                $ref = &$ref[$segment];
            }
            $ref[$last] = $value;
        }

        $this->flush($cacheKey);
    }

    /**
     * Delete a key using dot notation and write to disk.
     * Returns true when the key existed and was removed.
     *
     * NOTE: empty parent arrays are preserved (not pruned).
     */
    public function delete(string $key): bool
    {
        [$cacheKey, $subKey] = $this->resolveFilePath($key);
        $ref = &self::$loaded[$cacheKey]['data'];

        if ($subKey === null) {
            $ref = [];
            $this->flush($cacheKey);
            return true;
        }

        if (!is_array($ref)) {
            return false;
        }

        $segments = explode('.', $subKey);
        $lastKey  = array_pop($segments);

        foreach ($segments as $segment) {
            if (!is_array($ref) || !array_key_exists($segment, $ref)) {
                return false;
            }
            $ref = &$ref[$segment];
        }

        if (!is_array($ref) || !array_key_exists($lastKey, $ref)) {
            return false;
        }

        unset($ref[$lastKey]);
        $this->flush($cacheKey);
        return true;
    }

    /**
     * Persist an already-loaded config file back to disk.
     * Throws if the file key was never loaded.
     */
    public function saveFile(string $fileKey): void
    {
        $cacheKey = $this->basePath . '|' . $fileKey;

        if (!isset(self::$loaded[$cacheKey])) {
            throw new RuntimeException(
                "Cannot save config file '{$fileKey}' — it was never loaded."
            );
        }

        $this->flush($cacheKey);
    }

    // =========================================================================
    // Cache
    // =========================================================================

    public function clearCache(): void
    {
        if ($this->basePath === '') {
            return;
        }

        $prefix = $this->basePath . '|';

        foreach (array_keys(self::$loaded) as $cacheKey) {
            if (str_starts_with($cacheKey, $prefix)) {
                unset(self::$loaded[$cacheKey]);
            }
        }
    }

    public static function clearAllCaches(): void
    {
        self::$loaded   = [];
        self::$sentinel = null;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Split "app.debug" into ["app", "debug"] and return the cache key + subKey.
     *
     * @return array{0: string, 1: ?string}
     */
    private function resolveFilePath(string $key): array
    {
        [$fileKey, $subKey] = array_pad(explode('.', $key, 2), 2, null);
        $cacheKey = $this->basePath . '|' . $fileKey;

        if (!isset(self::$loaded[$cacheKey])) {
            $filePath = $this->basePath . $fileKey . '.php';
            $data     = [];

            // Module bundle first (ebay/amazon/app), then system overlay.
            $bundle = $this->loadModuleBundle($fileKey);
            if ($bundle !== null) {
                $data = $bundle;
                $configDir = $this->findModuleConfigDir($fileKey);
                if ($configDir !== null) {
                    $filePath = $configDir . 'app.php';
                }
            }

            $systemPath = $this->basePath . $fileKey . '.php';
            if (is_file($systemPath)) {
                $system = include $systemPath;

                if (!is_array($system)) {
                    throw new RuntimeException(sprintf(
                        "Config file '%s' must return an array, got: %s",
                        $fileKey,
                        get_debug_type($system)
                    ));
                }

                $data = array_replace_recursive($data, $system);
                $filePath = $systemPath;
            }

            self::$loaded[$cacheKey] = ['path' => $filePath, 'data' => $data];
        }

        return [$cacheKey, $subKey];
    }

    /**
     * Get a module configuration value.
     *
     * Examples:
     * getModule('store', 'ebay.client_id')
     * getModule('store', 'app.platform_user_ids')
     * getModule('news', 'settings.per_page', 10)
     */
    public function getModule(string $module, string $key, mixed $default = null): mixed
    {
        [$cacheKey, $subKey] = $this->resolveModuleFilePath($module, $key);

        if ($subKey === null) {
            return self::$loaded[$cacheKey]['data'];
        }

        $value = self::$loaded[$cacheKey]['data'];
        foreach (explode('.', $subKey) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Find a module configuration file.
     *
     * Example: modules/store/config/ebay.php
     *
     * @return array{0: string, 1: ?string}
     */
    private function resolveModuleFilePath(string $module, string $key): array
    {
        $this->validateName($module);

        $parts   = explode('.', $key, 2);
        $fileKey = $parts[0];
        $subKey  = $parts[1] ?? null;
        $this->validateName($fileKey);

        $filePath = null;
        $moduleDirectory = null;

        foreach ($this->modulePaths as $modulePath) {
            $candidateDirectory = $modulePath . $module . DIRECTORY_SEPARATOR;
            $candidateFile = $candidateDirectory . 'config' . DIRECTORY_SEPARATOR . $fileKey . '.php';

            if (is_file($candidateFile)) {
                $moduleDirectory = $candidateDirectory;
                $filePath = $candidateFile;
                break;
            }

            if ($moduleDirectory === null && is_dir($candidateDirectory)) {
                $moduleDirectory = $candidateDirectory;
            }
        }

        if ($moduleDirectory === null) {
            $moduleDirectory = $this->modulePaths[0] . $module . DIRECTORY_SEPARATOR;
        }

        if ($filePath === null) {
            $filePath = $moduleDirectory . 'config' . DIRECTORY_SEPARATOR . $fileKey . '.php';
        }

        $cacheKey = 'module:' . $module . ':' . $fileKey;
        $this->loadFile($cacheKey, $filePath);

        return [$cacheKey, $subKey];
    }

    /**
     * Merge modules/{module}/config/*.php into one array for Config::get('{module}.*').
     * app.php keys sit at the top level; other files nest under their basename.
     *
     * @return array<string, mixed>|null
     */
    private function loadModuleBundle(string $module): ?array
    {
        $dir = $this->findModuleConfigDir($module);
        if ($dir === null) {
            return null;
        }

        $files = glob($dir . '*.php') ?: [];
        if ($files === []) {
            return null;
        }

        $data = [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                continue;
            }
            $chunk = include $file;
            if (!is_array($chunk)) {
                throw new RuntimeException(sprintf(
                    "Module config '%s' must return an array, got: %s",
                    $file,
                    get_debug_type($chunk)
                ));
            }
            if ($name === 'app') {
                foreach ($chunk as $k => $v) {
                    if (!array_key_exists($k, $data)) {
                        $data[$k] = $v;
                    }
                }
            } else {
                $data[$name] = $chunk;
            }
        }

        return $data;
    }

    private function findModuleConfigDir(string $module): ?string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $module)) {
            return null;
        }
        foreach ($this->modulePaths as $modulePath) {
            $dir = $modulePath . $module . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;
            if (is_dir($dir)) {
                return $dir;
            }
        }
        return null;
    }

    private function loadFile(string $cacheKey, string $filePath): void
    {
        if (isset(self::$loaded[$cacheKey])) {
            return;
        }

        $data = [];
        if (is_file($filePath)) {
            $included = include $filePath;
            if (!is_array($included)) {
                throw new RuntimeException(sprintf(
                    "Config file '%s' must return an array, got: %s",
                    $filePath,
                    get_debug_type($included)
                ));
            }
            $data = $included;
        }

        self::$loaded[$cacheKey] = ['path' => $filePath, 'data' => $data];
    }

    private function validateName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid config name: {$name}");
        }
    }

    /**
     * Write a config file atomically.
     */
    private function flush(string $cacheKey): void
    {
        if (!isset(self::$loaded[$cacheKey])) {
            return;
        }

        $filePath = self::$loaded[$cacheKey]['path'];
        $exported = $this->prettyExport(self::$loaded[$cacheKey]['data']);
        $phpCode  = "<?php\n\nreturn " . $exported . ";\n";

        $dir = dirname($filePath);

        if (!is_dir($dir)) {
            throw new RuntimeException("Config directory does not exist: {$dir}");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("Config directory is not writable: {$dir}");
        }

        $tempFile = @tempnam($dir, 'cfg');

        if ($tempFile === false) {
            throw new RuntimeException("Could not create temp file in: {$dir}");
        }

        // Verify tempnam() created the file in our target directory.
        $realDir = rtrim(realpath($dir) ?: $dir, '/\\');
        $tempDir = rtrim(realpath(dirname($tempFile)) ?: dirname($tempFile), '/\\');

        if ($tempDir !== $realDir) {
            @unlink($tempFile);
            throw new RuntimeException(
                "tempnam() created a file outside the expected directory: {$dir}"
            );
        }

        if (file_put_contents($tempFile, $phpCode) === false) {
            @unlink($tempFile);
            throw new RuntimeException("Failed to write config to temp file: {$filePath}");
        }

        @chmod($tempFile, 0644);

        if (!@rename($tempFile, $filePath)) {
            @unlink($tempFile);
            throw new RuntimeException("Failed to atomically replace config file: {$filePath}");
        }
    }

    private function normalizeBasePath(string $basePath): string
    {
        $basePath = trim($basePath);
        return $basePath === '' ? '' : rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Export a nested array as pretty-printed PHP.
     */
    private function prettyExport(mixed $expression, int $depth = 1): string
    {
        $indent = '    ';

        if (is_object($expression) || is_resource($expression)) {
            throw new RuntimeException(sprintf(
                'Config values must be scalar or array, got: %s',
                get_debug_type($expression)
            ));
        }

        if (!is_array($expression)) {
            return var_export($expression, true);
        }

        if ($expression === []) {
            return '[]';
        }

        $isIndexed = array_is_list($expression);
        $lines     = [];

        foreach ($expression as $key => $value) {
            $formattedKey   = $isIndexed ? '' : var_export($key, true) . ' => ';
            $formattedValue = $this->prettyExport($value, $depth + 1);
            $lines[]        = str_repeat($indent, $depth) . $formattedKey . $formattedValue;
        }

        $closing = str_repeat($indent, $depth - 1);
        return "[\n" . implode(",\n", $lines) . "\n" . $closing . "]";
    }
}