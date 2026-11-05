<?php
declare(strict_types=1);

namespace System\Services;

use System\Engine\Route;
use System\Library\Database;
use Throwable;

/**
 * Record and report page access statistics.
 */
final class PageAccessService
{
    private const LOG_RETENTION_DAYS = 30;
    private const SKIP_PREFIXES = [
        'tools/admin/media',
        'user/admin/stats',
    ];

    public function __construct(private Database $db) {}

    public function record(Route $route, ?int $userId, string $method, string $path, string $ip): void
    {
        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $routeKey = mb_substr(trim($route->pattern !== '' ? $route->pattern : $route->url, '/'), 0, 191);
        if ($routeKey === '') {
            $routeKey = mb_substr(trim($path, '/'), 0, 191);
        }
        if ($routeKey === '') {
            return;
        }

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($routeKey, $prefix)) {
                return;
            }
        }

        $module = mb_substr($route->module, 0, 64);
        $path = mb_substr($path, 0, 255);
        $ip = mb_substr($ip, 0, 45);
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        try {
            $this->db->query(
                'INSERT INTO `#__page_access_daily` (`access_date`, `route`, `module`, `hits`, `unique_users`)
                 VALUES (?, ?, ?, 1, 0)
                 ON DUPLICATE KEY UPDATE `hits` = `hits` + 1',
                [$today, $routeKey, $module]
            );

            if ($userId !== null && $userId > 0) {
                $inserted = $this->db->query(
                    'INSERT IGNORE INTO `#__page_access_daily_users`
                        (`access_date`, `route`, `user_id`) VALUES (?, ?, ?)',
                    [$today, $routeKey, $userId]
                )->affected;
                if ($inserted > 0) {
                    $this->db->query(
                        'UPDATE `#__page_access_daily`
                            SET `unique_users` = `unique_users` + 1
                          WHERE `access_date` = ? AND `route` = ?',
                        [$today, $routeKey]
                    );
                }
            }

            $this->db->query(
                'INSERT INTO `#__page_access_log`
                    (`user_id`, `route`, `module`, `method`, `path`, `ip`, `created_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId !== null && $userId > 0 ? $userId : null,
                    $routeKey,
                    $module,
                    $method === 'HEAD' ? 'GET' : $method,
                    $path,
                    $ip,
                    $now,
                ]
            );

            // Cheap prune: ~1% of requests.
            if (random_int(1, 100) === 1) {
                $this->prune();
            }
        } catch (Throwable) {
            // Don't break page rendering if stats tables are missing.
        }
    }

    public function prune(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::LOG_RETENTION_DAYS * 86400);
        $dayCutoff = date('Y-m-d', time() - 90 * 86400);
        try {
            $this->db->query(
                'DELETE FROM `#__page_access_log` WHERE `created_at` < ?',
                [$cutoff]
            );
            $this->db->query(
                'DELETE FROM `#__page_access_daily_users` WHERE `access_date` < ?',
                [$dayCutoff]
            );
            $this->db->query(
                'DELETE FROM `#__page_access_daily` WHERE `access_date` < ?',
                [$dayCutoff]
            );
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @return array{
     *   hits_today:int, hits_7d:int, hits_30d:int,
     *   unique_today:int, unique_7d:int,
     *   top_pages:list<array{route:string, module:string, hits:int, unique_users:int}>,
     *   by_day:list<array{access_date:string, hits:int}>,
     *   by_module:list<array{module:string, hits:int}>,
     *   top_users:list<array{user_id:int, username:string, display_name:string, hits:int}>,
     *   recent:list<array{created_at:string, route:string, path:string, username:string, ip:string}>
     * }
     */
    public function report(int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $since = date('Y-m-d', time() - ($days - 1) * 86400);
        $today = date('Y-m-d');
        $since7 = date('Y-m-d', time() - 6 * 86400);

        $empty = [
            'hits_today' => 0, 'hits_7d' => 0, 'hits_30d' => 0,
            'unique_today' => 0, 'unique_7d' => 0,
            'top_pages' => [], 'by_day' => [], 'by_module' => [],
            'top_users' => [], 'recent' => [],
        ];

        try {
            $hitsToday = (int) $this->db->query(
                'SELECT COALESCE(SUM(`hits`), 0) FROM `#__page_access_daily` WHERE `access_date` = ?',
                [$today]
            )->value;
            $hits7 = (int) $this->db->query(
                'SELECT COALESCE(SUM(`hits`), 0) FROM `#__page_access_daily` WHERE `access_date` >= ?',
                [$since7]
            )->value;
            $hitsPeriod = (int) $this->db->query(
                'SELECT COALESCE(SUM(`hits`), 0) FROM `#__page_access_daily` WHERE `access_date` >= ?',
                [$since]
            )->value;

            $uniqueToday = (int) $this->db->query(
                'SELECT COUNT(DISTINCT `user_id`) FROM `#__page_access_daily_users` WHERE `access_date` = ?',
                [$today]
            )->value;
            $unique7 = (int) $this->db->query(
                'SELECT COUNT(DISTINCT `user_id`) FROM `#__page_access_daily_users` WHERE `access_date` >= ?',
                [$since7]
            )->value;

            $topPages = $this->db->query(
                "SELECT `route`, `module`, SUM(`hits`) AS hits, SUM(`unique_users`) AS unique_users
                   FROM `#__page_access_daily`
                  WHERE `access_date` >= ?
                  GROUP BY `route`, `module`
                  ORDER BY hits DESC
                  LIMIT 25",
                [$since]
            )->rows;

            $byDay = $this->db->query(
                "SELECT `access_date`, SUM(`hits`) AS hits
                   FROM `#__page_access_daily`
                  WHERE `access_date` >= ?
                  GROUP BY `access_date`
                  ORDER BY `access_date` ASC",
                [$since]
            )->rows;

            $byModule = $this->db->query(
                "SELECT `module`, SUM(`hits`) AS hits
                   FROM `#__page_access_daily`
                  WHERE `access_date` >= ?
                  GROUP BY `module`
                  ORDER BY hits DESC
                  LIMIT 20",
                [$since]
            )->rows;

            $topUsers = $this->db->query(
                "SELECT l.`user_id`, u.`username`, u.`display_name`, COUNT(*) AS hits
                   FROM `#__page_access_log` l
                   JOIN `#__users` u ON u.`id` = l.`user_id`
                  WHERE l.`created_at` >= ?
                    AND l.`user_id` IS NOT NULL
                  GROUP BY l.`user_id`, u.`username`, u.`display_name`
                  ORDER BY hits DESC
                  LIMIT 15",
                [$since . ' 00:00:00']
            )->rows;

            $recent = $this->db->query(
                "SELECT l.`created_at`, l.`route`, l.`path`, l.`ip`,
                        COALESCE(u.`username`, 'guest') AS username
                   FROM `#__page_access_log` l
                   LEFT JOIN `#__users` u ON u.`id` = l.`user_id`
                  ORDER BY l.`id` DESC
                  LIMIT 40"
            )->rows;
        } catch (Throwable) {
            return $empty;
        }

        return [
            'hits_today'   => $hitsToday,
            'hits_7d'      => $hits7,
            'hits_30d'     => $hitsPeriod,
            'unique_today' => $uniqueToday,
            'unique_7d'    => $unique7,
            'top_pages'    => array_map(static fn(array $r): array => [
                'route'        => (string) $r['route'],
                'module'       => (string) $r['module'],
                'hits'         => (int) $r['hits'],
                'unique_users' => (int) $r['unique_users'],
            ], $topPages),
            'by_day' => array_map(static fn(array $r): array => [
                'access_date' => (string) $r['access_date'],
                'hits'        => (int) $r['hits'],
            ], $byDay),
            'by_module' => array_map(static fn(array $r): array => [
                'module' => (string) ($r['module'] !== '' ? $r['module'] : '—'),
                'hits'   => (int) $r['hits'],
            ], $byModule),
            'top_users' => array_map(static fn(array $r): array => [
                'user_id'      => (int) $r['user_id'],
                'username'     => (string) $r['username'],
                'display_name' => (string) ($r['display_name'] ?? ''),
                'hits'         => (int) $r['hits'],
            ], $topUsers),
            'recent' => array_map(static fn(array $r): array => [
                'created_at' => (string) $r['created_at'],
                'route'      => (string) $r['route'],
                'path'       => (string) $r['path'],
                'username'   => (string) $r['username'],
                'ip'         => (string) $r['ip'],
            ], $recent),
        ];
    }
}
