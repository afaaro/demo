<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use System\Engine\Controller;
use System\Library\Dataset;

class ForumAdminPosts extends Controller
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
            ->searchPlaceholder('Text or thread title…')
            ->sortable(['thread_title', 'category_name', 'created_at'])
            ->defaultSort('created_at', 'desc')
            ->filter('category', 'select', $categoryOptions)
            ->filter('state', 'select', [
                'live'    => 'Live',
                'deleted' => 'Deleted',
                'all'     => 'All',
            ])
            ->columns([
                'select'        => '',
                'message'       => 'Message',
                'category_name' => 'Forum',
                'created_at'    => 'Posted',
                'actions'       => '',
            ])
            ->query(function ($db, array $state): array {
                $q = $state['q'];
                $cat = (int) ($state['filters']['category'] ?? 0);
                $stateFilter = (string) ($state['filters']['state'] ?? 'live');
                if (!in_array($stateFilter, ['live', 'deleted', 'all'], true)) {
                    $stateFilter = 'live';
                }

                $where  = ['1=1'];
                $params = [];
                if ($q !== '') {
                    $where[]  = '(p.`content` LIKE ? ESCAPE \'\\\\\' OR t.`title` LIKE ? ESCAPE \'\\\\\')';
                    $needle   = '%' . str_replace(
                        ['\\', '%', '_'],
                        ['\\\\', '\\%', '\\_'],
                        $q
                    ) . '%';
                    $params[] = $needle;
                    $params[] = $needle;
                }
                if ($cat > 0) {
                    $where[]  = 't.`category_id` = ?';
                    $params[] = $cat;
                }
                $where[] = match ($stateFilter) {
                    'deleted' => 'p.`is_deleted` = 1',
                    'all'     => '1=1',
                    default   => 'p.`is_deleted` = 0',
                };
                $sqlWhere = implode(' AND ', $where);

                $sortMap = [
                    'thread_title'  => 't.`title`',
                    'category_name' => 'c.`name`',
                    'created_at'    => 'p.`created_at`',
                ];
                $sortKey = $state['sort'];
                $orderCol = $sortMap[$sortKey] ?? 'p.`created_at`';
                $dir = $state['dir'] === 'asc' ? 'ASC' : 'DESC';
                $orderSql = " ORDER BY {$orderCol} {$dir}, p.`id` DESC";

                $total = (int) $db->query(
                    "SELECT COUNT(*)
                       FROM `#__forum_posts` p
                       JOIN `#__forum_threads` t ON t.`id` = p.`thread_id`
                      WHERE {$sqlWhere}",
                    $params
                )->value;

                $items = $db->query(
                    "SELECT p.`id`, p.`thread_id`, p.`content`, p.`is_deleted`, p.`created_at`, p.`edited_at`,
                            t.`title` AS thread_title, t.`is_deleted` AS thread_deleted,
                            c.`name` AS category_name,
                            COALESCE(NULLIF(u.`display_name`, ''), u.`username`) AS author_name
                       FROM `#__forum_posts` p
                       JOIN `#__forum_threads` t ON t.`id` = p.`thread_id`
                       JOIN `#__forum_categories` c ON c.`id` = t.`category_id`
                       LEFT JOIN `#__users` u ON u.`id` = p.`user_id`
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

                return '<input form="forum-posts-bulk" type="checkbox" '
                    . 'class="form-check-input forum-post-id" '
                    . 'name="ids[]" value="' . $id . '" autocomplete="off">';
            },

            'message' => function (array $row) use ($e): string {
                $r = $row['_row'];
                $id = (int) $r['id'];
                $threadId = (int) $r['thread_id'];
                $live = (int) $r['is_deleted'] === 0 && (int) $r['thread_deleted'] === 0;

                if ($live) {
                    $html = '<a class="sf-title" href="'
                        . $e($this->url->to('forum/thread', ['id' => $threadId]) . '#post-' . $id)
                        . '" target="_blank" rel="noopener">' . $e((string) $r['thread_title']) . '</a>';
                } else {
                    $html = '<span class="sf-title">' . $e((string) $r['thread_title']) . '</span>';
                }

                $html .= '<span class="sf-sub">';
                if ((int) $r['is_deleted'] === 1) {
                    $html .= '<span class="sf-pill is-off">Deleted</span> ';
                }
                $html .= $e((string) ($r['author_name'] ?? '—')) . ' · '
                    . $e(forum_excerpt((string) $r['content'], 90));

                return $html . '</span>';
            },

            'category_name' => function (array $row) use ($e): string {
                return $e((string) $row['_row']['category_name']);
            },

            'created_at' => function (array $row) use ($e): string {
                return '<span class="sf-when">'
                    . $e(forum_when($row['_row']['created_at'] ?? null))
                    . '</span>';
            },

            'actions' => function (array $row) use ($e): string {
                $r = $row['_row'];
                $id = (int) $r['id'];
                $threadId = (int) $r['thread_id'];
                $live = (int) $r['is_deleted'] === 0 && (int) $r['thread_deleted'] === 0;

                $html = '<div class="sf-row-acts">';
                if ($live) {
                    $html .= '<a class="sf-btn" href="'
                        . $e($this->url->to('forum/thread', ['id' => $threadId]) . '#post-' . $id)
                        . '" target="_blank" rel="noopener">Open</a>';
                }

                return $html . '</div>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e) {
            $this->view->assign('title', 'Forum messages');
            $this->view->block('right_sidebar', 'Forum');

            echo '<div class="sf-page"><div class="sf-head"><div><h1>Messages</h1>'
               . '<p>Search and remove posts without leaving the admin.</p></div></div>';

            echo '<section class="sf-card">';

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

            echo $dataset->render();

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
