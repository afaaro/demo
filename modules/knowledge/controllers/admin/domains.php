<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use Module\Knowledge\Services\DomainRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class KnowledgeAdminDomains extends Controller
{
    private DomainRepository $domains;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->domains = new DomainRepository($this->db);
    }

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
                'count'   => 'Entries',
                'actions' => '',
            ])
            ->query(function ($db, array $state): array {
                $items = $this->domains->withCounts();
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
                    . $e($this->url->to('knowledge/admin/domains/edit', ['id' => $c['id']]))
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
                    . $e($this->url->to('knowledge/admin/domains/edit', ['id' => $c['id']]))
                    . '" class="sf-btn">Edit</a>';
                $html .= '<form method="post" action="'
                    . $e($this->url->to('knowledge/admin/domains/delete'))
                    . '" onsubmit="return confirm(\'Delete this domain? Entries will be unassigned.\');">';
                $html .= $this->request->csrfField();
                $html .= '<input type="hidden" name="id" value="' . (int) $c['id'] . '">';
                $html .= '<button type="submit" class="sf-btn is-danger">Delete</button>';
                $html .= '</form>';
                return $html . '</div>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e) {
            $this->view->assign('title', 'Knowledge domains');
            $this->view->block('right_sidebar', 'Knowledge');

            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>Domains</h1><p>Group knowledge by topic (automotive, medical, …).</p></div>';
            echo '<div class="sf-actions">';
            echo '<a href="' . $e($this->url->to('knowledge/admin/home')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a>';
            echo '<a href="' . $e($this->url->to('knowledge/admin/domains/add')) . '" class="sf-btn is-primary">'
               . '<i class="bi bi-plus-lg"></i>New domain</a>';
            echo '</div></div>';

            echo '<section class="sf-card">';
            echo $dataset->render();
            echo '</section></div>';
        });
    }

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
                $domain = $this->domains->create(
                    $old['slug'],
                    $old['name'],
                    (int) $old['sort_order'],
                    (bool) $old['active']
                );
                $this->notify->success("Domain '{$domain->name}' created.");
                redirect_to('knowledge/admin/domains');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old) {
            $this->view->assign('title', 'New domain');
            $this->view->block('right_sidebar', 'Knowledge');
            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>New domain</h1><p>Add a topic for knowledge entries.</p></div>';
            echo '<div class="sf-actions">'
               . '<a href="' . $this->view->e($this->url->to('knowledge/admin/domains')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a></div>';
            echo '</div>';
            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->domainForm($old, $this->url->to('knowledge/admin/domains/add'), 'Create');
            echo '</div></section></div>';
        });
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $domain = $this->domains->find($id);
        if ($domain === null) {
            throw new NotFoundException('Domain not found.');
        }

        $old = [
            'id'         => $domain->id,
            'slug'       => $domain->slug,
            'name'       => $domain->name,
            'sort_order' => $domain->sortOrder,
            'active'     => $domain->active ? 1 : 0,
        ];

        if ($this->request->isPost()) {
            $old = $this->readForm();
            try {
                $this->domains->update($id, $old);
                $this->notify->success('Domain updated.');
                redirect_to('knowledge/admin/domains');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old, $id) {
            $this->view->assign('title', 'Edit domain');
            $this->view->block('right_sidebar', 'Knowledge');
            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>Edit domain</h1><p>' . $this->view->e((string) $old['name']) . '</p></div>';
            echo '<div class="sf-actions">'
               . '<a href="' . $this->view->e($this->url->to('knowledge/admin/domains')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a></div>';
            echo '</div>';
            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->domainForm($old, $this->url->to('knowledge/admin/domains/edit', ['id' => $id]), 'Save');
            echo '</div></section></div>';
        });
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('knowledge/admin/domains');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('knowledge/admin/domains');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $domain = $this->domains->find($id);

        if ($domain !== null) {
            $this->domains->delete($id);
            $this->notify->success("Domain '{$domain->name}' deleted.");
        } else {
            $this->notify->error('Domain not found.');
        }

        redirect_to('knowledge/admin/domains');
    }

    private function readForm(): array
    {
        return [
            'slug'       => trim((string) $this->request->post('slug', 'string', '')),
            'name'       => trim((string) $this->request->post('name', 'string', '')),
            'sort_order' => (int) $this->request->post('sort_order', 'int', 0),
            'active'     => (int) $this->request->post('active', 'int', 0),
        ];
    }

    private function domainForm(array $old, string $action, string $submitLabel): string
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
                        id="domain_active" <?= !empty($old['active']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="domain_active">Available for new entries</label>
                </div>
            </div>
        </div>

        <div class="sf-actions mt-4">
            <button type="submit" class="sf-btn is-primary"><?= $this->view->e($submitLabel) ?></button>
            <a href="<?= $this->view->e($this->url->to('knowledge/admin/domains')) ?>" class="sf-btn">Cancel</a>
        </div>

        <?= $this->form->close() ?>
        <?php
        return (string) ob_get_clean();
    }
}
