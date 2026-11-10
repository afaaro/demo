<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

final class SyncLogRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @param array<string, mixed> $details
     */
    public function log(
        int $channelId,
        string $action,
        string $status,
        string $summary = '',
        array $details = [],
        ?int $userId = null,
        string $userLabel = '',
    ): void {
        if ($channelId <= 0) {
            return;
        }
        $status = in_array($status, ['ok', 'error', 'skipped'], true) ? $status : 'ok';
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }

        $payload = [
            'channel_id'   => $channelId,
            'action'       => mb_substr(trim($action), 0, 64),
            'status'       => $status,
            'summary'      => mb_substr(trim($summary), 0, 500),
            'details_json' => $json,
            'user_id'      => $userId,
            'user_label'   => mb_substr(trim($userLabel), 0, 128),
            'created_at'   => date('Y-m-d H:i:s'),
        ];
        if (TenantContext::get()->has()) {
            $payload = $this->withTenant($payload);
        }
        $this->db->insert('store_sync_log', $payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forChannel(int $channelId, int $limit = 50): array
    {
        if ($channelId <= 0) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        if (TenantContext::get()->has()) {
            $rows = $this->db->query(
                "SELECT * FROM `#__store_sync_log`
                  WHERE `channel_id` = ? AND `tenant_id` = ?
                  ORDER BY `id` DESC
                  LIMIT ?",
                [$channelId, $this->tenantId(), $limit]
            )->rows;
        } else {
            $rows = $this->db->query(
                "SELECT * FROM `#__store_sync_log`
                  WHERE `channel_id` = ?
                  ORDER BY `id` DESC
                  LIMIT ?",
                [$channelId, $limit]
            )->rows;
        }

        return array_map(static function (array $row): array {
            $raw = $row['details_json'] ?? '{}';
            $details = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
            return [
                'id'         => (int) $row['id'],
                'channel_id' => (int) $row['channel_id'],
                'action'     => (string) ($row['action'] ?? ''),
                'status'     => (string) ($row['status'] ?? ''),
                'summary'    => (string) ($row['summary'] ?? ''),
                'details'    => is_array($details) ? $details : [],
                'user_label' => (string) ($row['user_label'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * Recent error rows (optionally filtered by action keywords).
     *
     * @param list<string> $actions  Exact action names; empty = any action
     * @return array<int, array<string, mixed>>
     */
    public function recentErrors(int $limit = 50, array $actions = [], int $withinHours = 168): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $withinHours = max(1, min(720, $withinHours));
        $where = ['`tenant_id` = ?', "`status` = 'error'", '`created_at` >= DATE_SUB(NOW(), INTERVAL ? HOUR)'];
        $params = [$this->tenantId(), $withinHours];

        $actions = array_values(array_filter(array_map(
            static fn($a) => mb_substr(trim((string) $a), 0, 64),
            $actions
        ), static fn(string $a) => $a !== ''));
        if ($actions !== []) {
            $placeholders = implode(', ', array_fill(0, count($actions), '?'));
            $where[] = '`action` IN (' . $placeholders . ')';
            foreach ($actions as $a) {
                $params[] = $a;
            }
        }

        $params[] = $limit;
        $rows = $this->db->query(
            'SELECT * FROM `#__store_sync_log`
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY `id` DESC
              LIMIT ?',
            $params
        )->rows;

        return array_map(static function (array $row): array {
            $raw = $row['details_json'] ?? '{}';
            $details = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
            return [
                'id'         => (int) $row['id'],
                'channel_id' => (int) $row['channel_id'],
                'action'     => (string) ($row['action'] ?? ''),
                'status'     => (string) ($row['status'] ?? ''),
                'summary'    => (string) ($row['summary'] ?? ''),
                'details'    => is_array($details) ? $details : [],
                'user_label' => (string) ($row['user_label'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * Aggregate last ok / last error per channel + action for the current tenant.
     *
     * @return list<array{
     *   channel_id:int, channel_label:string, channel_type:string,
     *   action:string, last_ok_at:?string, last_error_at:?string,
     *   last_error_summary:string, last_status:string
     * }>
     */
    public function healthByChannel(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT l.`channel_id`, l.`action`,
                    MAX(CASE WHEN l.`status` = 'ok' THEN l.`created_at` END) AS last_ok_at,
                    MAX(CASE WHEN l.`status` = 'error' THEN l.`created_at` END) AS last_error_at,
                    SUBSTRING_INDEX(
                      GROUP_CONCAT(
                        CASE WHEN l.`status` = 'error' THEN l.`summary` END
                        ORDER BY l.`id` DESC SEPARATOR '\\n'
                      ), '\\n', 1
                    ) AS last_error_summary,
                    SUBSTRING_INDEX(GROUP_CONCAT(l.`status` ORDER BY l.`id` DESC SEPARATOR ','), ',', 1) AS last_status,
                    COALESCE(NULLIF(c.`label`, ''), c.`name`, '') AS channel_label,
                    COALESCE(c.`type`, '') AS channel_type
               FROM `#__store_sync_log` l
               LEFT JOIN `#__store_channels` c
                 ON c.`id` = l.`channel_id` AND c.`tenant_id` = l.`tenant_id`
              WHERE l.`tenant_id` = ?
              GROUP BY l.`channel_id`, l.`action`, channel_label, channel_type
              ORDER BY channel_label ASC, l.`action` ASC",
            [$tid]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'channel_id'         => (int) $row['channel_id'],
                'channel_label'      => (string) ($row['channel_label'] ?? ''),
                'channel_type'       => (string) ($row['channel_type'] ?? ''),
                'action'             => (string) ($row['action'] ?? ''),
                'last_ok_at'         => !empty($row['last_ok_at']) ? (string) $row['last_ok_at'] : null,
                'last_error_at'      => !empty($row['last_error_at']) ? (string) $row['last_error_at'] : null,
                'last_error_summary' => (string) ($row['last_error_summary'] ?? ''),
                'last_status'        => (string) ($row['last_status'] ?? ''),
            ];
        }
        return $out;
    }
}
