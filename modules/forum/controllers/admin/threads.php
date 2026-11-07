<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use System\Engine\Controller;
use System\Library\Dataset;

class ForumAdminThreads extends Controller
{
    protected function boot(): void
    {
        forum_require_moderate();
    }

    public function indexAction(): void
    {
        $categories = $this->db->query(
            "SELECT `id`, `name` FROM `#__forum_categories` ORDER BY `position`, `name`"
        )->rows;

        $categoryOptions = ['0' => 'All forums'];
        foreach ($categories as $c) {
            $categoryOptions[(string) (int) $c['id']] = (string) $c['name'];
        }

        $dataset = (new Dataset($this->registry))
            ->perPage(20)
            ->searchPlaceholder('Title…')
            ->sortable(['title', 'category_name', 'post_count', 'last_post_at'])
            ->defaultSort('last_post_at', 'desc')
            ->filter('category', 'select', $categoryOptions)
            ->filter('state', 'select', [
                'live'    => 'Live',
                'sticky'  => 'Sticky',
                'locked'  => 'Locked',
                'deleted' => 'Deleted',
                'all'     => 'All',
            ])
            ->columns([
                'select'        => '',
                'title'         => 'Thread',
                'category_name' => 'Forum',
                'replies'       => 'Replies',
                'last_post_at'  => 'Last',
                'actions'       => '',
            ])
            ->query(function ($db, array $state): array {
                $q = $state['q'];
                $cat = (int) ($state['filters']['category'] ?? 0);
                $stateFilter = (string) ($state['filters']['state'] ?? 'live');
                if (!in_array($stateFilter, ['live', 'sticky', 'locked', 'deleted', 'all'], true)) {
                    $stateFilter = 'live';
                }

                $where  = ['1=1'];
                $params = [];
                if ($q !== '') {
                    $where[]  = 't.`title` LIKE ? ESCAPE \'\\\\\'';
                    $params[] = '%' . str_replace(
                        ['\\', '%', '_'],
                        ['\\\\', '\\%', '\\_'],
                        $q
                    ) . '%';
                }
                if ($cat > 0) {
                    $where[]  = 't.`category_id` = ?';
                    $params[] = $cat;
                }
                $where[] = match ($stateFilter) {
                    'sticky'  => 't.`is_deleted` = 0 AND t.`is_pinned` = 1',
                    'locked'  => 't.`is_deleted` = 0 AND t.`is_locked` = 1',
                    'deleted' => 't.`is_deleted` = 1',
                    'all'     => '1=1',
                    default   => 't.`is_deleted` = 0',
                };
                $sqlWhere = implode(' AND ', $where);

                $sortMap = [
                    'title'          => 't.`title`',
                    'category_name'  => 'c.`name`',
                    'post_count'     => 't.`post_count`',
                    'last_post_at'   => 't.`last_post_at`',
                ];
                $sortKey = $state['sort'];
                $orderCol = $sortMap[$sortKey] ?? 't.`last_post_at`';
                $dir = $state['dir'] === 'asc' ? 'ASC' : 'DESC';
                $orderSql = " ORDER BY {$orderCol} {$dir}, t.`id` DESC";

                $total = (int) $db->query(
                    "SELECT COUNT(*) FROM `#__forum_threads` t WHERE {$sqlWhere}",
                    $params
                )->value;

                $items = $db->query(
                    "SELECT t.*,
                            c.`name` AS category_name, c.`slug` AS category_slug,
                            COALESCE(NULLIF(au.`display_name`, ''), au.`username`) AS author_name,
                            COALESCE(NULLIF(lu.`display_name`, ''), lu.`username`) AS last_poster
                       FROM `#__forum_threads` t
                       JOIN `#__forum_categories` c ON c.`id` = t.`category_id`
                       LEFT JOIN `#__users` au ON au.`id` = t.`user_id`
                       LEFT JOIN `#__users` lu ON lu.`id` = t.`last_post_by`
                      WHERE {$sqlWhere}
                      {$orderSql}
                      LIMIT ?, ?",
                    [...$params, $state['offset'], $state['per_page']]
                )->rows;

                return [
                    'rows'  => array_map(static fn(array $row): array => ['_row' => $row], $items),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $value): string =>
            $this->view->e((string) $value);

        $dataset->columns([
            'select' => function (array $row): string {
                $id = (int) $row['_row']['id'];

                return '<input form="forum-threads-bulk" type="checkbox" '
                    . 'class="form-check-input forum-thread-id" '
                    . 'name="ids[]" value="' . $id . '" autocomplete="off">';
            },

            'title' => function (array $row) use ($e): string {
                $r = $row['_row'];
                $id = (int) $r['id'];
                $live = (int) $r['is_deleted'] === 0;

                if ($live) {
                    $html = '<a class="sf-title" href="'
                        . $e($this->url->to('forum/thread', ['id' => $id]))
                        . '" target="_blank" rel="noopener">' . $e((string) $r['title']) . '</a>';
                } else {
                    $html = '<span class="sf-title">' . $e((string) $r['title']) . '</span>';
                }

                $html .= '<span class="sf-sub">';
                if (!$live) {
                    $html .= '<span class="sf-pill is-off">Deleted</span> ';
                }
                if ((int) $r['is_pinned'] === 1) {
                    $html .= '<span class="sf-pill is-pin">Sticky</span> ';
                }
                if ((int) $r['is_locked'] === 1) {
                    $html .= '<span class="sf-pill is-wait">Locked</span> ';
                }
                $html .= $e((string) ($r['author_name'] ?? '—'));

                return $html . '</span>';
            },

            'category_name' => function (array $row) use ($e): string {
                return $e((string) $row['_row']['category_name']);
            },

            'replies' => function (array $row): string {
                return (string) max(0, (int) $row['_row']['post_count'] - 1);
            },

            'last_post_at' => function (array $row) use ($e): string {
                return '<span class="sf-when">'
                    . $e(forum_when($row['_row']['last_post_at'] ?? null))
                    . '</span>';
            },

            'actions' => function (array $row) use ($e): string {
                $r = $row['_row'];
                $id = (int) $r['id'];
                $live = (int) $r['is_deleted'] === 0;

                $html = '<div class="sf-row-acts">';
                if ($live) {
                    $html .= '<a class="sf-btn" href="'
                        . $e($this->url->to('forum/thread', ['id' => $id]))
                        . '" target="_blank" rel="noopener">Open</a>';
                }

                return $html . '</div>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $categories, $e) {
            $this->view->assign('title', 'Forum threads');
            $this->view->block('right_sidebar', 'Forum');

            echo '<div class="sf-page"><div class="sf-head"><div><h1>Threads</h1>'
               . '<p>Pin, lock, move or remove discussions.</p></div>';
            echo '<div class="sf-actions"><a class="sf-btn" href="'
               . $e($this->url->to('forum')) . '" target="_blank" rel="noopener">'
               . '<i class="bi bi-box-arrow-up-right"></i>View forum</a></div></div>';

            echo '<section class="sf-card">';

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

            echo $dataset->render();

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
