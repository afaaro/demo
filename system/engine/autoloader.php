<?php
declare(strict_types=1);

namespace System\Engine;

class Autoloader
{
    /** @var array<string, string[]> PSR-4 prefix → base dirs (trailing slash each) */
    private array $namespaces = [];
    /** @var string[] Dirs for underscore/flat/CamelCase/snake_case lookup */
    private array $directories = [];
    /** @var array<string, string> Explicit class → file, checked first */
    private array $classMap = [];
    /** @var string[] */
    private array $extensions = ['.php'];
    /** @var array<string, true> Negative cache — classes already known to be missing */
    private array $missed = [];

    // =========================================================================
    // Registration
    // =========================================================================

    public function register(bool $prepend = false): void
    {
        spl_autoload_register([$this, 'load'], true, $prepend);
    }

    // =========================================================================
    // Configuration
    // =========================================================================

    public function addNamespace(string $prefix, string $baseDir, bool $prepend = false): void
    {
        $prefix  = trim($prefix, '\\') . '\\';
        $baseDir = $this->normalizePath($baseDir);

        if ($prepend) {
            array_unshift($this->namespaces[$prefix], $baseDir);
        } else {
            $this->namespaces[$prefix][] = $baseDir;
        }
        krsort($this->namespaces); // Longest prefix first
    }

    public function addDirectory(string $dir, bool $prepend = false): void
    {
        $dir = $this->normalizePath($dir);
        if (in_array($dir, $this->directories, true)) {
            return;
        }
        $prepend ? array_unshift($this->directories, $dir) : $this->directories[] = $dir;
    }

    /** @param array<string, string> $map */
    public function classMap(array $map): void
    {
        foreach ($map as $class => $file) {
            $this->classMap[ltrim($class, '\\')] = $this->normalizePath($file);
        }
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    public function load(string $class): string|false
    {
        $class = ltrim($class, '\\');
        if (isset($this->missed[$class])) {
            return false;
        }

        $file = $this->resolveFromClassMap($class)
            ?? $this->resolveFromNamespace($class)
            ?? $this->resolveFromUnderscore($class)
            ?? $this->resolveFromDirectories($class);

        if ($file === null) {
            error_log("[Autoloader] Miss: {$class}");
            $this->missed[$class] = true;
            return false;
        }

        error_log("[Autoloader] Hit: {$class} → {$file}");
        require_once $file;
        return $file;
    }

    // =========================================================================
    // Resolution strategies
    // =========================================================================

    private function resolveFromClassMap(string $class): ?string
    {
        $file = $this->classMap[$class] ?? null;
        return $file !== null && file_exists($file) ? $file : null;
    }

    private function resolveFromNamespace(string $class): ?string
    {
        foreach ($this->namespaces as $prefix => $baseDirs) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace(
                '\\',
                '/',
                substr($class, strlen($prefix))
            );

            $candidates = array_unique([
                $relative,
                strtolower($relative),
                $this->camelToFlat($relative),
                $this->camelToSnake($relative),
                strtolower($this->camelToSnake($relative)),
            ]);

            foreach ($baseDirs as $baseDir) {
                foreach ($candidates as $candidate) {
                    foreach ($this->extensions as $extension) {
                        $file = $baseDir . $candidate . $extension;

                        if (is_file($file)) {
                            return $file;
                        }
                    }
                }
            }
        }

        return null;
    }
    
    /**
     * Lowercase all directory segments but keep the filename's CamelCase.
     * Services/UserGroup → services/UserGroup
     */
    private function camelToFlat(string $path): string
    {
        $parts = explode('/', $path);
        $file  = array_pop($parts);
        $dirs  = array_map('strtolower', $parts);
        return implode('/', array_merge($dirs, [$file]));
    }

    /**
     * Underscore convention: Foo_Bar_Baz → foo/bar/Baz.php (and lowercase variants).
     */
    private function resolveFromUnderscore(string $class): ?string
    {
        if (!str_contains($class, '_')) {
            return null;
        }

        $parts     = explode('_', $class);
        $fileName  = array_pop($parts);
        $subPath   = strtolower(implode('/', $parts));

        $candidates = array_unique([
            $subPath . '/' . $fileName,
            $subPath . '/' . strtolower($fileName),
            str_replace('_', '/', strtolower($class)),
        ]);

        return $this->findInDirectories($candidates);
    }

    /**
     * Flat / CamelCase convention: UserAccount → UserAccount.php, user_account.php,
     * user/Account.php, etc.
     */
    private function resolveFromDirectories(string $class): ?string
    {
        $camelPath = $this->camelToPath($class);
        $snakeName = $this->camelToSnake($class);

        $candidates = array_unique([
            $class,
            strtolower($class),
            $camelPath,
            strtolower($camelPath),
            $snakeName, // matches user_group.php, example_name_name.php
        ]);

        return $this->findInDirectories($candidates);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param string[] $candidates */
    private function findInDirectories(array $candidates): ?string
    {
        foreach ($this->directories as $dir) {
            foreach ($candidates as $relative) {
                foreach ($this->extensions as $ext) {
                    $file = $dir . $relative . $ext;
                    if (file_exists($file)) {
                        return $file;
                    }
                }
            }
        }
        return null;
    }

    /** Convert CamelCase to path: UserGroup → User/Group */
    private function camelToPath(string $class): string
    {
        return preg_replace('/([a-z0-9])([A-Z])/', '$1/$2', $class) ?? $class;
    }

    /** Convert CamelCase to snake_case: UserGroup → user_group */
    private function camelToSnake(string $class): string
    {
        return strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $class) ?? $class);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        if (!str_contains(basename($path), '.')) {
            $path .= '/';
        }
        return $path;
    }
}