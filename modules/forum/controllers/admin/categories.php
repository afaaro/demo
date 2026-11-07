<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class ForumAdminCategories extends Controller
{
    protected function boot(): void
    {
        forum_require_moderate();
    }

    public function indexAction(): void
    {
        $dataset = (new Dataset($this->registry))
            ->perPage(100)
            ->searchPlaceholder('Search forum name or slug…')
            ->sortable(['position', 'name', 'status', 'thread_count', 'posts_count'])
            ->defaultSort('position', 'asc')
            ->columns([
                'forum'        => 'Forum',
                'status'       => 'Status',
                'thread_count' => 'Threads',
                'posts_count'  => 'Messages',
                'actions'      => '',
            ])
            ->query(function ($db, array $state): array {
                $where  = ['1=1'];
                $params = [];
                if ($state['q'] !== '') {
                    $where[]  = '(c.`name` LIKE ? ESCAPE \'\\\\\' OR c.`slug` LIKE ? ESCAPE \'\\\\\')';
                    $needle   = '%' . str_replace(
                        ['\\', '%', '_'],
                        ['\\\\', '\\%', '\\_'],
                        $state['q']
                    ) . '%';
                    $params[] = $needle;
                    $params[] = $needle;
                }

                $sqlWhere = implode(' AND ', $where);

                $sortMap = [
                    'position'      => 'c.`position`',
                    'name'          => 'c.`name`',
                    'status'        => 'c.`status`',
                    'thread_count'  => 'thread_count',
                    'posts_count'   => 'c.`posts_count`',
                ];
                $sortKey = $state['sort'];
                $orderCol = $sortMap[$sortKey] ?? 'c.`position`';
                $dir = $state['dir'] === 'asc' ? 'ASC' : 'DESC';
                $orderSql = " ORDER BY {$orderCol} {$dir}, c.`name` ASC, c.`id` DESC";

                $total = (int) $db->query(
                    "SELECT COUNT(*) FROM `#__forum_categories` c WHERE {$sqlWhere}",
                    $params
                )->value;

                $items = $db->query(
                    "SELECT c.*,
                            (SELECT COUNT(*) FROM `#__forum_threads` t
                              WHERE t.`category_id` = c.`id` AND t.`is_deleted` = 0) AS thread_count
                       FROM `#__forum_categories` c
                      WHERE {$sqlWhere}
                      {$orderSql}
                      LIMIT ?, ?",
                    [...$params, $state['offset'], $state['per_page']]
                )->rows;

                return [
                    'rows'  => array_map(static fn(array $c): array => ['_row' => $c], $items),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $value): string =>
            $this->view->e((string) $value);

        $dataset->columns([
            'forum' => function (array $row) use ($e): string {
                $c = $row['_row'];
                $id = (int) $c['id'];

                $html = '<a class="sf-title" href="'
                    . $e($this->url->to('forum/admin/categories/edit', ['id' => $id]))
                    . '">' . $e((string) $c['name']) . '</a>';
                $html .= '<span class="sf-sub">/' . $e((string) $c['slug']);
                if (((string) ($c['description'] ?? '')) !== '') {
                    $html .= ' · ' . $e((string) $c['description']);
                }

                return $html . '</span>';
            },

            'status' => function (array $row): string {
                return match ((string) $row['_row']['status']) {
                    'visible' => '<span class="sf-pill is-ok">Visible</span>',
                    'locked'  => '<span class="sf-pill is-wait">Locked</span>',
                    default   => '<span class="sf-pill is-off">Hidden</span>',
                };
            },

            'thread_count' => function (array $row): string {
                return (string) (int) ($row['_row']['thread_count'] ?? 0);
            },

            'posts_count' => function (array $row): string {
                return (string) (int) ($row['_row']['posts_count'] ?? 0);
            },

            'actions' => function (array $row) use ($e): string {
                $c = $row['_row'];
                $id = (int) $c['id'];
                $status = (string) $c['status'];

                $html = '<div class="sf-row-acts">';
                if ($status === 'visible') {
                    $html .= '<a class="sf-btn" href="'
                        . $e($this->url->to('forum/category', ['slug' => (string) $c['slug']]))
                        . '" target="_blank" rel="noopener">Open</a>';
                }
                $html .= '<a class="sf-btn" href="'
                    . $e($this->url->to('forum/admin/categories/edit', ['id' => $id]))
                    . '">Edit</a>';
                $html .= '<form method="post" action="'
                    . $e($this->url->to('forum/admin/categories/delete'))
                    . '" onsubmit="return confirm(\'Delete this forum?\');">';
                $html .= $this->request->csrfField();
                $html .= '<input type="hidden" name="id" value="' . $id . '">';
                $html .= '<button type="submit" class="sf-btn is-danger">Delete</button>';
                $html .= '</form>';

                return $html . '</div>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e) {
            $this->view->assign('title', 'Forums');
            $this->view->block('right_sidebar', 'Forum');

            echo '<div class="sf-page">';
            echo '<div class="sf-head"><div><h1>Forums</h1>'
               . '<p>Node list members see on the board.</p></div>';
            echo '<div class="sf-actions">';
            echo '<a class="sf-btn" href="' . $e($this->url->to('forum')) . '" target="_blank" rel="noopener">'
               . '<i class="bi bi-box-arrow-up-right"></i>View forum</a>';
            echo '<a class="sf-btn is-primary" href="' . $e($this->url->to('forum/admin/categories/add')) . '">'
               . '<i class="bi bi-plus-lg"></i>New forum</a>';
            echo '</div></div><section class="sf-card">';
            echo $dataset->render();
            echo '</section></div>';
        });
    }

    public function addAction(): void
    {
        $old = [
            'name' => '', 'slug' => '', 'description' => '', 'position' => '100', 'status' => 'visible',
            'access_role' => '0', 'post_role' => '-101',
        ];
        if ($this->request->isPost()) {
            $old = $this->readForm();
            if ($this->save(0, $old)) {
                $this->notify->success('Forum created.');
                redirect_to('forum/admin/categories');
                return;
            }
        }
        $this->formPage('New forum', $old, $this->url->to('forum/admin/categories/add'), 'Create');
    }

    public function editAction(): void
    {
        $id = (int) $this->request->param('id', 'int', 0);
        $row = $this->db->first('forum_categories', ['id' => $id]);
        if ($id <= 0 || $row === []) {
            throw new NotFoundException('Category not found.');
        }
        $old = [
            'name'        => (string) $row['name'],
            'slug'        => (string) $row['slug'],
            'description' => (string) ($row['description'] ?? ''),
            'position'    => (string) (int) $row['position'],
            'status'      => (string) $row['status'],
            'access_role' => (string) (int) ($row['access_role'] ?? 0),
            'post_role'   => (string) (int) ($row['post_role'] ?? -101),
        ];
        if ($this->request->isPost()) {
            $old = $this->readForm();
            if ($this->save($id, $old)) {
                $this->notify->success('Forum saved.');
                redirect_to('forum/admin/categories');
                return;
            }
        }
        $this->formPage('Edit forum', $old, $this->url->to('forum/admin/categories/edit', ['id' => $id]), 'Save');
    }

    public function deleteAction(): void
    {
        $id = (int) $this->request->post('id', 'int', 0);
        if ($id <= 0) {
            redirect_to('forum/admin/categories');
        }
        $live = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__forum_threads` WHERE `category_id` = ? AND `is_deleted` = 0",
            [$id]
        )->value;
        if ($live > 0) {
            $this->notify->error('Move or delete the threads in this forum first.');
            redirect_to('forum/admin/categories');
        }
        $this->db->query('DELETE FROM `#__forum_categories` WHERE `id` = ?', [$id]);
        $this->notify->success('Forum deleted.');
        redirect_to('forum/admin/categories');
    }

    /**
     * @return array{name:string,slug:string,description:string,position:string,status:string,access_role:string,post_role:string}
     */
    private function readForm(): array
    {
        $status = (string) $this->request->post('status', 'string', 'visible');
        if (!in_array($status, ['visible', 'hidden', 'locked'], true)) {
            $status = 'visible';
        }
        $allowed = [];
        foreach (\System\Services\User::accessOptions() as $opt) {
            $allowed[(int) $opt['value']] = true;
        }
        $access = (int) $this->request->post('access_role', 'int', 0);
        $post = (int) $this->request->post('post_role', 'int', -101);
        if (!isset($allowed[$access])) {
            $access = 0;
        }
        if (!isset($allowed[$post])) {
            $post = -101;
        }
        return [
            'name'        => trim((string) $this->request->post('name', 'string', '')),
            'slug'        => trim((string) $this->request->post('slug', 'string', '')),
            'description' => trim((string) $this->request->post('description', 'string', '')),
            'position'    => (string) (int) $this->request->post('position', 'int', 100),
            'status'      => $status,
            'access_role' => (string) $access,
            'post_role'   => (string) $post,
        ];
    }

    /**
     * @param array{name:string,slug:string,description:string,position:string,status:string,access_role:string,post_role:string} $data
     */
    private function save(int $id, array $data): bool
    {
        if ($data['name'] === '') {
            $this->notify->error('Name is required.');
            return false;
        }
        $slug = $data['slug'] !== '' ? slug($data['slug']) : forum_category_slugify($data['name'], $this->db, $id);
        if ($slug === '') {
            $this->notify->error('Could not build a slug.');
            return false;
        }
        $payload = [
            'name'        => $data['name'],
            'slug'        => $slug,
            'description' => $data['description'],
            'position'    => (int) $data['position'],
            'status'      => $data['status'],
            'access_role' => (int) $data['access_role'],
            'post_role'   => (int) $data['post_role'],
        ];
        if ($id > 0) {
            $this->db->update('forum_categories', $payload, ['id' => $id]);
        } else {
            $this->db->insert('forum_categories', $payload);
        }
        return true;
    }

    /** @param array{name:string,slug:string,description:string,position:string,status:string} $old */
    private function formPage(string $title, array $old, string $action, string $submit): void
    {
        echo $this->view->inline(function () use ($title, $old, $action, $submit) {
            $this->view->assign('title', $title);
            $this->view->block('right_sidebar', 'Forum');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $blurb = $title === 'New forum'
                ? 'Add a board members can post in.'
                : (string) $old['name'];

            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>' . $e($title) . '</h1><p>' . $e($blurb) . '</p></div>';
            echo '<div class="sf-actions">'
               . '<a href="' . $e($this->url->to('forum/admin/categories')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a></div>';
            echo '</div>';
            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->forumForm($old, $action, $submit);
            echo '</div></section></div>';
        });
    }

    /** @param array{name:string,slug:string,description:string,position:string,status:string} $old */
    private function forumForm(array $old, string $action, string $submitLabel): string
    {
        ob_start();
        ?>
        <?= $this->form->open(['action' => $action, 'method' => 'POST']) ?>

        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->form->field([
                    'name'     => 'name',
                    'label'    => 'Name',
                    'type'     => 'text',
                    'value'    => (string) $old['name'],
                    'required' => true,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->form->field([
                    'name'  => 'slug',
                    'label' => 'Slug',
                    'type'  => 'text',
                    'value' => (string) $old['slug'],
                    'help'  => 'Leave blank to generate from the name.',
                ]) ?>
            </div>
        </div>

        <div class="row g-3 mt-0">
            <div class="col-12">
                <?= $this->form->field([
                    'name'  => 'description',
                    'label' => 'Description',
                    'type'  => 'textarea',
                    'rows'  => 3,
                    'value' => (string) $old['description'],
                ]) ?>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->form->field([
                    'name'  => 'position',
                    'label' => 'Position',
                    'type'  => 'number',
                    'value' => (string) $old['position'],
                    'help'  => 'Lower numbers appear first.',
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->form->field([
                    'name'    => 'status',
                    'label'   => 'Status',
                    'type'    => 'select',
                    'value'   => (string) $old['status'],
                    'options' => [
                        'visible' => 'Visible',
                        'hidden'  => 'Hidden',
                        'locked'  => 'Locked',
                    ],
                ]) ?>
            </div>
        </div>

        <?php
        $accessOpts = [];
        foreach (\System\Services\User::accessOptions() as $opt) {
            $accessOpts[(string) $opt['value']] = $opt['label'];
        }
        ?>
        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->form->field([
                    'name'    => 'access_role',
                    'label'   => 'Who can view',
                    'type'    => 'select',
                    'value'   => (string) ($old['access_role'] ?? '0'),
                    'options' => $accessOpts,
                    'help'    => '0 = public. Super Administrator is dedicated.',
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->form->field([
                    'name'    => 'post_role',
                    'label'   => 'Who can post',
                    'type'    => 'select',
                    'value'   => (string) ($old['post_role'] ?? '-101'),
                    'options' => $accessOpts,
                ]) ?>
            </div>
        </div>

        <div class="sf-actions mt-4">
            <button type="submit" class="sf-btn is-primary"><?= $this->view->e($submitLabel) ?></button>
            <a href="<?= $this->view->e($this->url->to('forum/admin/categories')) ?>" class="sf-btn">Cancel</a>
        </div>

        <?= $this->form->close() ?>
        <?php
        return (string) ob_get_clean();
    }
}
