<?php
declare(strict_types=1);

namespace Module\Community\Services;

use System\Library\Database;

final class PostRepository
{
    public function __construct(private Database $db) {}

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = $this->db->query(
            "SELECT p.*,
                    u.`username`,
                    u.`display_name`,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS author_name
               FROM `#__community_posts` p
               LEFT JOIN `#__users` u ON u.`id` = p.`user_id`
              WHERE p.`id` = ?
              LIMIT 1",
            [$id]
        )->row;
        if ($row === []) {
            return null;
        }
        $row['media'] = $this->mediaForPost($id);
        return $row;
    }

    public function existsVisible(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        $row = $this->db->query(
            "SELECT 1 AS ok FROM `#__community_posts`
              WHERE `id` = ? AND `status` = 'visible'
              LIMIT 1",
            [$id]
        )->row;
        return $row !== [];
    }

    /**
     * @param list<int> $authorIds
     * @return list<array<string, mixed>>
     */
    public function feedForAuthors(array $authorIds, int $limit = 15, int $offset = 0): array
    {
        $authorIds = array_values(array_unique(array_filter(
            array_map('intval', $authorIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($authorIds === []) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $placeholders = implode(',', array_fill(0, count($authorIds), '?'));
        $rows = $this->db->query(
            "SELECT p.*,
                    u.`username`,
                    u.`display_name`,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS author_name
               FROM `#__community_posts` p
               LEFT JOIN `#__users` u ON u.`id` = p.`user_id`
              WHERE p.`status` = 'visible'
                AND p.`user_id` IN ({$placeholders})
                AND p.`visibility` IN ('friends', 'public')
              ORDER BY p.`created_at` DESC, p.`id` DESC
              LIMIT {$limit} OFFSET {$offset}",
            $authorIds
        )->rows;
        return $this->attachMedia($rows);
    }

    /**
     * Recent public posts from people outside the viewer's friend set (discovery).
     *
     * @param list<int> $excludeAuthorIds
     * @return list<array<string, mixed>>
     */
    public function publicDiscover(array $excludeAuthorIds, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $excludeAuthorIds = array_values(array_unique(array_filter(
            array_map('intval', $excludeAuthorIds),
            static fn(int $id): bool => $id > 0
        )));
        $params = [];
        $excludeSql = '';
        if ($excludeAuthorIds !== []) {
            $placeholders = implode(',', array_fill(0, count($excludeAuthorIds), '?'));
            $excludeSql = "AND p.`user_id` NOT IN ({$placeholders})";
            $params = $excludeAuthorIds;
        }
        $rows = $this->db->query(
            "SELECT p.*,
                    u.`username`,
                    u.`display_name`,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS author_name
               FROM `#__community_posts` p
               LEFT JOIN `#__users` u ON u.`id` = p.`user_id`
              WHERE p.`status` = 'visible'
                AND p.`visibility` = 'public'
                {$excludeSql}
              ORDER BY p.`created_at` DESC, p.`id` DESC
              LIMIT {$limit}",
            $params
        )->rows;
        return $this->attachMedia($rows);
    }

    /**
     * @param list<int> $postIds
     * @return array<int, int>
     */
    public function commentCountsForPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(
            array_map('intval', $postIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        try {
            $rows = $this->db->query(
                "SELECT `entity_id`, COUNT(*) AS cnt
                   FROM `#__comments`
                  WHERE `module` = 'community'
                    AND `entity_type` = 'post'
                    AND `status` = 'visible'
                    AND `entity_id` IN ({$placeholders})
                  GROUP BY `entity_id`",
                array_map('strval', $postIds)
            )->rows;
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['entity_id']] = (int) $row['cnt'];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function wallForUser(
        int $profileUserId,
        int $viewerId,
        bool $viewerIsFriendOrSelf,
        int $limit = 15,
        int $offset = 0,
    ): array {
        if ($profileUserId < 1) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $visibilitySql = $viewerIsFriendOrSelf
            ? "p.`visibility` IN ('friends','public')"
            : "p.`visibility` = 'public'";

        $rows = $this->db->query(
            "SELECT p.*,
                    u.`username`,
                    u.`display_name`,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS author_name
               FROM `#__community_posts` p
               LEFT JOIN `#__users` u ON u.`id` = p.`user_id`
              WHERE p.`status` = 'visible'
                AND p.`user_id` = ?
                AND {$visibilitySql}
              ORDER BY p.`created_at` DESC, p.`id` DESC
              LIMIT {$limit} OFFSET {$offset}",
            [$profileUserId]
        )->rows;
        return $this->attachMedia($rows);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function adminSearch(string $q, string $status, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $where = ['1=1'];
        $params = [];

        if ($q !== '') {
            $where[] = '(p.`body` LIKE ? OR u.`username` LIKE ? OR u.`display_name` LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'p.`status` = ?';
            $params[] = $status;
        }

        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->query(
            "SELECT COUNT(*)
               FROM `#__community_posts` p
               LEFT JOIN `#__users` u ON u.`id` = p.`user_id`
              WHERE {$sqlWhere}",
            $params
        )->value;

        $rows = $this->db->query(
            "SELECT p.*,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS author_name,
                    u.`username`
               FROM `#__community_posts` p
               LEFT JOIN `#__users` u ON u.`id` = p.`user_id`
              WHERE {$sqlWhere}
              ORDER BY p.`created_at` DESC, p.`id` DESC
              LIMIT {$limit} OFFSET {$offset}",
            $params
        )->rows;

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array{path:string,mime:string}|null $photo
     * @return array<string, mixed>
     */
    public function create(int $userId, string $body, string $visibility, ?array $photo = null): array
    {
        $body = trim($body);
        if ($userId < 1) {
            throw new \InvalidArgumentException('Invalid author.');
        }
        if ($body === '' && $photo === null) {
            throw new \InvalidArgumentException('Write something or add a photo.');
        }
        if (mb_strlen($body) > 5000) {
            throw new \InvalidArgumentException('Post is too long.');
        }
        if (!in_array($visibility, ['friends', 'public'], true)) {
            $visibility = 'friends';
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) $this->db->insert('community_posts', [
            'user_id'    => $userId,
            'body'       => $body,
            'visibility' => $visibility,
            'status'     => 'visible',
            'created_at' => $now,
            'updated_at' => null,
        ]);

        if ($photo !== null) {
            $this->db->insert('community_post_media', [
                'post_id'    => $id,
                'path'       => $photo['path'],
                'mime'       => $photo['mime'],
                'sort_order' => 0,
                'created_at' => $now,
            ]);
        }

        $post = $this->find($id);
        if ($post === null) {
            throw new \RuntimeException('Could not load created post.');
        }
        return $post;
    }

    public function softDelete(int $postId, int $userId, bool $asAdmin = false): void
    {
        $post = $this->find($postId);
        if ($post === null || ($post['status'] ?? '') === 'deleted') {
            throw new \RuntimeException('Post not found.');
        }
        if (!$asAdmin && (int) $post['user_id'] !== $userId) {
            throw new \RuntimeException('You can only delete your own posts.');
        }
        $this->db->update('community_posts', [
            'status'     => 'deleted',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $postId]);
    }

    public function restore(int $postId): void
    {
        $this->db->update('community_posts', [
            'status'     => 'visible',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $postId]);
    }

    public function canView(array $post, int $viewerId, FriendshipRepository $friends): bool
    {
        if (($post['status'] ?? '') !== 'visible') {
            return false;
        }
        $authorId = (int) ($post['user_id'] ?? 0);
        if ($authorId === $viewerId) {
            return true;
        }
        if (($post['visibility'] ?? '') === 'public') {
            return true;
        }
        return $friends->areFriends($viewerId, $authorId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mediaForPost(int $postId): array
    {
        if ($postId < 1) {
            return [];
        }
        return $this->db->query(
            'SELECT * FROM `#__community_post_media`
              WHERE `post_id` = ?
              ORDER BY `sort_order` ASC, `id` ASC',
            [$postId]
        )->rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachMedia(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $mediaRows = $this->db->query(
            "SELECT * FROM `#__community_post_media`
              WHERE `post_id` IN ({$placeholders})
              ORDER BY `sort_order` ASC, `id` ASC",
            $ids
        )->rows;
        $byPost = [];
        foreach ($mediaRows as $m) {
            $byPost[(int) $m['post_id']][] = $m;
        }
        foreach ($rows as &$row) {
            $row['media'] = $byPost[(int) $row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }
}
