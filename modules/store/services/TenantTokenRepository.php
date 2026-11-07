<?php
declare(strict_types=1);

namespace Module\Store\Services;

use InvalidArgumentException;
use System\Library\Database;

final class TenantTokenRepository
{
    public function __construct(private Database $db) {}

    /**
     * Create a token. Returns plaintext once.
     *
     * @param list<string> $scopes
     * @return array{id:int, token:string, prefix:string, last4:string}
     */
    public function create(int $tenantId, string $name, array $scopes = ['*'], ?string $expiresAt = null): array
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Invalid tenant.');
        }
        $name = mb_substr(trim($name) !== '' ? trim($name) : 'API token', 0, 128);
        $plain = 'sft_' . bin2hex(random_bytes(24));
        $prefix = substr($plain, 0, 12);
        $last4 = substr($plain, -4);
        $hash = hash('sha256', $plain);
        $scopesJson = json_encode(array_values($scopes), JSON_UNESCAPED_UNICODE);
        if (!is_string($scopesJson)) {
            $scopesJson = '["*"]';
        }

        $id = (int) $this->db->insert('store_tenant_tokens', [
            'tenant_id'    => $tenantId,
            'name'         => $name,
            'token_hash'   => $hash,
            'prefix'       => $prefix,
            'last4'        => $last4,
            'scopes_json'  => $scopesJson,
            'expires_at'   => $expiresAt,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return [
            'id'     => $id,
            'token'  => $plain,
            'prefix' => $prefix,
            'last4'  => $last4,
        ];
    }

    /** @return array<string, mixed>|null */
    public function findValidByPlainToken(string $plain): ?array
    {
        $plain = trim($plain);
        if ($plain === '' || !str_starts_with($plain, 'sft_')) {
            return null;
        }
        $hash = hash('sha256', $plain);
        $row = $this->db->query(
            "SELECT tk.*, t.`slug`, t.`name` AS tenant_name, t.`status` AS tenant_status
               FROM `#__store_tenant_tokens` tk
               JOIN `#__store_tenants` t ON t.`id` = tk.`tenant_id`
              WHERE tk.`token_hash` = ?
                AND tk.`revoked_at` IS NULL
                AND (tk.`expires_at` IS NULL OR tk.`expires_at` > NOW())
              LIMIT 1",
            [$hash]
        )->row;
        if ($row === []) {
            return null;
        }
        return $this->hydrate($row);
    }

    public function touch(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->db->update('store_tenant_tokens', [
            'last_used_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function revoke(int $tenantId, int $tokenId): void
    {
        $this->db->query(
            'UPDATE `#__store_tenant_tokens`
                SET `revoked_at` = ?
              WHERE `id` = ? AND `tenant_id` = ? AND `revoked_at` IS NULL',
            [date('Y-m-d H:i:s'), $tokenId, $tenantId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function forTenant(int $tenantId): array
    {
        $rows = $this->db->query(
            "SELECT tk.*, t.`slug`, t.`name` AS tenant_name, t.`status` AS tenant_status
               FROM `#__store_tenant_tokens` tk
               JOIN `#__store_tenants` t ON t.`id` = tk.`tenant_id`
              WHERE tk.`tenant_id` = ?
              ORDER BY tk.`id` DESC",
            [$tenantId]
        )->rows;
        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $raw = $row['scopes_json'] ?? '[]';
        $scopes = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        return [
            'id'            => (int) $row['id'],
            'tenant_id'     => (int) $row['tenant_id'],
            'name'          => (string) ($row['name'] ?? ''),
            'prefix'        => (string) ($row['prefix'] ?? ''),
            'last4'         => (string) ($row['last4'] ?? ''),
            'scopes'        => is_array($scopes) ? array_values(array_map('strval', $scopes)) : ['*'],
            'expires_at'    => $row['expires_at'] ?? null,
            'last_used_at'  => $row['last_used_at'] ?? null,
            'revoked_at'    => $row['revoked_at'] ?? null,
            'created_at'    => (string) ($row['created_at'] ?? ''),
            'slug'          => (string) ($row['slug'] ?? ''),
            'tenant_name'   => (string) ($row['tenant_name'] ?? ''),
            'tenant_status' => (string) ($row['tenant_status'] ?? ''),
        ];
    }
}
