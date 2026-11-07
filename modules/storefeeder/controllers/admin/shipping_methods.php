<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\ShippingMethodRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreFeederAdminShippingMethods extends Controller
{
    private ShippingMethodRepository $methods;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->methods = new ShippingMethodRepository($this->db);
    }

    public function indexAction(): void
    {
        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->sortable(['company', 'method', 'cost'])
            ->defaultSort('company', 'asc')
            ->columns([
                'company'  => 'Shipping Company',
                'method'   => 'Shipping Method',
                'cost'     => 'Cost',
                'default'  => 'Default',
                'actions'  => '',
            ])
            ->query(function ($db, array $state): array {
                $rows = $this->methods->all();
                $rows = $this->sortListRows($rows, $state['sort'], $state['dir'], ['cost' => 'float']);
                $total = count($rows);
                $slice = array_slice($rows, $state['offset'], $state['per_page']);

                return [
                    'rows'  => array_map(static fn(array $row): array => ['_row' => $row], $slice),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $dataset->columns([
            'company' => function (array $row) use ($e): string {
                return $e((string) $row['_row']['company']);
            },
            'method' => function (array $row) use ($e): string {
                return '<span class="fw-semibold">' . $e((string) $row['_row']['method']) . '</span>';
            },
            'cost'    => fn(array $row): string =>
                '<span class="text-end d-block">£ '
                . number_format((float) $row['_row']['cost'], 2) . '</span>',
            'default' => function (array $row) use ($e): string {
                $item = $row['_row'];
                $id = (int) $item['id'];
                if (!empty($item['is_default'])) {
                    return '<span class="text-muted">Is Default</span>';
                }
                $html = '<form method="post" class="d-inline" action="'
                    . $e($this->url->to('storefeeder/admin/shipping-methods/default', ['id' => $id])) . '">';
                $html .= $this->request->csrfField();
                $html .= '<button type="submit" class="btn btn-link btn-sm p-0">Make Default</button>';
                return $html . '</form>';
            },
            'actions' => function (array $row) use ($e): string {
                return '<span class="text-end d-block"><a href="'
                    . $e($this->url->to('storefeeder/admin/shipping-methods/edit', ['id' => (int) $row['_row']['id']]))
                    . '">Edit/View Shipping Method</a></span>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e) {
            $this->view->assign('title', 'Shipping Methods');
            $this->view->block('right_sidebar', 'Shipping');

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">Shipping Methods</h1>';
            echo '<a class="btn btn-success" href="'
               . $e($this->url->to('storefeeder/admin/shipping-methods/new')) . '">'
               . '<i class="bi bi-plus-lg me-1"></i>New Shipping Method</a>';
            echo '</div>';

            echo $dataset->render();
        });
    }

    public function newAction(): void
    {
        $this->renderForm(null);
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $row = $this->methods->find($id);
        if ($row === null) {
            throw new NotFoundException('Shipping method not found.');
        }
        $this->renderForm($row);
    }

    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/shipping-methods');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/shipping-methods');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $data = [
            'company'      => (string) $this->request->post('company', 'string', ''),
            'method'       => (string) $this->request->post('method', 'string', ''),
            'cost'         => (float) $this->request->post('cost', 'float', 0),
            'carrier_code' => (string) $this->request->post('carrier_code', 'string', ''),
            'enabled'      => (string) $this->request->post('enabled', 'string', '') === '1',
        ];

        try {
            if ($id > 0) {
                $this->methods->update($id, $data);
                $this->notify->success('Shipping method updated.');
            } else {
                $id = $this->methods->create(
                    $data['company'],
                    $data['method'],
                    $data['cost'],
                    $data['carrier_code'],
                );
                $this->notify->success('Shipping method created.');
            }
            redirect_to('storefeeder/admin/shipping-methods/edit', ['id' => $id]);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
            redirect_to($id > 0
                ? 'storefeeder/admin/shipping-methods/edit'
                : 'storefeeder/admin/shipping-methods/new', $id > 0 ? ['id' => $id] : []);
        }
    }

    public function defaultAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/shipping-methods');
            return;
        }
        try {
            $this->request->checkToken();
            $id = (int) $this->request->get('id', 'int', 0);
            $this->methods->setDefault($id);
            $this->notify->success('Default shipping method updated.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('storefeeder/admin/shipping-methods');
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/shipping-methods');
            return;
        }
        try {
            $this->request->checkToken();
            $this->methods->delete((int) $this->request->get('id', 'int', 0));
            $this->notify->success('Shipping method deleted.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('storefeeder/admin/shipping-methods');
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
            if ($kind === 'float') {
                return $mult * ((float) $va <=> (float) $vb);
            }
            if ($kind === 'int') {
                return $mult * ((int) $va <=> (int) $vb);
            }
            return $mult * strcasecmp((string) $va, (string) $vb);
        });
        return $rows;
    }

    /** @param array<string, mixed>|null $row */
    private function renderForm(?array $row): void
    {
        echo $this->view->inline(function () use ($row) {
            $this->view->assign('title', $row ? 'Edit Shipping Method' : 'New Shipping Method');
            $this->view->block('right_sidebar', 'Shipping');
            $e = fn(string $s): string => $this->view->e($s);
            $id = (int) ($row['id'] ?? 0);

            echo '<div class="mb-3"><a class="text-decoration-none small" href="'
               . $e($this->url->to('storefeeder/admin/shipping-methods')) . '">'
               . '&larr; Shipping Methods</a></div>';
            echo '<h1 class="h3 mb-4">' . ($row ? 'Edit/View Shipping Method' : 'New Shipping Method') . '</h1>';

            echo '<form method="post" action="'
               . $e($this->url->to('storefeeder/admin/shipping-methods/save')) . '" class="card border-0 shadow-sm">';
            echo '<div class="card-body">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<div class="row g-3">';
            echo '<div class="col-md-6"><label class="form-label">Shipping Company</label>'
               . '<input type="text" name="company" class="form-control" maxlength="128" value="'
               . $e((string) ($row['company'] ?? '')) . '"></div>';
            echo '<div class="col-md-6"><label class="form-label">Shipping Method</label>'
               . '<input type="text" name="method" class="form-control" required maxlength="128" value="'
               . $e((string) ($row['method'] ?? '')) . '"></div>';
            echo '<div class="col-md-4"><label class="form-label">Cost (£)</label>'
               . '<input type="number" step="0.01" min="0" name="cost" class="form-control" value="'
               . $e(number_format((float) ($row['cost'] ?? 0), 2, '.', '')) . '"></div>';
            echo '<div class="col-md-4"><label class="form-label">Carrier code</label>'
               . '<input type="text" name="carrier_code" class="form-control" maxlength="64" placeholder="HERMES / DPD / ROYAL_MAIL" value="'
               . $e((string) ($row['carrier_code'] ?? '')) . '">'
               . '<div class="form-text">Used when mapping to marketplace ship notifications.</div></div>';
            echo '<div class="col-md-4 d-flex align-items-end"><div class="form-check">'
               . '<input class="form-check-input" type="checkbox" name="enabled" value="1" id="sm_enabled"'
               . ((!isset($row['enabled']) || !empty($row['enabled'])) ? ' checked' : '') . '>'
               . '<label class="form-check-label" for="sm_enabled">Enabled</label></div></div>';
            echo '</div>';
            echo '<div class="mt-4 d-flex gap-2">';
            echo '<button type="submit" class="btn btn-primary">Save</button>';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('storefeeder/admin/shipping-methods')) . '">Cancel</a>';
            if ($id > 0) {
                echo '<button type="submit" formaction="'
                   . $e($this->url->to('storefeeder/admin/shipping-methods/delete', ['id' => $id]))
                   . '" class="btn btn-outline-danger ms-auto"'
                   . ' onclick="return confirm(\'Delete this shipping method?\');">Delete</button>';
            }
            echo '</div></div></form>';
        });
    }
}
