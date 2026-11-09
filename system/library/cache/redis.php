<?php
declare(strict_types=1);

namespace System\Library\Cache;

use Redis as RedisClient;

class Redis
{
    private \Redis $redis;
    private int $expire;
    private string $prefix;

    public function __construct(int $expire = 3600)
    {
        if (!class_exists('Redis')) {
            throw new \RuntimeException('Redis extension is not installed.');
        }

        $this->expire = $expire;
        $this->prefix = defined('CACHE_PREFIX') ? CACHE_PREFIX : 'app_';

        $host     = defined('REDIS_HOST') ? REDIS_HOST : (defined('CACHE_HOSTNAME') ? CACHE_HOSTNAME : '127.0.0.1');
        $port     = defined('REDIS_PORT') ? REDIS_PORT : 6379;
        $password = defined('REDIS_PASSWORD') ? REDIS_PASSWORD : (defined('CACHE_PASSWORD') ? CACHE_PASSWORD : '');
        $db       = defined('REDIS_DB') ? (int) REDIS_DB : (defined('CACHE_DB') ? (int) CACHE_DB : 0);

        $this->redis = new RedisClient();

        if (!$this->redis->pconnect($host, (int) $port, 1.0)) {
            throw new \RuntimeException("Failed to connect to Redis at {$host}:{$port}");
        }

        if ($password !== '') {
            $this->redis->auth($password);
        }

        if ($db > 0) {
            $this->redis->select($db);
        }
    }

    public function get(string $key): mixed
    {
        $raw = $this->redis->get($this->prefix . $key);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }

    public function set(string $key, mixed $value, int $expire = 0): void
    {
        $expire  = $expire ?: $this->expire;
        $encoded = json_encode($value);

        if ($encoded === false) {
            throw new \RuntimeException("Failed to encode cache value for key '{$key}'.");
        }

        if ($expire > 0) {
            $this->redis->setex($this->prefix . $key, $expire, $encoded);
        } else {
            $this->redis->set($this->prefix . $key, $encoded);
        }
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function increment(string $key, int $ttl = 60): int
    {
        $full = $this->prefix . $key;
        $value = (int) $this->redis->incr($full);
        if ($value === 1) {
            $this->redis->expire($full, max(1, $ttl));
        }
        return $value;
    }

    public function flush(): bool
    {
        $pattern  = $this->prefix . '*';
        $iterator = null;

        while (false !== ($keys = $this->redis->scan($iterator, $pattern, 100))) {
            foreach ($keys as $key) {
                $this->redis->del($key);
            }
        }

        return true;
    }
}