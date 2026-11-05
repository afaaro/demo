<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use System\Engine\Controller;

class ForumHome extends Controller
{
    public function indexAction(): void
    {
        $this->view->addModuleStyle('forum', 'css/forum.css');

        $userId = $this->auth->check() ? (int) $this->auth->id() : 0;
        $acc = forum_access_clause('c.access_role');

        $categories = $this->db->query(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM `#__forum_threads` t
                      WHERE t.`category_id` = c.`id` AND t.`is_deleted` = 0) AS thread_count,
                    (SELECT t2.`title` FROM `#__forum_threads` t2
                      WHERE t2.`category_id` = c.`id` AND t2.`is_deleted` = 0
                      ORDER BY t2.`last_post_at` DESC, t2.`id` DESC LIMIT 1) AS last_title,
                    (SELECT t2.`id` FROM `#__forum_threads` t2
                      WHERE t2.`category_id` = c.`id` AND t2.`is_deleted` = 0
                      ORDER BY t2.`last_post_at` DESC, t2.`id` DESC LIMIT 1) AS last_thread_id,
                    (SELECT t2.`last_post_at` FROM `#__forum_threads` t2
                      WHERE t2.`category_id` = c.`id` AND t2.`is_deleted` = 0
                      ORDER BY t2.`last_post_at` DESC, t2.`id` DESC LIMIT 1) AS last_post_at,
                    (SELECT COALESCE(NULLIF(u.`display_name`, ''), u.`username`)
                       FROM `#__forum_threads` t2
                       LEFT JOIN `#__users` u ON u.`id` = t2.`last_post_by`
                      WHERE t2.`category_id` = c.`id` AND t2.`is_deleted` = 0
                      ORDER BY t2.`last_post_at` DESC, t2.`id` DESC LIMIT 1) AS last_poster
             FROM `#__forum_categories` c
             WHERE c.`status` = 'visible' AND {$acc['sql']}
             ORDER BY c.`position`, c.`name`",
            $acc['params']
        )->rows;

        $unreadByCat = [];
        if ($userId > 0 && $categories !== []) {
            $rows = $this->db->query(
                "SELECT t.`category_id`, COUNT(*) AS unread
                   FROM `#__forum_threads` t
                   LEFT JOIN `#__forum_reads` r
                     ON r.`thread_id` = t.`id` AND r.`user_id` = ?
                  WHERE t.`is_deleted` = 0
                    AND (r.`last_read_at` IS NULL OR r.`last_read_at` < t.`last_post_at`)
                  GROUP BY t.`category_id`",
                [$userId]
            )->rows;
            foreach ($rows as $row) {
                $unreadByCat[(int) $row['category_id']] = (int) $row['unread'];
            }
        }

        $latest = $this->db->query(
            "SELECT t.`id`, t.`title`, t.`last_post_at`, t.`post_count`, t.`is_pinned`,
                    c.`name` AS category_name, c.`slug` AS category_slug,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`) AS last_poster
               FROM `#__forum_threads` t
               JOIN `#__forum_categories` c ON c.`id` = t.`category_id` AND c.`status` = 'visible'
               LEFT JOIN `#__users` u ON u.`id` = t.`last_post_by`
              WHERE t.`is_deleted` = 0 AND {$acc['sql']}
              ORDER BY t.`last_post_at` DESC, t.`id` DESC
              LIMIT 8",
            $acc['params']
        )->rows;

        echo $this->view->render($this, 'index', 'main', [
            'title'       => 'Forums',
            'categories'  => $categories,
            'unreadByCat' => $unreadByCat,
            'latest'      => $latest,
        ]);
    }

    public function whatsNewAction(): void
    {
        $this->view->addModuleStyle('forum', 'css/forum.css');
        $userId = $this->auth->check() ? (int) $this->auth->id() : 0;
        $acc = forum_access_clause('c.access_role');

        $items = $this->db->query(
            "SELECT t.`id`, t.`title`, t.`last_post_at`, t.`post_count`, t.`view_count`,
                    t.`is_pinned`, t.`is_locked`, t.`created_at`,
                    c.`name` AS category_name, c.`slug` AS category_slug,
                    COALESCE(NULLIF(au.`display_name`, ''), au.`username`) AS author_name,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`) AS last_poster,
                    r.`last_read_at`
               FROM `#__forum_threads` t
               JOIN `#__forum_categories` c ON c.`id` = t.`category_id` AND c.`status` = 'visible'
               LEFT JOIN `#__users` au ON au.`id` = t.`user_id`
               LEFT JOIN `#__users` u ON u.`id` = t.`last_post_by`
               LEFT JOIN `#__forum_reads` r ON r.`thread_id` = t.`id` AND r.`user_id` = ?
              WHERE t.`is_deleted` = 0 AND {$acc['sql']}
              ORDER BY t.`last_post_at` DESC, t.`id` DESC
              LIMIT 40",
            array_merge([$userId], $acc['params'])
        )->rows;

        echo $this->view->render($this, 'whats_new', 'main', [
            'title' => "What's new",
            'items' => $items,
        ]);
    }
}
