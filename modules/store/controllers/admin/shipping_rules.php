<?php
declare(strict_types=1);

use Module\Store\Services\ShippingMethodRepository;
use Module\Store\Services\ShippingRegionRepository;
use Module\Store\Services\ShippingRuleRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreAdminShippingRules extends Controller
{
    private ShippingRuleRepository $rules;
    private ShippingMethodRepository $methods;
    private ShippingRegionRepository $regions;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->rules = new ShippingRuleRepository($this->db);
        $this->methods = new ShippingMethodRepository($this->db);
        $this->regions = new ShippingRegionRepository($this->db);
    }

    public function indexAction(): void
    {
        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->sortable(['priority', 'name', 'region_name', 'enabled'])
            ->defaultSort('priority', 'asc')
            ->columns([
                'priority'    => 'Priority',
                'name'        => 'Name',
                'region_name' => 'Region',
                'method'      => 'Method',
                'weight'      => 'Weight (kg)',
                'order_total' => 'Order total',
                'enabled'     => 'Enabled',
                'actions'     => '',
            ])
            ->query(function ($db, array $state): array {
                $rows = $this->rules->all();
                $sort = $state['sort'];
                $mult = strtolower($state['dir']) === 'desc' ? -1 : 1;
                usort($rows, function (array $a, array $b) use ($sort, $mult): int {
                    if ($sort === 'priority') {
                        return $mult * ((int) ($a['priority'] ?? 0) <=> (int) ($b['priority'] ?? 0));
                    }
                    if ($sort === 'enabled') {
                        return $mult * ((int) !empty($a['enabled']) <=> (int) !empty($b['enabled']));
                    }
                    return $mult * strcasecmp((string) ($a[$sort] ?? ''), (string) ($b[$sort] ?? ''));
                });
                $total = count($rows);
                $slice = array_slice($rows, $state['offset'], $state['per_page']);

                return [
                    'rows'  => array_map(static fn(array $row): array => ['_row' => $row], $slice),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $dataset->columns([
            'priority' => fn(array $row): string => (string) (int) $row['_row']['priority'],
            'name' => function (array $row) use ($e): string {
                return $e((string) ($row['_row']['name'] ?: '—'));
            },
            'region_name' => function (array $row) use ($e): string {
                return $e((string) ($row['_row']['region_name'] ?: 'Any'));
            },
            'method' => function (array $row) use ($e): string {
                $item = $row['_row'];
                $label = trim((string) ($item['method_company'] ?? ''));
                $label = ($label !== '' ? $label . ' — ' : '') . (string) ($item['method_name'] ?? '');
                return $e($label);
            },
            'weight' => function (array $row) use ($e): string {
                return $e($this->rangeLabel(
                    $row['_row']['min_weight_kg'] ?? null,
                    $row['_row']['max_weight_kg'] ?? null
                ));
            },
            'order_total' => function (array $row) use ($e): string {
                return $e($this->rangeLabel(
                    $row['_row']['min_order_total'] ?? null,
                    $row['_row']['max_order_total'] ?? null,
                    '£'
                ));
            },
            'enabled' => fn(array $row): string => !empty($row['_row']['enabled']) ? 'Yes' : 'No',
            'actions' => function (array $row) use ($e): string {
                return '<span class="text-end d-block"><a href="'
                    . $e($this->url->to('store/admin/shipping-rules/edit', ['id' => (int) $row['_row']['id']]))
                    . '">Edit</a></span>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset) {
            $this->view->assign('title', 'Shipping Rules');
            $this->view->block('right_sidebar', 'Shipping');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">Shipping Rules</h1>';
            echo '<div class="d-flex gap-2">';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/shipping-rules/tester')) . '">Rules Tester</a>';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/shipping-rules/import')) . '">Import</a>';
            echo '<a class="btn btn-success" href="'
               . $e($this->url->to('store/admin/shipping-rules/new')) . '">'
               . '<i class="bi bi-plus-lg me-1"></i>New Shipping Rule</a>';
            echo '</div></div>';

            echo $dataset->render();
        });
    }

    public function newAction(): void
    {
        $this->renderForm(null);
    }

    public function editAction(): void
    {
        $row = $this->rules->find((int) $this->request->get('id', 'int', 0));
        if ($row === null) {
            throw new NotFoundException('Shipping rule not found.');
        }
        $this->renderForm($row);
    }

    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/shipping-rules');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('store/admin/shipping-rules');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $data = [
            'name'            => (string) $this->request->post('name', 'string', ''),
            'region_id'       => (int) $this->request->post('region_id', 'int', 0),
            'method_id'       => (int) $this->request->post('method_id', 'int', 0),
            'min_weight_kg'   => (string) $this->request->post('min_weight_kg', 'string', ''),
            'max_weight_kg'   => (string) $this->request->post('max_weight_kg', 'string', ''),
            'min_order_total' => (string) $this->request->post('min_order_total', 'string', ''),
            'max_order_total' => (string) $this->request->post('max_order_total', 'string', ''),
            'priority'        => (int) $this->request->post('priority', 'int', 100),
            'enabled'         => (string) $this->request->post('enabled', 'string', '') === '1',
        ];

        try {
            if ($id > 0) {
                $this->rules->update($id, $data);
                $this->notify->success('Shipping rule updated.');
            } else {
                $id = $this->rules->create($data);
                $this->notify->success('Shipping rule created.');
            }
            redirect_to('store/admin/shipping-rules/edit', ['id' => $id]);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
            redirect_to('store/admin/shipping-rules');
        }
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/shipping-rules');
            return;
        }
        try {
            $this->request->checkToken();
            $this->rules->delete((int) $this->request->get('id', 'int', 0));
            $this->notify->success('Shipping rule deleted.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/shipping-rules');
    }

    public function testerAction(): void
    {
        $result = null;
        $country = 'GB';
        $postcode = '';
        $weight = 1.0;
        $total = 0.0;

        if ($this->request->isPost()) {
            try {
                $this->request->checkToken();
            } catch (\RuntimeException) {
                $this->notify->error('Session expired.');
                redirect_to('store/admin/shipping-rules/tester');
                return;
            }
            $country = strtoupper(trim((string) $this->request->post('country', 'string', 'GB')));
            $postcode = trim((string) $this->request->post('postcode', 'string', ''));
            $weight = (float) $this->request->post('weight_kg', 'float', 1);
            $total = (float) $this->request->post('order_total', 'float', 0);
            $result = $this->rules->match($this->regions, $country, $postcode, $weight, $total);
        }

        echo $this->view->inline(function () use ($result, $country, $postcode, $weight, $total) {
            $this->view->assign('title', 'Shipping Rules Tester');
            $this->view->block('right_sidebar', 'Shipping');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="mb-3"><a class="text-decoration-none small" href="'
               . $e($this->url->to('store/admin/shipping-rules')) . '">&larr; Shipping Rules</a></div>';
            echo '<h1 class="h3 mb-4">Shipping Rules Tester</h1>';

            echo '<form method="post" class="card border-0 shadow-sm mb-4"><div class="card-body">';
            echo $this->request->csrfField();
            echo '<div class="row g-3">';
            echo '<div class="col-md-3"><label class="form-label">Country (ISO2)</label>'
               . '<input name="country" class="form-control" value="' . $e($country) . '" required></div>';
            echo '<div class="col-md-3"><label class="form-label">Postcode</label>'
               . '<input name="postcode" class="form-control" value="' . $e($postcode) . '"></div>';
            echo '<div class="col-md-3"><label class="form-label">Weight (kg)</label>'
               . '<input type="number" step="0.001" name="weight_kg" class="form-control" value="'
               . $e((string) $weight) . '"></div>';
            echo '<div class="col-md-3"><label class="form-label">Order total (£)</label>'
               . '<input type="number" step="0.01" name="order_total" class="form-control" value="'
               . $e((string) $total) . '"></div>';
            echo '</div>';
            echo '<button type="submit" class="btn btn-primary mt-3">Test rules</button>';
            echo '</div></form>';

            if ($this->request->isPost()) {
                if ($result === null) {
                    echo '<div class="alert alert-warning">No matching shipping rule.</div>';
                } else {
                    $method = trim((string) ($result['method_company'] ?? ''));
                    $method = ($method !== '' ? $method . ' — ' : '') . (string) ($result['method_name'] ?? '');
                    echo '<div class="alert alert-success">'
                       . '<strong>Match:</strong> ' . $e((string) ($result['name'] ?: 'Rule #' . $result['id']))
                       . '<br>Region: ' . $e((string) ($result['region_name'] ?: 'Any'))
                       . '<br>Method: ' . $e($method)
                       . '<br>Priority: ' . (int) $result['priority']
                       . '</div>';
                }
            }
        });
    }

    public function importAction(): void
    {
        if ($this->request->isPost()) {
            try {
                $this->request->checkToken();
            } catch (\RuntimeException) {
                $this->notify->error('Session expired.');
                redirect_to('store/admin/shipping-rules/import');
                return;
            }

            $file = $_FILES['csv'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $this->notify->error('Please upload a CSV file.');
                redirect_to('store/admin/shipping-rules/import');
                return;
            }

            $created = 0;
            $errors = 0;
            $fh = fopen((string) $file['tmp_name'], 'rb');
            if ($fh === false) {
                $this->notify->error('Unable to read upload.');
                redirect_to('store/admin/shipping-rules/import');
                return;
            }

            $header = fgetcsv($fh);
            $methodsByKey = [];
            foreach ($this->methods->all() as $m) {
                $methodsByKey[strtolower(trim((string) $m['company']) . '|' . trim((string) $m['method']))] = (int) $m['id'];
                $methodsByKey[strtolower(trim((string) $m['method']))] = (int) $m['id'];
            }
            $regionsByName = [];
            foreach ($this->regions->all() as $r) {
                $regionsByName[strtolower(trim((string) $r['name']))] = (int) $r['id'];
            }

            while (($cols = fgetcsv($fh)) !== false) {
                if ($cols === [null] || $cols === []) {
                    continue;
                }
                // name,region,method,min_weight,max_weight,min_total,max_total,priority
                $name = trim((string) ($cols[0] ?? ''));
                $regionName = strtolower(trim((string) ($cols[1] ?? '')));
                $methodKey = strtolower(trim((string) ($cols[2] ?? '')));
                $methodId = $methodsByKey[$methodKey] ?? 0;
                if ($methodId <= 0) {
                    $errors++;
                    continue;
                }
                try {
                    $this->rules->create([
                        'name'            => $name,
                        'region_id'       => $regionsByName[$regionName] ?? 0,
                        'method_id'       => $methodId,
                        'min_weight_kg'   => $cols[3] ?? '',
                        'max_weight_kg'   => $cols[4] ?? '',
                        'min_order_total' => $cols[5] ?? '',
                        'max_order_total' => $cols[6] ?? '',
                        'priority'        => (int) ($cols[7] ?? 100),
                        'enabled'         => true,
                    ]);
                    $created++;
                } catch (\Throwable) {
                    $errors++;
                }
            }
            fclose($fh);
            unset($header);

            $this->notify->success("Imported {$created} rule(s)" . ($errors ? ", {$errors} skipped" : '') . '.');
            redirect_to('store/admin/shipping-rules');
            return;
        }

        echo $this->view->inline(function () {
            $this->view->assign('title', 'Shipping Rules Import');
            $this->view->block('right_sidebar', 'Shipping');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="mb-3"><a class="text-decoration-none small" href="'
               . $e($this->url->to('store/admin/shipping-rules')) . '">&larr; Shipping Rules</a></div>';
            echo '<h1 class="h3 mb-4">Shipping Rules Import</h1>';
            echo '<div class="card border-0 shadow-sm"><div class="card-body">';
            echo '<p class="text-muted">CSV columns: '
               . '<code>name,region,method,min_weight,max_weight,min_total,max_total,priority</code>. '
               . '<code>method</code> can be method name or <code>Company|Method</code>.</p>';
            echo '<form method="post" enctype="multipart/form-data">';
            echo $this->request->csrfField();
            echo '<input type="file" name="csv" accept=".csv,text/csv" class="form-control mb-3" required>';
            echo '<button type="submit" class="btn btn-primary">Import</button>';
            echo '</form></div></div>';
        });
    }

    /** @param array<string, mixed>|null $row */
    private function renderForm(?array $row): void
    {
        $methods = $this->methods->all();
        $regions = $this->regions->all();

        echo $this->view->inline(function () use ($row, $methods, $regions) {
            $this->view->assign('title', $row ? 'Edit Shipping Rule' : 'New Shipping Rule');
            $this->view->block('right_sidebar', 'Shipping');
            $e = fn(string $s): string => $this->view->e($s);
            $id = (int) ($row['id'] ?? 0);

            echo '<div class="mb-3"><a class="text-decoration-none small" href="'
               . $e($this->url->to('store/admin/shipping-rules')) . '">&larr; Shipping Rules</a></div>';
            echo '<h1 class="h3 mb-4">' . ($row ? 'Edit Shipping Rule' : 'New Shipping Rule') . '</h1>';

            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/shipping-rules/save'))
               . '" class="card border-0 shadow-sm"><div class="card-body">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<div class="row g-3">';
            echo '<div class="col-md-6"><label class="form-label">Name</label>'
               . '<input name="name" class="form-control" value="'
               . $e((string) ($row['name'] ?? '')) . '"></div>';
            echo '<div class="col-md-3"><label class="form-label">Priority</label>'
               . '<input type="number" name="priority" class="form-control" value="'
               . (int) ($row['priority'] ?? 100) . '">'
               . '<div class="form-text">Lower runs first.</div></div>';
            echo '<div class="col-md-3 d-flex align-items-end"><div class="form-check">'
               . '<input class="form-check-input" type="checkbox" name="enabled" value="1" id="sr_en"'
               . ((!isset($row['enabled']) || !empty($row['enabled'])) ? ' checked' : '') . '>'
               . '<label class="form-check-label" for="sr_en">Enabled</label></div></div>';

            echo '<div class="col-md-6"><label class="form-label">Region</label><select name="region_id" class="form-select">';
            echo '<option value="0">Any region</option>';
            foreach ($regions as $r) {
                $sel = ((int) ($row['region_id'] ?? 0) === (int) $r['id']) ? ' selected' : '';
                echo '<option value="' . (int) $r['id'] . '"' . $sel . '>'
                   . $e((string) $r['name']) . '</option>';
            }
            echo '</select></div>';

            echo '<div class="col-md-6"><label class="form-label">Shipping method</label><select name="method_id" class="form-select" required>';
            foreach ($methods as $m) {
                $label = trim((string) $m['company']);
                $label = ($label !== '' ? $label . ' — ' : '') . (string) $m['method'];
                $sel = ((int) ($row['method_id'] ?? 0) === (int) $m['id']) ? ' selected' : '';
                echo '<option value="' . (int) $m['id'] . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></div>';

            foreach ([
                ['min_weight_kg', 'Min weight (kg)'],
                ['max_weight_kg', 'Max weight (kg)'],
                ['min_order_total', 'Min order total (£)'],
                ['max_order_total', 'Max order total (£)'],
            ] as [$field, $label]) {
                $val = $row[$field] ?? '';
                echo '<div class="col-md-3"><label class="form-label">' . $e($label) . '</label>'
                   . '<input type="number" step="0.001" name="' . $e($field) . '" class="form-control" value="'
                   . $e($val === null ? '' : (string) $val) . '"></div>';
            }
            echo '</div>';
            echo '<div class="mt-4 d-flex gap-2">';
            echo '<button type="submit" class="btn btn-primary">Save</button>';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/shipping-rules')) . '">Cancel</a>';
            if ($id > 0) {
                echo '<button type="submit" formaction="'
                   . $e($this->url->to('store/admin/shipping-rules/delete', ['id' => $id]))
                   . '" class="btn btn-outline-danger ms-auto"'
                   . ' onclick="return confirm(\'Delete this rule?\');">Delete</button>';
            }
            echo '</div></div></form>';
        });
    }

    private function rangeLabel(mixed $min, mixed $max, string $prefix = ''): string
    {
        $minS = ($min === null || $min === '') ? '' : $prefix . $min;
        $maxS = ($max === null || $max === '') ? '' : $prefix . $max;
        if ($minS === '' && $maxS === '') {
            return '—';
        }
        if ($minS !== '' && $maxS !== '') {
            return $minS . ' – ' . $maxS;
        }
        return $minS !== '' ? '≥ ' . $minS : '≤ ' . $maxS;
    }
}
