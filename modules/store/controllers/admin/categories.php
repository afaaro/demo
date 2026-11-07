<?php
declare(strict_types=1);

use Module\Store\Services\Category;
use Module\Store\Services\CategoryRepository;
use Module\Store\Services\TenantContext;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreAdminCategories extends Controller
{
    private CategoryRepository $categories;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->categories = new CategoryRepository($this->db);
        // Permissions: Action.php only.
    }

    // =========================================================================
    // List
    // =========================================================================

    public function indexAction(): void
    {
        $tree = $this->categories->tree();

        echo $this->view->inline(function () use ($tree) {
            $this->view->assign('title', 'Categories');
            $this->view->block('right_sidebar', 'Categories');

            echo '<div class="d-flex justify-content-between align-items-center mb-4">';
            echo '<h1 class="h3 mb-0">Categories</h1>';
            echo '<a href="' . $this->view->e($this->url->to('store/admin/categories/add')) . '"'
               . ' class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add category</a>';
            echo '</div>';

            if (empty($tree)) {
                echo '<div class="alert alert-info">No categories defined yet. '
                   . 'Categories group products and can be nested.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-striped align-middle">';
            echo '<thead><tr>'
               . '<th>Name</th><th>Slug</th>'
               . '<th class="text-end">Products</th>'
               . '<th class="text-center">Active</th>'
               . '<th></th>'
               . '</tr></thead><tbody>';

            $this->renderTreeRows($tree, 0);

            echo '</tbody></table></div>';
        });
    }

    /**
     * Render the tree as table rows with indentation.
     *
     * @param array<int, Category> $nodes
     */
    private function renderTreeRows(array $nodes, int $depth): void
    {
        foreach ($nodes as $c) {
            $pad = $depth > 0
                ? '<span class="text-muted">' . str_repeat('&mdash; ', $depth) . '</span>'
                : '';

            $tid = TenantContext::get()->has() ? (int) TenantContext::get()->id() : 0;
            $productCount = $tid > 0
                ? (int) $this->db->query(
                    "SELECT COUNT(*) FROM `#__store_product_categories`
                      WHERE `category_id` = ? AND `tenant_id` = ?",
                    [$c->id, $tid]
                )->value
                : 0;

            echo '<tr>';
            echo '<td>' . $pad . '<strong>' . $this->view->e($c->name) . '</strong>';

            if ($c->description !== '') {
                echo '<br><span class="text-muted small">'
                   . $this->view->e($c->description)
                   . '</span>';
            }
            echo '</td>';

            echo '<td><code class="text-muted">' . $this->view->e($c->slug) . '</code></td>';
            echo '<td class="text-end">' . $productCount . '</td>';
            echo '<td class="text-center">';
            echo $c->active
                ? '<i class="bi bi-check-circle-fill text-success"></i>'
                : '<i class="bi bi-dash-circle text-muted"></i>';
            echo '</td>';

            echo '<td class="text-end text-nowrap">';
            echo '<a href="' . $this->view->e($this->url->to('store/admin/categories/edit', ['id' => $c->id])) . '"'
               . ' class="btn btn-sm btn-outline-secondary">Edit</a> ';

            echo '<form method="post"'
               . ' action="' . $this->view->e($this->url->to('store/admin/categories/delete', ['id' => $c->id])) . '"'
               . ' class="d-inline"'
               . ' onsubmit="return confirm(\'Delete this category? Child categories will be moved to the top level. Product links will be removed.\');">';
            echo $this->request->csrfField();
            echo '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
            echo '</form>';

            echo '</td>';
            echo '</tr>';

            if (!empty($c->children)) {
                $this->renderTreeRows($c->children, $depth + 1);
            }
        }
    }

    // =========================================================================
    // Add
    // =========================================================================

    public function addAction(): void
    {
        $old = [
            'name'        => '',
            'slug'        => '',
            'description' => '',
            'parent_id'   => null,
            'sort_order'  => 0,
            'active'      => 1,
        ];

        $flat = $this->categories->flat();

        if ($this->request->isPost()) {
            $old['name']        = trim((string) $this->request->post('name',        'string', ''));
            $old['slug']        = trim((string) $this->request->post('slug',        'string', ''));
            $old['description'] = trim((string) $this->request->post('description', 'string', ''));
            $old['sort_order']  = (int)          $this->request->post('sort_order',  'int',    0);
            $old['active']      = (int)          $this->request->post('active',      'int',    0);

            $parentInput = $this->request->post('parent_id', 'string', '');
            $old['parent_id'] = $parentInput !== '' ? (int) $parentInput : null;

            // Auto-generate slug from name if blank.
            if ($old['slug'] === '') {
                $old['slug'] = $this->slugify($old['name']);
            }

            try {
                $category = $this->categories->create([
                    'parent_id'   => $old['parent_id'],
                    'slug'        => $old['slug'],
                    'name'        => $old['name'],
                    'description' => $old['description'],
                    'sort_order'  => $old['sort_order'],
                    'active'      => (bool) $old['active'],
                ]);

                $this->notify->success("Category '{$category->name}' created.");
                redirect_to('store/admin/categories');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old, $flat) {
            $this->view->assign('title', 'Add Category');
            echo '<h1 class="h3 mb-4">Add Category</h1>';
            echo $this->categoryForm(
                $old,
                $this->url->to('store/admin/categories/add'),
                $flat
            );
        });
    }

    // =========================================================================
    // Edit
    // =========================================================================

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);

        $category = $this->categories->find($id);
        if ($category === null) {
            throw new NotFoundException('Category not found.');
        }

        $flat = $this->categories->flat();

        // Exclude self and descendants from the parent picker (no cycles).
        $excluded = $this->categories->descendantIds($id);

        $old = [
            'name'        => $category->name,
            'slug'        => $category->slug,
            'description' => $category->description,
            'parent_id'   => $category->parentId,
            'sort_order'  => $category->sortOrder,
            'active'      => $category->active ? 1 : 0,
        ];

        if ($this->request->isPost()) {
            $old['name']        = trim((string) $this->request->post('name',        'string', ''));
            $old['slug']        = trim((string) $this->request->post('slug',        'string', ''));
            $old['description'] = trim((string) $this->request->post('description', 'string', ''));
            $old['sort_order']  = (int)          $this->request->post('sort_order',  'int',    0);
            $old['active']      = (int)          $this->request->post('active',      'int',    0);

            $parentInput = $this->request->post('parent_id', 'string', '');
            $old['parent_id'] = $parentInput !== '' ? (int) $parentInput : null;

            if ($old['slug'] === '') {
                $old['slug'] = $this->slugify($old['name']);
            }

            try {
                $this->categories->update($id, [
                    'parent_id'   => $old['parent_id'],
                    'slug'        => $old['slug'],
                    'name'        => $old['name'],
                    'description' => $old['description'],
                    'sort_order'  => $old['sort_order'],
                    'active'      => (bool) $old['active'],
                ]);

                $this->notify->success('Category updated.');
                redirect_to('store/admin/categories');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old, $id, $flat, $excluded) {
            $this->view->assign('title', 'Edit Category');
            echo '<h1 class="h3 mb-4">Edit Category</h1>';
            echo $this->categoryForm(
                $old,
                $this->url->to('store/admin/categories/edit', ['id' => $id]),
                $flat,
                $excluded
            );
        });
    }

    // =========================================================================
    // Delete
    // =========================================================================

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/categories');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $category = $this->categories->find($id);

        if ($category !== null) {
            $this->categories->delete($id);
            $this->notify->success("Category '{$category->name}' deleted.");
        } else {
            $this->notify->error('Category not found.');
        }

        redirect_to('store/admin/categories');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array{
     *     name:string, slug:string, description:string,
     *     parent_id:?int, sort_order:int, active:int
     * } $old
     * @param array<int, array{category:Category, depth:int}> $flat
     * @param array<int, int> $excluded  Category IDs to hide from the parent picker
     */
    private function categoryForm(array $old, string $action, array $flat, array $excluded = []): string
    {
        ob_start();
        ?>
        <?= $this->form->open(['action' => $action, 'method' => 'POST']) ?>

        <?= $this->form->field([
            'name'     => 'name',
            'label'    => 'Name',
            'type'     => 'text',
            'value'    => $old['name'],
            'required' => true,
        ]) ?>

        <?= $this->form->field([
            'name'  => 'slug',
            'label' => 'Slug',
            'type'  => 'text',
            'value' => $old['slug'],
            'help'  => 'URL-friendly identifier. Leave blank to auto-generate from the name.',
        ]) ?>

        <div class="mb-3">
            <label class="form-label">Parent category</label>
            <select name="parent_id" class="form-select">
                <option value="">— Top level —</option>
                <?php foreach ($flat as $row):
                    $c = $row['category'];
                    if (in_array($c->id, $excluded, true)) continue;
                    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $row['depth']);
                    $selected = $old['parent_id'] === $c->id;
                ?>
                    <option value="<?= (int) $c->id ?>" <?= $selected ? 'selected' : '' ?>>
                        <?= $indent . $this->view->e($c->name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Categories can be nested. Select a parent to place this under it.</div>
        </div>

        <?= $this->form->field([
            'name'  => 'description',
            'label' => 'Description',
            'type'  => 'textarea',
            'value' => $old['description'],
            'rows'  => 3,
        ]) ?>

        <?= $this->form->field([
            'name'  => 'sort_order',
            'label' => 'Sort order',
            'type'  => 'text',
            'value' => (string) $old['sort_order'],
            'help'  => 'Lower numbers appear first. Defaults to 0.',
        ]) ?>

        <?= $this->form->field([
            'name'    => 'active',
            'label'   => 'Active',
            'type'    => 'checkbox',
            'value'   => 1,
            'checked' => !empty($old['active']),
        ]) ?>

        <hr class="my-4">
        <button type="submit" class="btn btn-primary">Save</button>
        <a href="<?= $this->view->e($this->url->to('store/admin/categories')) ?>"
           class="btn btn-outline-secondary">Cancel</a>

        <?= $this->form->close() ?>
        <?php
        return (string) ob_get_clean();
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}