<?php
declare(strict_types=1);

namespace Module\Community\Services;

use System\Library\Database;

final class ReactionRepository
{
    public function __construct(private Database $db) {}

    public function countLikes(int $postId): int
    {
        if ($postId < 1) {
            return 0;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__community_reactions`
              WHERE `post_id` = ? AND `type` = 'like'",
            [$postId]
        )->value;
    }

    public function userLiked(int $postId, int $userId): bool
    {
        if ($postId < 1 || $userId < 1) {
            return false;
        }
        $row = $this->db->query(
            'SELECT `id` FROM `#__community_reactions`
              WHERE `post_id` = ? AND `user_id` = ?
              LIMIT 1',
            [$postId, $userId]
        )->row;
        return $row !== [];
    }

    /**
     * Toggle like. Returns true when liked after the call, false when removed.
     */
    public function toggleLike(int $postId, int $userId): bool
    {
        if ($postId < 1 || $userId < 1) {
            throw new \InvalidArgumentException('Invalid reaction.');
        }
        $existing = $this->db->query(
            'SELECT `id` FROM `#__community_reactions`
              WHERE `post_id` = ? AND `user_id` = ?
              LIMIT 1',
            [$postId, $userId]
        )->row;

        if ($existing !== []) {
            $this->db->delete('community_reactions', ['id' => (int) $existing['id']]);
            return false;
        }

        $this->db->insert('community_reactions', [
            'post_id'    => $postId,
            'user_id'    => $userId,
            'type'       => 'like',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    /**
     * @param list<int> $postIds
     * @return array<int, int>
     */
    public function likeCountsForPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(
            array_map('intval', $postIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $rows = $this->db->query(
            "SELECT `post_id`, COUNT(*) AS cnt
               FROM `#__community_reactions`
              WHERE `type` = 'like' AND `post_id` IN ({$placeholders})
              GROUP BY `post_id`",
            $postIds
        )->rows;
        $out = array_fill_keys($postIds, 0);
        foreach ($rows as $row) {
            $out[(int) $row['post_id']] = (int) $row['cnt'];
        }
        return $out;
    }

    /**
     * @param list<int> $postIds
     * @return array<int, bool>
     */
    public function likedByUser(array $postIds, int $userId): array
    {
        $postIds = array_values(array_unique(array_filter(
            array_map('intval', $postIds),
            static fn(int $id): bool => $id > 0
        )));
        $out = array_fill_keys($postIds, false);
        if ($postIds === [] || $userId < 1) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $params = array_merge([$userId], $postIds);
        $rows = $this->db->query(
            "SELECT `post_id` FROM `#__community_reactions`
              WHERE `user_id` = ? AND `post_id` IN ({$placeholders})",
            $params
        )->rows;
        foreach ($rows as $row) {
            $out[(int) $row['post_id']] = true;
        }
        return $out;
    }
}
