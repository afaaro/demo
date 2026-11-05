<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

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
        $this->db->insert('sf_sync_log', $payload);
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
                "SELECT * FROM `#__sf_sync_log`
                  WHERE `channel_id` = ? AND `tenant_id` = ?
                  ORDER BY `id` DESC
                  LIMIT ?",
                [$channelId, $this->tenantId(), $limit]
            )->rows;
        } else {
            $rows = $this->db->query(
                "SELECT * FROM `#__sf_sync_log`
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
}
