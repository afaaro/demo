<?php
declare(strict_types=1);

namespace System\Library;

use System\Engine\Registry;
use RuntimeException;
use Closure;

class RateLimiter
{
    private Registry $registry;
    private ?Closure $scopeResolver = null;

    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Register a callback that returns the current scope (tenant id, user id, etc).
     * Called on every enforce()/clear()/hits() when no explicit $scope is passed.
     * Return null or '' to fall back to IP.
     */
    public function setScopeResolver(callable $resolver): void
    {
        $this->scopeResolver = Closure::fromCallable($resolver);
    }

    public function enforce(
        string $action,
        int $maxHits = 60,
        int $window = 60,
        string $scope = ''
    ): void {
        $hits = $this->registry->get('cache')->increment(
            $this->buildKey($action, $scope),
            $window
        );

        if ($hits > $maxHits) {
            throw new RuntimeException(
                "Rate limit exceeded for '{$action}'. Try again in {$window} seconds.",
                429
            );
        }
    }

    public function clear(string $action, string $scope = ''): void
    {
        $this->registry->get('cache')->delete($this->buildKey($action, $scope));
    }

    public function hits(string $action, string $scope = ''): int
    {
        return (int) ($this->registry->get('cache')->get($this->buildKey($action, $scope)) ?? 0);
    }

    private function buildKey(string $action, string $scope): string
    {
        $scope = $scope !== '' ? $scope : $this->resolveScope();
        $id    = $scope !== '' ? $scope : $this->ip();

        return 'rl:' . $action . ':' . $id;
    }

    private function resolveScope(): string
    {
        if ($this->scopeResolver === null) {
            return '';
        }

        $scope = ($this->scopeResolver)();

        return $scope !== null && $scope !== '' ? (string) $scope : '';
    }

    private function ip(): string
    {
        $trustProxy = (bool) $this->registry->get('config')->get('http.trust_proxy', false);

        if ($trustProxy) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                if (empty($_SERVER[$header])) {
                    continue;
                }
                $candidate = trim(explode(',', (string) $_SERVER[$header])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}