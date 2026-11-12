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

    /**
     * Streams the user owns plus streams they manage.
     *
     * @return list<array<string, mixed>>
     */
    public function listForStudio(int $userId, int $limit = 100): array
    {
        if ($userId < 1) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        return $this->db->query(
            "SELECT s.*,
                    CASE WHEN s.`user_id` = ? THEN 'owner' ELSE 'manage' END AS `access`
               FROM `#__livestream_streams` s
               LEFT JOIN `#__livestream_stream_members` m
                 ON m.`stream_id` = s.`id` AND m.`user_id` = ?
              WHERE s.`user_id` = ? OR m.`user_id` = ?
              ORDER BY
                CASE WHEN s.`status` = 'live' THEN 0 ELSE 1 END,
                s.`id` DESC
              LIMIT {$limit}",
            [$userId, $userId, $userId, $userId]
        )->rows;
    }

    /**
     * Owner or manager. Includes `access` = owner|manage.
     *
     * @return array<string, mixed>|null
     */
    public function findAccessible(int $id, int $userId): ?array
    {
        if ($id < 1 || $userId < 1) {
            return null;
        }
        $row = $this->db->query(
            "SELECT s.*,
                    CASE WHEN s.`user_id` = ? THEN 'owner' ELSE 'manage' END AS `access`
               FROM `#__livestream_streams` s
               LEFT JOIN `#__livestream_stream_members` m
                 ON m.`stream_id` = s.`id` AND m.`user_id` = ?
              WHERE s.`id` = ?
                AND (s.`user_id` = ? OR m.`user_id` = ?)
              LIMIT 1",
            [$userId, $userId, $id, $userId, $userId]
        )->row;
        return $row === [] || $row === null ? null : $row;
    }

    public function findUserIdByUsername(string $username): int
    {
        $username = trim($username);
        if ($username === '') {
            return 0;
        }
        $row = $this->db->query(
            'SELECT `id` FROM `#__users` WHERE `username` = ? LIMIT 1',
            [$username]
        )->row;
        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listManagers(int $streamId): array
    {
        if ($streamId < 1) {
            return [];
        }
        return $this->db->query(
            "SELECT m.`user_id`, m.`created_at`,
                    COALESCE(u.`username`, '') AS `username`
               FROM `#__livestream_stream_members` m
               LEFT JOIN `#__users` u ON u.`id` = m.`user_id`
              WHERE m.`stream_id` = ?
              ORDER BY m.`id` ASC",
            [$streamId]
        )->rows;
    }

    /** Empty string on success, otherwise an error notice. */
    public function addManager(int $streamId, int $ownerId, int $managerId): string
    {
        if ($this->findOwned($streamId, $ownerId) === null) {
            return 'Stream not found.';
        }
        if ($managerId < 1) {
            return 'No user with that username.';
        }
        if ($managerId === $ownerId) {
            return 'You already own this stream.';
        }
        $existing = $this->db->query(
            'SELECT `id` FROM `#__livestream_stream_members`
              WHERE `stream_id` = ? AND `user_id` = ? LIMIT 1',
            [$streamId, $managerId]
        )->row;
        if (is_array($existing) && $existing !== []) {
            return 'That user is already a manager.';
        }
        $this->db->insert('livestream_stream_members', [
            'stream_id'  => $streamId,
            'user_id'    => $managerId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return '';
    }

    public function removeManager(int $streamId, int $ownerId, int $managerId): bool
    {
        if ($this->findOwned($streamId, $ownerId) === null || $managerId < 1) {
            return false;
        }
        $this->db->query(
            'DELETE FROM `#__livestream_stream_members` WHERE `stream_id` = ? AND `user_id` = ?',
            [$streamId, $managerId]
        );
        return true;
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

    /** @return array<string, mixed>|null */
    public function findByShareToken(string $token): ?array
    {
        $token = trim($token);
        if (strlen($token) < 32) {
            return null;
        }
        $row = $this->db->query(
            'SELECT * FROM `#__livestream_streams` WHERE `share_token` = ? LIMIT 1',
            [$token]
        )->row;
        return $row === [] || $row === null ? null : $row;
    }

    public function ensureShareToken(int $id): string
    {
        $stream = $this->find($id);
        if ($stream === null) {
            return '';
        }
        $existing = trim((string) ($stream['share_token'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }
        $token = bin2hex(random_bytes(24));
        $this->db->update('livestream_streams', [
            'share_token' => $token,
            'updated_at'  => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
        return $token;
    }

    public function revokeShareToken(int $id): void
    {
        if ($id < 1) {
            return;
        }
        $this->db->update('livestream_streams', [
            'share_token' => null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function studioShareToken(int $userId): string
    {
        if ($userId < 1) {
            return '';
        }
        $row = $this->db->query(
            'SELECT `share_token` FROM `#__livestream_studio_shares` WHERE `user_id` = ? LIMIT 1',
            [$userId]
        )->row;
        return is_array($row) ? trim((string) ($row['share_token'] ?? '')) : '';
    }

    public function ensureStudioShare(int $userId): string
    {
        $existing = $this->studioShareToken($userId);
        if ($existing !== '') {
            return $existing;
        }
        $token = bin2hex(random_bytes(24));
        $this->db->insert('livestream_studio_shares', [
            'user_id'     => $userId,
            'share_token' => $token,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    public function revokeStudioShare(int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        $this->db->query(
            'DELETE FROM `#__livestream_studio_shares` WHERE `user_id` = ?',
            [$userId]
        );
    }

    public function userIdByStudioShare(string $token): int
    {
        $token = trim($token);
        if (strlen($token) < 32) {
            return 0;
        }
        $row = $this->db->query(
            'SELECT `user_id` FROM `#__livestream_studio_shares` WHERE `share_token` = ? LIMIT 1',
            [$token]
        )->row;
        return is_array($row) ? (int) ($row['user_id'] ?? 0) : 0;
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
