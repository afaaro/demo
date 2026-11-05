<?php
declare(strict_types=1);

use Module\News\Services\NewsCategory;
use Module\News\Services\NewsCategoryRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class NewsAdminCategories extends Controller
{
    private NewsCategoryRepository $categories;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->categories = new NewsCategoryRepository($this->db);
        // Permissions: Action.php only.
    }

    // --------------------------------------------------------------
    // List
    // --------------------------------------------------------------

    public function indexAction(): void
    {
        $items = $this->categories->withCounts();

        echo $this->view->inline(function () use ($items) {
            $this->view->assign('title', 'News categories');
            $this->view->block('right_sidebar', 'News');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>Categories</h1><p>Group articles by topic.</p></div>';
            echo '<div class="sf-actions">';
            echo '<a href="' . $e($this->url->to('news/admin/home')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a>';
            echo '<a href="' . $e($this->url->to('news/admin/categories/add')) . '" class="sf-btn is-primary">'
               . '<i class="bi bi-plus-lg"></i>New category</a>';
            echo '</div></div>';

            echo '<section class="sf-card">';

            if ($items === []) {
                echo '<p class="sf-empty">No categories yet. '
                   . '<a href="' . $e($this->url->to('news/admin/categories/add')) . '">Create the first one</a>.</p>'
                   . '</section></div>';
                return;
            }

            echo '<div class="table-responsive"><table class="sf-table">';
            echo '<thead><tr><th>Name</th><th>Slug</th>'
               . '<th class="c-count">Articles</th><th class="c-acts"></th></tr></thead><tbody>';

            foreach ($items as $c) {
                echo '<tr>';
                echo '<td><a class="sf-title" href="'
                   . $e($this->url->to('news/admin/categories/edit', ['id' => $c['id']])) . '">'
                   . $e((string) $c['name']) . '</a></td>';
                echo '<td><span class="sf-sub">' . $e((string) ($c['slug'] ?? '')) . '</span></td>';
                echo '<td class="c-count">' . (int) $c['count'] . '</td>';
                echo '<td class="c-acts"><div class="sf-row-acts">';
                echo '<a href="' . $e($this->url->to('news/admin/categories/edit', ['id' => $c['id']])) . '"'
                   . ' class="sf-btn">Edit</a>';
                echo '<form method="post" action="' . $e($this->url->to('news/admin/categories/delete')) . '"'
                   . ' onsubmit="return confirm(\'Delete this category? Articles will be unassigned.\');">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="id" value="' . (int) $c['id'] . '">';
                echo '<button type="submit" class="sf-btn is-danger">Delete</button>';
                echo '</form></div></td></tr>';
            }

            echo '</tbody></table></div></section></div>';
        });
    }

    // --------------------------------------------------------------
    // Add
    // --------------------------------------------------------------

    public function addAction(): void
    {
        $old = [
            'id'         => 0,
            'slug'       => '',
            'name'       => '',
            'sort_order' => 0,
            'active'     => 1,
        ];

        if ($this->request->isPost()) {
            $old = $this->readForm();

            try {
                $cat = $this->categories->create(
                    $old['slug'],
                    $old['name'],
                    (int) $old['sort_order'],
                    (bool) $old['active']
                );
                $this->notify->success("Category '{$cat->name}' created.");
                redirect_to('news/admin/categories');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old) {
            $this->view->assign('title', 'New category');
            $this->view->block('right_sidebar', 'News');
            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>New category</h1><p>Add a topic for articles.</p></div>';
            echo '<div class="sf-actions">'
               . '<a href="' . $this->view->e($this->url->to('news/admin/categories')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a></div>';
            echo '</div>';
            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->categoryForm($old, $this->url->to('news/admin/categories/add'), 'Create');
            echo '</div></section></div>';
        });
    }

    // --------------------------------------------------------------
    // Edit
    // --------------------------------------------------------------

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $cat = $this->categories->find($id);
        if ($cat === null) {
            throw new NotFoundException('Category not found.');
        }

        $old = [
            'id'         => $cat->id,
            'slug'       => $cat->slug,
            'name'       => $cat->name,
            'sort_order' => $cat->sortOrder,
            'active'     => $cat->active ? 1 : 0,
        ];

        if ($this->request->isPost()) {
            $old = $this->readForm();

            try {
                $this->categories->update($id, $old);
                $this->notify->success('Category updated.');
                redirect_to('news/admin/categories');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old, $id) {
            $this->view->assign('title', 'Edit category');
            $this->view->block('right_sidebar', 'News');
            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>Edit category</h1><p>' . $this->view->e((string) $old['name']) . '</p></div>';
            echo '<div class="sf-actions">'
               . '<a href="' . $this->view->e($this->url->to('news/admin/categories')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a></div>';
            echo '</div>';
            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->categoryForm($old, $this->url->to('news/admin/categories/edit', ['id' => $id]), 'Save');
            echo '</div></section></div>';
        });
    }

    // --------------------------------------------------------------
    // Delete
    // --------------------------------------------------------------

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('news/admin/categories');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('news/admin/categories');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $cat = $this->categories->find($id);

        if ($cat !== null) {
            $this->categories->delete($id);
            $this->notify->success("Category '{$cat->name}' deleted.");
        } else {
            $this->notify->error('Category not found.');
        }

        redirect_to('news/admin/categories');
    }

    // --------------------------------------------------------------
    // Helpers
    // --------------------------------------------------------------

    private function readForm(): array
    {
        return [
            'slug'       => trim((string) $this->request->post('slug', 'string', '')),
            'name'       => trim((string) $this->request->post('name', 'string', '')),
            'sort_order' => (int) $this->request->post('sort_order', 'int', 0),
            'active'     => (int) $this->request->post('active', 'int', 0),
        ];
    }

    private function categoryForm(array $old, string $action, string $submitLabel): string
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
                    'help'  => 'Lowercase letters, numbers, dashes.',
                ]) ?>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->form->field([
                    'name'  => 'sort_order',
                    'label' => 'Sort order',
                    'type'  => 'number',
                    'value' => (string) $old['sort_order'],
                ]) ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Active</label>
                <div class="form-check">
                    <input type="checkbox" name="active" value="1" class="form-check-input"
                        id="cat_active" <?= !empty($old['active']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cat_active">Available for new articles</label>
                </div>
            </div>
        </div>

        <div class="sf-actions mt-4">
            <button type="submit" class="sf-btn is-primary"><?= $this->view->e($submitLabel) ?></button>
            <a href="<?= $this->view->e($this->url->to('news/admin/categories')) ?>" class="sf-btn">Cancel</a>
        </div>

        <?= $this->form->close() ?>
        <?php
        return (string) ob_get_clean();
    }
}