<?php
declare(strict_types=1);

namespace Module\Media\Services;

use System\Library\Database;

final class VideoRepository
{
    public function __construct(private Database $db) {}

    public function find(string $id): ?array
    {
        require_once dirname(__DIR__) . '/helpers/functions.php';
        $id = media_normalize_video_id($id);
        if ($id === '') {
            return null;
        }
        $row = $this->db->query(
            'SELECT v.*, c.title AS channel_title, c.avatar AS channel_avatar, c.slug AS channel_slug
             FROM `#__media_videos` v
             INNER JOIN `#__media_channels` c ON c.channel_id = v.channel_id
             WHERE v.video_id = ?
             LIMIT 1',
            [$id]
        )->row;
        return $row === [] ? null : $row;
    }

    public function findPublic(string $id): ?array
    {
        $row = $this->find($id);
        if ($row === null || ($row['status'] ?? '') !== 'public') {
            return null;
        }
        return $row;
    }

    public function idExists(string $id): bool
    {
        require_once dirname(__DIR__) . '/helpers/functions.php';
        $id = media_normalize_video_id($id);
        if ($id === '') {
            return false;
        }
        $row = $this->db->query(
            'SELECT 1 FROM `#__media_videos` WHERE `video_id` = ? LIMIT 1',
            [$id]
        )->row;
        return $row !== [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function feed(string $format = 'all', string $q = '', int $limit = 24, int $offset = 0): array
    {
        $where = ["v.status = 'public'"];
        $params = [];

        if ($format === 'audio') {
            $where[] = "v.media_type = 'audio'";
        } elseif ($format === 'images') {
            $where[] = "v.media_type = 'image'";
        } elseif ($format === 'video') {
            $where[] = "v.media_type IN ('video','embed')";
        }

        if ($q !== '') {
            $where[] = '(v.title LIKE ? OR c.title LIKE ? OR v.description LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sqlWhere = implode(' AND ', $where);
        $limit = max(1, min(48, $limit));
        $offset = max(0, $offset);

        return $this->db->query(
            "SELECT v.*, c.title AS channel_title, c.avatar AS channel_avatar
             FROM `#__media_videos` v
             INNER JOIN `#__media_channels` c ON c.channel_id = v.channel_id
             WHERE {$sqlWhere}
             ORDER BY v.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forChannel(int $channelId, bool $publicOnly, int $limit = 12, int $offset = 0): array
    {
        $where = ['v.channel_id = ?'];
        $params = [$channelId];
        if ($publicOnly) {
            $where[] = "v.status = 'public'";
        }
        $sqlWhere = implode(' AND ', $where);
        $limit = max(1, min(48, $limit));
        $offset = max(0, $offset);

        return $this->db->query(
            "SELECT v.* FROM `#__media_videos` v
             WHERE {$sqlWhere}
             ORDER BY v.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->rows;
    }

    public function countForChannel(int $channelId, bool $publicOnly = true): int
    {
        $sql = 'SELECT COUNT(*) FROM `#__media_videos` WHERE `channel_id` = ?';
        $params = [$channelId];
        if ($publicOnly) {
            $sql .= " AND `status` = 'public'";
        }
        return (int) $this->db->query($sql, $params)->value;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recommendations(string $excludeId, int $limit = 8): array
    {
        require_once dirname(__DIR__) . '/helpers/functions.php';
        $excludeId = media_normalize_video_id($excludeId);
        $limit = max(1, min(20, $limit));
        return $this->db->query(
            "SELECT v.video_id, v.title, v.file_path, v.source_url, v.media_type,
                    v.thumbnail_path, v.views_count, v.created_at, c.title AS channel_title
             FROM `#__media_videos` v
             INNER JOIN `#__media_channels` c ON c.channel_id = v.channel_id
             WHERE v.video_id != ? AND v.status = 'public'
             ORDER BY v.created_at DESC
             LIMIT {$limit}",
            [$excludeId]
        )->rows;
    }

    /**
     * @param array{
     *   channel_id:int,uploaded_by:int,title:string,description?:string,
     *   media_type:string,file_path?:?string,source_url?:?string,
     *   thumbnail_path?:?string,status?:string
     * } $data
     */
    public function create(array $data): string
    {
        require_once dirname(__DIR__) . '/helpers/functions.php';

        $videoId = media_new_video_id(fn(string $id): bool => $this->idExists($id));
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'INSERT INTO `#__media_videos`
                (`video_id`,`channel_id`,`uploaded_by`,`title`,`description`,`media_type`,
                 `file_path`,`source_url`,`thumbnail_path`,`status`,`views_count`,`created_at`,`updated_at`)
             VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?)',
            [
                $videoId,
                (int) $data['channel_id'],
                (int) $data['uploaded_by'],
                (string) $data['title'],
                $data['description'] ?? null,
                (string) $data['media_type'],
                $data['file_path'] ?? null,
                $data['source_url'] ?? null,
                $data['thumbnail_path'] ?? null,
                $data['status'] ?? 'public',
                $now,
                $now,
            ]
        );
        return $videoId;
    }

    public function updateMeta(string $videoId, int $channelId, string $title, string $status, string $description = ''): void
    {
        require_once dirname(__DIR__) . '/helpers/functions.php';
        $videoId = media_normalize_video_id($videoId);
        if ($videoId === '') {
            return;
        }
        $this->db->query(
            'UPDATE `#__media_videos`
             SET `title` = ?, `status` = ?, `description` = ?, `updated_at` = ?
             WHERE `video_id` = ? AND `channel_id` = ?',
            [$title, $status, $description !== '' ? $description : null, date('Y-m-d H:i:s'), $videoId, $channelId]
        );
    }

    public function delete(string $videoId, int $channelId): ?array
    {
        require_once dirname(__DIR__) . '/helpers/functions.php';
        $videoId = media_normalize_video_id($videoId);
        if ($videoId === '') {
            return null;
        }
        $row = $this->db->query(
            'SELECT `file_path`, `thumbnail_path` FROM `#__media_videos`
             WHERE `video_id` = ? AND `channel_id` = ? LIMIT 1',
            [$videoId, $channelId]
        )->row;
        if ($row === []) {
            return null;
        }
        $this->db->query(
            'DELETE FROM `#__media_video_comments` WHERE `video_id` = ?',
            [$videoId]
        );
        try {
            $this->db->query(
                "DELETE FROM `#__comments`
                  WHERE `module` = 'media' AND `entity_type` = 'video' AND `entity_id` = ?",
                [$videoId]
            );
        } catch (\Throwable) {
            // Shared comments table may not exist yet.
        }
        $this->db->query(
            'DELETE FROM `#__media_video_reactions` WHERE `video_id` = ?',
            [$videoId]
        );
        $this->db->query(
            'DELETE FROM `#__media_videos` WHERE `video_id` = ? AND `channel_id` = ?',
            [$videoId, $channelId]
        );
        return $row;
    }

    public function reactionCounts(string $videoId): array
    {
        $likes = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__media_video_reactions` WHERE `video_id` = ? AND `type` = 'like'",
            [$videoId]
        )->value;
        $dislikes = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__media_video_reactions` WHERE `video_id` = ? AND `type` = 'dislike'",
            [$videoId]
        )->value;
        return ['likes' => $likes, 'dislikes' => $dislikes];
    }

    public function userReaction(string $videoId, int $userId): ?string
    {
        if ($userId < 1) {
            return null;
        }
        $row = $this->db->query(
            'SELECT `type` FROM `#__media_video_reactions` WHERE `video_id` = ? AND `user_id` = ? LIMIT 1',
            [$videoId, $userId]
        )->row;
        return $row === [] ? null : (string) $row['type'];
    }

    public function countAll(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM `#__media_videos`')->value;
    }

    public function countPublic(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM `#__media_videos` WHERE `status` = 'public'")->value;
    }

    public function totalViews(): int
    {
        return (int) $this->db->query('SELECT COALESCE(SUM(`views_count`),0) FROM `#__media_videos`')->value;
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function adminSearch(string $q, string $status, string $type, int $limit, int $offset, string $sort, string $dir): array
    {
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(v.`title` LIKE ? OR c.`title` LIKE ? OR v.`video_id` LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if (in_array($status, ['public', 'private', 'processing'], true)) {
            $where[] = 'v.`status` = ?';
            $params[] = $status;
        }
        if (in_array($type, ['video', 'audio', 'image', 'embed'], true)) {
            $where[] = 'v.`media_type` = ?';
            $params[] = $type;
        }
        $sqlWhere = implode(' AND ', $where);

        $sortMap = [
            'title'       => 'v.`title`',
            'created_at'  => 'v.`created_at`',
            'views_count' => 'v.`views_count`',
            'status'      => 'v.`status`',
        ];
        $orderCol = $sortMap[$sort] ?? 'v.`created_at`';
        $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

        $total = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__media_videos` v
             INNER JOIN `#__media_channels` c ON c.channel_id = v.channel_id
             WHERE {$sqlWhere}",
            $params
        )->value;

        $rows = $this->db->query(
            "SELECT v.*, c.title AS channel_title
             FROM `#__media_videos` v
             INNER JOIN `#__media_channels` c ON c.channel_id = v.channel_id
             WHERE {$sqlWhere}
             ORDER BY {$orderCol} {$dirSql}, v.`created_at` DESC
             LIMIT ?, ?",
            [...$params, $offset, $limit]
        )->rows;

        return ['rows' => $rows, 'total' => $total];
    }
}
