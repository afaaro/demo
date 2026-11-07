<?php
declare(strict_types=1);

use Module\Store\Services\SupplierRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreAdminSuppliers extends Controller
{
    private SupplierRepository $suppliers;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->suppliers = new SupplierRepository($this->db);
    }

    public function indexAction(): void
    {
        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->searchPlaceholder('Name, contact, email…')
            ->sortable(['name', 'lead_time_days', 'product_count', 'po_count', 'active'])
            ->defaultSort('name', 'asc')
            ->filter('active', 'select', [
                ''  => 'All statuses',
                '1' => 'Active',
                '0' => 'Inactive',
            ])
            ->columns([
                'name'           => 'Supplier',
                'contact'        => 'Contact',
                'lead_time_days' => 'Lead days',
                'product_count'  => 'Products',
                'po_count'       => 'POs',
                'active'         => 'Status',
                'actions'        => '',
            ])
            ->query(function ($db, array $state): array {
                $activeFilter = (string) ($state['filters']['active'] ?? '');
                $activeOnly = match ($activeFilter) {
                    '1' => true,
                    '0' => false,
                    default => null,
                };

                $rows = $this->suppliers->all($state['q'], $activeOnly);
                $rows = $this->sortListRows($rows, $state['sort'], $state['dir'], [
                    'lead_time_days' => 'int',
                    'product_count'  => 'int',
                    'po_count'       => 'int',
                    'active'         => 'bool',
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
                if ($item['company'] !== '' && $item['company'] !== $item['name']) {
                    $html .= '<div class="small text-muted">' . $e($item['company']) . '</div>';
                }
                return $html;
            },
            'contact' => function (array $row) use ($e): string {
                $item = $row['_row'];
                $bits = array_filter([$item['contact_name'], $item['email'], $item['phone']]);
                return $bits !== []
                    ? '<span class="small">' . $e(implode(' · ', $bits)) . '</span>'
                    : '<span class="text-muted">—</span>';
            },
            'lead_time_days' => function (array $row): string {
                $days = (int) $row['_row']['lead_time_days'];
                return '<span class="text-end d-block">' . ($days > 0 ? (string) $days : '—') . '</span>';
            },
            'product_count' => function (array $row) use ($e): string {
                $item = $row['_row'];
                $count = (int) $item['product_count'];
                if ($count <= 0) {
                    return '<span class="text-end d-block">0</span>';
                }
                $url = $this->url->to('store/admin/products', ['f_supplier' => $item['name']]);
                return '<span class="text-end d-block"><a href="' . $e($url) . '">' . $count . '</a></span>';
            },
            'po_count' => function (array $row) use ($e): string {
                $item = $row['_row'];
                $count = (int) $item['po_count'];
                if ($count <= 0) {
                    return '<span class="text-end d-block">0</span>';
                }
                $url = $this->url->to('store/admin/purchase-orders', ['supplier' => $item['name']]);
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
                    . $e($this->url->to('store/admin/suppliers/edit', ['id' => $id]))
                    . '">Edit</a> ';
                $html .= '<form method="post" class="d-inline" action="'
                    . $e($this->url->to('store/admin/suppliers/delete', ['id' => $id])) . '"'
                    . ' onsubmit="return confirm(\'Delete this supplier? Products using it will have supplier cleared.\');">';
                $html .= $this->request->csrfField();
                $html .= '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
                return $html . '</form></div>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e) {
            $this->view->assign('title', 'Suppliers');
            $this->view->block('right_sidebar', 'Suppliers');

            echo '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-truck me-1"></i>Suppliers</h1>'
               . '<p class="text-muted small mb-0">Contacts, lead times, and product / PO links.</p></div>';
            echo '</div>';

            echo '<div class="row g-4">';
            echo '<div class="col-lg-4">';
            echo '<div class="border rounded p-3 bg-body-tertiary">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Add supplier</h2>';
            echo '<form method="post" action="' . $e($this->url->to('store/admin/suppliers/add')) . '">';
            echo $this->request->csrfField();
            echo $this->supplierFields($this->emptyForm());
            echo '<button type="submit" class="btn btn-primary btn-sm mt-2">'
               . '<i class="bi bi-plus-lg me-1"></i>Add supplier</button>';
            echo '</form></div></div>';

            echo '<div class="col-lg-8">';
            echo $dataset->render();
            echo '</div></div>';
        });
    }

    public function addAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/suppliers');
            return;
        }
        $this->guardCsrf();

        $data = $this->readForm();
        try {
            $created = $this->suppliers->create($data['name'], $data);
            $this->notify->success("Supplier '{$created}' saved.");
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/suppliers');
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $supplier = $this->suppliers->find($id);
        if ($supplier === null) {
            throw new NotFoundException('Supplier not found.');
        }

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $data = $this->readForm();
            try {
                $name = $this->suppliers->update($id, $data);
                $this->notify->success("Supplier '{$name}' updated.");
                redirect_to('store/admin/suppliers');
                return;
            } catch (\Throwable $ex) {
                $this->notify->error($ex->getMessage());
                $supplier = array_merge($supplier, $data);
            }
        }

        echo $this->view->inline(function () use ($supplier, $id) {
            $this->view->assign('title', 'Edit supplier');
            $this->view->block('right_sidebar', 'Suppliers');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="mb-3"><a class="small text-decoration-none" href="'
               . $e($this->url->to('store/admin/suppliers')) . '">&larr; Suppliers</a></div>';
            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<h1 class="h3 mb-0">Edit supplier</h1>';
            echo '<div class="d-flex gap-2">';
            if ((int) $supplier['product_count'] > 0) {
                echo '<a class="btn btn-sm btn-outline-primary" href="'
                   . $e($this->url->to('store/admin/products', ['f_supplier' => $supplier['name']]))
                   . '">' . (int) $supplier['product_count'] . ' product(s)</a>';
            }
            if ((int) $supplier['po_count'] > 0) {
                echo '<a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($this->url->to('store/admin/purchase-orders', ['supplier' => $supplier['name']]))
                   . '">' . (int) $supplier['po_count'] . ' PO(s)</a>';
            }
            echo '</div></div>';

            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/suppliers/edit', ['id' => $id]))
               . '" class="border rounded p-3" style="max-width:40rem">';
            echo $this->request->csrfField();
            echo $this->supplierFields($supplier);
            echo '<div class="form-text mb-3">Renaming updates products and purchase orders using this supplier.</div>';
            echo '<button type="submit" class="btn btn-primary">Save</button> ';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/suppliers')) . '">Cancel</a>';
            echo '</form>';
        });
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/suppliers');
            return;
        }
        $this->guardCsrf();

        $id = (int) $this->request->get('id', 'int', 0);
        $supplier = $this->suppliers->find($id);
        if ($supplier === null) {
            $this->notify->error('Supplier not found.');
            redirect_to('store/admin/suppliers');
            return;
        }

        $this->suppliers->delete($id);
        $this->notify->success("Supplier '{$supplier['name']}' deleted.");
        redirect_to('store/admin/suppliers');
    }

    /** @return array<string, mixed> */
    private function emptyForm(): array
    {
        return [
            'name' => '', 'company' => '', 'contact_name' => '', 'email' => '',
            'phone' => '', 'website' => '', 'address' => '', 'lead_time_days' => 0,
            'currency' => 'GBP', 'po_mode' => 'below_reorder', 'sold_days' => 30,
            'include_in_requisitions' => true, 'accepts_back_orders' => true,
            'min_order_value' => 0, 'notes' => '', 'active' => true,
        ];
    }

    /** @param array<string, mixed> $old */
    private function supplierFields(array $old): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $html = '<div class="row g-2">';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="supplier_name">Name</label>'
            . '<input type="text" class="form-control form-control-sm" name="name" id="supplier_name" required maxlength="128"'
            . ' value="' . $e((string) ($old['name'] ?? '')) . '"></div>';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="supplier_company">Company</label>'
            . '<input type="text" class="form-control form-control-sm" name="company" id="supplier_company" maxlength="128"'
            . ' value="' . $e((string) ($old['company'] ?? '')) . '"></div>';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="supplier_contact">Contact</label>'
            . '<input type="text" class="form-control form-control-sm" name="contact_name" id="supplier_contact" maxlength="128"'
            . ' value="' . $e((string) ($old['contact_name'] ?? '')) . '"></div>';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="supplier_email">Email</label>'
            . '<input type="email" class="form-control form-control-sm" name="email" id="supplier_email" maxlength="255"'
            . ' value="' . $e((string) ($old['email'] ?? '')) . '"></div>';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="supplier_phone">Phone</label>'
            . '<input type="text" class="form-control form-control-sm" name="phone" id="supplier_phone" maxlength="64"'
            . ' value="' . $e((string) ($old['phone'] ?? '')) . '"></div>';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="supplier_website">Website</label>'
            . '<input type="text" class="form-control form-control-sm" name="website" id="supplier_website" maxlength="255"'
            . ' value="' . $e((string) ($old['website'] ?? '')) . '"></div>';
        $html .= '<div class="col-md-4 mb-2"><label class="form-label small" for="supplier_lead">Lead time (days)</label>'
            . '<input type="number" min="0" class="form-control form-control-sm" name="lead_time_days" id="supplier_lead"'
            . ' value="' . (int) ($old['lead_time_days'] ?? 0) . '"></div>';
        $html .= '<div class="col-md-4 mb-2"><label class="form-label small" for="supplier_currency">Currency</label>'
            . '<input type="text" class="form-control form-control-sm" name="currency" id="supplier_currency" maxlength="3"'
            . ' value="' . $e((string) ($old['currency'] ?? 'GBP')) . '"></div>';
        $html .= '<div class="col-md-4 mb-2"><label class="form-label small" for="supplier_min_order">Min order value</label>'
            . '<input type="number" min="0" step="0.01" class="form-control form-control-sm" name="min_order_value" id="supplier_min_order"'
            . ' value="' . $e(number_format((float) ($old['min_order_value'] ?? 0), 2, '.', '')) . '"></div>';

        $html .= '<div class="col-12"><hr class="my-2"><div class="fw-semibold small mb-2">Inventory Planner</div></div>';
        $mode = (string) ($old['po_mode'] ?? 'below_reorder');
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="supplier_po_mode">Purchase order mode</label>'
            . '<select class="form-select form-select-sm" name="po_mode" id="supplier_po_mode">';
        foreach (\Module\Store\Services\InventoryPlannerService::modeOptions() as $val => $label) {
            $sel = $mode === $val ? ' selected' : '';
            $html .= '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="supplier_sold_days">Sold-in-days window</label>'
            . '<input type="number" min="1" max="365" class="form-control form-control-sm" name="sold_days" id="supplier_sold_days"'
            . ' value="' . (int) ($old['sold_days'] ?? 30) . '">'
            . '<div class="form-text">Used when PO mode is “Sold in the last XX days”.</div></div>';

        $inc = !empty($old['include_in_requisitions']) ? ' checked' : '';
        $back = !empty($old['accepts_back_orders']) ? ' checked' : '';
        $html .= '<div class="col-12 form-check mb-1">'
            . '<input type="checkbox" class="form-check-input" name="include_in_requisitions" value="1" id="supplier_include"' . $inc . '>'
            . '<label class="form-check-label small" for="supplier_include">Include in Inventory Planner</label></div>';
        $html .= '<div class="col-12 form-check mb-2">'
            . '<input type="checkbox" class="form-check-input" name="accepts_back_orders" value="1" id="supplier_backorders"' . $back . '>'
            . '<label class="form-check-label small" for="supplier_backorders">Accepts back orders (ignore supplier stock)</label></div>';

        $html .= '<div class="col-12 mb-2"><label class="form-label small" for="supplier_address">Address</label>'
            . '<textarea class="form-control form-control-sm" name="address" id="supplier_address" rows="2">'
            . $e((string) ($old['address'] ?? '')) . '</textarea></div>';
        $html .= '<div class="col-12 mb-2"><label class="form-label small" for="supplier_notes">Notes</label>'
            . '<textarea class="form-control form-control-sm" name="notes" id="supplier_notes" rows="2" maxlength="500">'
            . $e((string) ($old['notes'] ?? '')) . '</textarea></div>';
        $checked = !empty($old['active']) ? ' checked' : '';
        $html .= '<div class="col-12 form-check mb-2">'
            . '<input type="checkbox" class="form-check-input" name="active" value="1" id="supplier_active"' . $checked . '>'
            . '<label class="form-check-label small" for="supplier_active">Active</label></div>';
        $html .= '</div>';
        return $html;
    }

    /** @return array<string, mixed> */
    private function readForm(): array
    {
        return [
            'name'           => trim((string) $this->request->post('name', 'string', '')),
            'company'        => trim((string) $this->request->post('company', 'string', '')),
            'contact_name'   => trim((string) $this->request->post('contact_name', 'string', '')),
            'email'          => trim((string) $this->request->post('email', 'string', '')),
            'phone'          => trim((string) $this->request->post('phone', 'string', '')),
            'website'        => trim((string) $this->request->post('website', 'string', '')),
            'address'        => trim((string) $this->request->post('address', 'raw', '')),
            'lead_time_days' => (int) $this->request->post('lead_time_days', 'int', 0),
            'currency'       => trim((string) $this->request->post('currency', 'string', 'GBP')),
            'po_mode'        => trim((string) $this->request->post('po_mode', 'string', 'below_reorder')),
            'sold_days'      => (int) $this->request->post('sold_days', 'int', 30),
            'include_in_requisitions' => (int) $this->request->post('include_in_requisitions', 'int', 0) === 1,
            'accepts_back_orders'     => (int) $this->request->post('accepts_back_orders', 'int', 0) === 1,
            'min_order_value'         => (float) $this->request->post('min_order_value', 'float', 0),
            'notes'          => trim((string) $this->request->post('notes', 'string', '')),
            'active'         => (int) $this->request->post('active', 'int', 0) === 1,
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

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('store/admin/suppliers');
            exit;
        }
    }
}
