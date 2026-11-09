<?php
declare(strict_types=1);

namespace Module\Community\Services;

use System\Library\Database;

final class FriendshipRepository
{
    public function __construct(private Database $db) {}

    /**
     * @return array{low:int,high:int}
     */
    public function pair(int $a, int $b): array
    {
        return $a < $b
            ? ['low' => $a, 'high' => $b]
            : ['low' => $b, 'high' => $a];
    }

    /** @return array<string, mixed>|null */
    public function findBetween(int $a, int $b): ?array
    {
        if ($a < 1 || $b < 1 || $a === $b) {
            return null;
        }
        $pair = $this->pair($a, $b);
        $row = $this->db->query(
            'SELECT * FROM `#__community_friendships`
              WHERE `user_id` = ? AND `friend_id` = ?
              LIMIT 1',
            [$pair['low'], $pair['high']]
        )->row;
        return $row === [] ? null : $row;
    }

    public function areFriends(int $a, int $b): bool
    {
        $row = $this->findBetween($a, $b);
        return $row !== null && ($row['status'] ?? '') === 'accepted';
    }

    /**
     * Relationship from $viewer toward $other.
     *
     * @return 'none'|'friends'|'outgoing'|'incoming'|'declined'
     */
    public function statusBetween(int $viewer, int $other): string
    {
        $row = $this->findBetween($viewer, $other);
        return $this->statusFromRow($viewer, $row);
    }

    /**
     * Batch relationship status for search/list UIs (avoids N+1).
     *
     * @param list<int> $otherIds
     * @return array<int, 'none'|'friends'|'outgoing'|'incoming'|'declined'>
     */
    public function statusesBetween(int $viewer, array $otherIds): array
    {
        $out = [];
        $ids = [];
        foreach ($otherIds as $id) {
            $id = (int) $id;
            if ($id < 1 || $id === $viewer) {
                continue;
            }
            $ids[$id] = $id;
            $out[$id] = 'none';
        }
        if ($viewer < 1 || $ids === []) {
            return $out;
        }

        $idsList = array_values($ids);
        $placeholders = implode(',', array_fill(0, count($idsList), '?'));
        $rows = $this->db->query(
            "SELECT * FROM `#__community_friendships`
              WHERE (`user_id` = ? AND `friend_id` IN ({$placeholders}))
                 OR (`friend_id` = ? AND `user_id` IN ({$placeholders}))",
            array_merge([$viewer], $idsList, [$viewer], $idsList)
        )->rows;

        foreach ($rows as $row) {
            $a = (int) ($row['user_id'] ?? 0);
            $b = (int) ($row['friend_id'] ?? 0);
            $other = $a === $viewer ? $b : $a;
            if (isset($out[$other])) {
                $out[$other] = $this->statusFromRow($viewer, $row);
            }
        }
        return $out;
    }

    /** @return list<int> */
    public function friendIds(int $userId, int $limit = 500): array
    {
        if ($userId < 1) {
            return [];
        }
        $limit = max(1, min(2000, $limit));
        $rows = $this->db->query(
            "SELECT `user_id`, `friend_id`
               FROM `#__community_friendships`
              WHERE `status` = 'accepted'
                AND (`user_id` = ? OR `friend_id` = ?)
              LIMIT {$limit}",
            [$userId, $userId]
        )->rows;

        $ids = [];
        foreach ($rows as $row) {
            $a = (int) $row['user_id'];
            $b = (int) $row['friend_id'];
            $other = $a === $userId ? $b : $a;
            if ($other > 0) {
                $ids[$other] = $other;
            }
        }
        return array_values($ids);
    }

