<?php

namespace System\Library;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use Traversable;

class Arr implements IteratorAggregate
{
    protected array $stack;

    public function __construct(array $stack = [])
    {
        $this->stack = $stack;
    }

    public static function create(array $stack = []): static
    {
        return new static($stack);
    }

    public static function accessible($value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

    public static function dot(array $array, string $prepend = ''): array
    {
        $results = [];
        foreach ($array as $key => $value) {
            $newKey = $prepend . $key;
            if (is_array($value) && !empty($value)) {
                $results += static::dot($value, $newKey . '.');
            } else {
                $results[$newKey] = $value;
            }
        }
        return $results;
    }

    public static function flatten(array $array, string $delimiter = '.'): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                foreach (static::flatten($value, $delimiter) as $subKey => $subValue) {
                    $result[$key . $delimiter . $subKey] = $subValue;
                }
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    public static function get($array, string $key, $default = null)
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }
        return $array;
    }

    public static function set(array &$array, string $key, $value): void
    {
        $keys = explode('.', $key);
        while (count($keys) > 1) {
            $segment = array_shift($keys);
            if (!isset($array[$segment]) || !is_array($array[$segment])) {
                $array[$segment] = [];
            }
            $array = &$array[$segment];
        }
        $array[array_shift($keys)] = $value;
    }

    public static function forget(array &$array, $keys): void
    {
        $original = &$array;
        foreach ((array)$keys as $key) {
            $array = &$original;
            if (array_key_exists($key, $array)) {
                unset($array[$key]);
                continue;
            }
            $parts = explode('.', $key);
            while (count($parts) > 1) {
                $part = array_shift($parts);
                if (!isset($array[$part]) || !is_array($array[$part])) {
                    continue 2;
                }
                $array = &$array[$part];
            }
            unset($array[array_shift($parts)]);
        }
    }

    public static function erase(array &$array, string $key): void
    {
        static::forget($array, $key);
    }

    public static function has(array $array, string $key): bool
    {
        if (array_key_exists($key, $array)) return true;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) return false;
            $array = $array[$segment];
        }
        return true;
    }

    public static function merge_replace(...$arrays): array
    {
        $base = array_shift($arrays);
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                    $base[$key] = self::merge_replace($base[$key], $value);
                } else {
                    $base[$key] = $value;
                }
            }
        }
        return $base;
    }

    public static function merge_unique(...$arrays): array
    {
        $base = array_shift($arrays);
        foreach ($arrays as $append) {
            foreach ($append as $key => $value) {
                if (!array_key_exists($key, $base)) {
                    $base[$key] = $value;
                } elseif (is_array($value) && is_array($base[$key])) {
                    $base[$key] = self::merge_unique($base[$key], $value);
                }
            }
        }
        return $base;
    }

    public static function only(array $array, array|string $keys): array
    {
        return array_intersect_key($array, array_flip((array)$keys));
    }

    public static function filter_recursive(array $array): array
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $value = static::filter_recursive($value);
            }
            if (empty($value)) {
                unset($array[$key]);
            }
        }
        return $array;
    }

    public static function pluck(array $array, string|array $value, string|array|null $key = null): array
    {
        [$valuePath, $keyPath] = static::explodePluckParameters($value, $key);
        $results = [];

        foreach ($array as $item) {
            $itemValue = static::get($item, implode('.', $valuePath));
            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = static::get($item, implode('.', $keyPath));
                $results[$itemKey] = $itemValue;
            }
        }

        return $results;
    }

    protected static function explodePluckParameters(string|array $value, string|array|null $key): array
    {
        $value = is_string($value) ? explode('.', $value) : $value;
        $key = is_null($key) || is_array($key) ? $key : explode('.', $key);
        return [$value, $key];
    }

    public static function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    public function all(): array
    {
        return $this->stack;
    }

    public function first(): mixed
    {
        return reset($this->stack);
    }

    public function last(): mixed
    {
        return end($this->stack);
    }

    public function count(): int
    {
        return count($this->stack);
    }

    public function push(mixed $value): static
    {
        $this->stack[] = $value;
        return $this;
    }

    public function map(callable $callback): static
    {
        $this->stack = array_map($callback, $this->stack);
        return $this;
    }

    public function filter(callable $callback = null): static
    {
        $this->stack = array_filter($this->stack, $callback ?? fn($v) => !empty($v));
        return $this;
    }

    public function toArray(): array
    {
        return $this->stack;
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->stack, $options);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->stack);
    }
}