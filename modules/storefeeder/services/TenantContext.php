<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;

/**
 * Request-scoped current tenant. Never accept tenant_id from the client —
 * only TenantResolver may set this.
 */
final class TenantContext
{
    private static ?self $instance = null;

    private ?int $tenantId = null;
    private string $tenantSlug = '';
    private string $tenantName = '';
    private string $tenantStatus = '';
    /** @var list<string> */
    private array $scopes = [];
    private string $authVia = ''; // token|membership|platform|none
    private ?int $tokenId = null;

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->tenantSlug = '';
        $this->tenantName = '';
        $this->tenantStatus = '';
        $this->scopes = [];
        $this->authVia = 'none';
        $this->tokenId = null;
    }

    /**
     * @param list<string> $scopes
     */
    public function set(
        int $tenantId,
        string $slug,
        string $name,
        string $status,
        string $authVia,
        array $scopes = [],
        ?int $tokenId = null,
    ): void {
        if ($tenantId <= 0) {
            throw new RuntimeException('Invalid tenant id.');
        }
        $this->tenantId = $tenantId;
        $this->tenantSlug = $slug;
        $this->tenantName = $name;
        $this->tenantStatus = $status;
        $this->authVia = $authVia;
        $this->scopes = $scopes;
        $this->tokenId = $tokenId;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function requireId(): int
    {
        if ($this->tenantId === null || $this->tenantId <= 0) {
            throw new RuntimeException('No tenant context.', 403);
        }
        if ($this->tenantStatus === 'suspended') {
            throw new RuntimeException('Tenant is suspended.', 403);
        }
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null && $this->tenantId > 0;
    }

    public function slug(): string
    {
        return $this->tenantSlug;
    }

    public function name(): string
    {
        return $this->tenantName;
    }

    public function status(): string
    {
        return $this->tenantStatus;
    }

    public function authVia(): string
    {
        return $this->authVia;
    }

    public function tokenId(): ?int
    {
        return $this->tokenId;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function allows(string $scope): bool
    {
        if ($this->scopes === [] || in_array('*', $this->scopes, true)) {
            return true;
        }
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Run a callback with a forced tenant (jobs / platform tools).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function run(int $tenantId, string $slug, string $name, string $status, callable $fn): mixed
    {
        $prev = [
            $this->tenantId,
            $this->tenantSlug,
            $this->tenantName,
            $this->tenantStatus,
            $this->scopes,
            $this->authVia,
            $this->tokenId,
        ];
        $this->set($tenantId, $slug, $name, $status, 'platform', ['*']);
        try {
            return $fn();
        } finally {
            [$this->tenantId, $this->tenantSlug, $this->tenantName, $this->tenantStatus, $this->scopes, $this->authVia, $this->tokenId] = $prev;
        }
    }
}
