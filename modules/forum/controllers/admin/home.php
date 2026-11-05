<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use System\Engine\Controller;

class ForumAdminHome extends Controller
{
    protected function boot(): void
    {
        forum_require_moderate();
    }

    public function indexAction(): void
    {
        $stats = [
            'forums'  => (int) $this->db->query(
                "SELECT COUNT(*) FROM `#__forum_categories` WHERE `status` = 'visible'"
            )->value,
            'threads' => (int) $this->db->query(
                "SELECT COUNT(*) FROM `#__forum_threads` WHERE `is_deleted` = 0"
            )->value,
            'posts'   => (int) $this->db->query(
                "SELECT COUNT(*) FROM `#__forum_posts` WHERE `is_deleted` = 0"
            )->value,
            'locked'  => (int) $this->db->query(
                "SELECT COUNT(*) FROM `#__forum_threads` WHERE `is_deleted` = 0 AND `is_locked` = 1"
            )->value,
        ];

        $latest = $this->db->query(
            "SELECT t.`id`, t.`title`, t.`post_count`, t.`is_pinned`, t.`is_locked`, t.`last_post_at`,
                    c.`name` AS category_name,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`) AS last_poster
               FROM `#__forum_threads` t
               JOIN `#__forum_categories` c ON c.`id` = t.`category_id`
               LEFT JOIN `#__users` u ON u.`id` = t.`last_post_by`
              WHERE t.`is_deleted` = 0
              ORDER BY t.`last_post_at` DESC, t.`id` DESC
              LIMIT 8"
        )->rows;

        echo $this->view->inline(function () use ($stats, $latest) {
            $this->view->assign('title', 'Forum');
            $this->view->block('right_sidebar', 'Forum');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="sf-page">';
            echo '<div class="sf-head"><div><h1>Forum</h1>'
               . '<p>Moderate forums, threads and messages.</p></div>';
            echo '<div class="sf-actions">';
            echo '<a class="sf-btn" href="' . $e($this->url->to('forum')) . '" target="_blank" rel="noopener">'
               . '<i class="bi bi-box-arrow-up-right"></i>View forum</a>';
            echo '<a class="sf-btn is-primary" href="' . $e($this->url->to('forum/admin/categories/add')) . '">'
               . '<i class="bi bi-plus-lg"></i>New forum</a>';
            echo '</div></div>';

            echo '<div class="sf-stats">';
            foreach ([
                ['Forums', $stats['forums'], $this->url->to('forum/admin/categories')],
                ['Threads', $stats['threads'], $this->url->to('forum/admin/threads')],
                ['Messages', $stats['posts'], $this->url->to('forum/admin/posts')],
                ['Locked', $stats['locked'], $this->url->to('forum/admin/threads', ['state' => 'locked'])],
            ] as [$label, $value, $href]) {
                echo '<a class="sf-stat" href="' . $e($href) . '"><span>'
                   . $e($label) . '</span><strong>' . (int) $value . '</strong></a>';
            }
            echo '</div>';

            echo '<section class="sf-card"><div class="sf-card-h"><h2>Latest activity</h2>'
               . '<a href="' . $e($this->url->to('forum/admin/threads')) . '">View all</a></div>';

            if ($latest === []) {
                echo '<p class="sf-empty">No threads yet.</p></section></div>';
                return;
            }

            echo '<div class="table-responsive"><table class="sf-table"><thead><tr>'
               . '<th>Thread</th><th class="c-cat">Forum</th><th class="c-count">Replies</th>'
               . '<th class="c-when">Last message</th><th class="c-acts"></th>'
               . '</tr></thead><tbody>';
            foreach ($latest as $row) {
                $id = (int) $row['id'];
                $replies = max(0, (int) $row['post_count'] - 1);
                echo '<tr><td><a class="sf-title" href="'
                   . $e($this->url->to('forum/thread', ['id' => $id])) . '" target="_blank" rel="noopener">'
                   . $e((string) $row['title']) . '</a><span class="sf-sub">';
                if ((int) $row['is_pinned'] === 1) {
                    echo '<span class="sf-pill is-pin">Sticky</span> ';
                }
                if ((int) $row['is_locked'] === 1) {
                    echo '<span class="sf-pill is-off">Locked</span> ';
                }
                echo $e((string) ($row['last_poster'] ?? '—')) . '</span></td>';
                echo '<td class="c-cat">' . $e((string) $row['category_name']) . '</td>';
                echo '<td class="c-count">' . $replies . '</td>';
                echo '<td class="c-when sf-when">' . $e(forum_when($row['last_post_at'] ?? null)) . '</td>';
                echo '<td class="c-acts"><div class="sf-row-acts">'
                   . '<a class="sf-btn" href="' . $e($this->url->to('forum/admin/threads', ['q' => (string) $row['title']]))
                   . '">Manage</a></div></td></tr>';
            }
            echo '</tbody></table></div></section></div>';
        });
    }
}
