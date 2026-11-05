<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\WarehouseRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreFeederAdminWarehouses extends Controller
{
    private WarehouseRepository $warehouses;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->warehouses = new WarehouseRepository($this->db);
    }

    public function indexAction(): void
    {
        $this->warehouses->ensure('Default');

        $q = trim((string) $this->request->get('q', 'string', ''));
        $activeFilter = trim((string) $this->request->get('active', 'string', ''));
        $activeOnly = match ($activeFilter) {
            '1' => true,
            '0' => false,
            default => null,
        };

        $rows = $this->warehouses->all($q, $activeOnly);

        echo $this->view->inline(function () use ($rows, $q, $activeFilter) {
            $this->view->assign('title', 'Warehouses');
            $this->view->block('right_sidebar', 'Warehouses');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-building me-1"></i>Warehouses</h1>'
               . '<p class="text-muted small mb-0">Locations for stock, products, and purchase orders.</p></div>';
            echo '</div>';

            echo '<div class="row g-4">';
            echo '<div class="col-lg-4">';
            echo '<div class="border rounded p-3 bg-body-tertiary">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Add warehouse</h2>';
            echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/warehouses/add')) . '">';
            echo $this->request->csrfField();
            echo $this->warehouseFields($this->emptyForm());
            echo '<button type="submit" class="btn btn-primary btn-sm mt-2">'
               . '<i class="bi bi-plus-lg me-1"></i>Add warehouse</button>';
            echo '</form></div></div>';

            echo '<div class="col-lg-8">';
            echo '<form method="get" class="row g-2 align-items-end mb-3" action="'
               . $e($this->url->to('storefeeder/admin/warehouses')) . '">';
            echo '<div class="col-md-6"><label class="form-label small mb-1">Search</label>'
               . '<input type="search" name="q" class="form-control" value="' . $e($q) . '"'
               . ' placeholder="Name, contact, address…"></div>';
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
                   . $e($this->url->to('storefeeder/admin/warehouses')) . '">Reset</a>';
            }
            echo '</div></form>';

            if ($rows === []) {
                echo '<div class="alert alert-info mb-0">No warehouses found.</div>';
            } else {
                echo '<div class="small text-muted mb-2">' . count($rows) . ' warehouse(s)</div>';
                echo '<div class="table-responsive border rounded">';
                echo '<table class="table table-sm table-hover align-middle mb-0">';
                echo '<thead class="table-light"><tr>'
                   . '<th>Warehouse</th><th>Contact</th>'
                   . '<th class="text-end">Products</th>'
                   . '<th class="text-end">Stock</th>'
                   . '<th class="text-end">POs</th>'
                   . '<th>Status</th><th></th>'
                   . '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    $name = (string) $row['name'];
                    $isDefault = strcasecmp($name, 'Default') === 0;
                    $productsUrl = $this->url->to('storefeeder/admin/products', ['f_warehouse' => $name]);
                    $stockUrl = $this->url->to('storefeeder/admin/inventory', ['warehouse' => $name]);
                    $poUrl = $this->url->to('storefeeder/admin/purchase-orders', ['warehouse' => $name]);

                    echo '<tr' . (!$row['active'] ? ' class="table-secondary"' : '') . '>';
                    echo '<td><span class="fw-semibold">' . $e($name) . '</span>';
                    if ($isDefault) {
                        echo ' <span class="badge text-bg-secondary">Default</span>';
                    }
                    if ($row['company'] !== '' && $row['company'] !== $name) {
                        echo '<div class="small text-muted">' . $e($row['company']) . '</div>';
                    }
                    if ($row['notes'] !== '') {
                        echo '<div class="small text-muted">'
                           . $e(mb_strimwidth($row['notes'], 0, 80, '…')) . '</div>';
                    }
                    echo '</td>';
                    echo '<td class="small">';
                    $bits = array_filter([$row['contact_name'], $row['email'], $row['phone']]);
                    echo $bits !== [] ? $e(implode(' · ', $bits)) : '<span class="text-muted">—</span>';
                    echo '</td>';
                    echo '<td class="text-end">';
                    echo (int) $row['product_count'] > 0
                        ? '<a href="' . $e($productsUrl) . '">' . (int) $row['product_count'] . '</a>'
                        : '0';
                    echo '</td>';
                    echo '<td class="text-end">';
                    echo (int) $row['stock_units'] > 0
                        ? '<a href="' . $e($stockUrl) . '">' . (int) $row['stock_units'] . '</a>'
                        : '<a class="text-muted text-decoration-none" href="' . $e($stockUrl) . '">0</a>';
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
                       . $e($this->url->to('storefeeder/admin/warehouses/edit', ['id' => $id]))
                       . '">Edit</a> ';
                    if (!$isDefault) {
                        echo '<form method="post" class="d-inline" action="'
                           . $e($this->url->to('storefeeder/admin/warehouses/delete', ['id' => $id])) . '"'
                           . ' onsubmit="return confirm(\'Delete this warehouse? Products and POs will move to Default.\');">';
                        echo $this->request->csrfField();
                        echo '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
                        echo '</form>';
                    }
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div></div>';
        });
    }

    public function addAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/warehouses');
            return;
        }
        $this->guardCsrf();

        $data = $this->readForm();
        try {
            $created = $this->warehouses->create($data['name'], $data);
            $this->notify->success("Warehouse '{$created}' saved.");
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('storefeeder/admin/warehouses');
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $warehouse = $this->warehouses->find($id);
        if ($warehouse === null) {
            throw new NotFoundException('Warehouse not found.');
        }

        $isDefault = strcasecmp((string) $warehouse['name'], 'Default') === 0;

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $data = $this->readForm();
            if ($isDefault) {
                $data['name'] = 'Default';
                $data['active'] = true;
            }
            try {
                $name = $this->warehouses->update($id, $data);
                $this->notify->success("Warehouse '{$name}' updated.");
                redirect_to('storefeeder/admin/warehouses');
                return;
            } catch (\Throwable $ex) {
                $this->notify->error($ex->getMessage());
                $warehouse = array_merge($warehouse, $data);
            }
        }

        echo $this->view->inline(function () use ($warehouse, $id, $isDefault) {
            $this->view->assign('title', 'Edit warehouse');
            $this->view->block('right_sidebar', 'Warehouses');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $name = (string) $warehouse['name'];

            echo '<div class="mb-3"><a class="small text-decoration-none" href="'
               . $e($this->url->to('storefeeder/admin/warehouses')) . '">&larr; Warehouses</a></div>';
            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<h1 class="h3 mb-0">Edit warehouse</h1>';
            echo '<div class="d-flex flex-wrap gap-2">';
            if ((int) $warehouse['product_count'] > 0) {
                echo '<a class="btn btn-sm btn-outline-primary" href="'
                   . $e($this->url->to('storefeeder/admin/products', ['f_warehouse' => $name]))
                   . '">' . (int) $warehouse['product_count'] . ' product(s)</a>';
            }
            echo '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('storefeeder/admin/inventory', ['warehouse' => $name]))
               . '">' . (int) $warehouse['stock_units'] . ' stock unit(s)</a>';
            if ((int) $warehouse['po_count'] > 0) {
                echo '<a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($this->url->to('storefeeder/admin/purchase-orders', ['warehouse' => $name]))
                   . '">' . (int) $warehouse['po_count'] . ' PO(s)</a>';
            }
            echo '</div></div>';

            echo '<form method="post" action="'
               . $e($this->url->to('storefeeder/admin/warehouses/edit', ['id' => $id]))
               . '" class="border rounded p-3" style="max-width:40rem">';
            echo $this->request->csrfField();
            echo $this->warehouseFields($warehouse, $isDefault);
            if (!$isDefault) {
                echo '<div class="form-text mb-3">Renaming updates products and purchase orders using this warehouse.</div>';
            } else {
                echo '<div class="form-text mb-3">Default warehouse name and status are locked.</div>';
            }
            echo '<button type="submit" class="btn btn-primary">Save</button> ';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('storefeeder/admin/warehouses')) . '">Cancel</a>';
            echo '</form>';
        });
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/warehouses');
            return;
        }
        $this->guardCsrf();

        $id = (int) $this->request->get('id', 'int', 0);
        $warehouse = $this->warehouses->find($id);
        if ($warehouse === null) {
            $this->notify->error('Warehouse not found.');
            redirect_to('storefeeder/admin/warehouses');
            return;
        }

        try {
            $this->warehouses->delete($id);
            $this->notify->success("Warehouse '{$warehouse['name']}' deleted.");
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('storefeeder/admin/warehouses');
    }

    /** @return array<string, mixed> */
    private function emptyForm(): array
    {
        return [
            'name' => '', 'company' => '', 'contact_name' => '', 'email' => '',
            'phone' => '', 'address' => '', 'notes' => '', 'active' => true,
        ];
    }

    /** @param array<string, mixed> $old */
    private function warehouseFields(array $old, bool $lockName = false): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $html = '<div class="row g-2">';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="warehouse_name">Name</label>'
            . '<input type="text" class="form-control form-control-sm" name="name" id="warehouse_name" required maxlength="128"'
            . ' value="' . $e((string) ($old['name'] ?? '')) . '"'
            . ($lockName ? ' readonly' : '') . '></div>';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="warehouse_company">Company</label>'
            . '<input type="text" class="form-control form-control-sm" name="company" id="warehouse_company" maxlength="128"'
            . ' value="' . $e((string) ($old['company'] ?? '')) . '"></div>';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="warehouse_contact">Contact</label>'
            . '<input type="text" class="form-control form-control-sm" name="contact_name" id="warehouse_contact" maxlength="128"'
            . ' value="' . $e((string) ($old['contact_name'] ?? '')) . '"></div>';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="warehouse_email">Email</label>'
            . '<input type="email" class="form-control form-control-sm" name="email" id="warehouse_email" maxlength="255"'
            . ' value="' . $e((string) ($old['email'] ?? '')) . '"></div>';
        $html .= '<div class="col-md-6 mb-2"><label class="form-label small" for="warehouse_phone">Phone</label>'
            . '<input type="text" class="form-control form-control-sm" name="phone" id="warehouse_phone" maxlength="64"'
            . ' value="' . $e((string) ($old['phone'] ?? '')) . '"></div>';
        $html .= '<div class="col-12 mb-2"><label class="form-label small" for="warehouse_address">Address</label>'
            . '<textarea class="form-control form-control-sm" name="address" id="warehouse_address" rows="3">'
            . $e((string) ($old['address'] ?? '')) . '</textarea>'
            . '<div class="form-text">Shown on Book Delivery warehouse details.</div></div>';
        $html .= '<div class="col-12 mb-2"><label class="form-label small" for="warehouse_notes">Notes</label>'
            . '<textarea class="form-control form-control-sm" name="notes" id="warehouse_notes" rows="2" maxlength="500">'
            . $e((string) ($old['notes'] ?? '')) . '</textarea></div>';
        if ($lockName) {
            $html .= '<input type="hidden" name="active" value="1">';
            $html .= '<div class="col-12 small text-muted mb-2">Active (required for Default)</div>';
        } else {
            $checked = !empty($old['active']) ? ' checked' : '';
            $html .= '<div class="col-12 form-check mb-2">'
                . '<input type="checkbox" class="form-check-input" name="active" value="1" id="warehouse_active"'
                . $checked . '>'
                . '<label class="form-check-label small" for="warehouse_active">Active</label></div>';
        }
        $html .= '</div>';
        return $html;
    }

    /** @return array<string, mixed> */
    private function readForm(): array
    {
        return [
            'name'         => trim((string) $this->request->post('name', 'string', '')),
            'company'      => trim((string) $this->request->post('company', 'string', '')),
            'contact_name' => trim((string) $this->request->post('contact_name', 'string', '')),
            'email'        => trim((string) $this->request->post('email', 'string', '')),
            'phone'        => trim((string) $this->request->post('phone', 'string', '')),
            'address'      => trim((string) $this->request->post('address', 'raw', '')),
            'notes'        => trim((string) $this->request->post('notes', 'string', '')),
            'active'       => (int) $this->request->post('active', 'int', 0) === 1,
        ];
    }

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('storefeeder/admin/warehouses');
            exit;
        }
    }
}
