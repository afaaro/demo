<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

/**
 * Helpers for repositories that must filter by current tenant.
 */
trait TenantScoped
{
    protected function tenantId(): int
    {
        return TenantContext::get()->requireId();
    }

    /** SQL fragment: `alias`.`tenant_id` = ? (bind with tenantId()). */
    protected function tenantClause(string $alias = ''): string
    {
        $col = $alias !== '' ? "`{$alias}`.`tenant_id`" : '`tenant_id`';
        return "{$col} = ?";
    }

    /**
     * Merge tenant_id into a where/insert payload.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function withTenant(array $data): array
    {
        $data['tenant_id'] = $this->tenantId();
        return $data;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    protected function onlyOwnTenant(?array $row): ?array
    {
        if ($row === null || $row === []) {
            return null;
        }
        $tid = (int) ($row['tenant_id'] ?? 0);
        if ($tid !== $this->tenantId()) {
            return null;
        }
        return $row;
    }
}
