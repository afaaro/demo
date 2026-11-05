<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use System\Engine\Controller;
use System\Engine\NotFoundException;

class ForumAdminCategories extends Controller
{
    protected function boot(): void
    {
        forum_require_moderate();
    }

    public function indexAction(): void
    {
        $items = $this->db->query(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM `#__forum_threads` t
                      WHERE t.`category_id` = c.`id` AND t.`is_deleted` = 0) AS thread_count
               FROM `#__forum_categories` c
              ORDER BY c.`position`, c.`name`"
        )->rows;

        echo $this->view->inline(function () use ($items) {
            $this->view->assign('title', 'Forums');
            $this->view->block('right_sidebar', 'Forum');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="sf-page">';
            echo '<div class="sf-head"><div><h1>Forums</h1>'
               . '<p>Node list members see on the board.</p></div>';
            echo '<div class="sf-actions">';
            echo '<a class="sf-btn" href="' . $e($this->url->to('forum')) . '" target="_blank" rel="noopener">'
               . '<i class="bi bi-box-arrow-up-right"></i>View forum</a>';
            echo '<a class="sf-btn is-primary" href="' . $e($this->url->to('forum/admin/categories/add')) . '">'
               . '<i class="bi bi-plus-lg"></i>New forum</a>';
            echo '</div></div><section class="sf-card">';

            if ($items === []) {
                echo '<p class="sf-empty">No forums yet. '
                   . '<a href="' . $e($this->url->to('forum/admin/categories/add')) . '">Create one</a>.</p></section></div>';
                return;
            }

            echo '<div class="sf-card-h"><h2>' . count($items) . ' forum'
               . (count($items) === 1 ? '' : 's') . '</h2></div>';
            echo '<div class="table-responsive"><table class="sf-table"><thead><tr>'
               . '<th>Forum</th><th class="c-status">Status</th><th class="c-count">Threads</th>'
               . '<th class="c-count">Messages</th><th class="c-acts"></th>'
               . '</tr></thead><tbody>';
            foreach ($items as $c) {
                $id = (int) $c['id'];
                $status = (string) $c['status'];
                $pill = match ($status) {
                    'visible' => '<span class="sf-pill is-ok">Visible</span>',
                    'locked'  => '<span class="sf-pill is-wait">Locked</span>',
                    default   => '<span class="sf-pill is-off">Hidden</span>',
                };
                echo '<tr><td><a class="sf-title" href="'
                   . $e($this->url->to('forum/admin/categories/edit', ['id' => $id])) . '">'
                   . $e((string) $c['name']) . '</a>'
                   . '<span class="sf-sub">/' . $e((string) $c['slug'])
                   . (((string) ($c['description'] ?? '')) !== '' ? ' · ' . $e((string) $c['description']) : '')
                   . '</span></td>';
                echo '<td class="c-status">' . $pill . '</td>';
                echo '<td class="c-count">' . (int) $c['thread_count'] . '</td>';
                echo '<td class="c-count">' . (int) ($c['posts_count'] ?? 0) . '</td>';
                echo '<td class="c-acts"><div class="sf-row-acts">';
                if ($status === 'visible') {
                    echo '<a class="sf-btn" href="'
                       . $e($this->url->to('forum/category', ['slug' => (string) $c['slug']]))
                       . '" target="_blank" rel="noopener">Open</a>';
                }
                echo '<a class="sf-btn" href="' . $e($this->url->to('forum/admin/categories/edit', ['id' => $id])) . '">Edit</a>';
                echo '<form method="post" action="' . $e($this->url->to('forum/admin/categories/delete')) . '"'
                   . ' onsubmit="return confirm(\'Delete this forum?\');">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="id" value="' . $id . '">';
                echo '<button type="submit" class="sf-btn is-danger">Delete</button>';
                echo '</form></div></td></tr>';
            }
            echo '</tbody></table></div></section></div>';
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
