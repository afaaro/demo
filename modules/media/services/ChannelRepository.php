<?php
declare(strict_types=1);

namespace Module\Media\Services;

use System\Library\Database;

final class ChannelRepository
{
    public function __construct(private Database $db) {}

    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = $this->db->query(
            'SELECT * FROM `#__media_channels` WHERE `channel_id` = ? LIMIT 1',
            [$id]
        )->row;
        return $row === [] ? null : $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM `#__media_channels` WHERE `slug` = ? LIMIT 1',
            [$slug]
        )->row;
        return $row === [] ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->query(
            'SELECT c.*, s.role
             FROM `#__media_channels` c
             INNER JOIN `#__media_channel_staff` s ON s.channel_id = c.channel_id
             WHERE s.user_id = ?
             ORDER BY c.title ASC',
            [$userId]
        )->rows;
    }

    public function staffRole(int $channelId, int $userId): ?string
    {
        $row = $this->db->query(
            'SELECT `role` FROM `#__media_channel_staff`
             WHERE `channel_id` = ? AND `user_id` = ? LIMIT 1',
            [$channelId, $userId]
        )->row;
        return $row === [] ? null : (string) $row['role'];
    }

    public function canManage(int $channelId, int $userId): bool
    {
        return $this->staffRole($channelId, $userId) !== null;
    }

    public function canDelete(int $channelId, int $userId): bool
    {
        return in_array($this->staffRole($channelId, $userId), ['owner', 'manager'], true);
    }

    public function create(int $ownerId, string $title, string $description = ''): int
    {
        require_once dirname(__DIR__) . '/helpers/functions.php';

        $base = media_slugify($title);
        $slug = $base;
        $n = 1;
        while ($this->findBySlug($slug) !== null) {
            $slug = $base . '-' . $n;
            $n++;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'INSERT INTO `#__media_channels`
                (`owner_id`, `title`, `slug`, `description`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$ownerId, $title, $slug, $description !== '' ? $description : null, $now, $now]
        );

        $channelId = (int) $this->db->getLastId();
        $this->db->query(
            'INSERT INTO `#__media_channel_staff` (`channel_id`, `user_id`, `role`, `assigned_at`)
             VALUES (?, ?, \'owner\', ?)',
            [$channelId, $ownerId, $now]
        );

        return $channelId;
    }

    public function countAll(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM `#__media_channels`')->value;
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function adminSearch(string $q, int $limit, int $offset, string $sort, string $dir): array
    {
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(c.`title` LIKE ? OR c.`slug` LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sqlWhere = implode(' AND ', $where);

        $sortMap = [
            'title'      => 'c.`title`',
            'created_at' => 'c.`created_at`',
            'videos'     => 'video_count',
        ];
        $orderCol = $sortMap[$sort] ?? 'c.`created_at`';
        $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

        $total = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__media_channels` c WHERE {$sqlWhere}",
            $params
        )->value;

        $rows = $this->db->query(
            "SELECT c.*,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`) AS owner_name,
                    (SELECT COUNT(*) FROM `#__media_videos` v WHERE v.channel_id = c.channel_id) AS video_count
             FROM `#__media_channels` c
             LEFT JOIN `#__users` u ON u.`id` = c.`owner_id`
             WHERE {$sqlWhere}
             ORDER BY {$orderCol} {$dirSql}, c.`channel_id` DESC
             LIMIT ?, ?",
            [...$params, $offset, $limit]
        )->rows;

        return ['rows' => $rows, 'total' => $total];
    }
}
