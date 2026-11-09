<?php
declare(strict_types=1);

namespace System\Library;

class Cache
{
    private object $adaptor;

    public function __construct(string $adaptor, int $expire = 3600)
    {
        $adaptor = self::normalizeAdaptor($adaptor);
        $class = 'System\\Library\\Cache\\' . ucfirst($adaptor);

        if (!class_exists($class)) {
            throw new \RuntimeException('Could not load cache adaptor ' . $adaptor . '.');
        }

        $this->adaptor = new $class($expire);
    }

    public static function normalizeAdaptor(string $adaptor): string
    {
        $adaptor = strtolower(trim($adaptor));
        if ($adaptor === 'apc') {
            $adaptor = 'apcu';
        }
        if ($adaptor === 'auto') {
            $enabled = filter_var((string) ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN);
            $adaptor = (function_exists('apcu_fetch') && $enabled) ? 'apcu' : 'file';
        }
        if (!preg_match('/^[a-z][a-z0-9]*$/', $adaptor)) {
            throw new \InvalidArgumentException('Invalid cache adaptor name.');
        }
        return $adaptor;
    }

    public function get(string $key): mixed
    {
        return $this->adaptor->get($key);
    }

    public function set(string $key, mixed $value, int $expire = 0): void
    {
        $this->adaptor->set($key, $value, $expire);
    }

    public function delete(string $key): void
    {
        $this->adaptor->delete($key);
    }

    /**
     * Atomically increment a counter (used by RateLimiter).
     * Returns the value after increment.
     */
    public function increment(string $key, int $ttl = 60): int
    {
        if (method_exists($this->adaptor, 'increment')) {
            return (int) $this->adaptor->increment($key, max(1, $ttl));
        }

        $current = (int) ($this->get($key) ?? 0);
        $next = $current + 1;
        $this->set($key, $next, max(1, $ttl));
        return $next;
    }

    public function flush(): bool
    {
        if (method_exists($this->adaptor, 'flush')) {
            return (bool) $this->adaptor->flush();
        }
        return false;
    }
}