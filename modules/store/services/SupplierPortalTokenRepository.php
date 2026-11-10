<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class SupplierPortalTokenRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return array{id:int, token:string, expires_at:?string}
     */
    public function create(int $supplierId, ?string $expiresAt = null): array
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        if ($supplierId <= 0) {
            throw new RuntimeException('Invalid supplier.');
        }
        // Revoke prior active tokens for this supplier.
        $this->db->query(
            "UPDATE `#__store_supplier_portal_tokens`
                SET `revoked_at` = ?
              WHERE `supplier_id` = ? AND `tenant_id` = ? AND `revoked_at` IS NULL",
            [date('Y-m-d H:i:s'), $supplierId, $this->tenantId()]
        );
        $token = bin2hex(random_bytes(24));
        $id = (int) $this->db->insert('store_supplier_portal_tokens', $this->withTenant([
            'supplier_id' => $supplierId,
            'token'       => $token,
            'expires_at'  => $expiresAt,
            'created_at'  => date('Y-m-d H:i:s'),
        ]));
        return ['id' => $id, 'token' => $token, 'expires_at' => $expiresAt];
    }

    /** @return array<string, mixed>|null */
    public function findValid(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $row = $this->db->query(
            "SELECT t.*, s.`name` AS supplier_name, s.`tenant_id` AS supplier_tenant_id
               FROM `#__store_supplier_portal_tokens` t
               JOIN `#__store_suppliers` s ON s.`id` = t.`supplier_id` AND s.`tenant_id` = t.`tenant_id`
              WHERE t.`token` = ?
                AND t.`revoked_at` IS NULL
                AND (t.`expires_at` IS NULL OR t.`expires_at` > NOW())
              LIMIT 1",
            [$token]
        )->row;
        if ($row === []) {
            return null;
        }
        return [
            'id'            => (int) $row['id'],
            'tenant_id'     => (int) $row['tenant_id'],
            'supplier_id'   => (int) $row['supplier_id'],
            'token'         => (string) $row['token'],
            'expires_at'    => $row['expires_at'] ?? null,
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function forSupplier(int $supplierId): array
    {
        if ($supplierId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        return $this->db->query(
            "SELECT `id`, `token`, `expires_at`, `created_at`, `revoked_at`
               FROM `#__store_supplier_portal_tokens`
              WHERE `supplier_id` = ? AND `tenant_id` = ?
              ORDER BY `id` DESC",
            [$supplierId, $this->tenantId()]
        )->rows;
    }

    public function revoke(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "UPDATE `#__store_supplier_portal_tokens`
                SET `revoked_at` = ?
              WHERE `id` = ? AND `tenant_id` = ? AND `revoked_at` IS NULL",
            [date('Y-m-d H:i:s'), $id, $this->tenantId()]
        );
    }
}
