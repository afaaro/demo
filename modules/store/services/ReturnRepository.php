<?php
declare(strict_types=1);

namespace Module\Store\Services;

use InvalidArgumentException;
use System\Library\Database;

final class ReturnRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @param array{
     *   order_id?:?int, channel_id?:?int, external_id?:string, external_order_id?:string,
     *   status?:string, reason?:string, notes?:string, refund_amount?:float|int|string,
     *   currency?:string, buyer_username?:string, items?:array<int, mixed>
     * } $data
     */
    public function create(array $data): int
    {
        $items = $data['items'] ?? [];
        $json = json_encode(is_array($items) ? $items : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '[]';
        }

        $payload = [
            'order_id'          => !empty($data['order_id']) ? (int) $data['order_id'] : null,
            'channel_id'        => !empty($data['channel_id']) ? (int) $data['channel_id'] : null,
            'external_id'       => mb_substr(trim((string) ($data['external_id'] ?? '')), 0, 128),
            'external_order_id' => mb_substr(trim((string) ($data['external_order_id'] ?? '')), 0, 64),
            'status'            => mb_substr(trim((string) ($data['status'] ?? 'open')) ?: 'open', 0, 64),
            'reason'            => mb_substr(trim((string) ($data['reason'] ?? '')), 0, 255),
            'notes'             => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 1000),
            'refund_amount'     => round((float) ($data['refund_amount'] ?? 0), 2),
            'currency'          => mb_substr(strtoupper(trim((string) ($data['currency'] ?? 'GBP'))) ?: 'GBP', 0, 3),
            'buyer_username'    => mb_substr(trim((string) ($data['buyer_username'] ?? '')), 0, 128),
            'items_json'        => $json,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
        if (TenantContext::get()->has()) {
            $payload = $this->withTenant($payload);
        }
        return (int) $this->db->insert('store_returns', $payload);
    }

    public function updateStatus(int $id, string $status, string $notes = ''): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid return id.');
        }
        $payload = [
            'status'     => mb_substr(trim($status) ?: 'open', 0, 64),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($notes !== '') {
            $payload['notes'] = mb_substr(trim($notes), 0, 1000);
        }
        $where = ['id' => $id];
        if (TenantContext::get()->has()) {
            $where = $this->withTenant($where);
        }
        $this->db->update('store_returns', $payload, $where);
    }

    /**
     * Upsert by channel + external return id.
     *
     * @param array<string, mixed> $data
     * @return 'created'|'updated'|'skipped'
     */
    public function upsertByExternal(int $channelId, string $externalId, array $data): string
    {
        $externalId = mb_substr(trim($externalId), 0, 128);
        if ($channelId <= 0 || $externalId === '' || !TenantContext::get()->has()) {
            return 'skipped';
        }

        $existing = $this->db->query(
            "SELECT * FROM `#__store_returns`
              WHERE `channel_id` = ? AND `external_id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$channelId, $externalId, $this->tenantId()]
        )->row;

        $items = $data['items'] ?? [];
        $json = json_encode(is_array($items) ? $items : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '[]';
        }

        $payload = [
            'order_id'          => !empty($data['order_id']) ? (int) $data['order_id'] : null,
            'channel_id'        => $channelId,
            'external_id'       => $externalId,
            'external_order_id' => mb_substr(trim((string) ($data['external_order_id'] ?? '')), 0, 64),
            'status'            => mb_substr(trim((string) ($data['status'] ?? 'open')) ?: 'open', 0, 64),
            'reason'            => mb_substr(trim((string) ($data['reason'] ?? '')), 0, 255),
            'notes'             => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 1000),
            'refund_amount'     => round((float) ($data['refund_amount'] ?? 0), 2),
            'currency'          => mb_substr(strtoupper(trim((string) ($data['currency'] ?? 'GBP'))) ?: 'GBP', 0, 3),
            'buyer_username'    => mb_substr(trim((string) ($data['buyer_username'] ?? '')), 0, 128),
            'items_json'        => $json,
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        if ($existing === [] || $existing === null) {
            $payload['created_at'] = date('Y-m-d H:i:s');
            $this->db->insert('store_returns', $this->withTenant($payload));
            return 'created';
        }

        $this->db->update('store_returns', $payload, $this->withTenant(['id' => (int) $existing['id']]));
        return 'updated';
    }

    public function markRestocked(int $id, ?string $at = null): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid return id.');
        }
        $this->db->update('store_returns', [
            'restocked_at' => $at ?? date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->db->query(
            "SELECT r.*, c.`label` AS channel_label, o.`external_order_id` AS order_external_id
               FROM `#__store_returns` r
               LEFT JOIN `#__store_channels` c ON c.`id` = r.`channel_id`
               LEFT JOIN `#__store_orders` o ON o.`id` = r.`order_id`
              WHERE r.`id` = ?
              LIMIT 1",
            [$id]
        )->row;
        return $row === [] ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forOrder(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }
        if (TenantContext::get()->has()) {
            $rows = $this->db->query(
                "SELECT r.*, c.`label` AS channel_label
                   FROM `#__store_returns` r
                   LEFT JOIN `#__store_channels` c ON c.`id` = r.`channel_id`
                  WHERE r.`order_id` = ? AND r.`tenant_id` = ?
                  ORDER BY r.`id` DESC",
                [$orderId, $this->tenantId()]
            )->rows;
        } else {
            $rows = $this->db->query(
                "SELECT r.*, c.`label` AS channel_label
                   FROM `#__store_returns` r
                   LEFT JOIN `#__store_channels` c ON c.`id` = r.`channel_id`
                  WHERE r.`order_id` = ?
                  ORDER BY r.`id` DESC",
                [$orderId]
            )->rows;
        }
        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(string $status = '', int $channelId = 0, string $search = '', int $limit = 100, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];
        if (TenantContext::get()->has()) {
            $where[] = 'r.`tenant_id` = ?';
            $params[] = $this->tenantId();
        }
        if ($status !== '') {
            $where[] = 'r.`status` = ?';
            $params[] = $status;
        }
        if ($channelId > 0) {
            $where[] = 'r.`channel_id` = ?';
            $params[] = $channelId;
        }
        if ($search !== '') {
            $where[] = '(r.`external_order_id` LIKE ? OR r.`external_id` LIKE ? OR r.`buyer_username` LIKE ? OR r.`reason` LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT r.*, c.`label` AS channel_label, o.`external_order_id` AS order_external_id
                  FROM `#__store_returns` r
                  LEFT JOIN `#__store_channels` c ON c.`id` = r.`channel_id`
                  LEFT JOIN `#__store_orders` o ON o.`id` = r.`order_id`
                 WHERE ' . implode(' AND ', $where)
             . ' ORDER BY r.`id` DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $rows = $this->db->query($sql, $params)->rows;
        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function count(string $status = '', int $channelId = 0, string $search = ''): int
    {
        $where = ['1=1'];
        $params = [];
        if (TenantContext::get()->has()) {
            $where[] = '`tenant_id` = ?';
            $params[] = $this->tenantId();
        }
        if ($status !== '') {
            $where[] = '`status` = ?';
            $params[] = $status;
        }
        if ($channelId > 0) {
            $where[] = '`channel_id` = ?';
            $params[] = $channelId;
        }
        if ($search !== '') {
            $where[] = '(`external_order_id` LIKE ? OR `external_id` LIKE ? OR `buyer_username` LIKE ? OR `reason` LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM `#__store_returns` WHERE ' . implode(' AND ', $where),
            $params
        )->value;
    }

    /**
     * Approved / open returns awaiting inbound receive.
     *
     * @return list<array<string, mixed>>
     */
    public function inboundQueue(int $limit = 100, int $offset = 0, string $search = ''): array
    {
        $where = ["LOWER(r.`status`) IN ('open', 'approved')"];
        $params = [];
        if (TenantContext::get()->has()) {
            $where[] = 'r.`tenant_id` = ?';
            $params[] = $this->tenantId();
        }
        if ($search !== '') {
            $where[] = '(r.`external_order_id` LIKE ? OR r.`external_id` LIKE ? OR r.`buyer_username` LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT r.*, c.`label` AS channel_label, o.`external_order_id` AS order_external_id
                  FROM `#__store_returns` r
                  LEFT JOIN `#__store_channels` c ON c.`id` = r.`channel_id`
                  LEFT JOIN `#__store_orders` o ON o.`id` = r.`order_id`
                 WHERE ' . implode(' AND ', $where)
             . ' ORDER BY r.`id` ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $rows = $this->db->query($sql, $params)->rows;
        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function countInbound(string $search = ''): int
    {
        $where = ["LOWER(`status`) IN ('open', 'approved')"];
        $params = [];
        if (TenantContext::get()->has()) {
            $where[] = '`tenant_id` = ?';
            $params[] = $this->tenantId();
        }
        if ($search !== '') {
            $where[] = '(`external_order_id` LIKE ? OR `external_id` LIKE ? OR `buyer_username` LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM `#__store_returns` WHERE ' . implode(' AND ', $where),
            $params
        )->value;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $raw = $row['items_json'] ?? '[]';
        $items = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        return [
            'id'                => (int) $row['id'],
            'order_id'          => isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : null,
            'channel_id'        => isset($row['channel_id']) && $row['channel_id'] !== null ? (int) $row['channel_id'] : null,
            'channel_label'     => (string) ($row['channel_label'] ?? ''),
            'order_external_id' => (string) ($row['order_external_id'] ?? $row['external_order_id'] ?? ''),
            'external_id'       => (string) ($row['external_id'] ?? ''),
            'external_order_id' => (string) ($row['external_order_id'] ?? ''),
            'status'            => (string) ($row['status'] ?? ''),
            'reason'            => (string) ($row['reason'] ?? ''),
            'notes'             => (string) ($row['notes'] ?? ''),
            'refund_amount'     => (float) ($row['refund_amount'] ?? 0),
            'currency'          => (string) ($row['currency'] ?? 'GBP'),
            'buyer_username'    => (string) ($row['buyer_username'] ?? ''),
            'items'             => is_array($items) ? $items : [],
            'created_at'        => (string) ($row['created_at'] ?? ''),
            'updated_at'        => (string) ($row['updated_at'] ?? ''),
            'restocked_at'      => isset($row['restocked_at']) && $row['restocked_at'] !== null && $row['restocked_at'] !== ''
                ? (string) $row['restocked_at']
                : null,
        ];
    }
}
