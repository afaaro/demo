<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\BrandRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreFeederAdminBrands extends Controller
{
    private BrandRepository $brands;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->brands = new BrandRepository($this->db);
    }

    public function indexAction(): void
    {
        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->searchPlaceholder('Name, website, notes…')
            ->sortable(['name', 'website', 'product_count', 'active'])
            ->defaultSort('name', 'asc')
            ->filter('active', 'select', [
                ''  => 'All statuses',
                '1' => 'Active',
                '0' => 'Inactive',
            ])
            ->columns([
                'name'          => 'Brand',
                'website'       => 'Website',
                'product_count' => 'Products',
                'active'        => 'Status',
                'actions'       => '',
            ])
            ->query(function ($db, array $state): array {
                $activeFilter = (string) ($state['filters']['active'] ?? '');
                $activeOnly = match ($activeFilter) {
                    '1' => true,
                    '0' => false,
                    default => null,
                };

                $rows = $this->brands->all($state['q'], $activeOnly);
                $rows = $this->sortListRows($rows, $state['sort'], $state['dir'], [
                    'product_count' => 'int',
                    'active'        => 'bool',
                ]);
                $total = count($rows);
                $slice = array_slice($rows, $state['offset'], $state['per_page']);

                return [
                    'rows'  => array_map(static fn(array $row): array => ['_row' => $row], $slice),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $value): string => $this->view->e((string) $value);

        $dataset->columns([
            'name' => function (array $row) use ($e): string {
                $item = $row['_row'];
                $html = '<span class="fw-semibold' . (!$item['active'] ? ' text-muted' : '') . '">'
                    . $e($item['name']) . '</span>';
                if ($item['notes'] !== '') {
                    $html .= '<div class="small text-muted">'
                        . $e(mb_strimwidth((string) $item['notes'], 0, 80, '…')) . '</div>';
                }
                return $html;
            },
            'website' => function (array $row) use ($e): string {
                $item = $row['_row'];
                if ($item['website'] === '') {
                    return '<span class="text-muted">—</span>';
                }
                $href = $this->externalUrl((string) $item['website']);
                return '<a class="small" href="' . $e($href) . '" target="_blank" rel="noopener">'
                    . $e($item['website']) . '</a>';
            },
            'product_count' => function (array $row) use ($e): string {
                $item = $row['_row'];
                $count = (int) $item['product_count'];
                if ($count <= 0) {
                    return '<span class="text-end d-block">0</span>';
                }
                $url = $this->url->to('storefeeder/admin/products', ['f_brand' => $item['name']]);
                return '<span class="text-end d-block"><a href="' . $e($url) . '">' . $count . '</a></span>';
            },
            'active' => function (array $row): string {
                $item = $row['_row'];
                return $item['active']
                    ? '<span class="badge text-bg-success">Active</span>'
                    : '<span class="badge text-bg-secondary">Inactive</span>';
            },
            'actions' => function (array $row) use ($e): string {
                $item = $row['_row'];
                $id = (int) $item['id'];
                $html = '<div class="text-end text-nowrap">';
                $html .= '<a class="btn btn-sm btn-outline-secondary" href="'
                    . $e($this->url->to('storefeeder/admin/brands/edit', ['id' => $id]))
                    . '">Edit</a> ';
                $html .= '<form method="post" class="d-inline" action="'
                    . $e($this->url->to('storefeeder/admin/brands/delete', ['id' => $id])) . '"'
                    . ' onsubmit="return confirm(\'Delete this brand? Products using it will have brand cleared.\');">';
                $html .= $this->request->csrfField();
                $html .= '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
                return $html . '</form></div>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e) {
            $this->view->assign('title', 'Brands');
            $this->view->block('right_sidebar', 'Brands');

            echo '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-award me-1"></i>Brands</h1>'
               . '<p class="text-muted small mb-0">Catalog brands used on products.</p></div>';
            echo '</div>';

            echo '<div class="row g-4">';
            echo '<div class="col-lg-4">';
            echo '<div class="border rounded p-3 bg-body-tertiary">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Add brand</h2>';
            echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/brands/add')) . '">';
            echo $this->request->csrfField();
            echo $this->brandFields([
                'name' => '', 'website' => '', 'logo_url' => '', 'notes' => '', 'active' => true,
            ]);
            echo '<button type="submit" class="btn btn-primary btn-sm mt-2">'
               . '<i class="bi bi-plus-lg me-1"></i>Add brand</button>';
            echo '</form></div></div>';

            echo '<div class="col-lg-8">';
            echo $dataset->render();
            echo '</div></div>';
        });
    }

    public function addAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/brands');
            return;
        }
        $this->guardCsrf();

        $data = $this->readForm();
        try {
            $created = $this->brands->create($data['name'], $data);
            $this->notify->success("Brand '{$created}' saved.");
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('storefeeder/admin/brands');
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $brand = $this->brands->find($id);
        if ($brand === null) {
            throw new NotFoundException('Brand not found.');
        }

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $data = $this->readForm();
            try {
                $name = $this->brands->update($id, $data);
                $this->notify->success("Brand '{$name}' updated.");
                redirect_to('storefeeder/admin/brands');
                return;
            } catch (\Throwable $ex) {
                $this->notify->error($ex->getMessage());
                $brand = array_merge($brand, $data);
            }
        }

        echo $this->view->inline(function () use ($brand, $id) {
            $this->view->assign('title', 'Edit brand');
            $this->view->block('right_sidebar', 'Brands');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="mb-3"><a class="small text-decoration-none" href="'
               . $e($this->url->to('storefeeder/admin/brands')) . '">&larr; Brands</a></div>';
            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<h1 class="h3 mb-0">Edit brand</h1>';
            if ((int) $brand['product_count'] > 0) {
                echo '<a class="btn btn-sm btn-outline-primary" href="'
                   . $e($this->url->to('storefeeder/admin/products', ['f_brand' => $brand['name']]))
                   . '">' . (int) $brand['product_count'] . ' product(s)</a>';
            }
            echo '</div>';

            echo '<form method="post" action="'
               . $e($this->url->to('storefeeder/admin/brands/edit', ['id' => $id]))
               . '" class="border rounded p-3" style="max-width:36rem">';
            echo $this->request->csrfField();
            echo $this->brandFields($brand);
            echo '<div class="form-text mb-3">Renaming updates products that currently use this brand.</div>';
            echo '<button type="submit" class="btn btn-primary">Save</button> ';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('storefeeder/admin/brands')) . '">Cancel</a>';
            echo '</form>';
        });
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/brands');
            return;
        }
        $this->guardCsrf();

        $id = (int) $this->request->get('id', 'int', 0);
        $brand = $this->brands->find($id);
        if ($brand === null) {
            $this->notify->error('Brand not found.');
            redirect_to('storefeeder/admin/brands');
            return;
        }

        $this->brands->delete($id);
        $this->notify->success("Brand '{$brand['name']}' deleted.");
        redirect_to('storefeeder/admin/brands');
    }

    /** @param array<string, mixed> $old */
    private function brandFields(array $old): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $html = '<div class="mb-2">'
            . '<label class="form-label small" for="brand_name">Name</label>'
            . '<input type="text" class="form-control form-control-sm" name="name" id="brand_name" required maxlength="128"'
            . ' value="' . $e((string) ($old['name'] ?? '')) . '" placeholder="e.g. Dazey">'
            . '</div>';
        $html .= '<div class="mb-2">'
            . '<label class="form-label small" for="brand_website">Website</label>'
            . '<input type="text" class="form-control form-control-sm" name="website" id="brand_website" maxlength="255"'
            . ' value="' . $e((string) ($old['website'] ?? '')) . '" placeholder="https://…">'
            . '</div>';
        $html .= '<div class="mb-2">'
            . '<label class="form-label small" for="brand_logo">Logo URL</label>'
            . '<input type="text" class="form-control form-control-sm" name="logo_url" id="brand_logo" maxlength="500"'
            . ' value="' . $e((string) ($old['logo_url'] ?? '')) . '" placeholder="/uploads/media/…">'
            . '</div>';
        $html .= '<div class="mb-2">'
            . '<label class="form-label small" for="brand_notes">Notes</label>'
            . '<textarea class="form-control form-control-sm" name="notes" id="brand_notes" rows="2" maxlength="500">'
            . $e((string) ($old['notes'] ?? '')) . '</textarea></div>';
        $checked = !empty($old['active']) ? ' checked' : '';
        $html .= '<div class="form-check mb-2">'
            . '<input type="checkbox" class="form-check-input" name="active" value="1" id="brand_active"' . $checked . '>'
            . '<label class="form-check-label small" for="brand_active">Active</label></div>';
        return $html;
    }

    /** @return array<string, mixed> */
    private function readForm(): array
    {
        return [
            'name'     => trim((string) $this->request->post('name', 'string', '')),
            'website'  => trim((string) $this->request->post('website', 'string', '')),
            'logo_url' => trim((string) $this->request->post('logo_url', 'string', '')),
            'notes'    => trim((string) $this->request->post('notes', 'string', '')),
            'active'   => (int) $this->request->post('active', 'int', 0) === 1,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $types
     * @return array<int, array<string, mixed>>
     */
    private function sortListRows(array $rows, string $sort, string $dir, array $types = []): array
    {
        $mult = strtolower($dir) === 'desc' ? -1 : 1;
        usort($rows, function (array $a, array $b) use ($sort, $mult, $types): int {
            $va = $a[$sort] ?? '';
            $vb = $b[$sort] ?? '';
            $kind = $types[$sort] ?? 'string';
            if ($kind === 'int') {
                return $mult * ((int) $va <=> (int) $vb);
            }
            if ($kind === 'bool') {
                return $mult * ((int) (bool) $va <=> (int) (bool) $vb);
            }
            return $mult * strcasecmp((string) $va, (string) $vb);
        });
        return $rows;
    }

    private function externalUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '#';
        }
        if (!preg_match('#^https?://#i', $url)) {
            return 'https://' . ltrim($url, '/');
        }
        return $url;
    }

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('storefeeder/admin/brands');
            exit;
        }
    }
}
