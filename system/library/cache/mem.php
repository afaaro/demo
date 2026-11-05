<?php
declare(strict_types=1);

namespace System\Library\Cache;

class Mem
{
    private \Memcache $memcache;
    private int $expire;
    private string $prefix;

    public function __construct(int $expire = 3600)
    {
        if (!class_exists('Memcache')) {
            throw new \RuntimeException('Memcache extension is not installed.');
        }

        $this->expire = $expire;
        $this->prefix = defined('CACHE_PREFIX') ? CACHE_PREFIX : 'app_';

        $host = defined('CACHE_HOSTNAME') ? CACHE_HOSTNAME : '127.0.0.1';
        $port = defined('CACHE_PORT')     ? CACHE_PORT     : 11211;

        $this->memcache = new \Memcache();

        if (!$this->memcache->connect($host, (int) $port, 1)) {
            throw new \RuntimeException("Failed to connect to Memcache at {$host}:{$port}");
        }
    }

    public function get(string $key): mixed
    {
        $raw = $this->memcache->get($this->prefix . $key);

        if (!is_array($raw) || !array_key_exists('v', $raw)) {
            return null;
        }

        return $raw['v'];
    }

    public function set(string $key, mixed $value, int $expire = 0): void
    {
        $expire = $expire ?: $this->expire;
        $this->memcache->set($this->prefix . $key, ['v' => $value], MEMCACHE_COMPRESSED, $expire);
    }

    public function delete(string $key): void
    {
        $this->memcache->delete($this->prefix . $key);
    }

    public function flush(): bool
    {
        return $this->memcache->flush();
    }
}