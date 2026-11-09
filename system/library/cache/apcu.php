<?php
declare(strict_types=1);

namespace System\Library\Cache;

class Apcu
{
    private int $expire;
    private bool $active;
    private string $prefix;

    public function __construct(int $expire = 3600)
    {
        $this->expire = $expire;
        $this->active = function_exists('apcu_cache_info') && (bool) ini_get('apc.enabled');
        $this->prefix = defined('CACHE_PREFIX') ? CACHE_PREFIX : 'app_';
    }

    public function get(string $key): mixed
    {
        if (!$this->active) {
            return null;
        }

        $value = apcu_fetch($this->prefix . $key, $success);

        return $success ? $value : null;
    }

    public function set(string $key, mixed $value, int $expire = 0): void
    {
        if (!$this->active) {
            return;
        }

        $expire = $expire ?: $this->expire;

        apcu_store($this->prefix . $key, $value, $expire);
    }

    public function delete(string $key): void
    {
        if (!$this->active) {
            return;
        }

        apcu_delete($this->prefix . $key);
    }

    public function increment(string $key, int $ttl = 60): int
    {
        if (!$this->active) {
            return 1;
        }

        $full = $this->prefix . $key;
        $success = false;
        $value = apcu_inc($full, 1, $success);
        if (!$success) {
            apcu_store($full, 1, max(1, $ttl));
            return 1;
        }
        return (int) $value;
    }

    public function flush(): bool
    {
        if (!$this->active || !function_exists('apcu_clear_cache')) {
            return false;
        }

        // Only delete keys with our prefix.
        $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/');
        foreach ($iterator as $entry) {
            apcu_delete($entry['key']);
        }

        return true;
    }
}