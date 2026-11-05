<?php
declare(strict_types=1);

namespace System\Engine;

/**
 * Console command registry.
 *
 * Module commands are discovered from each module's helpers/console.php,
 * which must return an array of [commandName => callable].
 *
 * A command handler has the signature:
 *   function (Registry $registry, array $args): int
 */
final class Console
{
    /** @var array<string, callable> */
    private array $commands = [];

    /** @var array<string, string> command name => module name */
    private array $sources = [];

    private bool $loaded = false;

    /**
     * Scan all installed modules and register their commands.
     * Safe to call multiple times.
     */
    public function load(?Registry $registry = null): self
    {
        if ($this->loaded) {
            return $this;
        }
        $this->loaded = true;

        $registry ??= Registry::getInstance();

        foreach (get_modules() as $moduleName => $modulePath) {
            $file = rtrim($modulePath, '/\\')
                  . DIRECTORY_SEPARATOR . 'helpers'
                  . DIRECTORY_SEPARATOR . 'console.php';

            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            // Make $registry available inside the included file.
            $commands = (static function () use ($file, $registry) {
                return include $file;
            })();

            if (!is_array($commands)) {
                error_log("[Console] {$file} did not return an array.");
                continue;
            }

            foreach ($commands as $name => $handler) {
                if (!is_string($name) || !is_callable($handler)) {
                    error_log("[Console] Invalid command in {$file}: " . var_export($name, true));
                    continue;
                }

                if (isset($this->commands[$name])) {
                    error_log(sprintf(
                        "[Console] Command '%s' from '%s' overrides one from '%s'.",
                        $name, $moduleName, $this->sources[$name] ?? '?'
                    ));
                }

                $this->commands[$name] = $handler;
                $this->sources[$name]  = $moduleName;
            }
        }

        return $this;
    }

    /**
     * Register a command programmatically (for built-ins).
     */
    public function register(string $name, callable $handler): self
    {
        $this->commands[$name] = $handler;
        return $this;
    }

    public function get(string $name): ?callable
    {
        return $this->commands[$name] ?? null;
    }

    /** @return array<string, callable> */
    public function all(): array
    {
        return $this->commands;
    }

    public function source(string $name): ?string
    {
        return $this->sources[$name] ?? null;
    }

    /**
     * Execute a command and return its exit code.
     */
    public function run(string $name, array $args, Registry $registry): int
    {
        $handler = $this->commands[$name] ?? null;
        if ($handler === null) {
            fwrite(STDERR, "Unknown command: {$name}\n");
            return 1;
        }
        return (int) $handler($registry, $args);
    }
}