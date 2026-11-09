<?php
declare(strict_types=1);

namespace System\Library\Cache;

class File
{
    private int $expire;
    private string $dir;

    public function __construct(int $expire = 3600)
    {
        $this->expire = $expire > 0 ? $expire : 3600;

        if (defined('DIR_CACHE')) {
            $this->dir = rtrim((string) DIR_CACHE, '/\\') . DIRECTORY_SEPARATOR;
        } elseif (defined('BASEDIR')) {
            $this->dir = rtrim((string) BASEDIR, '/\\')
                . DIRECTORY_SEPARATOR . 'storage'
                . DIRECTORY_SEPARATOR . 'cache'
                . DIRECTORY_SEPARATOR;
        } else {
            throw new \RuntimeException(
                'Cache directory is not configured: define DIR_CACHE or BASEDIR.'
            );
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cache directory could not be created: {$this->dir}");
        }
    }

    public function get(string $key): mixed
    {
        $this->maybeGc();

        $files = glob($this->dir . 'cache.' . $this->safeKey($key) . '.*') ?: [];
        if ($files === []) {
            return null;
        }

        // Newest first: the highest expiry timestamp is the most recent write.
        usort(
            $files,
            static fn(string $a, string $b): int =>
                (int) substr(strrchr($b, '.'), 1) <=> (int) substr(strrchr($a, '.'), 1)
        );

        $now = time();

        foreach ($files as $file) {
            $expiresAt = (int) substr((string) strrchr($file, '.'), 1);

            if ($expiresAt <= $now) {
                @unlink($file);
                continue;
            }

            $raw = @file_get_contents($file);
            if ($raw === false) {
                return null;
            }

            $decoded = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || !array_key_exists('__v', $decoded)) {
                // Corrupt or unreadable — self-heal by removing it.
                @unlink($file);
                return null;
            }

            return $decoded['__v'];
        }

        return null;
    }

    public function set(string $key, mixed $value, int $expire = 0): void
    {
        if (!is_dir($this->dir) || !is_writable($this->dir)) {
            throw new \RuntimeException("Cache directory is not writable: {$this->dir}");
        }

        if ($expire <= 0) {
            $expire = $this->expire;
        }

        try {
            $data = json_encode(
                ['__v' => $value],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                "Failed to encode cache value for key '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }

        // Replace prior versions of this key so the cache dir does not accumulate files.
        $this->delete($key);

        $file = $this->dir . 'cache.' . $this->safeKey($key) . '.' . (time() + $expire);
        $tmp  = $file . '.tmp.' . bin2hex(random_bytes(6));

        if (@file_put_contents($tmp, $data) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to write cache temp file: {$tmp}");
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to move cache file into place: {$file}");
        }
    }

    public function increment(string $key, int $ttl = 60): int
    {
        $lockPath = $this->dir . 'lock.' . $this->safeKey($key);
        $fh = @fopen($lockPath, 'c+');
        if ($fh === false) {
            $current = (int) ($this->get($key) ?? 0);
            $next = $current + 1;
            $this->set($key, $next, max(1, $ttl));
            return $next;
        }

        try {
            flock($fh, LOCK_EX);
            $current = (int) ($this->get($key) ?? 0);
            $next = $current + 1;
            $this->set($key, $next, max(1, $ttl));
            return $next;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function delete(string $key): void
    {
        foreach (glob($this->dir . 'cache.' . $this->safeKey($key) . '.*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function safeKey(string $key): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Cache key cannot be empty.');
        }

        // Human-readable prefix + hash so distinct keys never collide.
        $readable = preg_replace('/[^A-Za-z0-9_-]/', '_', $key);
        $readable = substr((string) $readable, 0, 40);

        $hash = function_exists('hash') && in_array('xxh128', hash_algos(), true)
            ? hash('xxh128', $key)
            : md5($key);

        return $readable . '.' . $hash;
    }

    private function maybeGc(): void
    {
        $marker = $this->dir . '.gc';
        $last   = @filemtime($marker) ?: 0;

        if (time() - $last < 3600) {
            return;
        }

        @touch($marker);

        $now = time();
        foreach (glob($this->dir . 'cache.*') ?: [] as $file) {
            $expiresAt = (int) substr((string) strrchr($file, '.'), 1);
            if ($expiresAt > 0 && $expiresAt <= $now) {
                @unlink($file);
            }
        }
    }

    public function __destruct()
    {
        try {
            $this->maybeGc();
        } catch (\Throwable) {
            // Never throw from a destructor.
        }
    }
}