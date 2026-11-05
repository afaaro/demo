<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use System\Engine\Controller;
use System\Library\Paginator;

class ForumAdminPosts extends Controller
{
    private const PER_PAGE = 20;

    protected function boot(): void
    {
        forum_require_moderate();
    }

    public function indexAction(): void
    {
        $q     = trim((string) $this->request->get('q', 'string', ''));
        $cat   = (int) $this->request->get('category', 'int', 0);
        $state = (string) $this->request->get('state', 'string', 'live');
        if (!in_array($state, ['live', 'deleted', 'all'], true)) {
            $state = 'live';
        }

        $where  = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[]  = '(p.`content` LIKE ? OR t.`title` LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($cat > 0) {
            $where[]  = 't.`category_id` = ?';
            $params[] = $cat;
        }
        $where[] = match ($state) {
            'deleted' => 'p.`is_deleted` = 1',
            'all'     => '1=1',
            default   => 'p.`is_deleted` = 0',
        };
        $sqlWhere = implode(' AND ', $where);

        $total = (int) $this->db->query(
            "SELECT COUNT(*)
               FROM `#__forum_posts` p
               JOIN `#__forum_threads` t ON t.`id` = p.`thread_id`
              WHERE {$sqlWhere}",
            $params
        )->value;
        $pager = Paginator::make(
            $total,
            (int) $this->request->get('page', 'int', 1),
            self::PER_PAGE
        );

        $items = $this->db->query(
            "SELECT p.`id`, p.`thread_id`, p.`content`, p.`is_deleted`, p.`created_at`, p.`edited_at`,
                    t.`title` AS thread_title, t.`is_deleted` AS thread_deleted,
                    c.`name` AS category_name,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`) AS author_name
               FROM `#__forum_posts` p
               JOIN `#__forum_threads` t ON t.`id` = p.`thread_id`
               JOIN `#__forum_categories` c ON c.`id` = t.`category_id`
               LEFT JOIN `#__users` u ON u.`id` = p.`user_id`
              WHERE {$sqlWhere}
              ORDER BY p.`created_at` DESC, p.`id` DESC
              LIMIT {$pager->perPage} OFFSET {$pager->offset}",
            $params
        )->rows;

        $categories = $this->db->query(
            "SELECT `id`, `name` FROM `#__forum_categories` ORDER BY `position`, `name`"
        )->rows;
        $query = array_filter([
            'q'        => $q !== '' ? $q : null,
            'category' => $cat > 0 ? $cat : null,
            'state'    => $state !== 'live' ? $state : null,
        ]);

