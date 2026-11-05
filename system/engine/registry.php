<?php
declare(strict_types=1);

namespace System\Engine;

use Closure;
use RuntimeException;

final class Registry
{
    /** @var array<string, array{resolver: Closure, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var array<string, true> */
    private array $resolving = [];

    private static ?self $instance = null;

    // =========================================================================
    // Instantiation — singleton only
    // =========================================================================

    /**
     * Private constructor: use Registry::getInstance().
     *
     * If you need an isolated container for a test, use Registry::fresh()
     * (which swaps the singleton) rather than `new Registry()`.
     */
    private function __construct() {}

    private function __clone() {}

    public function __wakeup(): void
    {
        throw new RuntimeException('Registry cannot be unserialized.');
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replace the singleton with a fresh empty container and return it.
     *
     * Call this at the start of each test, or when bootstrapping a CLI/worker
     * context that needs a clean slate. The previous instance is cleared so
     * any stray references see an empty container instead of stale bindings.
     */
    public static function fresh(): self
    {
        if (self::$instance !== null) {
            self::$instance->clear();
        }
        return self::$instance = new self();
    }

    /**
     * Alias for fresh(). Kept for callers that used resetInstance().
     *
     * @deprecated Use fresh() instead.
     */
    public static function resetInstance(): void
    {
        self::fresh();
    }

    // =========================================================================
    // Registration
    // =========================================================================

    public function singleton(string $key, Closure $resolver): void
    {
        $this->bindings[$key] = ['resolver' => $resolver, 'shared' => true];
        unset($this->resolved[$key]);
    }

    public function factory(string $key, Closure $resolver): void
    {
        $this->bindings[$key] = ['resolver' => $resolver, 'shared' => false];
        unset($this->resolved[$key]);
    }

    public function set(string $key, mixed $value): void
    {
        $this->bindings[$key] = [
            'resolver' => static fn() => $value,
            'shared'   => true,
        ];
        $this->resolved[$key] = $value;
    }

    public function instance(string $key, object $instance): void
    {
        $this->set($key, $instance);
    }

    // =========================================================================
    // Resolution
    // =========================================================================

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        if (!isset($this->bindings[$key])) {
            $keys = $this->bindings === []
                ? '(none)'
                : implode(', ', array_keys($this->bindings));
            throw new RuntimeException(
                "Service '{$key}' is not registered. Registered: {$keys}"
            );
        }

        if (isset($this->resolving[$key])) {
            $chain = implode(' → ', array_keys($this->resolving)) . " → {$key}";
            throw new RuntimeException("Circular dependency: {$chain}");
        }

        $this->resolving[$key] = true;
        try {
            $value = ($this->bindings[$key]['resolver'])($this);
        } finally {
            unset($this->resolving[$key]);
        }

        if ($this->bindings[$key]['shared']) {
            $this->resolved[$key] = $value;
        }

        return $value;
    }

    // =========================================================================
    // Magic property access
    // =========================================================================

    /**
     * Assigning a Closure registers a *singleton*. For a fresh instance per
     * call, use factory() explicitly.
     */
    public function __set(string $key, mixed $value): void
    {
        if ($value instanceof Closure) {
            $this->singleton($key, $value);
        } else {
            $this->set($key, $value);
        }
    }

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __isset(string $key): bool
    {
        return $this->has($key);
    }

    public function __unset(string $key): void
    {
        $this->remove($key);
    }

    // =========================================================================
    // Inspection & management
    // =========================================================================

    public function has(string $key): bool
    {
        return isset($this->bindings[$key]);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->bindings);
    }

    public function isCached(string $key): bool
    {
        return array_key_exists($key, $this->resolved);
    }

    public function remove(string $key): void
    {
        unset($this->bindings[$key], $this->resolved[$key], $this->resolving[$key]);
    }

    public function flushResolved(): void
    {
        $this->resolved = [];
    }

    public function clear(): void
    {
        $this->bindings  = [];
        $this->resolved  = [];
        $this->resolving = [];
    }

    /**
     * @param array<int, string> $keys
     */
    public function assertRegistered(array $keys): void
    {
        $missing = array_values(array_diff($keys, array_keys($this->bindings)));
        if ($missing !== []) {
            throw new RuntimeException(
                'Bootstrap incomplete. Missing services: ' . implode(', ', $missing)
            );
        }
    }
}