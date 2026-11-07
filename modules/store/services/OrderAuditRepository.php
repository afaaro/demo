<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

final class OrderAuditRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @param array<string, mixed> $changes
     */
    public function log(
        int $orderId,
        string $action,
        string $summary = '',
        array $changes = [],
        ?int $userId = null,
        string $userLabel = '',
    ): void {
        if ($orderId <= 0) {
            return;
        }
        $json = json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }

        $payload = [
            'order_id'     => $orderId,
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
        $this->db->insert('store_order_audit', $payload);
    }

    /**
     * @return array<int, array{
     *   id:int, order_id:int, user_id:?int, user_label:string,
     *   action:string, summary:string, changes:array<string, mixed>, created_at:string
     * }>
     */
    public function forOrder(int $orderId, int $limit = 100): array
    {
        if ($orderId <= 0) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        if (TenantContext::get()->has()) {
            $rows = $this->db->query(
                "SELECT * FROM `#__store_order_audit`
                  WHERE `order_id` = ? AND `tenant_id` = ?
                  ORDER BY `id` DESC
                  LIMIT ?",
                [$orderId, $this->tenantId(), $limit]
            )->rows;
        } else {
            $rows = $this->db->query(
                "SELECT * FROM `#__store_order_audit`
                  WHERE `order_id` = ?
                  ORDER BY `id` DESC
                  LIMIT ?",
                [$orderId, $limit]
            )->rows;
        }

        $out = [];
        foreach ($rows as $row) {
            $raw = $row['changes_json'] ?? '{}';
            $changes = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
            $out[] = [
                'id'         => (int) $row['id'],
                'order_id'   => (int) $row['order_id'],
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
