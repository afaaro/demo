<?php
declare(strict_types=1);

use Module\News\Services\NewsCategory;
use Module\News\Services\NewsCategoryRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

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
        $dataset = (new Dataset($this->registry))
            ->perPage(100)
            ->searchable(['name', 'slug'])
            ->searchPlaceholder('Search name or slug…')
            ->sortable(['name', 'slug', 'count'])
            ->defaultSort('name', 'asc')
            ->columns([
                'name'    => 'Name',
                'slug'    => 'Slug',
                'count'   => 'Articles',
                'actions' => '',
            ])
            ->query(function ($db, array $state): array {
                $items = $this->categories->withCounts();
                $q = $state['q'];
                if ($q !== '') {
                    $needle = mb_strtolower($q);
                    $items = array_values(array_filter(
                        $items,
                        static fn(array $c): bool =>
                            str_contains(mb_strtolower((string) $c['name']), $needle)
                            || str_contains(mb_strtolower((string) $c['slug']), $needle)
                    ));
                }

                $sort = in_array($state['sort'], ['name', 'slug', 'count'], true)
                    ? $state['sort']
                    : 'name';
                $dir = $state['dir'] === 'desc' ? -1 : 1;
                usort($items, static function (array $a, array $b) use ($sort, $dir): int {
                    $av = $a[$sort] ?? '';
                    $bv = $b[$sort] ?? '';
                    if ($sort === 'count') {
                        return $dir * ((int) $av <=> (int) $bv);
                    }
                    return $dir * strcasecmp((string) $av, (string) $bv);
                });

                $total = count($items);
                $slice = array_slice($items, $state['offset'], $state['per_page']);

                return [
                    'rows'  => array_map(static fn(array $c): array => ['_row' => $c], $slice),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $value): string =>
            $this->view->e((string) $value);

        $dataset->columns([
            'name' => function (array $row) use ($e): string {
                $c = $row['_row'];

                return '<a class="sf-title" href="'
                    . $e($this->url->to('news/admin/categories/edit', ['id' => $c['id']]))
                    . '">' . $e((string) $c['name']) . '</a>';
            },

            'slug' => function (array $row) use ($e): string {
                return '<span class="sf-sub">'
                    . $e((string) ($row['_row']['slug'] ?? '')) . '</span>';
            },

            'count' => function (array $row): string {
                return (string) (int) $row['_row']['count'];
            },

            'actions' => function (array $row) use ($e): string {
                $c = $row['_row'];

                $html = '<div class="sf-row-acts">';
                $html .= '<a href="'
                    . $e($this->url->to('news/admin/categories/edit', ['id' => $c['id']]))
                    . '" class="sf-btn">Edit</a>';
                $html .= '<form method="post" action="'
                    . $e($this->url->to('news/admin/categories/delete'))
                    . '" onsubmit="return confirm(\'Delete this category? Articles will be unassigned.\');">';
                $html .= $this->request->csrfField();
                $html .= '<input type="hidden" name="id" value="' . (int) $c['id'] . '">';
                $html .= '<button type="submit" class="sf-btn is-danger">Delete</button>';
                $html .= '</form>';

                return $html . '</div>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e) {
            $this->view->assign('title', 'News categories');
            $this->view->block('right_sidebar', 'News');

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
            echo $dataset->render();
            echo '</section></div>';
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