        echo $this->view->inline(function () use ($items, $pager, $total, $q, $cat, $state, $categories, $query) {
            $this->view->assign('title', 'Forum messages');
            $this->view->block('right_sidebar', 'Forum');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $filtered = $q !== '' || $cat > 0 || $state !== 'live';

            echo '<div class="sf-page"><div class="sf-head"><div><h1>Messages</h1>'
               . '<p>Search and remove posts without leaving the admin.</p></div></div>';

            echo '<section class="sf-card">';
            echo '<form method="get" action="' . $e($this->url->to('forum/admin/posts')) . '" class="sf-filters">';
            echo '<label>Search<input type="search" name="q" value="' . $e($q) . '" placeholder="Text or thread title…"></label>';
            echo '<label>Forum<select name="category"><option value="0">All forums</option>';
            foreach ($categories as $c) {
                $sel = $cat === (int) $c['id'] ? ' selected' : '';
                echo '<option value="' . (int) $c['id'] . '"' . $sel . '>' . $e((string) $c['name']) . '</option>';
            }
            echo '</select></label>';
            echo '<label>State<select name="state">';
            foreach (['live' => 'Live', 'deleted' => 'Deleted', 'all' => 'All'] as $val => $label) {
                $sel = $state === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></label>';
            echo '<button type="submit" class="sf-btn">Apply</button>';
            echo $filtered
                ? '<a class="sf-btn" href="' . $e($this->url->to('forum/admin/posts')) . '">Reset</a>'
                : '<span></span>';
            echo '</form>';

            echo '<div class="sf-card-h"><h2>' . (int) $total . ' message'
               . ((int) $total === 1 ? '' : 's') . '</h2></div>';

            if ($items === []) {
                echo '<p class="sf-empty">' . ($filtered ? 'No messages match these filters.' : 'No messages yet.')
                   . '</p></section></div>';
                return;
            }

            echo '<form id="forum-posts-bulk" method="post" action="'
               . $e($this->url->to('forum/admin/posts/bulk'))
               . '" class="sf-bulk" autocomplete="off">';
            echo $this->request->csrfField();
            echo '<label class="sf-bulk__all"><input type="checkbox" class="form-check-input" id="forum-posts-bulk-all">'
               . 'Select all</label>';
            echo '<label>Action<select name="action" id="forum-posts-bulk-action">';
            echo '<option value="">Choose action…</option>';
            echo '<option value="delete">Delete</option>';
            echo '<option value="restore">Restore</option>';
            echo '</select></label>';
            echo '<button type="submit" class="sf-btn" id="forum-posts-bulk-apply" disabled>Apply</button>';
            echo '<span class="sf-bulk__count" id="forum-posts-bulk-count">0 selected</span></form>';

            echo '<div class="table-responsive"><table class="sf-table"><thead><tr>'
               . '<th class="c-check"></th><th>Message</th><th class="c-cat">Forum</th>'
               . '<th class="c-when">Posted</th><th class="c-acts"></th>'
               . '</tr></thead><tbody>';
            foreach ($items as $row) {
                $id = (int) $row['id'];
                $threadId = (int) $row['thread_id'];
                $live = (int) $row['is_deleted'] === 0 && (int) $row['thread_deleted'] === 0;
                echo '<tr>';
                echo '<td class="c-check"><input form="forum-posts-bulk" type="checkbox" class="form-check-input forum-post-id"'
                   . ' name="ids[]" value="' . $id . '" autocomplete="off"></td>';
                echo '<td>';
                if ($live) {
                    echo '<a class="sf-title" href="'
                       . $e($this->url->to('forum/thread', ['id' => $threadId]) . '#post-' . $id)
                       . '" target="_blank" rel="noopener">' . $e((string) $row['thread_title']) . '</a>';
                } else {
                    echo '<span class="sf-title">' . $e((string) $row['thread_title']) . '</span>';
                }
                echo '<span class="sf-sub">';
                if ((int) $row['is_deleted'] === 1) {
                    echo '<span class="sf-pill is-off">Deleted</span> ';
                }
                echo $e((string) ($row['author_name'] ?? '—')) . ' · '
                   . $e(forum_excerpt((string) $row['content'], 90)) . '</span></td>';
                echo '<td class="c-cat">' . $e((string) $row['category_name']) . '</td>';
                echo '<td class="c-when sf-when">' . $e(forum_when($row['created_at'] ?? null)) . '</td>';
                echo '<td class="c-acts"><div class="sf-row-acts">';
                if ($live) {
                    echo '<a class="sf-btn" href="'
                       . $e($this->url->to('forum/thread', ['id' => $threadId]) . '#post-' . $id)
                       . '" target="_blank" rel="noopener">Open</a>';
                }
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
            echo forum_admin_pager($pager, 'forum/admin/posts', $query);
            echo forum_admin_bulk_script('forum-posts-bulk', 'forum-post-id', 'Delete the selected messages?');
            echo '</section></div>';
        });
    }

    public function bulkAction(): void
    {
        $ids = forum_posted_ids($this->request);
        $action = (string) $this->request->post('action', 'string', '');
        if ($ids === [] || $action === '') {
            $this->notify->error('Choose messages and an action.');
            redirect_to('forum/admin/posts');
        }

        $n = 0;
        foreach ($ids as $id) {
            $ok = match ($action) {
                'delete'  => forum_soft_delete_post($this->db, $id),
                'restore' => forum_restore_post($this->db, $id),
                default   => false,
            };
            if ($ok) {
                $n++;
            }
        }

        if ($n === 0) {
            $this->notify->error('Nothing changed.');
        } else {
            $this->notify->success($n . ' message' . ($n === 1 ? '' : 's') . ' updated.');
        }
        redirect_to('forum/admin/posts');
    }
}
