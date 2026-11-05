<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class ProductSavedFilterRepository
{
    use TenantScoped;

    /** Query keys allowed in a saved filter preset. */
    private const ALLOWED_KEYS = [
        'q', 'category_id', 'stock', 'active', 'sort', 'dir',
        'f_sku', 'f_name', 'f_ean', 'f_mpn', 'f_supplier_sku',
        'f_supplier', 'f_warehouse', 'f_type',
        'f_pickbin', 'f_on_po', 'f_on_back_order',
    ];

    public function __construct(private Database $db) {}

    /**
     * @return list<array{id:int, name:string, params:array<string, string>}>
     */
    public function forUser(int $userId): array
    {
        if ($userId <= 0 || !TenantContext::get()->has()) {
            return [];
        }

        $rows = $this->db->query(
            "SELECT `id`, `name`, `params_json`
               FROM `#__sf_product_saved_filters`
              WHERE `user_id` = ? AND `tenant_id` = ?
              ORDER BY `name` ASC",
            [$userId, $this->tenantId()]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'     => (int) $row['id'],
                'name'   => (string) $row['name'],
                'params' => $this->decodeParams((string) $row['params_json']),
            ];
        }
        return $out;
    }

    /**
     * @return array{id:int, name:string, params:array<string, string>}|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        if ($id <= 0 || $userId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT `id`, `name`, `params_json`
               FROM `#__sf_product_saved_filters`
              WHERE `id` = ? AND `user_id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$id, $userId, $this->tenantId()]
        )->row;
        if ($row === []) {
            return null;
        }
        return [
            'id'     => (int) $row['id'],
            'name'   => (string) $row['name'],
            'params' => $this->decodeParams((string) $row['params_json']),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function save(int $userId, string $name, array $params): int
    {
        if ($userId <= 0) {
            throw new RuntimeException('You must be signed in to save filters.');
        }
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $name = mb_substr(trim($name), 0, 128);
        if ($name === '') {
            throw new InvalidArgumentException('Filter name is required.');
        }

        $clean = $this->sanitizeParams($params);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not encode filter params.');
        }

        $tid = $this->tenantId();
        $existing = $this->db->query(
            "SELECT `id` FROM `#__sf_product_saved_filters`
              WHERE `user_id` = ? AND `name` = ? AND `tenant_id` = ? LIMIT 1",
            [$userId, $name, $tid]
        )->row;

        if ($existing !== []) {
            $id = (int) $existing['id'];
            $this->db->update('sf_product_saved_filters', [
                'params_json' => $json,
            ], ['id' => $id, 'tenant_id' => $tid]);
            return $id;
        }

        return (int) $this->db->insert('sf_product_saved_filters', $this->withTenant([
            'user_id'     => $userId,
            'name'        => $name,
            'params_json' => $json,
            'created_at'  => date('Y-m-d H:i:s'),
        ]));
    }

    public function deleteForUser(int $id, int $userId): void
    {
        if ($id <= 0 || $userId <= 0) {
            throw new RuntimeException('Filter not found.');
        }
        if (!TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "DELETE FROM `#__sf_product_saved_filters` WHERE `id` = ? AND `user_id` = ? AND `tenant_id` = ?",
            [$id, $userId, $this->tenantId()]
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function sanitizeParams(array $params): array
    {
        $out = [];
        foreach (self::ALLOWED_KEYS as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $val = trim((string) $params[$key]);
            if ($val === '') {
                continue;
            }
            $out[$key] = mb_substr($val, 0, 255);
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function decodeParams(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $this->sanitizeParams($decoded);
    }
}
