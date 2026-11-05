<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\SupplierRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreFeederAdminSuppliers extends Controller
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
        $q = trim((string) $this->request->get('q', 'string', ''));
        $activeFilter = trim((string) $this->request->get('active', 'string', ''));
        $activeOnly = match ($activeFilter) {
            '1' => true,
            '0' => false,
            default => null,
        };

        $rows = $this->suppliers->all($q, $activeOnly);

        echo $this->view->inline(function () use ($rows, $q, $activeFilter) {
            $this->view->assign('title', 'Suppliers');
            $this->view->block('right_sidebar', 'Suppliers');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-truck me-1"></i>Suppliers</h1>'
               . '<p class="text-muted small mb-0">Contacts, lead times, and product / PO links.</p></div>';
            echo '</div>';

            echo '<div class="row g-4">';
            echo '<div class="col-lg-4">';
            echo '<div class="border rounded p-3 bg-body-tertiary">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Add supplier</h2>';
            echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/suppliers/add')) . '">';
            echo $this->request->csrfField();
            echo $this->supplierFields($this->emptyForm());
            echo '<button type="submit" class="btn btn-primary btn-sm mt-2">'
               . '<i class="bi bi-plus-lg me-1"></i>Add supplier</button>';
            echo '</form></div></div>';

            echo '<div class="col-lg-8">';
            echo '<form method="get" class="row g-2 align-items-end mb-3" action="'
               . $e($this->url->to('storefeeder/admin/suppliers')) . '">';
            echo '<div class="col-md-6"><label class="form-label small mb-1">Search</label>'
               . '<input type="search" name="q" class="form-control" value="' . $e($q) . '"'
               . ' placeholder="Name, contact, email…"></div>';
            echo '<div class="col-md-3"><label class="form-label small mb-1">Status</label>'
               . '<select name="active" class="form-select">';
            foreach (['' => 'All', '1' => 'Active', '0' => 'Inactive'] as $val => $label) {
                $sel = $activeFilter === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Apply</button>';
            if ($q !== '' || $activeFilter !== '') {
                echo ' <a class="btn btn-outline-secondary" href="'
                   . $e($this->url->to('storefeeder/admin/suppliers')) . '">Reset</a>';
            }
            echo '</div></form>';

            if ($rows === []) {
                echo '<div class="alert alert-info mb-0">No suppliers found.</div>';
            } else {
                echo '<div class="small text-muted mb-2">' . count($rows) . ' supplier(s)</div>';
                echo '<div class="table-responsive border rounded">';
                echo '<table class="table table-sm table-hover align-middle mb-0">';
                echo '<thead class="table-light"><tr>'
                   . '<th>Supplier</th><th>Contact</th><th class="text-end">Lead days</th>'
                   . '<th class="text-end">Products</th><th class="text-end">POs</th><th>Status</th><th></th>'
                   . '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    $name = (string) $row['name'];
                    $productsUrl = $this->url->to('storefeeder/admin/products', ['f_supplier' => $name]);
                    $poUrl = $this->url->to('storefeeder/admin/purchase-orders', ['supplier' => $name]);
                    echo '<tr' . (!$row['active'] ? ' class="table-secondary"' : '') . '>';
                    echo '<td><span class="fw-semibold">' . $e($name) . '</span>';
                    if ($row['company'] !== '' && $row['company'] !== $name) {
                        echo '<div class="small text-muted">' . $e($row['company']) . '</div>';
                    }
                    echo '</td>';
                    echo '<td class="small">';
                    $bits = array_filter([$row['contact_name'], $row['email'], $row['phone']]);
                    echo $bits !== [] ? $e(implode(' · ', $bits)) : '<span class="text-muted">—</span>';
                    echo '</td>';
                    echo '<td class="text-end">'
                       . ((int) $row['lead_time_days'] > 0 ? (int) $row['lead_time_days'] : '—')
                       . '</td>';
                    echo '<td class="text-end">';
                    echo (int) $row['product_count'] > 0
                        ? '<a href="' . $e($productsUrl) . '">' . (int) $row['product_count'] . '</a>'
                        : '0';
                    echo '</td>';
                    echo '<td class="text-end">';
                    echo (int) $row['po_count'] > 0
                        ? '<a href="' . $e($poUrl) . '">' . (int) $row['po_count'] . '</a>'
                        : '0';
                    echo '</td>';
                    echo '<td>' . ($row['active']
                        ? '<span class="badge text-bg-success">Active</span>'
                        : '<span class="badge text-bg-secondary">Inactive</span>') . '</td>';
                    echo '<td class="text-end text-nowrap">';
                    echo '<a class="btn btn-sm btn-outline-secondary" href="'
                       . $e($this->url->to('storefeeder/admin/suppliers/edit', ['id' => $id]))
                       . '">Edit</a> ';
                    echo '<form method="post" class="d-inline" action="'
                       . $e($this->url->to('storefeeder/admin/suppliers/delete', ['id' => $id])) . '"'
                       . ' onsubmit="return confirm(\'Delete this supplier? Products using it will have supplier cleared.\');">';
                    echo $this->request->csrfField();
                    echo '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
                    echo '</form></td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div></div>';
        });
    }

    public function addAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/suppliers');
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
        redirect_to('storefeeder/admin/suppliers');
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
                redirect_to('storefeeder/admin/suppliers');
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
               . $e($this->url->to('storefeeder/admin/suppliers')) . '">&larr; Suppliers</a></div>';
            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<h1 class="h3 mb-0">Edit supplier</h1>';
            echo '<div class="d-flex gap-2">';
            if ((int) $supplier['product_count'] > 0) {
                echo '<a class="btn btn-sm btn-outline-primary" href="'
                   . $e($this->url->to('storefeeder/admin/products', ['f_supplier' => $supplier['name']]))
                   . '">' . (int) $supplier['product_count'] . ' product(s)</a>';
            }
            if ((int) $supplier['po_count'] > 0) {
                echo '<a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($this->url->to('storefeeder/admin/purchase-orders', ['supplier' => $supplier['name']]))
                   . '">' . (int) $supplier['po_count'] . ' PO(s)</a>';
            }
            echo '</div></div>';

            echo '<form method="post" action="'
               . $e($this->url->to('storefeeder/admin/suppliers/edit', ['id' => $id]))
               . '" class="border rounded p-3" style="max-width:40rem">';
            echo $this->request->csrfField();
            echo $this->supplierFields($supplier);
            echo '<div class="form-text mb-3">Renaming updates products and purchase orders using this supplier.</div>';
            echo '<button type="submit" class="btn btn-primary">Save</button> ';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('storefeeder/admin/suppliers')) . '">Cancel</a>';
            echo '</form>';
        });
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/suppliers');
            return;
        }
        $this->guardCsrf();

        $id = (int) $this->request->get('id', 'int', 0);
        $supplier = $this->suppliers->find($id);
        if ($supplier === null) {
            $this->notify->error('Supplier not found.');
            redirect_to('storefeeder/admin/suppliers');
            return;
        }

        $this->suppliers->delete($id);
        $this->notify->success("Supplier '{$supplier['name']}' deleted.");
        redirect_to('storefeeder/admin/suppliers');
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
        foreach (\Module\StoreFeeder\Services\InventoryPlannerService::modeOptions() as $val => $label) {
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

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('storefeeder/admin/suppliers');
            exit;
        }
    }
}
