<?php
declare(strict_types=1);

namespace Module\Comments\Services;

use System\Library\Database;

final class CommentRepository
{
    public function __construct(private Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forEntity(
        string $module,
        string $entityType,
        string $entityId,
        int $limit = 50,
        bool $visibleOnly = true,
    ): array {
        $module = $this->sanitizeKey($module);
        $entityType = $this->sanitizeKey($entityType);
        $entityId = trim($entityId);
        if ($module === '' || $entityType === '' || $entityId === '') {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $statusSql = $visibleOnly ? "AND c.`status` = 'visible'" : "AND c.`status` <> 'deleted'";

        return $this->db->query(
            "SELECT c.*,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS username
               FROM `#__comments` c
               LEFT JOIN `#__users` u ON u.`id` = c.`user_id`
              WHERE c.`module` = ?
                AND c.`entity_type` = ?
                AND c.`entity_id` = ?
                {$statusSql}
              ORDER BY c.`created_at` DESC, c.`id` DESC
              LIMIT {$limit}",
            [$module, $entityType, $entityId]
        )->rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 40, ?string $module = null): array
    {
        $limit = max(1, min(100, $limit));
        $params = [];
        $moduleSql = '';
        if ($module !== null && $module !== '') {
            $moduleSql = 'AND c.`module` = ?';
            $params[] = $this->sanitizeKey($module);
        }

        return $this->db->query(
            "SELECT c.*,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS username
               FROM `#__comments` c
               LEFT JOIN `#__users` u ON u.`id` = c.`user_id`
              WHERE c.`status` = 'visible'
                {$moduleSql}
              ORDER BY c.`created_at` DESC, c.`id` DESC
              LIMIT {$limit}",
            $params
        )->rows;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function adminSearch(
        string $q,
        string $module,
        string $status,
        int $limit,
        int $offset,
    ): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $where = ['1=1'];
        $params = [];

        if ($q !== '') {
            $where[] = '(c.`body` LIKE ? OR u.`username` LIKE ? OR u.`display_name` LIKE ? OR c.`entity_id` LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($module !== '') {
            $where[] = 'c.`module` = ?';
            $params[] = $this->sanitizeKey($module);
        }
        if ($status !== '') {
            $where[] = 'c.`status` = ?';
            $params[] = $status;
        } else {
            $where[] = "c.`status` <> 'deleted'";
        }

        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->query(
            "SELECT COUNT(*)
               FROM `#__comments` c
               LEFT JOIN `#__users` u ON u.`id` = c.`user_id`
              WHERE {$sqlWhere}",
            $params
        )->value;

        $rows = $this->db->query(
            "SELECT c.*,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS username
               FROM `#__comments` c
               LEFT JOIN `#__users` u ON u.`id` = c.`user_id`
              WHERE {$sqlWhere}
              ORDER BY c.`created_at` DESC, c.`id` DESC
              LIMIT {$limit} OFFSET {$offset}",
            $params
        )->rows;

        return ['rows' => $rows, 'total' => $total];
    }

    public function create(
        string $module,
        string $entityType,
        string $entityId,
        int $userId,
        string $body,
    ): array {
        $module = $this->sanitizeKey($module);
        $entityType = $this->sanitizeKey($entityType);
        $entityId = trim($entityId);
        $body = trim($body);
        if ($module === '' || $entityType === '' || $entityId === '' || $userId < 1 || $body === '') {
            throw new \InvalidArgumentException('Invalid comment.');
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) $this->db->insert('comments', [
            'module'      => $module,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'user_id'     => $userId,
            'body'        => $body,
            'status'      => 'visible',
            'created_at'  => $now,
            'updated_at'  => null,
        ]);

        $row = $this->find($id);
        return $row ?? [
            'id'         => $id,
            'module'     => $module,
            'entity_type'=> $entityType,
            'entity_id'  => $entityId,
            'user_id'    => $userId,
            'body'       => $body,
            'status'     => 'visible',
            'created_at' => $now,
            'username'   => 'User',
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = $this->db->query(
            "SELECT c.*,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS username
               FROM `#__comments` c
               LEFT JOIN `#__users` u ON u.`id` = c.`user_id`
              WHERE c.`id` = ?
              LIMIT 1",
            [$id]
        )->row;
        return $row === [] ? null : $row;
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['visible', 'hidden', 'deleted'], true)) {
            throw new \InvalidArgumentException('Invalid status.');
        }
        $this->db->update('comments', [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function deleteForEntity(string $module, string $entityType, string $entityId): void
    {
        $this->db->query(
            "DELETE FROM `#__comments`
              WHERE `module` = ? AND `entity_type` = ? AND `entity_id` = ?",
            [$this->sanitizeKey($module), $this->sanitizeKey($entityType), trim($entityId)]
        );
    }

    /**
     * Resolve a public title + URL for hub/admin display.
     *
     * @return array{title: string, url: string}
     */
    public function resolveEntity(string $module, string $entityType, string $entityId): array
    {
        $module = $this->sanitizeKey($module);
        $entityType = $this->sanitizeKey($entityType);
        $entityId = trim($entityId);

        try {
            if ($module === 'media' && $entityType === 'video') {
                $row = $this->db->query(
                    'SELECT `title`, `video_id` FROM `#__media_videos` WHERE `video_id` = ? LIMIT 1',
                    [$entityId]
                )->row;
                if ($row !== []) {
                    return [
                        'title' => (string) $row['title'],
                        'url'   => url('media/watch', ['id' => (string) $row['video_id']]),
                    ];
                }
            }

            if ($module === 'news' && $entityType === 'article') {
                $row = $this->db->query(
                    'SELECT `title`, `slug` FROM `#__news` WHERE `id` = ? LIMIT 1',
                    [(int) $entityId]
                )->row;
                if ($row !== []) {
                    return [
                        'title' => (string) $row['title'],
                        'url'   => url('news/view', ['slug' => (string) $row['slug']]),
                    ];
                }
            }
        } catch (\Throwable) {
            // Entity table may be missing.
        }

        return [
            'title' => $module . '/' . $entityType . ' #' . $entityId,
            'url'   => url('comments'),
        ];
    }

    public function entityExists(string $module, string $entityType, string $entityId): bool
    {
        $module = $this->sanitizeKey($module);
        $entityType = $this->sanitizeKey($entityType);
        $entityId = trim($entityId);

        try {
            if ($module === 'media' && $entityType === 'video') {
                $row = $this->db->query(
                    "SELECT 1 AS ok FROM `#__media_videos`
                      WHERE `video_id` = ? AND `status` = 'public' LIMIT 1",
                    [$entityId]
                )->row;
                return $row !== [];
            }
            if ($module === 'news' && $entityType === 'article') {
                $row = $this->db->query(
                    "SELECT 1 AS ok FROM `#__news`
                      WHERE `id` = ? AND `status` = 'published' LIMIT 1",
                    [(int) $entityId]
                )->row;
                return $row !== [];
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function sanitizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9_-]+$/', $value) ? $value : '';
    }
}
