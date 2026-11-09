<?php
declare(strict_types=1);

namespace Module\Community\Services;

use System\Library\Database;

final class NotificationRepository
{
    public function __construct(private Database $db) {}

    public function create(
        int $userId,
        int $actorId,
        string $type,
        string $entityType,
        string $entityId,
    ): void {
        if ($userId < 1 || $actorId < 1 || $userId === $actorId) {
            return;
        }
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?? '';
        $entityType = preg_replace('/[^a-z0-9_]/', '', strtolower($entityType)) ?? '';
        $entityId = trim($entityId);
        if ($type === '' || $entityType === '' || $entityId === '') {
            return;
        }

        // Dedupe unread likes/requests so toggle spam does not flood the inbox.
        if (in_array($type, ['post_like', 'friend_request'], true)) {
            $existing = $this->db->query(
                'SELECT `id` FROM `#__community_notifications`
                  WHERE `user_id` = ? AND `actor_id` = ? AND `type` = ?
                    AND `entity_type` = ? AND `entity_id` = ? AND `is_read` = 0
                  LIMIT 1',
                [$userId, $actorId, $type, $entityType, $entityId]
            )->row;
            if ($existing !== []) {
                return;
            }
        }

        $this->db->insert('community_notifications', [
            'user_id'     => $userId,
            'actor_id'    => $actorId,
            'type'        => $type,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'is_read'     => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function unreadCount(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM `#__community_notifications`
              WHERE `user_id` = ? AND `is_read` = 0',
            [$userId]
        )->value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId, int $limit = 40): array
    {
        if ($userId < 1) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        return $this->db->query(
            "SELECT n.*,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS actor_name,
                    u.`username` AS actor_username
               FROM `#__community_notifications` n
               LEFT JOIN `#__users` u ON u.`id` = n.`actor_id`
              WHERE n.`user_id` = ?
              ORDER BY n.`created_at` DESC, n.`id` DESC
              LIMIT {$limit}",
            [$userId]
        )->rows;
    }

    public function markRead(int $userId, ?int $notificationId = null): void
    {
        if ($userId < 1) {
            return;
        }
        if ($notificationId !== null && $notificationId > 0) {
            $this->db->query(
                'UPDATE `#__community_notifications`
                    SET `is_read` = 1
                  WHERE `user_id` = ? AND `id` = ?',
                [$userId, $notificationId]
            );
            return;
        }
        $this->db->query(
            'UPDATE `#__community_notifications`
                SET `is_read` = 1
              WHERE `user_id` = ? AND `is_read` = 0',
            [$userId]
        );
    }
}
