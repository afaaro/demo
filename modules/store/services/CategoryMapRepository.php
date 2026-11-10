<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class CategoryMapRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /** @return list<array<string, mixed>> */
    public function all(?int $channelId = null): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $params = [$this->tenantId()];
        $sql = "SELECT m.*,
                       sc.`name` AS store_category_name,
                       c.`name` AS channel_name,
                       c.`type` AS channel_type
                  FROM `#__store_category_maps` m
             LEFT JOIN `#__store_categories` sc
                    ON sc.`id` = m.`store_category_id` AND sc.`tenant_id` = m.`tenant_id`
             LEFT JOIN `#__store_channels` c
                    ON c.`id` = m.`channel_id` AND c.`tenant_id` = m.`tenant_id`
                 WHERE m.`tenant_id` = ?";
        if ($channelId !== null && $channelId > 0) {
            $sql .= ' AND m.`channel_id` = ?';
            $params[] = $channelId;
        }
        $sql .= ' ORDER BY m.`id` DESC';
        return array_map([$this, 'hydrate'], $this->db->query($sql, $params)->rows);
    }

    /** @return array<string, mixed>|null */
    public function findForCategoryChannel(int $storeCategoryId, int $channelId): ?array
    {
        if ($storeCategoryId <= 0 || $channelId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_category_maps`
              WHERE `store_category_id` = ? AND `channel_id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$storeCategoryId, $channelId, $this->tenantId()]
        )->row;
        return $row === [] ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed>|null $aspects
     */
    public function upsert(
        int $storeCategoryId,
        int $channelId,
        string $externalCategoryId,
        ?array $aspects = null,
    ): int {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        if ($storeCategoryId <= 0 || $channelId <= 0) {
            throw new RuntimeException('Category and channel are required.');
        }
        $externalCategoryId = mb_substr(trim($externalCategoryId), 0, 64);
        if ($externalCategoryId === '') {
            throw new RuntimeException('External category ID is required.');
        }
        $existing = $this->findForCategoryChannel($storeCategoryId, $channelId);
        $aspectsJson = $aspects !== null
            ? json_encode($aspects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        if ($existing !== null) {
            $this->db->update('store_category_maps', [
                'external_category_id' => $externalCategoryId,
                'aspects_json'         => $aspectsJson,
                'updated_at'           => date('Y-m-d H:i:s'),
            ], $this->withTenant(['id' => (int) $existing['id']]));
            return (int) $existing['id'];
        }
        return (int) $this->db->insert('store_category_maps', $this->withTenant([
            'store_category_id'    => $storeCategoryId,
            'channel_id'           => $channelId,
            'external_category_id' => $externalCategoryId,
            'aspects_json'         => $aspectsJson,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]));
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            'DELETE FROM `#__store_category_maps` WHERE `id` = ? AND `tenant_id` = ?',
            [$id, $this->tenantId()]
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $raw = $row['aspects_json'] ?? null;
        $aspects = is_array($raw) ? $raw : (json_decode((string) ($raw ?? ''), true) ?: null);
        return [
            'id'                   => (int) $row['id'],
            'store_category_id'    => (int) ($row['store_category_id'] ?? 0),
            'channel_id'           => (int) ($row['channel_id'] ?? 0),
            'external_category_id' => (string) ($row['external_category_id'] ?? ''),
            'aspects'              => is_array($aspects) ? $aspects : null,
            'store_category_name'  => (string) ($row['store_category_name'] ?? ''),
            'channel_name'         => (string) ($row['channel_name'] ?? ''),
            'channel_type'         => (string) ($row['channel_type'] ?? ''),
        ];
    }
}
