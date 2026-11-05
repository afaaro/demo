<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use System\Library\Database;

final class ProductAuditRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @param array<string, mixed> $changes
     */
    public function log(
        int $productId,
        string $action,
        string $summary = '',
        array $changes = [],
        ?int $userId = null,
        string $userLabel = '',
    ): void {
        if ($productId <= 0) {
            return;
        }

        $json = json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }

        $payload = [
            'product_id'   => $productId,
            'user_id'      => $userId,
            'user_label'   => mb_substr(trim($userLabel), 0, 128),
            'action'       => mb_substr(trim($action) !== '' ? trim($action) : 'updated', 0, 64),
            'summary'      => mb_substr(trim($summary), 0, 500),
            'changes_json' => $json,
            'created_at'   => date('Y-m-d H:i:s'),
        ];
        if (TenantContext::get()->has()) {
            $payload = $this->withTenant($payload);
        }
        $this->db->insert('sf_product_audit', $payload);
    }

    /**
     * @return array<int, array{
     *   id:int, product_id:int, user_id:?int, user_label:string,
     *   action:string, summary:string, changes:array<string, mixed>, created_at:string
     * }>
     */
    public function forProduct(int $productId, int $limit = 100): array
    {
        if ($productId <= 0) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        if (!TenantContext::get()->has()) {
            return [];
        }

        $rows = $this->db->query(
            "SELECT * FROM `#__sf_product_audit`
              WHERE `product_id` = ? AND `tenant_id` = ?
              ORDER BY `id` DESC
              LIMIT ?",
            [$productId, $this->tenantId(), $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $raw = $row['changes_json'] ?? '{}';
            $changes = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
            $out[] = [
                'id'         => (int) $row['id'],
                'product_id' => (int) $row['product_id'],
                'user_id'    => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'user_label' => (string) ($row['user_label'] ?? ''),
                'action'     => (string) ($row['action'] ?? ''),
                'summary'    => (string) ($row['summary'] ?? ''),
                'changes'    => is_array($changes) ? $changes : [],
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $out;
    }
}