    public function friendCount(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__community_friendships`
              WHERE `status` = 'accepted'
                AND (`user_id` = ? OR `friend_id` = ?)",
            [$userId, $userId]
        )->value;
    }

    /**
     * @return list<array{id:int,username:string,display_name:?string}>
     */
    public function mutualFriends(int $viewerId, int $otherId, int $limit = 6): array
    {
        if ($viewerId < 1 || $otherId < 1 || $viewerId === $otherId) {
            return [];
        }
        $a = $this->friendIds($viewerId);
        $b = $this->friendIds($otherId);
        if ($a === [] || $b === []) {
            return [];
        }
        $mutual = array_values(array_intersect($a, $b));
        if ($mutual === []) {
            return [];
        }
        $limit = max(1, min(24, $limit));
        $slice = array_slice($mutual, 0, $limit);
        $placeholders = implode(',', array_fill(0, count($slice), '?'));
        return $this->db->query(
            "SELECT u.`id`, u.`username`, u.`display_name`
               FROM `#__users` u
              WHERE u.`id` IN ({$placeholders})
                AND u.`status` = 'active'
              ORDER BY COALESCE(NULLIF(u.`display_name`, ''), u.`username`)
              LIMIT {$limit}",
            $slice
        )->rows;
    }

    public function mutualFriendCount(int $viewerId, int $otherId): int
    {
        if ($viewerId < 1 || $otherId < 1 || $viewerId === $otherId) {
            return 0;
        }
        $a = $this->friendIds($viewerId);
        $b = $this->friendIds($otherId);
        if ($a === [] || $b === []) {
            return 0;
        }
        return count(array_intersect($a, $b));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFriends(int $userId, int $limit = 200): array
    {
        $ids = $this->friendIds($userId, $limit);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->query(
            "SELECT u.`id`, u.`username`, u.`display_name`
               FROM `#__users` u
              WHERE u.`id` IN ({$placeholders})
              ORDER BY COALESCE(NULLIF(u.`display_name`, ''), u.`username`)",
            $ids
        )->rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingIncoming(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->query(
            "SELECT f.*,
                    u.`username`,
                    u.`display_name`
               FROM `#__community_friendships` f
               INNER JOIN `#__users` u ON u.`id` = f.`requested_by`
              WHERE f.`status` = 'pending'
                AND f.`requested_by` <> ?
                AND (f.`user_id` = ? OR f.`friend_id` = ?)
              ORDER BY f.`created_at` DESC
              LIMIT {$limit}",
            [$userId, $userId, $userId]
        )->rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingOutgoing(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->query(
            "SELECT f.*,
                    CASE WHEN f.`user_id` = ? THEN f.`friend_id` ELSE f.`user_id` END AS other_id
               FROM `#__community_friendships` f
              WHERE f.`status` = 'pending'
                AND f.`requested_by` = ?
              ORDER BY f.`created_at` DESC
              LIMIT {$limit}",
            [$userId, $userId]
        )->rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingOutgoingWithUsers(int $userId): array
    {
        $rows = $this->pendingOutgoing($userId);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn(array $r): int => (int) ($r['other_id'] ?? 0), $rows);
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $users = $this->db->query(
            "SELECT `id`, `username`, `display_name`
               FROM `#__users`
              WHERE `id` IN ({$placeholders})",
            $ids
        )->rows;
        $byId = [];
        foreach ($users as $u) {
            $byId[(int) $u['id']] = $u;
        }
        $out = [];
        foreach ($rows as $row) {
            $other = (int) ($row['other_id'] ?? 0);
            if (!isset($byId[$other])) {
                continue;
            }
            $out[] = array_merge($row, $byId[$other], ['other_id' => $other]);
        }
        return $out;
    }

    public function request(int $fromUserId, int $toUserId): array
    {
        if ($fromUserId < 1 || $toUserId < 1 || $fromUserId === $toUserId) {
            throw new \InvalidArgumentException('Invalid friend request.');
        }

        $target = $this->db->query(
            "SELECT `id` FROM `#__users` WHERE `id` = ? AND `status` = 'active' LIMIT 1",
            [$toUserId]
        )->row;
        if ($target === []) {
            throw new \InvalidArgumentException('User not found.');
        }

        $existing = $this->findBetween($fromUserId, $toUserId);
        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            $status = (string) ($existing['status'] ?? '');
            if ($status === 'accepted') {
                throw new \RuntimeException('You are already friends.');
            }
            if ($status === 'pending') {
                throw new \RuntimeException('A friend request is already pending.');
            }
            // Re-open declined request.
            $this->db->update('community_friendships', [
                'status'       => 'pending',
                'requested_by' => $fromUserId,
                'updated_at'   => $now,
            ], ['id' => (int) $existing['id']]);
            $row = $this->db->first('community_friendships', ['id' => (int) $existing['id']]);
            return $row;
        }

        $pair = $this->pair($fromUserId, $toUserId);
        $id = (int) $this->db->insert('community_friendships', [
            'user_id'      => $pair['low'],
            'friend_id'    => $pair['high'],
            'status'       => 'pending',
            'requested_by' => $fromUserId,
            'created_at'   => $now,
            'updated_at'   => null,
        ]);
        return $this->db->first('community_friendships', ['id' => $id]);
    }

    public function accept(int $userId, int $friendshipId): array
    {
        $row = $this->requireOwnedPending($userId, $friendshipId, false);
        $now = date('Y-m-d H:i:s');
        $this->db->update('community_friendships', [
            'status'     => 'accepted',
            'updated_at' => $now,
        ], ['id' => $friendshipId]);
        return $this->db->first('community_friendships', ['id' => $friendshipId]) ?: $row;
    }

    public function decline(int $userId, int $friendshipId): void
    {
        $this->requireOwnedPending($userId, $friendshipId, false);
        $this->db->update('community_friendships', [
            'status'     => 'declined',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $friendshipId]);
    }

    public function unfriend(int $userId, int $otherUserId): void
    {
        $row = $this->findBetween($userId, $otherUserId);
        if ($row === null || ($row['status'] ?? '') !== 'accepted') {
            throw new \RuntimeException('Friendship not found.');
        }
        $this->db->delete('community_friendships', ['id' => (int) $row['id']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelOutgoing(int $userId, int $friendshipId): array
    {
        $row = $this->requireOwnedPending($userId, $friendshipId, true);
        $this->db->delete('community_friendships', ['id' => $friendshipId]);
        return $row;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return 'none'|'friends'|'outgoing'|'incoming'|'declined'
     */
    private function statusFromRow(int $viewer, ?array $row): string
    {
        if ($row === null || $row === []) {
            return 'none';
        }
        $status = (string) ($row['status'] ?? '');
        if ($status === 'accepted') {
            return 'friends';
        }
        if ($status === 'declined') {
            return 'declined';
        }
        if ($status === 'pending') {
            $requestedBy = (int) ($row['requested_by'] ?? 0);
            return $requestedBy === $viewer ? 'outgoing' : 'incoming';
        }
        return 'none';
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwnedPending(int $userId, int $friendshipId, bool $mustBeRequester): array
    {
        $row = $this->db->first('community_friendships', ['id' => $friendshipId]);
        if ($row === [] || ($row['status'] ?? '') !== 'pending') {
            throw new \RuntimeException('Friend request not found.');
        }
        $low = (int) $row['user_id'];
        $high = (int) $row['friend_id'];
        if ($userId !== $low && $userId !== $high) {
            throw new \RuntimeException('Friend request not found.');
        }
        $requestedBy = (int) $row['requested_by'];
        if ($mustBeRequester) {
            if ($requestedBy !== $userId) {
                throw new \RuntimeException('Only the requester can cancel this request.');
            }
        } else {
            if ($requestedBy === $userId) {
                throw new \RuntimeException('You cannot accept your own request.');
            }
        }
        return $row;
    }
}
