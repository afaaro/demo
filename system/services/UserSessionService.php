<?php
declare(strict_types=1);

namespace System\Services;

use System\Library\Database;
use System\Library\Session;
use Throwable;

/**
 * Persist active browser sessions so admins can see who is online.
 */
final class UserSessionService
{
    /** Consider online if activity within this many seconds. */
    public const ONLINE_WINDOW = 900;

    /** Don't write to DB more often than this (seconds). */
    private const TOUCH_THROTTLE = 60;

    private const SESSION_TOUCH_KEY = '_user_session_touched_at';

    public function __construct(
        private Database $db,
        private Session $session,
    ) {}

    public function start(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $sid = $this->session->id();
        if ($sid === '') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $ip = $this->clientIp();
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        try {
            $this->db->query(
                'INSERT INTO `#__user_sessions`
                    (`id`, `user_id`, `ip`, `user_agent`, `last_activity`, `created_at`)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    `user_id` = VALUES(`user_id`),
                    `ip` = VALUES(`ip`),
                    `user_agent` = VALUES(`user_agent`),
                    `last_activity` = VALUES(`last_activity`)',
                [$sid, $userId, $ip, $ua, $now, $now]
            );
            $this->db->update('users', [
                'last_seen_at' => $now,
                'updated_at'   => $now,
            ], ['id' => $userId]);
            $this->session->set(self::SESSION_TOUCH_KEY, time());
        } catch (Throwable) {
            // Table may not exist yet before migrate — don't break login.
        }
    }

    public function touch(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $sid = $this->session->id();
        if ($sid === '') {
            return;
        }

        $last = (int) ($this->session->get(self::SESSION_TOUCH_KEY) ?? 0);
        if ($last > 0 && (time() - $last) < self::TOUCH_THROTTLE) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        try {
            $exists = (int) $this->db->query(
                'SELECT COUNT(*) FROM `#__user_sessions` WHERE `id` = ?',
                [$sid]
            )->value;

            if ($exists > 0) {
                $this->db->query(
                    'UPDATE `#__user_sessions`
                        SET `last_activity` = ?, `ip` = ?, `user_agent` = ?
                      WHERE `id` = ? AND `user_id` = ?',
                    [
                        $now,
                        $this->clientIp(),
                        mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                        $sid,
                        $userId,
                    ]
                );
            } else {
                // Session cookie survived but row missing (e.g. after migrate) — recreate.
                $this->start($userId);
                return;
            }

            $this->db->update('users', [
                'last_seen_at' => $now,
            ], ['id' => $userId]);
            $this->session->set(self::SESSION_TOUCH_KEY, time());
        } catch (Throwable) {
            // ignore
        }
    }

    public function end(?string $sessionId = null): void
    {
        $sid = $sessionId ?? $this->session->id();
        if ($sid === '') {
            return;
        }
        try {
            $this->db->query('DELETE FROM `#__user_sessions` WHERE `id` = ?', [$sid]);
        } catch (Throwable) {
            // ignore
        }
        $this->session->remove(self::SESSION_TOUCH_KEY);
    }

    public function endAllForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $this->db->query('DELETE FROM `#__user_sessions` WHERE `user_id` = ?', [$userId]);
        } catch (Throwable) {
            // ignore
        }
    }

    /** Purge stale session rows older than the online window × 4. */
    public function prune(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::ONLINE_WINDOW * 4);
        try {
            $this->db->query(
                'DELETE FROM `#__user_sessions` WHERE `last_activity` < ?',
                [$cutoff]
            );
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @return array<int, array{
     *   user_id:int, username:string, email:string, display_name:string,
     *   sessions:int, last_activity:string, ip:string
     * }>
     */
    public function onlineUsers(): array
    {
        $since = date('Y-m-d H:i:s', time() - self::ONLINE_WINDOW);
        try {
            $rows = $this->db->query(
                "SELECT u.`id` AS user_id, u.`username`, u.`email`, u.`display_name`,
                        COUNT(s.`id`) AS sessions,
                        MAX(s.`last_activity`) AS last_activity,
                        SUBSTRING_INDEX(GROUP_CONCAT(s.`ip` ORDER BY s.`last_activity` DESC), ',', 1) AS ip
                   FROM `#__user_sessions` s
                   JOIN `#__users` u ON u.`id` = s.`user_id`
                  WHERE s.`last_activity` >= ?
                  GROUP BY u.`id`, u.`username`, u.`email`, u.`display_name`
                  ORDER BY last_activity DESC",
                [$since]
            )->rows;
        } catch (Throwable) {
            return [];
        }

        return array_map(static function (array $r): array {
            return [
                'user_id'       => (int) $r['user_id'],
                'username'      => (string) $r['username'],
                'email'         => (string) $r['email'],
                'display_name'  => (string) ($r['display_name'] ?? ''),
                'sessions'      => (int) $r['sessions'],
                'last_activity' => (string) ($r['last_activity'] ?? ''),
                'ip'            => (string) ($r['ip'] ?? ''),
            ];
        }, $rows);
    }

    /** @return array<int, true> user_id => online */
    public function onlineUserIdMap(): array
    {
        $since = date('Y-m-d H:i:s', time() - self::ONLINE_WINDOW);
        try {
            $rows = $this->db->query(
                'SELECT DISTINCT `user_id` FROM `#__user_sessions` WHERE `last_activity` >= ?',
                [$since]
            )->rows;
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['user_id']] = true;
        }
        return $map;
    }

    public static function isOnlineTimestamp(?string $lastSeenAt): bool
    {
        if ($lastSeenAt === null || $lastSeenAt === '') {
            return false;
        }
        $ts = strtotime($lastSeenAt);
        if ($ts === false) {
            return false;
        }
        return (time() - $ts) <= self::ONLINE_WINDOW;
    }

    private function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return mb_substr($ip, 0, 45);
        }
        return '';
    }
}
