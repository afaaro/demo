<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use System\Engine\Controller;
use System\Library\Paginator;

class ForumSearch extends Controller
{
    public function indexAction(): void
    {
        $this->view->addModuleStyle('forum', 'css/forum.css');

        $q = trim((string) $this->request->get('q', 'string', ''));
        $userId = $this->auth->check() ? (int) $this->auth->id() : 0;
        $items = [];
        $total = 0;
        $pager = Paginator::make(0, 1, 20);

        if (mb_strlen($q) >= 2) {
            $like = '%' . $q . '%';
            $acc = forum_access_clause('c.access_role');
            $total = (int) $this->db->query(
                "SELECT COUNT(DISTINCT t.`id`)
                   FROM `#__forum_threads` t
                   JOIN `#__forum_categories` c ON c.`id` = t.`category_id` AND c.`status` = 'visible'
                   LEFT JOIN `#__forum_posts` p ON p.`thread_id` = t.`id` AND p.`is_deleted` = 0
                  WHERE t.`is_deleted` = 0 AND {$acc['sql']}
                    AND (t.`title` LIKE ? OR p.`content` LIKE ?)",
                array_merge($acc['params'], [$like, $like])
            )->value;

            $pager = Paginator::make(
                $total,
                (int) $this->request->get('page', 'int', 1),
                20
            );

            $items = $this->db->query(
                "SELECT t.`id`, t.`title`, t.`last_post_at`, t.`post_count`, t.`view_count`,
                        t.`is_pinned`, t.`is_locked`,
                        c.`name` AS category_name, c.`slug` AS category_slug,
                        COALESCE(NULLIF(u.`display_name`, ''), u.`username`) AS last_poster,
                        r.`last_read_at`
                   FROM `#__forum_threads` t
                   JOIN `#__forum_categories` c ON c.`id` = t.`category_id` AND c.`status` = 'visible'
                   LEFT JOIN `#__users` u ON u.`id` = t.`last_post_by`
                   LEFT JOIN `#__forum_reads` r ON r.`thread_id` = t.`id` AND r.`user_id` = ?
                  WHERE t.`is_deleted` = 0 AND {$acc['sql']}
                    AND (
                        t.`title` LIKE ?
                        OR EXISTS (
                            SELECT 1 FROM `#__forum_posts` p
                             WHERE p.`thread_id` = t.`id`
                               AND p.`is_deleted` = 0
                               AND p.`content` LIKE ?
                        )
                    )
                  ORDER BY t.`last_post_at` DESC, t.`id` DESC
                  LIMIT {$pager->perPage} OFFSET {$pager->offset}",
                array_merge([$userId], $acc['params'], [$like, $like])
            )->rows;
        }

        echo $this->view->render($this, 'index', 'main', [
            'title' => $q !== '' ? 'Search · ' . $q : 'Search forum',
            'q'     => $q,
            'items' => $items,
            'total' => $total,
            'pager' => $pager,
        ]);
    }
}
