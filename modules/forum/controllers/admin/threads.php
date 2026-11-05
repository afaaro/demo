<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use System\Engine\Controller;
use System\Library\Paginator;

class ForumAdminThreads extends Controller
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
        if (!in_array($state, ['live', 'sticky', 'locked', 'deleted', 'all'], true)) {
            $state = 'live';
        }

        $where  = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[]  = 't.`title` LIKE ?';
            $params[] = '%' . $q . '%';
        }
        if ($cat > 0) {
            $where[]  = 't.`category_id` = ?';
            $params[] = $cat;
        }
        $where[] = match ($state) {
            'sticky'  => 't.`is_deleted` = 0 AND t.`is_pinned` = 1',
            'locked'  => 't.`is_deleted` = 0 AND t.`is_locked` = 1',
            'deleted' => 't.`is_deleted` = 1',
            'all'     => '1=1',
            default   => 't.`is_deleted` = 0',
        };
        $sqlWhere = implode(' AND ', $where);

        $total = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__forum_threads` t WHERE {$sqlWhere}",
            $params
        )->value;
        $pager = Paginator::make(
            $total,
            (int) $this->request->get('page', 'int', 1),
            self::PER_PAGE
        );

        $items = $this->db->query(
            "SELECT t.*,
                    c.`name` AS category_name, c.`slug` AS category_slug,
                    COALESCE(NULLIF(au.`display_name`, ''), au.`username`) AS author_name,
                    COALESCE(NULLIF(lu.`display_name`, ''), lu.`username`) AS last_poster
               FROM `#__forum_threads` t
               JOIN `#__forum_categories` c ON c.`id` = t.`category_id`
               LEFT JOIN `#__users` au ON au.`id` = t.`user_id`
               LEFT JOIN `#__users` lu ON lu.`id` = t.`last_post_by`
              WHERE {$sqlWhere}
              ORDER BY t.`last_post_at` DESC, t.`id` DESC
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
            $this->view->assign('title', 'Forum threads');
            $this->view->block('right_sidebar', 'Forum');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $filtered = $q !== '' || $cat > 0 || $state !== 'live';

            echo '<div class="sf-page"><div class="sf-head"><div><h1>Threads</h1>'
               . '<p>Pin, lock, move or remove discussions.</p></div>';
            echo '<div class="sf-actions"><a class="sf-btn" href="'
               . $e($this->url->to('forum')) . '" target="_blank" rel="noopener">'
               . '<i class="bi bi-box-arrow-up-right"></i>View forum</a></div></div>';

            echo '<section class="sf-card">';
            echo '<form method="get" action="' . $e($this->url->to('forum/admin/threads')) . '" class="sf-filters">';
            echo '<label>Search<input type="search" name="q" value="' . $e($q) . '" placeholder="Title…"></label>';
            echo '<label>Forum<select name="category"><option value="0">All forums</option>';
            foreach ($categories as $c) {
                $sel = $cat === (int) $c['id'] ? ' selected' : '';
                echo '<option value="' . (int) $c['id'] . '"' . $sel . '>' . $e((string) $c['name']) . '</option>';
            }
            echo '</select></label>';
            echo '<label>State<select name="state">';
            foreach (['live' => 'Live', 'sticky' => 'Sticky', 'locked' => 'Locked', 'deleted' => 'Deleted', 'all' => 'All'] as $val => $label) {
                $sel = $state === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></label>';
            echo '<button type="submit" class="sf-btn">Apply</button>';
            echo $filtered
                ? '<a class="sf-btn" href="' . $e($this->url->to('forum/admin/threads')) . '">Reset</a>'
                : '<span></span>';
            echo '</form>';

            echo '<div class="sf-card-h"><h2>' . (int) $total . ' thread'
               . ((int) $total === 1 ? '' : 's') . '</h2></div>';

            if ($items === []) {
                echo '<p class="sf-empty">' . ($filtered ? 'No threads match these filters.' : 'No threads yet.')
                   . '</p></section></div>';
                return;
            }

            echo '<form id="forum-threads-bulk" method="post" action="'
               . $e($this->url->to('forum/admin/threads/bulk'))
               . '" class="sf-bulk" autocomplete="off">';
            echo $this->request->csrfField();
            echo '<label class="sf-bulk__all"><input type="checkbox" class="form-check-input" id="forum-threads-bulk-all">'
               . 'Select all</label>';
            echo '<label>Action<select name="action" id="forum-threads-bulk-action">';
            echo '<option value="">Choose action…</option>';
            echo '<option value="pin">Sticky</option>';
            echo '<option value="unpin">Unsticky</option>';
            echo '<option value="lock">Lock</option>';
            echo '<option value="unlock">Unlock</option>';
            echo '<option value="move">Move to forum…</option>';
            echo '<option value="delete">Delete</option>';
            echo '<option value="restore">Restore</option>';
            echo '</select></label>';
            echo '<label class="d-none" id="forum-threads-bulk-extra">Forum<select name="category_id">';
            foreach ($categories as $c) {
                echo '<option value="' . (int) $c['id'] . '">' . $e((string) $c['name']) . '</option>';
            }
            echo '</select></label>';
            echo '<button type="submit" class="sf-btn" id="forum-threads-bulk-apply" disabled>Apply</button>';
            echo '<span class="sf-bulk__count" id="forum-threads-bulk-count">0 selected</span></form>';

            echo '<div class="table-responsive"><table class="sf-table"><thead><tr>'
               . '<th class="c-check"></th><th>Thread</th><th class="c-cat">Forum</th>'
               . '<th class="c-count">Replies</th><th class="c-when">Last</th><th class="c-acts"></th>'
               . '</tr></thead><tbody>';
            foreach ($items as $row) {
                $id = (int) $row['id'];
                $live = (int) $row['is_deleted'] === 0;
                echo '<tr>';
                echo '<td class="c-check"><input form="forum-threads-bulk" type="checkbox" class="form-check-input forum-thread-id"'
                   . ' name="ids[]" value="' . $id . '" autocomplete="off"></td>';
                echo '<td>';
                if ($live) {
                    echo '<a class="sf-title" href="' . $e($this->url->to('forum/thread', ['id' => $id]))
                       . '" target="_blank" rel="noopener">' . $e((string) $row['title']) . '</a>';
                } else {
                    echo '<span class="sf-title">' . $e((string) $row['title']) . '</span>';
                }
                echo '<span class="sf-sub">';
                if (!$live) {
                    echo '<span class="sf-pill is-off">Deleted</span> ';
                }
                if ((int) $row['is_pinned'] === 1) {
                    echo '<span class="sf-pill is-pin">Sticky</span> ';
                }
                if ((int) $row['is_locked'] === 1) {
                    echo '<span class="sf-pill is-wait">Locked</span> ';
                }
                echo $e((string) ($row['author_name'] ?? '—')) . '</span></td>';
                echo '<td class="c-cat">' . $e((string) $row['category_name']) . '</td>';
                echo '<td class="c-count">' . max(0, (int) $row['post_count'] - 1) . '</td>';
                echo '<td class="c-when sf-when">' . $e(forum_when($row['last_post_at'] ?? null)) . '</td>';
                echo '<td class="c-acts"><div class="sf-row-acts">';
                if ($live) {
                    echo '<a class="sf-btn" href="' . $e($this->url->to('forum/thread', ['id' => $id]))
                       . '" target="_blank" rel="noopener">Open</a>';
                }
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
            echo forum_admin_pager($pager, 'forum/admin/threads', $query);
            echo forum_admin_bulk_script('forum-threads-bulk', 'forum-thread-id', 'Delete the selected threads?');
            echo '</section></div>';
        });
    }

    public function bulkAction(): void
    {
        $ids = forum_posted_ids($this->request);
        $action = (string) $this->request->post('action', 'string', '');
        if ($ids === [] || $action === '') {
            $this->notify->error('Choose threads and an action.');
            redirect_to('forum/admin/threads');
        }

        $n = 0;
        foreach ($ids as $id) {
            $ok = match ($action) {
                'pin'     => forum_set_thread_flag($this->db, $id, 'is_pinned', 1),
                'unpin'   => forum_set_thread_flag($this->db, $id, 'is_pinned', 0),
                'lock'    => forum_set_thread_flag($this->db, $id, 'is_locked', 1),
                'unlock'  => forum_set_thread_flag($this->db, $id, 'is_locked', 0),
                'delete'  => forum_soft_delete_thread($this->db, $id),
                'restore' => forum_restore_thread($this->db, $id),
                'move'    => forum_move_thread(
                    $this->db,
                    $id,
                    (int) $this->request->post('category_id', 'int', 0)
                ),
                default   => false,
            };
            if ($ok) {
                $n++;
            }
        }

        if ($n === 0) {
            $this->notify->error('Nothing changed.');
        } else {
            $this->notify->success($n . ' thread' . ($n === 1 ? '' : 's') . ' updated.');
        }
        redirect_to('forum/admin/threads');
    }
}
