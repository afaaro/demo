<?php
declare(strict_types=1);

namespace Module\Livestream\Services;

use System\Library\Database;

final class StreamRepository
{
    public function __construct(private Database $db) {}

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = $this->db->query(
            'SELECT * FROM `#__livestream_streams` WHERE `id` = ? LIMIT 1',
            [$id]
        )->row;
        return $row === [] ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $row = $this->db->query(
            'SELECT * FROM `#__livestream_streams` WHERE `slug` = ? LIMIT 1',
            [$slug]
        )->row;
        return $row === [] ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByUser(int $userId): ?array
    {
        $rows = $this->listByUser($userId, 1);
        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByUser(int $userId, int $limit = 50): array
    {
        if ($userId < 1) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        return $this->db->query(
            "SELECT * FROM `#__livestream_streams`
              WHERE `user_id` = ?
              ORDER BY
                CASE WHEN `status` = 'live' THEN 0 ELSE 1 END,
                `id` DESC
              LIMIT {$limit}",
            [$userId]
        )->rows;
    }

    /** @return array<string, mixed>|null */
    public function findOwned(int $id, int $userId): ?array
    {
        if ($id < 1 || $userId < 1) {
            return null;
        }
        $row = $this->db->query(
            'SELECT * FROM `#__livestream_streams` WHERE `id` = ? AND `user_id` = ? LIMIT 1',
            [$id, $userId]
        )->row;
        return $row === [] ? null : $row;
    }

    public function deleteOwned(int $id, int $userId): bool
    {
        if ($id < 1 || $userId < 1) {
            return false;
        }
        return $this->db->delete('livestream_streams', [
            'id'      => $id,
            'user_id' => $userId,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->query(
            "SELECT s.*,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS username
               FROM `#__livestream_streams` s
               LEFT JOIN `#__users` u ON u.`id` = s.`user_id`
              ORDER BY
                CASE WHEN s.`status` = 'live' THEN 0 ELSE 1 END,
                COALESCE(s.`started_at`, s.`created_at`) DESC
              LIMIT {$limit}"
        )->rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLive(int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->query(
            "SELECT s.*,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`, 'User') AS username
               FROM `#__livestream_streams` s
               LEFT JOIN `#__users` u ON u.`id` = s.`user_id`
              WHERE s.`status` = 'live'
              ORDER BY s.`started_at` DESC
              LIMIT {$limit}"
        )->rows;
    }

    public function create(int $userId, string $title, string $slug, string $streamKey): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) $this->db->insert('livestream_streams', [
            'user_id'    => $userId,
            'title'      => $title,
            'slug'       => $slug,
            'stream_key' => $streamKey,
            'status'     => 'idle',
            'created_at' => $now,
            'updated_at' => null,
        ]);
    }

    /**
     * Mirror a remote-authenticated publish into the local DB (home MediaMTX).
     * Keeps local status hooks working for streams created on soomaali.net.
     */
    public function upsertMirrored(string $slug, string $streamKey, string $title = ''): int
    {
        $slug = trim($slug);
        $streamKey = trim($streamKey);
        if ($slug === '' || $streamKey === '') {
            return 0;
        }
        $title = trim($title) !== '' ? trim($title) : $slug;
        $existing = $this->findBySlug($slug);
        $now = date('Y-m-d H:i:s');
        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->db->update('livestream_streams', [
                'stream_key' => $streamKey,
                'title'      => $title,
                'updated_at' => $now,
            ], ['id' => $id]);
            return $id;
        }
        return $this->create(1, $title, $slug, $streamKey);
    }

    public function updateTitle(int $id, string $title): void
    {
        $this->db->update('livestream_streams', [
            'title'      => $title,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function rotateKey(int $id, string $streamKey): void
    {
        $this->db->update('livestream_streams', [
            'stream_key' => $streamKey,
            'status'     => 'idle',
            'ended_at'   => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function markLive(int $id): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->update('livestream_streams', [
            'status'     => 'live',
            'started_at' => $now,
            'ended_at'   => null,
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    public function markIdle(int $id): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->update('livestream_streams', [
            'status'     => 'idle',
            'ended_at'   => $now,
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            $row = $this->db->query(
                'SELECT 1 AS ok FROM `#__livestream_streams` WHERE `slug` = ? AND `id` <> ? LIMIT 1',
                [$slug, $exceptId]
            )->row;
        } else {
            $row = $this->db->query(
                'SELECT 1 AS ok FROM `#__livestream_streams` WHERE `slug` = ? LIMIT 1',
                [$slug]
            )->row;
        }
        return $row !== [];
    }
}
