<?php
declare(strict_types=1);

namespace System\Library;

/**
 * Request-scoped in-memory key/value store.
 *
 * WARNING: state persists for the lifetime of the PHP process. In a
 * long-running worker (RoadRunner, Swoole, queue workers), call
 * Ram::clear() at the start of each request to avoid leakage between
 * requests.
 */
class Ram
{
    /**
     * @var array<string, mixed>
     */
    protected static array $data = [];

    public static function set(string $label, mixed $value): void
    {
        self::$data[$label] = $value;
    }

    public static function get(string $label, mixed $default = null): mixed
    {
        return array_key_exists($label, self::$data)
            ? self::$data[$label]
            : $default;
    }

    public static function has(string $label): bool
    {
        return array_key_exists($label, self::$data);
    }

    public static function delete(string $label): void
    {
        unset(self::$data[$label]);
    }

    public static function clear(): void
    {
        self::$data = [];
    }

    /**
     * Debug helper — returns a snapshot (by value) of the current store.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$data;
    }
}