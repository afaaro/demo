<?php
declare(strict_types=1);

namespace System\Services;

use System\Library\Database;
use Throwable;

final class UserAuthAudit
{
    public function __construct(private Database $db) {}

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function log(
        string $event,
        string $summary,
        ?int $actorUserId = null,
        ?int $targetUserId = null,
        ?int $targetGroupId = null,
        ?array $before = null,
        ?array $after = null,
    ): void {
        try {
            $this->db->insert('user_auth_audit', [
                'created_at'      => date('Y-m-d H:i:s'),
                'actor_user_id'   => $actorUserId,
                'target_user_id'  => $targetUserId,
                'target_group_id' => $targetGroupId,
                'event'           => mb_substr($event, 0, 64),
                'summary'         => mb_substr($summary, 0, 255),
                'before_json'     => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES),
                'after_json'      => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // Table may not exist until migrate.
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $userId, int $limit = 20): array
    {
        return $this->rows('target_user_id', $userId, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forGroup(int $groupId, int $limit = 20): array
    {
        return $this->rows('target_group_id', $groupId, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $column, int $id, int $limit): array
    {
        if ($id <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            return $this->db->query(
                "SELECT * FROM `#__user_auth_audit`
                  WHERE `{$column}` = ?
                  ORDER BY `id` DESC
                  LIMIT {$limit}",
                [$id]
            )->rows;
        } catch (Throwable) {
            return [];
        }
    }
}
