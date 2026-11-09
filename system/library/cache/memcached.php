<?php
declare(strict_types=1);

namespace System\Library\Cache;

use Memcached as MemcachedClient;

class Memcached
{
    private \Memcached $memcached;
    private int $expire;
    private string $prefix;

    public function __construct(int $expire = 3600)
    {
        if (!class_exists('Memcached')) {
            throw new \RuntimeException('Memcached extension is not installed.');
        }

        $this->expire = $expire;
        $this->prefix = defined('CACHE_PREFIX') ? CACHE_PREFIX : 'app_';

        $host = defined('CACHE_HOSTNAME') ? CACHE_HOSTNAME : '127.0.0.1';
        $port = defined('CACHE_PORT')     ? CACHE_PORT     : 11211;

        $this->memcached = new MemcachedClient();

        // Timeouts — prevents hanging if Memcached is down.
        $this->memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $this->memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $this->memcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 1000);
        $this->memcached->setOption(\Memcached::OPT_SEND_TIMEOUT, 1000);
        $this->memcached->setOption(\Memcached::OPT_RECV_TIMEOUT, 1000);

        $this->memcached->addServer($host, (int) $port);
    }

    public function get(string $key): mixed
    {
        $value = $this->memcached->get($this->prefix . $key);

        if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            return null;
        }

        return $value;
    }

    public function set(string $key, mixed $value, int $expire = 0): void
    {
        $expire = $expire ?: $this->expire;
        $this->memcached->set($this->prefix . $key, $value, $expire);
    }

    public function delete(string $key): void
    {
        $this->memcached->delete($this->prefix . $key);
    }

    public function increment(string $key, int $ttl = 60): int
    {
        $full = $this->prefix . $key;
        $value = $this->memcached->increment($full, 1);
        if ($value === false) {
            $this->memcached->set($full, 1, max(1, $ttl));
            return 1;
        }
        return (int) $value;
    }

    public function flush(): bool
    {
        return $this->memcached->flush();
    }
}