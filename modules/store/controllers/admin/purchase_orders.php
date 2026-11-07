<?php
declare(strict_types=1);

use Module\Store\Services\ChannelClientFactory;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\ChannelStockPublisher;
use Module\Store\Services\CredentialsVault;
use Module\Store\Services\InventoryService;
use Module\Store\Services\ListingRepository;
use Module\Store\Services\PickwaveRepository;
use Module\Store\Services\ProductRepository;
use Module\Store\Services\PurchaseOrderRepository;
use Module\Store\Services\SupplierRepository;
use Module\Store\Services\VariantRepository;
use Module\Store\Services\WarehouseRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreAdminPurchaseOrders extends Controller
{
    private PurchaseOrderRepository $orders;
    private SupplierRepository $suppliers;
    private WarehouseRepository $warehouses;
    private VariantRepository $variants;
    private ProductRepository $products;
    private InventoryService $inventory;
    private PickwaveRepository $pickwaves;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->orders     = new PurchaseOrderRepository($this->db);
        $this->suppliers  = new SupplierRepository($this->db);
        $this->warehouses = new WarehouseRepository($this->db);
        $this->variants   = new VariantRepository($this->db);
        $this->products   = new ProductRepository($this->db);
        $this->pickwaves  = new PickwaveRepository($this->db);

        $channels  = new ChannelRepository($this->db);
        $vault     = CredentialsVault::forTenant(
            (string) $this->config->get('store.credentials_key', '')
        );
        $publisher = new ChannelStockPublisher(
            $channels,
            new ChannelClientFactory($vault, $channels),
            new ListingRepository($this->db),
        );
        $this->inventory = new InventoryService($this->db, $publisher);
        // Permissions: Action.php only.
    }

    public function indexAction(): void
    {
        $filters = [
            'id'          => trim((string) $this->request->get('id', 'string', '')),
            'supplier'    => trim((string) $this->request->get('supplier', 'string', '')),
            'warehouse'   => trim((string) $this->request->get('warehouse', 'string', '')),
            'status'      => trim((string) $this->request->get('status', 'string', '')),
            'raised_from' => trim((string) $this->request->get('raised_from', 'string', '')),
            'raised_to'   => trim((string) $this->request->get('raised_to', 'string', '')),
            'due_from'    => trim((string) $this->request->get('due_from', 'string', '')),
            'due_to'      => trim((string) $this->request->get('due_to', 'string', '')),
            'lines_q'     => trim((string) $this->request->get('lines_q', 'string', '')),
        ];
        $rows = $this->orders->search($filters);
        $supplierList = $this->suppliers->all();
        $this->warehouses->ensure('Default');
        $warehouseList = $this->warehouses->all();

        echo $this->view->inline(function () use ($rows, $filters, $supplierList, $warehouseList) {
            $this->view->assign('title', 'Deliveries');
            $this->view->block('right_sidebar', 'Deliveries');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $fmtDate = static function (?string $dt): string {
                $dt = trim((string) $dt);
                if ($dt === '') {
                    return '—';
                }
                try {
                    return (new \DateTimeImmutable($dt))->format('d/m/Y');
                } catch (\Throwable $ex) {
                    return $dt;
                }
            };

            echo '<style>
              .sf-del-adhoc{background:#5b7c99;color:#fff;border-radius:.35rem;padding:.75rem 1rem;margin-bottom:1.25rem}
              .sf-del-adhoc label{font-size:.75rem;margin-bottom:.15rem;opacity:.9}
              .sf-del-toolbar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between;margin-bottom:.75rem}
              .sf-del-actions a{display:block;font-size:.8rem;line-height:1.35;white-space:nowrap}
              .sf-del-table th{vertical-align:top;font-size:.8rem}
              .sf-del-table .form-control,.sf-del-table .form-select{font-size:.8rem}
            </style>';

            // Ad-hoc create
            echo '<div class="sf-del-adhoc">';
            echo '<div class="fw-semibold mb-2">Create an Ad-Hoc Delivery / Purchase Order</div>';
            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/purchase-orders/ad-hoc'))
               . '" class="row g-2 align-items-end">';
            echo $this->request->csrfField();
            echo '<div class="col-md-6"><label class="form-label" for="adhoc_code">Product SKU / EAN / UPC</label>';
            echo '<input type="text" class="form-control form-control-sm" name="code" id="adhoc_code" required'
               . ' autocomplete="off"></div>';
            echo '<div class="col-md-2"><label class="form-label" for="adhoc_qty">Quantity</label>';
            echo '<input type="number" class="form-control form-control-sm" name="qty" id="adhoc_qty"'
               . ' min="1" value="1" required></div>';
            echo '<div class="col-md-2"><button type="submit" class="btn btn-sm btn-light w-100">Add</button></div>';
            echo '<div class="col-md-2 text-end"><a class="btn btn-sm btn-outline-light" href="'
               . $e($this->url->to('store/admin/purchase-orders/add'))
               . '">Full PO</a></div>';
            echo '</form></div>';

            echo '<div class="sf-del-toolbar">';
            echo '<div class="d-flex flex-wrap gap-2 align-items-center">';
            echo '<h1 class="h4 mb-0 me-2">Deliveries</h1>';
            echo '<button type="submit" form="sf-del-bulk" class="btn btn-sm btn-outline-primary"'
               . ' id="sf-del-book-bulk" disabled>Book in Outstanding</button>';
            echo '</div>';
            echo '<div class="d-flex gap-2">';
            echo '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/purchase-orders'))
               . '">Clear filtering</a>';
            echo '</div></div>';

            echo '<form id="sf-del-filters" method="get" action="'
               . $e($this->url->to('store/admin/purchase-orders')) . '"></form>';

            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/purchase-orders/book-in-bulk'))
               . '" id="sf-del-bulk">';
            echo $this->request->csrfField();

            echo '<div class="table-responsive border rounded bg-white">';
            echo '<table class="table table-sm table-hover align-middle mb-0 sf-del-table">';
            echo '<thead class="table-light"><tr>';
            echo '<th style="width:2rem"><input type="checkbox" class="form-check-input" id="sf-del-all"></th>';
            echo '<th>Purchase Order ID<br><input type="text" class="form-control form-control-sm mt-1"'
               . ' name="id" form="sf-del-filters" value="' . $e($filters['id']) . '"></th>';
            echo '<th>Supplier<br><select class="form-select form-select-sm mt-1" name="supplier"'
               . ' form="sf-del-filters" onchange="this.form.submit()"><option value="">All</option>';
            foreach ($supplierList as $s) {
                $sel = $filters['supplier'] === $s['name'] ? ' selected' : '';
                echo '<option value="' . $e($s['name']) . '"' . $sel . '>' . $e($s['name']) . '</option>';
            }
            echo '</select></th>';
            echo '<th>Warehouse<br><select class="form-select form-select-sm mt-1" name="warehouse"'
               . ' form="sf-del-filters" onchange="this.form.submit()"><option value="">All</option>';
            foreach ($warehouseList as $w) {
                $sel = $filters['warehouse'] === $w['name'] ? ' selected' : '';
                echo '<option value="' . $e($w['name']) . '"' . $sel . '>' . $e($w['name']) . '</option>';
            }
            echo '</select></th>';
            echo '<th>Date Raised<br><div class="d-flex gap-1 mt-1">'
               . '<input type="date" class="form-control form-control-sm" name="raised_from" form="sf-del-filters"'
               . ' value="' . $e($filters['raised_from']) . '" title="From">'
               . '<input type="date" class="form-control form-control-sm" name="raised_to" form="sf-del-filters"'
               . ' value="' . $e($filters['raised_to']) . '" title="To"></div></th>';
            echo '<th>Due Date<br><div class="d-flex gap-1 mt-1">'
               . '<input type="date" class="form-control form-control-sm" name="due_from" form="sf-del-filters"'
               . ' value="' . $e($filters['due_from']) . '" title="From">'
               . '<input type="date" class="form-control form-control-sm" name="due_to" form="sf-del-filters"'
               . ' value="' . $e($filters['due_to']) . '" title="To"></div></th>';
            echo '<th>Status<br><select class="form-select form-select-sm mt-1" name="status"'
               . ' form="sf-del-filters" onchange="this.form.submit()"><option value="">All</option>';
            foreach ([
                'draft' => 'Draft',
                'confirmed' => 'Sent To Supplier',
                'received' => 'Booked In',
                'cancelled' => 'Cancelled',
            ] as $val => $label) {
                $sel = $filters['status'] === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></th>';
            echo '<th class="text-end">Total Value</th>';
            echo '<th>Total Lines / Cartons / Units / Inventory<br>'
               . '<input type="text" class="form-control form-control-sm mt-1" name="lines_q"'
               . ' form="sf-del-filters" value="' . $e($filters['lines_q'])
               . '" placeholder="Lines ="></th>';
            echo '<th></th>';
            echo '</tr></thead><tbody>';

            if ($rows === []) {
                echo '<tr><td colspan="10" class="text-muted py-4 text-center">'
                   . 'No deliveries match these filters.</td></tr>';
            }

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $st = (string) $row['status'];
                $open = (int) ($row['open_qty'] ?? 0);
                $lines = (int) ($row['line_count'] ?? 0);
                $units = (int) ($row['units_ordered'] ?? 0);
                $viewUrl = $this->url->to('store/admin/purchase-orders/view', ['id' => $id]);
                $bookUrl = $this->url->to('store/admin/purchase-orders/book-delivery', ['id' => $id]);
                $grUrl = $this->url->to('store/admin/purchase-orders/goods-received', ['id' => $id]);
                $sheetUrl = $this->url->to('store/admin/purchase-orders/booking-sheet', ['id' => $id]);

                echo '<tr>';
                echo '<td><input type="checkbox" class="form-check-input sf-del-check" name="ids[]" value="'
                   . $id . '"' . ($st === 'confirmed' && $open > 0 ? '' : ' disabled') . '></td>';
                echo '<td><a href="' . $e($viewUrl) . '">' . $id . '</a></td>';
                echo '<td>' . $e((string) $row['supplier_name']) . '</td>';
                echo '<td>' . $e((string) ($row['warehouse'] ?? 'Default')) . '</td>';
                echo '<td class="text-nowrap">' . $e($fmtDate((string) ($row['created_at'] ?? ''))) . '</td>';
                echo '<td class="text-nowrap">' . $e($fmtDate((string) ($row['expected_date'] ?? ''))) . '</td>';
                echo '<td>' . $e(PurchaseOrderRepository::statusLabel($st)) . '</td>';
                echo '<td class="text-end">£' . number_format((float) ($row['total_value'] ?? 0), 2) . '</td>';
                echo '<td>' . $lines . ' / ' . $units . ' / ' . $units . ' / ' . $units . '</td>';
                echo '<td class="sf-del-actions">';
                if ($st === 'confirmed' && $open > 0) {
                    echo '<a href="' . $e($bookUrl) . '">Book In Outstanding</a>';
                }
                echo '<a href="' . $e($grUrl) . '">View Goods Received</a>';
                echo '<a href="' . $e($sheetUrl) . '">View Suggested Booking In Sheet</a>';
                echo '</td></tr>';
            }

            echo '</tbody></table></div></form>';

            $this->view->block('scripts', <<<'JS'
            <script>
            (function () {
                const all = document.getElementById('sf-del-all');
                const checks = () => Array.from(document.querySelectorAll('.sf-del-check:not(:disabled)'));
                const bulkBtn = document.getElementById('sf-del-book-bulk');
                const filterForm = document.getElementById('sf-del-filters');
                function sync() {
                    const n = checks().filter(c => c.checked).length;
                    if (bulkBtn) bulkBtn.disabled = n === 0;
                }
                if (all) {
                    all.addEventListener('change', function () {
                        checks().forEach(c => { c.checked = all.checked; });
                        sync();
                    });
                }
                document.addEventListener('change', function (e) {
                    if (e.target.classList && e.target.classList.contains('sf-del-check')) sync();
                });
                if (filterForm) {
                    filterForm.querySelectorAll('input[type="text"], input[type="date"]').forEach(function (el) {
                        el.addEventListener('keydown', function (ev) {
                            if (ev.key === 'Enter') { ev.preventDefault(); filterForm.submit(); }
                        });
                        if (el.type === 'date') {
                            el.addEventListener('change', function () { filterForm.submit(); });
                        }
                    });
                }
                sync();
            })();
            </script>
            JS);
        });
    }

    public function addAction(): void
    {
        try {
            $this->warehouses->ensure('Default');
            $warehouseName = 'Default';
            $wh = $this->warehouses->findByName($warehouseName);
            $parsed = PurchaseOrderRepository::parseAddress((string) ($wh['address'] ?? ''));
            $company = trim((string) ($wh['company'] ?? ''));
            $id = $this->orders->create(
                '',
                '',
                '',
                null,
                $warehouseName,
                $this->raisedByName(),
                0.0,
                20.0,
                [
                    'created_via'  => 'Manual',
                    'del_company'  => $company !== '' ? $company : $warehouseName,
                    'del_address1' => $parsed['address1'],
                    'del_address2' => $parsed['address2'],
                    'del_address3' => $parsed['address3'],
                    'del_city'     => $parsed['city'],
                    'del_county'   => $parsed['county'],
                    'del_country'  => $parsed['country'],
                    'del_postcode' => $parsed['postcode'],
                ]
            );
            redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            redirect_to('store/admin/purchase-orders');
        }
    }

    public function adHocAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }

        $code = trim((string) $this->request->post('code', 'string', ''));
        $qty = max(1, (int) $this->request->post('qty', 'int', 1));

        try {
            $variant = $this->variants->findBySkuOrBarcode($code);
            if ($variant === null || $variant->id === null) {
                throw new \RuntimeException("Product '{$code}' not found.");
            }
            $product = $this->products->find($variant->productId);
            if ($product === null) {
                throw new \RuntimeException('Product not found for variant.');
            }
            $supplier = trim($product->supplierName);
            if ($supplier === '') {
                $supplier = 'Ad-hoc';
            }
            $supplier = $this->suppliers->ensure($supplier);
            $warehouse = $this->warehouses->ensure(
                trim($product->warehouse) !== '' ? $product->warehouse : 'Default'
            );

            $raisedBy = '';
            $user = $this->auth->user();
            if ($user !== null) {
                $raisedBy = method_exists($user, 'name') ? (string) $user->name() : (string) ($user->username ?? '');
            }
            $id = $this->orders->create(
                $supplier,
                'Ad-hoc',
                'Created from ad-hoc delivery',
                null,
                $warehouse,
                $raisedBy,
                0.0,
                20.0,
                ['created_via' => 'Ad-hoc']
            );
            $this->orders->addLine($id, (int) $variant->id, $qty, (float) $variant->cost, $variant->sku);
            $this->orders->confirm($id);
            $this->notify->success("Ad-hoc delivery #{$id} created ({$variant->sku} × {$qty}).");
            redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            redirect_to('store/admin/purchase-orders');
        }
    }

    public function viewAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $po = $this->orders->find($id);
        if ($po === null) {
            throw new NotFoundException('Purchase order not found.');
        }

        $lines = $this->orders->lines($id);
        $money = $this->orders->moneyTotals($po, $lines);
        $supplierList = $this->suppliers->all();
        $this->warehouses->ensure('Default');
        $warehouseList = $this->warehouses->all();
        $isDraft = (string) $po['status'] === PurchaseOrderRepository::STATUS_DRAFT;
        $isConfirmed = (string) $po['status'] === PurchaseOrderRepository::STATUS_CONFIRMED;
        $openTotal = 0;
        foreach ($lines as $line) {
            $openTotal += PurchaseOrderRepository::lineOutstanding($line);
        }
        $pq = trim((string) $this->request->get('pq', 'string', ''));
        $catalogSupplier = $isDraft ? (string) ($po['supplier_name'] ?? '') : '';
        $catalog = $isDraft ? $this->orders->catalogVariants($pq, $catalogSupplier) : [];

        echo $this->view->inline(function () use (
            $po, $lines, $money, $supplierList, $warehouseList, $isDraft, $isConfirmed, $openTotal, $catalog, $pq
        ) {
            $this->renderPoScreen(
                $po,
                $lines,
                $money,
                $supplierList,
                $warehouseList,
                $isDraft,
                $isConfirmed,
                $openTotal,
                $catalog,
                $pq
            );
        });
    }

    public function updateAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $supplierName = trim((string) $this->request->post('supplier_name', 'string', ''));
            $supplier = $supplierName !== '' ? $this->suppliers->ensure($supplierName) : '';
            $expected = trim((string) $this->request->post('expected_date', 'string', ''));
            $warehouse = $this->warehouses->ensure(
                (string) $this->request->post('warehouse', 'string', 'Default')
            );
            $this->orders->updateHeader(
                $id,
                $supplier,
                (string) $this->request->post('reference', 'string', ''),
                (string) $this->request->post('notes', 'string', ''),
                $expected !== '' ? $expected : null,
                $warehouse,
                (float) $this->request->post('shipping_cost', 'float', 0),
                (float) $this->request->post('vat_rate', 'float', 20),
                $this->postedPoExtra()
            );
            $this->notify->success('Purchase order updated.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
    }

    public function updateExpectedAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $expected = trim((string) $this->request->post('expected_date', 'string', ''));
            $this->orders->setExpectedDate($id, $expected !== '' ? $expected : null);
            $this->notify->success('Expected date updated.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
    }

    public function updateMoneyAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $this->orders->updateShippingVat(
                $id,
                (float) $this->request->post('shipping_cost', 'float', 0),
                (float) $this->request->post('vat_rate', 'float', 20)
            );
            $this->notify->success('Shipping and VAT updated.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
    }

    public function addLineAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $posted = $this->request->all();
            $selected = is_array($posted['selected'] ?? null) ? $posted['selected'] : [];
            $qtys = is_array($posted['qty'] ?? null) ? $posted['qty'] : [];
            $added = 0;
            foreach ($selected as $rawId) {
                $vid = (int) $rawId;
                if ($vid <= 0) {
                    continue;
                }
                $variant = $this->variants->find($vid);
                if ($variant === null || $variant->id === null) {
                    continue;
                }
                $qty = max(1, (int) ($qtys[$vid] ?? $qtys[(string) $vid] ?? 1));
                $this->orders->addLine($id, (int) $variant->id, $qty, (float) $variant->cost, $variant->sku);
                $added++;
            }

            $sku = trim((string) ($posted['sku'] ?? ''));
            if ($sku !== '') {
                $qty = max(1, (int) ($posted['qty_sku'] ?? $posted['qty'] ?? 1));
                if (is_array($posted['qty'] ?? null)) {
                    $qty = max(1, (int) ($posted['qty_sku'] ?? 1));
                }
                $cost = (float) ($posted['unit_cost'] ?? 0);
                $variant = $this->variants->findBySku($sku);
                if ($variant === null || $variant->id === null) {
                    throw new \RuntimeException("SKU '{$sku}' not found.");
                }
                if ($cost <= 0) {
                    $cost = (float) $variant->cost;
                }
                $this->orders->addLine($id, (int) $variant->id, $qty, $cost, $variant->sku);
                $added++;
            }

            if ($added === 0) {
                throw new \RuntimeException('Select at least one product, or enter a SKU.');
            }
            $this->notify->success($added === 1 ? 'Line added.' : $added . ' lines added.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
    }

    public function deleteLineAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        $lineId = (int) $this->request->get('line_id', 'int', 0);
        try {
            $this->orders->deleteLine($lineId);
            $this->notify->success('Line removed.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
    }

    public function confirmAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $this->orders->confirm($id);
            $this->notify->success('Purchase order confirmed — stock now shows On Purchase Order.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
    }

    public function cancelAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $this->orders->cancel($id);
            $this->notify->success('Purchase order cancelled.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $this->orders->delete($id);
            $this->notify->success('Purchase order deleted.');
            redirect_to('store/admin/purchase-orders');
            return;
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
        }
    }

    public function receiveLineAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        $lineId = (int) $this->request->get('line_id', 'int', 0);
        $qty = (int) $this->request->post('qty', 'int', 0);

        try {
            $lines = $this->orders->lines($id);
            $line = null;
            foreach ($lines as $row) {
                if ((int) $row['id'] === $lineId) {
                    $line = $row;
                    break;
                }
            }
            if ($line === null) {
                throw new \RuntimeException('Line not found.');
            }

            $taken = $this->orders->receiveLine($lineId, $qty);
            $variantId = (int) $line['variant_id'];
            $this->inventory->adjust(
                $variantId,
                $taken,
                'received',
                'PO #' . $id,
                $this->auth->id()
            );
            $this->notify->success("Received {$taken} into stock.");
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
    }

    public function bookDeliveryAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $po = $this->orders->find($id);
        if ($po === null) {
            throw new NotFoundException('Purchase order not found.');
        }
        if ((string) $po['status'] !== PurchaseOrderRepository::STATUS_CONFIRMED) {
            $this->notify->warning('Only deliveries sent to supplier can be booked in.');
            redirect_to('store/admin/purchase-orders/view', ['id' => $id]);
            return;
        }

        $lines = $this->orders->lines($id);
        $money = $this->orders->moneyTotals($po, $lines);
        $warehouse = $this->warehouses->findByName((string) ($po['warehouse'] ?? 'Default'));

        echo $this->view->inline(function () use ($po, $lines, $money, $warehouse) {
            $this->view->assign('title', 'Book Delivery - ' . (int) $po['id']);
            $this->view->block('right_sidebar', 'Deliveries');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $id = (int) $po['id'];
            $fmtDate = static function (?string $dt): string {
                $dt = trim((string) $dt);
                if ($dt === '') {
                    return '—';
                }
                try {
                    return (new \DateTimeImmutable($dt))->format('d/m/Y');
                } catch (\Throwable $ex) {
                    return $dt;
                }
            };

            echo '<style>
              .sf-book-wrap{max-width:1200px}
              .sf-book-head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1rem}
              .sf-book-panels{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem}
              @media(max-width:900px){.sf-book-panels{grid-template-columns:1fr}}
              .sf-book-panel{background:#f7f7f7;border:1px solid #e5e5e5;padding:1rem 1.1rem}
              .sf-book-panel h2{font-size:.95rem;font-weight:700;margin:0 0 .75rem}
              .sf-book-panel dl{display:grid;grid-template-columns:11rem 1fr;gap:.35rem .75rem;margin:0;font-size:.9rem}
              .sf-book-panel dt{color:#555}
              .sf-book-panel dd{margin:0}
              .sf-book-table th{font-size:.78rem;white-space:nowrap}
              .sf-book-foot{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:1rem;margin-top:1rem}
            </style>';

            echo '<div class="sf-book-wrap">';
            echo '<div class="sf-book-head">';
            echo '<div><a href="' . $e($this->url->to('store/admin/purchase-orders'))
               . '">&larr; Back To Deliveries</a>';
            echo '<h1 class="h3 mb-0 mt-2">Book Delivery - ' . $id . '</h1></div>';
            echo '<div class="d-flex flex-wrap gap-2">';
            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/purchase-orders/split', ['id' => $id])) . '">';
            echo $this->request->csrfField();
            echo '<button type="submit" class="btn btn-primary"'
               . ' onclick="return confirm(\'Move outstanding items onto a new delivery?\');">'
               . 'Split by Remaining Items</button></form>';
            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/purchase-orders/cancel', ['id' => $id])) . '">';
            echo $this->request->csrfField();
            echo '<button type="submit" class="btn btn-danger"'
               . ' onclick="return confirm(\'Delete / cancel this delivery?\');">Delete</button></form>';
            echo '</div></div>';

            echo '<div class="sf-book-panels">';
            echo '<div class="sf-book-panel"><h2>Purchase Order Information</h2><dl>';
            echo '<dt>Supplier</dt><dd>' . $e((string) $po['supplier_name']) . '</dd>';
            echo '<dt>Date Raised</dt><dd>' . $e($fmtDate((string) ($po['created_at'] ?? ''))) . '</dd>';
            echo '<dt>Due Date</dt><dd>' . $e($fmtDate((string) ($po['expected_date'] ?? ''))) . '</dd>';
            echo '<dt>Sub Total</dt><dd>£ ' . number_format($money['subtotal'], 2) . '</dd>';
            echo '<dt>Shipping Costs</dt><dd>£ ' . number_format($money['shipping'], 2) . '</dd>';
            echo '<dt>VAT (' . number_format($money['vat_rate'], 0) . '%)</dt><dd>£ '
               . number_format($money['vat'], 2) . '</dd>';
            echo '<dt>Purchase Order Total</dt><dd><strong>£ '
               . number_format($money['total'], 2) . '</strong></dd>';
            echo '<dt>Raised By</dt><dd>'
               . $e((string) (($po['raised_by'] ?? '') !== '' ? $po['raised_by'] : '—')) . '</dd>';
            echo '</dl></div>';

            $whName = (string) ($po['warehouse'] ?? 'Default');
            $whCompany = $warehouse['company'] ?? '';
            if ($whCompany === '') {
                $whCompany = $whName;
            }
            $whAddress = trim((string) ($warehouse['address'] ?? ''));
            echo '<div class="sf-book-panel"><h2>Warehouse Details</h2><dl>';
            echo '<dt>Company</dt><dd>' . $e($whCompany) . '</dd>';
            echo '<dt>Address</dt><dd>' . ($whAddress !== '' ? nl2br($e($whAddress)) : '—') . '</dd>';
            echo '</dl></div></div>';

            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/purchase-orders/book-delivery-confirm', ['id' => $id]))
               . '" id="sf-book-form">';
            echo $this->request->csrfField();

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="sf-book-recalc">Recalculate</button>';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="sf-book-reset">Reset Filters</button>';
            echo '</div>';

            echo '<div class="table-responsive border rounded bg-white">';
            echo '<table class="table table-sm align-middle mb-0 sf-book-table">';
            echo '<thead class="table-light"><tr>'
               . '<th>SKU</th><th>Supplier SKU</th><th>EAN</th><th>Product Name</th><th>Notes</th>'
               . '<th class="text-end">Qty Ordered</th><th class="text-end">Qty Received</th>'
               . '<th class="text-end">Qty Written Off</th><th class="text-end">Qty Outstanding</th>'
               . '<th>Status</th><th>Book In</th></tr></thead><tbody>';

            foreach ($lines as $line) {
                $lid = (int) $line['id'];
                $ordered = (int) $line['qty_ordered'];
                $received = (int) $line['qty_received'];
                $written = (int) ($line['qty_written_off'] ?? 0);
                $open = PurchaseOrderRepository::lineOutstanding($line);
                $maxWrite = max(0, $ordered - $received);
                $sku = (string) ($line['variant_sku'] ?? $line['sku'] ?? '');
                $supSku = (string) ($line['product_supplier_sku'] ?? '');
                $ean = (string) ($line['product_ean'] ?? '');
                $pname = (string) ($line['product_name'] ?? $line['variant_name'] ?? '');
                $notes = (string) ($line['notes'] ?? '');
                $status = PurchaseOrderRepository::lineStatus($line);

                echo '<tr data-ordered="' . $ordered . '" data-received="' . $received
                   . '" data-open="' . $open . '" data-written="' . $written . '">';
                echo '<td class="fw-semibold">' . $e($sku) . '</td>';
                echo '<td>' . $e($supSku) . '</td>';
                echo '<td>' . $e($ean) . '</td>';
                echo '<td>' . $e($pname) . '</td>';
                echo '<td>' . $e($notes) . '</td>';
                echo '<td class="text-end">' . $ordered . '</td>';
                echo '<td class="text-end">' . $received . '</td>';
                echo '<td class="text-end">';
                echo '<input type="number" class="form-control form-control-sm sf-book-writeoff text-end"'
                   . ' min="0" max="' . $maxWrite . '" name="write_off[' . $lid . ']" value="'
                   . $written . '" data-line="' . $lid . '" style="width:5rem;margin-left:auto"'
                   . ($maxWrite <= 0 && $written <= 0 ? ' disabled' : '') . '>';
                echo '</td>';
                echo '<td class="text-end sf-book-open">' . $open . '</td>';
                echo '<td class="sf-book-status">' . $e($status) . '</td>';
                echo '<td class="text-nowrap">';
                if ($open > 0 || $maxWrite > 0) {
                    echo '<label class="d-inline-flex align-items-center gap-1 mb-0">'
                       . '<input type="checkbox" class="form-check-input sf-book-check"'
                       . ($open > 0 ? ' checked' : '')
                       . ' data-line="' . $lid . '"'
                       . ($open <= 0 ? ' disabled' : '') . '>'
                       . '<input type="number" class="form-control form-control-sm sf-book-qty" min="0" max="'
                       . $open . '" name="book_in[' . $lid . ']" value="' . ($open > 0 ? $open : 0)
                       . '" style="width:5rem"'
                       . ($open <= 0 ? ' disabled' : '') . '>'
                       . '</label>';
                } else {
                    echo '—';
                }
                echo '</td></tr>';
            }

            echo '</tbody></table></div>';

            echo '<div class="sf-book-foot">';
            echo '<div class="form-check form-switch">'
               . '<input class="form-check-input" type="checkbox" role="switch" id="sf-book-pickwave"'
               . ' name="add_to_pickwave" value="1">'
               . '<label class="form-check-label" for="sf-book-pickwave">'
               . 'Add Released Orders Onto a Pickwave <span class="text-muted" id="sf-book-pw-label">Off</span>'
               . '</label></div>';
            echo '<button type="submit" class="btn btn-primary btn-lg px-4">Confirm</button>';
            echo '</div></form></div>';

            $this->view->block('scripts', <<<'JS'
            <script>
            (function () {
                const form = document.getElementById('sf-book-form');
                const pw = document.getElementById('sf-book-pickwave');
                const pwLabel = document.getElementById('sf-book-pw-label');
                const recalc = document.getElementById('sf-book-recalc');
                const reset = document.getElementById('sf-book-reset');

                function rowOpen(row) {
                    const ordered = parseInt(row.getAttribute('data-ordered') || '0', 10) || 0;
                    const received = parseInt(row.getAttribute('data-received') || '0', 10) || 0;
                    const wo = row.querySelector('.sf-book-writeoff');
                    let written = wo ? (parseInt(wo.value || '0', 10) || 0) : 0;
                    const maxWrite = Math.max(0, ordered - received);
                    if (written < 0) written = 0;
                    if (written > maxWrite) written = maxWrite;
                    if (wo && String(written) !== wo.value) wo.value = String(written);
                    return Math.max(0, ordered - received - written);
                }

                function syncRow(row) {
                    const open = rowOpen(row);
                    const openCell = row.querySelector('.sf-book-open');
                    const statusCell = row.querySelector('.sf-book-status');
                    const cb = row.querySelector('.sf-book-check');
                    const qty = row.querySelector('.sf-book-qty');
                    if (openCell) openCell.textContent = String(open);
                    if (statusCell) {
                        const received = parseInt(row.getAttribute('data-received') || '0', 10) || 0;
                        statusCell.textContent = open <= 0 ? 'Complete' : (received > 0 ? 'Part Received' : 'Awaiting Product');
                    }
                    if (qty) {
                        qty.max = String(open);
                        if (cb) {
                            if (open <= 0) {
                                cb.checked = false;
                                cb.disabled = true;
                            } else {
                                cb.disabled = false;
                            }
                        }
                        if (cb && !cb.checked) {
                            qty.disabled = true;
                            qty.value = '0';
                        } else {
                            qty.disabled = open <= 0;
                            let v = parseInt(qty.value || '0', 10) || 0;
                            if (v > open) v = open;
                            qty.value = String(Math.max(0, v));
                        }
                    }
                }

                function syncAll() {
                    document.querySelectorAll('tr[data-ordered]').forEach(syncRow);
                }

                function syncChecks() {
                    document.querySelectorAll('.sf-book-check').forEach(function (cb) {
                        const qty = form.querySelector('input[name="book_in[' + cb.getAttribute('data-line') + ']"]');
                        if (!qty) return;
                        const row = cb.closest('tr');
                        if (!cb.checked) {
                            qty.disabled = true;
                            qty.value = '0';
                        } else if (row) {
                            syncRow(row);
                        }
                    });
                }

                document.querySelectorAll('.sf-book-check').forEach(function (cb) {
                    cb.addEventListener('change', syncChecks);
                });
                document.querySelectorAll('.sf-book-writeoff').forEach(function (inp) {
                    inp.addEventListener('input', function () {
                        const row = inp.closest('tr');
                        if (row) syncRow(row);
                    });
                    inp.addEventListener('change', function () {
                        const row = inp.closest('tr');
                        if (row) syncRow(row);
                    });
                });
                if (pw && pwLabel) {
                    pw.addEventListener('change', function () {
                        pwLabel.textContent = pw.checked ? 'On' : 'Off';
                    });
                }
                if (recalc) {
                    recalc.addEventListener('click', function () {
                        let book = 0, write = 0;
                        document.querySelectorAll('.sf-book-qty:not(:disabled)').forEach(function (inp) {
                            book += parseInt(inp.value || '0', 10) || 0;
                        });
                        document.querySelectorAll('.sf-book-writeoff:not(:disabled)').forEach(function (inp) {
                            const row = inp.closest('tr');
                            const base = parseInt((row && row.getAttribute('data-written')) || '0', 10) || 0;
                            const cur = parseInt(inp.value || '0', 10) || 0;
                            write += Math.max(0, cur - base);
                        });
                        window.alert('Selected book-in: ' + book + '\nAdditional write-off: ' + write);
                    });
                }
                if (reset) {
                    reset.addEventListener('click', function () {
                        document.querySelectorAll('tr[data-ordered]').forEach(function (row) {
                            const written = parseInt(row.getAttribute('data-written') || '0', 10) || 0;
                            const openBase = parseInt(row.getAttribute('data-open') || '0', 10) || 0;
                            const wo = row.querySelector('.sf-book-writeoff');
                            const cb = row.querySelector('.sf-book-check');
                            const qty = row.querySelector('.sf-book-qty');
                            if (wo) wo.value = String(written);
                            if (cb) { cb.checked = openBase > 0; cb.disabled = openBase <= 0; }
                            if (qty) { qty.disabled = openBase <= 0; qty.value = String(openBase); qty.max = String(openBase); }
                            syncRow(row);
                        });
                    });
                }
                if (form) {
                    form.addEventListener('submit', function (e) {
                        syncChecks();
                        let anyBook = false;
                        let anyWrite = false;
                        document.querySelectorAll('.sf-book-qty:not(:disabled)').forEach(function (inp) {
                            if ((parseInt(inp.value || '0', 10) || 0) > 0) anyBook = true;
                        });
                        document.querySelectorAll('.sf-book-writeoff:not(:disabled)').forEach(function (inp) {
                            const row = inp.closest('tr');
                            const base = parseInt((row && row.getAttribute('data-written')) || '0', 10) || 0;
                            const cur = parseInt(inp.value || '0', 10) || 0;
                            if (cur !== base) anyWrite = true;
                        });
                        if (!anyBook && !anyWrite) {
                            e.preventDefault();
                            window.alert('Enter a book-in quantity or change Qty Written Off for at least one line.');
                        }
                    });
                }
                syncAll();
            })();
            </script>
            JS);
        });
    }

    public function bookDeliveryConfirmAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        $raw = (array) $this->request->post('book_in', 'raw', []);
        $qtyByLine = [];
        foreach ($raw as $lineId => $qty) {
            $qtyByLine[(int) $lineId] = (int) $qty;
        }
        $rawWrite = (array) $this->request->post('write_off', 'raw', []);
        $writeOffByLine = [];
        foreach ($rawWrite as $lineId => $qty) {
            $writeOffByLine[(int) $lineId] = (int) $qty;
        }

        $addToPickwave = (bool) $this->request->post('add_to_pickwave', 'bool', false)
            || $this->request->post('add_to_pickwave', 'string', '') === '1';

        try {
            $received = $this->orders->bookInLines($id, $qtyByLine, $writeOffByLine);
            $units = 0;
            $variantIds = [];
            foreach ($received as $row) {
                $this->inventory->adjust(
                    (int) $row['variant_id'],
                    (int) $row['qty'],
                    'received',
                    'PO #' . $id,
                    $this->auth->id()
                );
                $units += (int) $row['qty'];
                $variantIds[] = (int) $row['variant_id'];
            }
            if ($units > 0) {
                $this->notify->success(
                    "Booked in {$units} unit" . ($units === 1 ? '' : 's') . ' into stock.'
                );
            } elseif ($writeOffByLine !== []) {
                $this->notify->success('Written-off quantities updated.');
            }

            if ($addToPickwave && $variantIds !== []) {
                $orderIds = $this->pickwaves->outstandingOrderIdsForVariants($variantIds);
                if ($orderIds === []) {
                    $this->notify->notice('No released orders waiting on these SKUs for a pickwave.');
                } else {
                    $picker = '';
                    $user = $this->auth->user();
                    if ($user !== null) {
                        $picker = method_exists($user, 'name')
                            ? (string) $user->name()
                            : (string) ($user->username ?? '');
                    }
                    $waveId = $this->pickwaves->create(
                        $orderIds,
                        $picker !== '' ? $picker : 'Book-in',
                        'Auto-created after booking in PO #' . $id,
                        $this->auth->id()
                    );
                    $n = count($orderIds);
                    $this->notify->success(
                        "Added {$n} order" . ($n === 1 ? '' : 's')
                        . " to pickwave #{$waveId}."
                    );
                    redirect_to('store/admin/pickwaves/view', ['id' => $waveId]);
                    return;
                }
            }
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            redirect_to('store/admin/purchase-orders/book-delivery', ['id' => $id]);
            return;
        }
        redirect_to('store/admin/purchase-orders/goods-received', ['id' => $id]);
    }

    public function splitAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $newId = $this->orders->splitByRemaining($id);
            $this->notify->success("Outstanding items moved to delivery #{$newId}.");
            redirect_to('store/admin/purchase-orders/book-delivery', ['id' => $newId]);
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            redirect_to('store/admin/purchase-orders/book-delivery', ['id' => $id]);
        }
    }

    public function receiveAllAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        redirect_to('store/admin/purchase-orders/book-delivery', ['id' => $id]);
    }

    public function bookInBulkAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/purchase-orders');
            return;
        }

        $ids = array_values(array_filter(
            array_map('intval', (array) $this->request->post('ids', 'raw', [])),
            static fn(int $id) => $id > 0
        ));
        if ($ids === []) {
            $this->notify->warning('Select at least one delivery with outstanding qty.');
            redirect_to('store/admin/purchase-orders');
            return;
        }

        $units = 0;
        $ok = 0;
        foreach ($ids as $id) {
            try {
                $units += $this->bookInOrder($id);
                $ok++;
            } catch (\Throwable $ex) {
                // continue other POs
            }
        }
        if ($ok > 0) {
            $this->notify->success(
                "Booked in {$units} unit" . ($units === 1 ? '' : 's')
                . " across {$ok} delivery" . ($ok === 1 ? '' : 's') . '.'
            );
        } else {
            $this->notify->error('Could not book in the selected deliveries.');
        }
        redirect_to('store/admin/purchase-orders');
    }

    public function goodsReceivedAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $po = $this->orders->find($id);
        if ($po === null) {
            throw new NotFoundException('Purchase order not found.');
        }
        $lines = array_values(array_filter(
            $this->orders->lines($id),
            static fn(array $l) => (int) ($l['qty_received'] ?? 0) > 0
        ));

        echo $this->view->inline(function () use ($po, $lines) {
            $this->view->assign('title', 'Goods Received #' . (int) $po['id']);
            $this->view->block('right_sidebar', 'Deliveries');
            $e = fn(string $s): string => $this->view->e($s);
            $id = (int) $po['id'];

            echo '<div class="mb-3"><a href="'
               . $e($this->url->to('store/admin/purchase-orders/view', ['id' => $id]))
               . '">&larr; Purchase order #' . $id . '</a></div>';
            echo '<h1 class="h3 mb-3">Goods Received — PO #' . $id . '</h1>';
            echo '<p class="text-muted">' . $e((string) $po['supplier_name'])
               . ' · ' . $e((string) ($po['warehouse'] ?? 'Default')) . '</p>';

            if ($lines === []) {
                echo '<div class="alert alert-info">No goods have been booked in yet.</div>';
                return;
            }

            echo '<div class="table-responsive border rounded bg-white"><table class="table table-sm mb-0">';
            echo '<thead class="table-light"><tr><th>SKU</th><th>Product</th>'
               . '<th class="text-end">Ordered</th><th class="text-end">Received</th></tr></thead><tbody>';
            foreach ($lines as $line) {
                echo '<tr>';
                echo '<td class="fw-semibold">' . $e((string) ($line['variant_sku'] ?? $line['sku'])) . '</td>';
                echo '<td>' . $e((string) ($line['product_name'] ?? '')) . '</td>';
                echo '<td class="text-end">' . (int) $line['qty_ordered'] . '</td>';
                echo '<td class="text-end">' . (int) $line['qty_received'] . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    public function bookingSheetAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $po = $this->orders->find($id);
        if ($po === null) {
            throw new NotFoundException('Purchase order not found.');
        }
        $lines = $this->orders->lines($id);

        echo $this->view->inline(function () use ($po, $lines) {
            $this->view->assign('title', 'Booking In Sheet #' . (int) $po['id']);
            $this->view->block('right_sidebar', 'Deliveries');
            $e = fn(string $s): string => $this->view->e($s);
            $id = (int) $po['id'];

            echo '<style>@media print{.btn,.alert{display:none!important}body{background:#fff}}</style>';
            echo '<div class="mb-3 d-print-none"><a href="'
               . $e($this->url->to('store/admin/purchase-orders/view', ['id' => $id]))
               . '">&larr; Purchase order #' . $id . '</a> ';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button></div>';

            echo '<h1 class="h3 mb-1">Suggested Booking In Sheet</h1>';
            echo '<p class="mb-3">PO #' . $id . ' · ' . $e((string) $po['supplier_name'])
               . ' · ' . $e((string) ($po['warehouse'] ?? 'Default'))
               . ' · ' . $e(PurchaseOrderRepository::statusLabel((string) $po['status'])) . '</p>';

            echo '<div class="table-responsive border rounded bg-white"><table class="table table-sm mb-0">';
            echo '<thead class="table-light"><tr><th>SKU</th><th>Product</th>'
               . '<th class="text-end">Ordered</th><th class="text-end">Received</th>'
               . '<th class="text-end">Outstanding</th><th>Tick</th></tr></thead><tbody>';
            foreach ($lines as $line) {
                $ordered = (int) $line['qty_ordered'];
                $received = (int) $line['qty_received'];
                $open = PurchaseOrderRepository::lineOutstanding($line);
                echo '<tr>';
                echo '<td class="fw-semibold">' . $e((string) ($line['variant_sku'] ?? $line['sku'])) . '</td>';
                echo '<td>' . $e((string) ($line['product_name'] ?? '')) . '</td>';
                echo '<td class="text-end">' . $ordered . '</td>';
                echo '<td class="text-end">' . $received . '</td>';
                echo '<td class="text-end">' . $open . '</td>';
                echo '<td style="width:3rem">☐</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    /** @return int units booked in */
    private function bookInOrder(int $id): int
    {
        $received = $this->orders->receiveAllOpen($id);
        $units = 0;
        foreach ($received as $row) {
            $this->inventory->adjust(
                (int) $row['variant_id'],
                (int) $row['qty'],
                'received',
                'PO #' . $id,
                $this->auth->id()
            );
            $units += (int) $row['qty'];
        }
        return $units;
    }
    private function raisedByName(): string
    {
        $user = $this->auth->user();
        if ($user === null) {
            return '';
        }
        if (method_exists($user, 'displayLabel')) {
            $label = trim((string) $user->displayLabel());
            if ($label !== '') {
                return $label;
            }
        }
        return trim((string) ($user->username ?? $user->displayName ?? ''));
    }

    /**
     * @param array<string, mixed> $po
     * @param list<array<string, mixed>> $lines
     * @param array{subtotal:float,shipping:float,vat:float,total:float,vat_rate:float} $money
     * @param list<array<string, mixed>> $supplierList
     * @param list<array<string, mixed>> $warehouseList
     * @param list<array<string, mixed>> $catalog
     */
    private function renderPoScreen(
        array $po,
        array $lines,
        array $money,
        array $supplierList,
        array $warehouseList,
        bool $isDraft,
        bool $isConfirmed,
        int $openTotal,
        array $catalog,
        string $pq,
    ): void {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $id = (int) $po['id'];
        $st = (string) $po['status'];
        $title = $isDraft ? 'Create Purchase Order' : ('Purchase Order #' . $id);
        $this->view->assign('title', $title);
        $this->view->block('right_sidebar', 'Deliveries');

        $warehouseJson = [];
        foreach ($warehouseList as $w) {
            $parsed = PurchaseOrderRepository::parseAddress((string) ($w['address'] ?? ''));
            $company = trim((string) ($w['company'] ?? ''));
            $warehouseJson[(string) $w['name']] = [
                'company'  => $company !== '' ? $company : (string) $w['name'],
                'address1' => $parsed['address1'],
                'address2' => $parsed['address2'],
                'address3' => $parsed['address3'],
                'city'     => $parsed['city'],
                'county'   => $parsed['county'],
                'country'  => $parsed['country'],
                'postcode' => $parsed['postcode'],
            ];
        }
        $supplierJson = [];
        foreach ($supplierList as $s) {
            $parsed = PurchaseOrderRepository::parseAddress((string) ($s['address'] ?? ''));
            $company = trim((string) ($s['company'] ?? ''));
            $supplierJson[(string) $s['name']] = [
                'company'   => $company !== '' ? $company : (string) $s['name'],
                'address1'  => $parsed['address1'],
                'address2'  => $parsed['address2'],
                'city'      => $parsed['city'],
                'county'    => $parsed['county'],
                'country'   => $parsed['country'],
                'postcode'  => $parsed['postcode'],
                'phone'     => (string) ($s['phone'] ?? ''),
                'email'     => (string) ($s['email'] ?? ''),
                'lead_time' => (string) (int) ($s['lead_time_days'] ?? 0),
                'min_order' => number_format((float) ($s['min_order_value'] ?? 0), 2, '.', ''),
                'code'      => (string) ($s['id'] ?? ''),
            ];
        }

        echo $this->poScreenStyles();

        echo '<div class="sf-po-page">';
        echo '<aside class="sf-po-rail">';
        echo '<div class="sf-po-rail-brand"><i class="bi bi-box-seam"></i><span>P.O.</span></div>';
        echo '<a class="sf-po-rail-link" href="' . $e($this->url->to('store/admin/purchase-orders'))
           . '">&lt;&lt; Back to Overview</a>';
        echo '<span class="sf-po-rail-link is-active">View Purchase Order</span>';
        echo '</aside>';

        echo '<div class="sf-po-main">';
        echo '<div class="sf-po-top">';
        echo '<h1>' . $e($title) . '</h1>';
        echo '<div class="d-flex flex-wrap gap-2 align-items-center">';
        if ($isDraft) {
            echo '<button type="submit" form="sf-po-edit-form" class="btn sf-po-save">Save</button>';
        } else {
            echo '<span class="badge text-bg-secondary">'
               . $e(PurchaseOrderRepository::statusLabel($st)) . '</span>';
        }
        echo '</div></div>';

        $formAction = $this->url->to('store/admin/purchase-orders/update', ['id' => $id]);
        if ($isDraft) {
            echo '<form method="post" action="' . $e($formAction) . '" id="sf-po-edit-form">';
            echo $this->request->csrfField();
        }

        echo '<div class="sf-po-grid">';
        echo $this->poInfoPanel($po, $isDraft);
        echo $this->poSupplierAddressPanel($po, $supplierList, $isDraft);
        echo $this->poDeliveryPanel($po, $warehouseList, $isDraft);
        echo $this->poSupplierDetailsPanel($po, $isDraft);
        echo '</div>';

        if ($isDraft) {
            echo '</form>';
        }

        echo $this->poActionsBar($po, $isDraft, $isConfirmed, $openTotal);
        echo $this->poTotalsBar($money);
        echo $this->poProductsSection($po, $lines, $catalog, $pq, $isDraft, $isConfirmed);

        echo '</div></div>';

        $payload = json_encode(
            ['warehouses' => $warehouseJson, 'suppliers' => $supplierJson],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        );
        $this->view->block('scripts', '<script>window.SF_PO_LOOKUPS=' . ($payload ?: '{}') . ';</script>'
            . $this->poScreenScript());
    }

    private function poScreenStyles(): string
    {
        return '<style>
.sf-po-page{display:grid;grid-template-columns:150px minmax(0,1fr);gap:1rem;align-items:start}
@media (max-width:991.98px){.sf-po-page{grid-template-columns:1fr}}
.sf-po-rail{position:sticky;top:.75rem}
.sf-po-rail-brand{display:flex;align-items:center;gap:.4rem;font-weight:600;margin-bottom:.75rem;color:#5b7c99}
.sf-po-rail-brand i{font-size:1.35rem}
.sf-po-rail-link{display:block;font-size:.8rem;padding:.35rem .5rem;border-radius:.25rem;color:#0d6efd;text-decoration:none}
.sf-po-rail-link.is-active{background:#fff;border:1px solid #cfd8e3;color:#333;font-weight:600}
.sf-po-top{display:flex;justify-content:space-between;align-items:center;gap:.75rem;margin-bottom:.85rem}
.sf-po-top h1{font-size:1.15rem;margin:0;font-weight:600;color:#4a5b6b}
.sf-po-save{background:#1f4e79;color:#fff;border:0;padding:.28rem 1.1rem;font-size:.85rem;border-radius:.25rem}
.sf-po-save:hover{background:#163a5c;color:#fff}
.sf-po-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.85rem}
@media (max-width:991.98px){.sf-po-grid{grid-template-columns:1fr}}
.sf-po-panel{border:1px solid #c5cdd6;border-radius:.2rem;overflow:hidden;background:#fff}
.sf-po-panel h2{margin:0;padding:.35rem .75rem;font-size:.8rem;font-weight:600;color:#fff}
.sf-po-panel.is-info h2{background:#5b7c99}
.sf-po-panel.is-info .sf-po-body{background:#d9e6f2}
.sf-po-panel.is-plain h2{background:#8a9198}
.sf-po-body{padding:.65rem .75rem .75rem}
.sf-po-row{display:grid;grid-template-columns:158px minmax(0,1fr);gap:.28rem .65rem;align-items:center;margin-bottom:.32rem}
.sf-po-row label{margin:0;font-size:.78rem;color:#333}
.sf-po-row .form-control,.sf-po-row .form-select{height:1.65rem;padding:.1rem .4rem;font-size:.8rem;border-radius:.2rem}
.sf-po-row textarea.form-control{height:auto;min-height:3.2rem}
.sf-po-cost{display:grid;grid-template-columns:158px minmax(0,1fr) auto;gap:.28rem .5rem;align-items:center;margin-bottom:.32rem}
.sf-po-cost label{margin:0;font-size:.78rem}
.sf-po-cost .form-control{height:1.65rem;padding:.1rem .4rem;font-size:.8rem}
.sf-po-static{font-size:.8rem;padding:.1rem 0}
.sf-po-actions{display:flex;flex-wrap:wrap;gap:.4rem;margin:0 0 .85rem}
.sf-po-totals{display:flex;flex-wrap:wrap;gap:1.25rem;font-size:.85rem;background:#f4f6f8;border:1px solid #d5dde5;padding:.5rem .75rem;margin-bottom:.85rem}
.sf-po-prod-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem}
.sf-po-prod-head h2{font-size:1rem;margin:0;font-weight:600;color:#4a5b6b}
.sf-po-hide{background:#5cb85c;color:#fff;border:0;font-size:.78rem;padding:.2rem .7rem;border-radius:.2rem}
.sf-po-picker{border:1px solid #c5cdd6;margin-bottom:0}
.sf-po-toolbar{display:flex;justify-content:space-between;align-items:center;gap:.5rem;background:#5b7c99;color:#fff;padding:.4rem .6rem}
.sf-po-add{background:#5cb85c;color:#fff;border:0;font-size:.8rem;padding:.25rem .7rem;border-radius:.2rem}
.sf-po-clear{background:#2f3b46;color:#fff;border:0;font-size:.75rem;padding:.2rem .55rem;border-radius:.2rem}
.sf-po-filter{display:flex;gap:.4rem;align-items:center;padding:.45rem .6rem;background:#eef2f5;border:1px solid #c5cdd6;border-bottom:0}
.sf-po-filter .form-control{height:1.7rem;font-size:.8rem}
</style>';
    }

    /** @param array<string, mixed> $po */
    private function poInfoPanel(array $po, bool $isDraft): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $ro = $isDraft ? '' : ' readonly';
        $shipping = number_format((float) ($po['shipping_cost'] ?? 0), 2, '.', '');
        $include = !empty($po['include_delivery_cost']) ? ' checked' : '';
        $html = '<section class="sf-po-panel is-info"><h2>Purchase Order Information</h2><div class="sf-po-body">';
        $html .= $this->poRow('Purchase Order Reference', '<input type="text" class="form-control" name="reference" maxlength="64" value="'
            . $e((string) ($po['reference'] ?? '')) . '"' . $ro . '>');
        $html .= '<div class="sf-po-cost"><label>Delivery Cost</label>'
            . '<div class="input-group input-group-sm"><span class="input-group-text">£</span>'
            . '<input type="number" class="form-control" name="shipping_cost" id="sf-po-shipping" min="0" step="0.01" value="'
            . $e($shipping) . '"' . $ro . '></div>'
            . '<input type="checkbox" name="include_delivery_cost" value="1" id="sf-po-include-cost" title="Include in total"'
            . $include . ($isDraft ? '' : ' disabled') . '></div>';
        $html .= $this->poRow('Due Date', '<input type="date" class="form-control" name="expected_date" value="'
            . $e((string) ($po['expected_date'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Date Sent', '<input type="date" class="form-control" name="date_sent" value="'
            . $e((string) ($po['date_sent'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Paid On', '<input type="date" class="form-control" name="paid_on" value="'
            . $e((string) ($po['paid_on'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Payment Reference', '<input type="text" class="form-control" name="payment_reference" maxlength="128" value="'
            . $e((string) ($po['payment_reference'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Payment Confirmed By', '<input type="text" class="form-control" name="payment_confirmed_by" maxlength="128" value="'
            . $e((string) ($po['payment_confirmed_by'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Status', '<div class="sf-po-static">'
            . $e(PurchaseOrderRepository::statusLabel((string) ($po['status'] ?? 'draft'))) . '</div>');
        $html .= $this->poRow('Raised By', '<div class="sf-po-static">'
            . $e((string) (($po['raised_by'] ?? '') !== '' ? $po['raised_by'] : '—')) . '</div>');
        $html .= $this->poRow('Created Via', '<div class="sf-po-static">'
            . $e((string) (($po['created_via'] ?? '') !== '' ? $po['created_via'] : 'Manual')) . '</div>');
        $html .= '<input type="hidden" name="vat_rate" id="sf-po-vat-rate" value="'
            . $e(number_format((float) ($po['vat_rate'] ?? 20), 2, '.', '')) . '">';
        $html .= '</div></section>';
        return $html;
    }

    /**
     * @param array<string, mixed> $po
     * @param list<array<string, mixed>> $supplierList
     */
    private function poSupplierAddressPanel(array $po, array $supplierList, bool $isDraft): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $ro = $isDraft ? '' : ' readonly';
        $current = (string) ($po['supplier_name'] ?? '');
        $html = '<section class="sf-po-panel is-plain"><h2>Supplier Address</h2><div class="sf-po-body">';
        $select = '<select class="form-select" name="supplier_name" id="sf-po-supplier"'
            . ($isDraft ? '' : ' disabled') . '>';
        $select .= '<option value="">Select supplier</option>';
        foreach ($supplierList as $s) {
            $name = (string) $s['name'];
            $sel = $current !== '' && strcasecmp($current, $name) === 0 ? ' selected' : '';
            $select .= '<option value="' . $e($name) . '"' . $sel . '>' . $e($name) . '</option>';
        }
        $select .= '</select>';
        if (!$isDraft) {
            $select .= '<input type="hidden" name="supplier_name" value="' . $e($current) . '">';
        }
        $html .= $this->poRow('Select Supplier', $select);
        $html .= $this->poRow('Company', '<input type="text" class="form-control" name="sup_company" id="sf-po-sup-company" maxlength="128" value="'
            . $e((string) ($po['sup_company'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Address 1', '<input type="text" class="form-control" name="sup_address1" id="sf-po-sup-address1" maxlength="255" value="'
            . $e((string) ($po['sup_address1'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Address 2', '<input type="text" class="form-control" name="sup_address2" id="sf-po-sup-address2" maxlength="255" value="'
            . $e((string) ($po['sup_address2'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('City', '<input type="text" class="form-control" name="sup_city" id="sf-po-sup-city" maxlength="128" value="'
            . $e((string) ($po['sup_city'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('County', '<input type="text" class="form-control" name="sup_county" id="sf-po-sup-county" maxlength="128" value="'
            . $e((string) ($po['sup_county'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Country', '<input type="text" class="form-control" name="sup_country" id="sf-po-sup-country" maxlength="128" value="'
            . $e((string) (($po['sup_country'] ?? '') !== '' ? $po['sup_country'] : 'UNITED KINGDOM')) . '"' . $ro . '>');
        $html .= $this->poRow('Postcode', '<input type="text" class="form-control" name="sup_postcode" id="sf-po-sup-postcode" maxlength="32" value="'
            . $e((string) ($po['sup_postcode'] ?? '')) . '"' . $ro . '>');
        $html .= '</div></section>';
        return $html;
    }

    /**
     * @param array<string, mixed> $po
     * @param list<array<string, mixed>> $warehouseList
     */
    private function poDeliveryPanel(array $po, array $warehouseList, bool $isDraft): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $ro = $isDraft ? '' : ' readonly';
        $current = (string) ($po['warehouse'] ?? 'Default');
        $html = '<section class="sf-po-panel is-plain"><h2>Delivery Address</h2><div class="sf-po-body">';
        $select = '<select class="form-select" name="warehouse" id="sf-po-warehouse"'
            . ($isDraft ? '' : ' disabled') . '>';
        foreach ($warehouseList as $w) {
            $name = (string) $w['name'];
            $sel = strcasecmp($current, $name) === 0 ? ' selected' : '';
            $select .= '<option value="' . $e($name) . '"' . $sel . '>' . $e($name) . '</option>';
        }
        $select .= '</select>';
        if (!$isDraft) {
            $select .= '<input type="hidden" name="warehouse" value="' . $e($current) . '">';
        }
        $html .= $this->poRow('Select Warehouse', $select);
        $html .= $this->poRow('Company', '<input type="text" class="form-control" name="del_company" id="sf-po-del-company" maxlength="128" value="'
            . $e((string) ($po['del_company'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Address 1', '<input type="text" class="form-control" name="del_address1" id="sf-po-del-address1" maxlength="255" value="'
            . $e((string) ($po['del_address1'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Address 2', '<input type="text" class="form-control" name="del_address2" id="sf-po-del-address2" maxlength="255" value="'
            . $e((string) ($po['del_address2'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Address 3', '<input type="text" class="form-control" name="del_address3" id="sf-po-del-address3" maxlength="255" value="'
            . $e((string) ($po['del_address3'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('City', '<input type="text" class="form-control" name="del_city" id="sf-po-del-city" maxlength="128" value="'
            . $e((string) ($po['del_city'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('County', '<input type="text" class="form-control" name="del_county" id="sf-po-del-county" maxlength="128" value="'
            . $e((string) ($po['del_county'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Country', '<input type="text" class="form-control" name="del_country" id="sf-po-del-country" maxlength="128" value="'
            . $e((string) (($po['del_country'] ?? '') !== '' ? $po['del_country'] : 'UNITED KINGDOM')) . '"' . $ro . '>');
        $html .= $this->poRow('Postcode', '<input type="text" class="form-control" name="del_postcode" id="sf-po-del-postcode" maxlength="32" value="'
            . $e((string) ($po['del_postcode'] ?? '')) . '"' . $ro . '>');
        $html .= '</div></section>';
        return $html;
    }

    /** @param array<string, mixed> $po */
    private function poSupplierDetailsPanel(array $po, bool $isDraft): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $ro = $isDraft ? '' : ' readonly';
        $change = (string) ($po['po_change_to_status'] ?? '');
        $html = '<section class="sf-po-panel is-plain"><h2>Supplier Details</h2><div class="sf-po-body">';
        $html .= $this->poRow('Reference', '<input type="text" class="form-control" name="supplier_reference" maxlength="64" value="'
            . $e((string) ($po['supplier_reference'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Supplier Code', '<input type="text" class="form-control" name="supplier_code" id="sf-po-sup-code" maxlength="64" value="'
            . $e((string) ($po['supplier_code'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Phone', '<input type="text" class="form-control" name="sup_phone" id="sf-po-sup-phone" maxlength="64" value="'
            . $e((string) ($po['sup_phone'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Email', '<input type="text" class="form-control" name="sup_email" id="sf-po-sup-email" maxlength="255" value="'
            . $e((string) ($po['sup_email'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Lead Time', '<input type="text" class="form-control" name="sup_lead_time" id="sf-po-sup-lead" maxlength="32" value="'
            . $e((string) ($po['sup_lead_time'] ?? '')) . '"' . $ro . '>');
        $html .= $this->poRow('Minimum Order Value', '<input type="number" class="form-control" name="sup_min_order_value" id="sf-po-sup-min" min="0" step="0.01" value="'
            . $e(number_format((float) ($po['sup_min_order_value'] ?? 0), 2, '.', '')) . '"' . $ro . '>');
        $opts = '<select class="form-select" name="po_change_to_status"' . ($isDraft ? '' : ' disabled') . '>';
        foreach (['' => '', 'draft' => 'Draft', 'confirmed' => 'Sent To Supplier'] as $val => $label) {
            $sel = $change === $val ? ' selected' : '';
            $opts .= '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
        }
        $opts .= '</select>';
        $html .= $this->poRow('Purchase Order Change To Status', $opts);
        $retained = !empty($po['retained_delivery']) ? ' checked' : '';
        $html .= '<div class="sf-po-cost"><label>Retained Delivery</label>'
            . '<input type="checkbox" name="retained_delivery" value="1"' . $retained . ($isDraft ? '' : ' disabled') . '>'
            . '<div class="d-flex align-items-center gap-2">'
            . '<span class="small text-nowrap">Estimated Pallet Count</span>'
            . '<input type="number" class="form-control" name="estimated_pallet_count" min="0" value="'
            . (int) ($po['estimated_pallet_count'] ?? 0) . '"' . $ro . ' style="max-width:5.5rem">'
            . '</div></div>';
        $html .= $this->poRow('Purchase Order Notes', '<textarea class="form-control" name="notes" rows="2" placeholder="Add any notes relating to this PO / Delivery"'
            . $ro . '>' . $e((string) ($po['notes'] ?? '')) . '</textarea>');
        $html .= '</div></section>';
        return $html;
    }

    /** @param array<string, mixed> $po */
    private function poActionsBar(array $po, bool $isDraft, bool $isConfirmed, int $openTotal): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $id = (int) $po['id'];
        $st = (string) $po['status'];
        $html = '<div class="sf-po-actions">';
        if ($isDraft) {
            $html .= '<form method="post" action="' . $e($this->url->to('store/admin/purchase-orders/confirm', ['id' => $id])) . '">'
                . $this->request->csrfField()
                . '<button type="submit" class="btn btn-sm btn-primary" onclick="return confirm(\'Confirm this purchase order? It will count toward On Purchase Order.\');">Confirm purchase order</button></form>';
        }
        if ($isConfirmed && $openTotal > 0) {
            $html .= '<a class="btn btn-sm btn-success" href="'
                . $e($this->url->to('store/admin/purchase-orders/book-delivery', ['id' => $id]))
                . '">Book In Outstanding (' . $openTotal . ')</a>';
        }
        $html .= '<a class="btn btn-sm btn-outline-secondary" href="'
            . $e($this->url->to('store/admin/purchase-orders/goods-received', ['id' => $id]))
            . '">View Goods Received</a>';
        $html .= '<a class="btn btn-sm btn-outline-secondary" href="'
            . $e($this->url->to('store/admin/purchase-orders/booking-sheet', ['id' => $id]))
            . '">Booking In Sheet</a>';
        if ($isDraft || $st === 'confirmed') {
            $html .= '<form method="post" action="' . $e($this->url->to('store/admin/purchase-orders/cancel', ['id' => $id])) . '">'
                . $this->request->csrfField()
                . '<button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'Cancel this purchase order?\');">Cancel</button></form>';
        }
        if ($isDraft || $st === 'cancelled') {
            $html .= '<form method="post" action="' . $e($this->url->to('store/admin/purchase-orders/delete', ['id' => $id])) . '">'
                . $this->request->csrfField()
                . '<button type="submit" class="btn btn-sm btn-outline-secondary" onclick="return confirm(\'Delete this purchase order?\');">Delete</button></form>';
        }
        $html .= '</div>';
        return $html;
    }

    /** @param array{subtotal:float,shipping:float,vat:float,total:float,vat_rate:float} $money */
    private function poTotalsBar(array $money): string
    {
        return '<div class="sf-po-totals" id="sf-po-totals" data-subtotal="'
            . number_format($money['subtotal'], 2, '.', '') . '">'
            . '<span>Sub Total <strong id="sf-po-subtotal">£ ' . number_format($money['subtotal'], 2) . '</strong></span>'
            . '<span>Shipping <strong id="sf-po-shipping-disp">£ ' . number_format($money['shipping'], 2) . '</strong></span>'
            . '<span>VAT (<span id="sf-po-vat-label">' . number_format($money['vat_rate'], 0) . '</span>%) '
            . '<strong id="sf-po-vat-disp">£ ' . number_format($money['vat'], 2) . '</strong></span>'
            . '<span>Purchase Order Total <strong id="sf-po-total-disp">£ ' . number_format($money['total'], 2) . '</strong></span>'
            . '</div>';
    }

    /**
     * @param array<string, mixed> $po
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $catalog
     */
    private function poProductsSection(
        array $po,
        array $lines,
        array $catalog,
        string $pq,
        bool $isDraft,
        bool $isConfirmed,
    ): string {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $id = (int) $po['id'];
        $html = '<div class="sf-po-prod-head"><h2>Products</h2>';
        if ($isDraft) {
            $html .= '<button type="button" class="sf-po-hide" id="sf-po-hide-btn">Hide Products</button>';
        }
        $html .= '</div>';

        if ($isDraft) {
            $filterUrl = $this->url->to('store/admin/purchase-orders/view', ['id' => $id]);
            $html .= '<div id="sf-po-picker-wrap">';
            $html .= '<form method="get" action="' . $e($filterUrl) . '" class="sf-po-filter">';
            $html .= '<input type="hidden" name="route" value="store/admin/purchase-orders/view">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<input type="search" class="form-control" name="pq" value="' . $e($pq)
                . '" placeholder="Filter SKU, name, barcode…" style="max-width:18rem">';
            $html .= '<button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>';
            $html .= '</form>';
            $html .= '<form method="post" action="'
                . $e($this->url->to('store/admin/purchase-orders/add-line', ['id' => $id]))
                . '" id="sf-po-add-selected">';
            $html .= $this->request->csrfField();
            $html .= '<div class="sf-po-picker table-responsive"><table class="table table-sm align-middle mb-0">';
            $html .= '<thead class="table-light"><tr><th style="width:2rem"></th><th>SKU</th><th>Product</th>'
                . '<th>Supplier SKU</th><th class="text-end">Stock</th><th class="text-end">Cost</th>'
                . '<th class="text-end" style="width:5.5rem">Qty</th></tr></thead><tbody>';
            if ($catalog === []) {
                $html .= '<tr><td colspan="7" class="text-muted">No products found'
                    . ((string) ($po['supplier_name'] ?? '') !== '' ? ' for this supplier' : '')
                    . '. Assign a supplier on products or clear the filter.</td></tr>';
            }
            foreach ($catalog as $row) {
                $vid = (int) $row['variant_id'];
                $pname = trim((string) ($row['product_name'] ?? ''));
                $vname = trim((string) ($row['variant_name'] ?? ''));
                $label = $pname !== '' ? $pname : $vname;
                if ($vname !== '' && $pname !== '' && $vname !== $pname) {
                    $label .= ' — ' . $vname;
                }
                $html .= '<tr class="sf-po-cat-row" data-supplier="'
                    . $e((string) ($row['supplier_name'] ?? '')) . '">';
                $html .= '<td><input type="checkbox" name="selected[]" value="' . $vid . '"></td>';
                $html .= '<td class="fw-semibold">' . $e((string) ($row['sku'] ?? '')) . '</td>';
                $html .= '<td>' . $e($label) . '</td>';
                $html .= '<td>' . $e((string) ($row['supplier_sku'] ?? '')) . '</td>';
                $html .= '<td class="text-end">' . (int) ($row['stock'] ?? 0) . '</td>';
                $html .= '<td class="text-end">£' . number_format((float) ($row['cost'] ?? 0), 2) . '</td>';
                $html .= '<td><input type="number" class="form-control form-control-sm text-end" name="qty['
                    . $vid . ']" min="1" value="1"></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
            $html .= '<div class="sf-po-toolbar">';
            $html .= '<button type="submit" class="sf-po-add">+ Add Selected Products</button>';
            $html .= '<div class="d-flex gap-2 align-items-center">';
            $html .= '<input type="text" class="form-control form-control-sm" name="sku" maxlength="64" placeholder="Or add by SKU" style="width:10rem">';
            $html .= '<input type="number" class="form-control form-control-sm" name="qty_sku" min="1" value="1" style="width:4.5rem" title="Qty">';
            $html .= '<a class="sf-po-clear" href="' . $e($filterUrl) . '">Clear Filtering</a>';
            $html .= '</div></div></form></div>';
        }

        $html .= '<div class="table-responsive border rounded bg-white mt-2">';
        $html .= '<table class="table table-sm align-middle mb-0">';
        $html .= '<thead class="table-light"><tr><th>SKU</th><th>Product</th><th class="text-end">Ordered</th>'
            . '<th class="text-end">Received</th><th class="text-end">Open</th>'
            . '<th class="text-end">Unit cost</th><th></th></tr></thead><tbody>';
        if ($lines === []) {
            $html .= '<tr><td colspan="7" class="text-muted">No lines yet.</td></tr>';
        }
        foreach ($lines as $line) {
            $lid = (int) $line['id'];
            $ordered = (int) $line['qty_ordered'];
            $received = (int) $line['qty_received'];
            $open = PurchaseOrderRepository::lineOutstanding($line);
            $sku = (string) ($line['variant_sku'] ?? $line['sku'] ?? '');
            $pname = trim((string) ($line['product_name'] ?? ''));
            $vname = trim((string) ($line['variant_name'] ?? ''));
            $label = $pname !== '' ? $pname : $vname;
            if ($vname !== '' && $pname !== '' && $vname !== $pname) {
                $label .= ' — ' . $vname;
            }
            $html .= '<tr><td class="fw-semibold">' . $e($sku) . '</td><td>' . $e($label) . '</td>'
                . '<td class="text-end">' . $ordered . '</td><td class="text-end">' . $received . '</td>'
                . '<td class="text-end">' . $open . '</td><td class="text-end">£'
                . number_format((float) $line['unit_cost'], 2) . '</td><td class="text-end text-nowrap">';
            if ($isDraft) {
                $html .= '<form method="post" class="d-inline" action="'
                    . $e($this->url->to('store/admin/purchase-orders/delete-line', [
                        'id' => $id,
                        'line_id' => $lid,
                    ])) . '" onsubmit="return confirm(\'Remove this line?\');">'
                    . $this->request->csrfField()
                    . '<button type="submit" class="btn btn-sm btn-outline-danger">Remove</button></form>';
            }
            if ($isConfirmed && $open > 0) {
                $html .= '<form method="post" class="d-inline-flex align-items-center gap-1 ms-1" action="'
                    . $e($this->url->to('store/admin/purchase-orders/receive-line', [
                        'id' => $id,
                        'line_id' => $lid,
                    ])) . '">'
                    . $this->request->csrfField()
                    . '<input type="number" class="form-control form-control-sm" name="qty" min="1" max="'
                    . $open . '" value="' . $open . '" style="width:5rem">'
                    . '<button type="submit" class="btn btn-sm btn-success">Receive</button></form>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    private function poRow(string $label, string $control): string
    {
        $e = fn(string $s): string => $this->view->e($s);
        return '<div class="sf-po-row"><label>' . $e($label) . '</label><div>' . $control . '</div></div>';
    }

    private function poScreenScript(): string
    {
        return <<<'JS'
<script>
(function () {
    const lookups = window.SF_PO_LOOKUPS || {warehouses: {}, suppliers: {}};
    function fill(prefix, data, map) {
        if (!data) return;
        Object.keys(map).forEach(function (key) {
            const el = document.getElementById(prefix + map[key]);
            if (el) el.value = data[key] || '';
        });
    }
    const warehouseSel = document.getElementById('sf-po-warehouse');
    if (warehouseSel) {
        warehouseSel.addEventListener('change', function () {
            fill('sf-po-del-', lookups.warehouses[this.value], {
                company: 'company', address1: 'address1', address2: 'address2', address3: 'address3',
                city: 'city', county: 'county', country: 'country', postcode: 'postcode'
            });
        });
    }
    const supplierSel = document.getElementById('sf-po-supplier');
    if (supplierSel) {
        supplierSel.addEventListener('change', function () {
            const data = lookups.suppliers[this.value] || {};
            fill('sf-po-sup-', data, {
                company: 'company', address1: 'address1', address2: 'address2',
                city: 'city', county: 'county', country: 'country', postcode: 'postcode',
                phone: 'phone', email: 'email', lead_time: 'lead', min_order: 'min'
            });
            const code = document.getElementById('sf-po-sup-code');
            if (code) code.value = data.code || '';
            const name = this.value;
            document.querySelectorAll('.sf-po-cat-row').forEach(function (row) {
                const sup = row.getAttribute('data-supplier') || '';
                row.style.display = (!name || sup === name) ? '' : 'none';
            });
        });
    }
    const hideBtn = document.getElementById('sf-po-hide-btn');
    const picker = document.getElementById('sf-po-picker-wrap');
    if (hideBtn && picker) {
        hideBtn.addEventListener('click', function () {
            const hidden = picker.style.display === 'none';
            picker.style.display = hidden ? '' : 'none';
            hideBtn.textContent = hidden ? 'Hide Products' : 'Show Products';
        });
    }
    const totals = document.getElementById('sf-po-totals');
    const shipInp = document.getElementById('sf-po-shipping');
    const vatInp = document.getElementById('sf-po-vat-rate');
    const includeInp = document.getElementById('sf-po-include-cost');
    function money(n) { return '£ ' + (Math.round(n * 100) / 100).toFixed(2); }
    function refresh() {
        if (!totals || !shipInp) return;
        const subtotal = parseFloat(totals.getAttribute('data-subtotal') || '0') || 0;
        const include = !includeInp || includeInp.checked;
        const shipping = include ? Math.max(0, parseFloat(shipInp.value || '0') || 0) : 0;
        const rate = Math.max(0, Math.min(100, parseFloat((vatInp && vatInp.value) || '20') || 0));
        const vat = Math.round((subtotal + shipping) * (rate / 100) * 100) / 100;
        const total = Math.round((subtotal + shipping + vat) * 100) / 100;
        const shipDisp = document.getElementById('sf-po-shipping-disp');
        const vatDisp = document.getElementById('sf-po-vat-disp');
        const vatLabel = document.getElementById('sf-po-vat-label');
        const totalDisp = document.getElementById('sf-po-total-disp');
        if (shipDisp) shipDisp.textContent = money(shipping);
        if (vatDisp) vatDisp.textContent = money(vat);
        if (vatLabel) vatLabel.textContent = String(Math.round(rate));
        if (totalDisp) totalDisp.textContent = money(total);
    }
    if (shipInp) {
        shipInp.addEventListener('input', function () {
            if (includeInp && parseFloat(shipInp.value || '0') > 0) includeInp.checked = true;
            refresh();
        });
    }
    if (includeInp) includeInp.addEventListener('change', refresh);
    refresh();
})();
</script>
JS;
    }

    /** @return array<string, mixed> */
    private function postedPoExtra(): array
    {
        return [
            'date_sent'              => trim((string) $this->request->post('date_sent', 'string', '')),
            'paid_on'                => trim((string) $this->request->post('paid_on', 'string', '')),
            'payment_reference'      => (string) $this->request->post('payment_reference', 'string', ''),
            'payment_confirmed_by'   => (string) $this->request->post('payment_confirmed_by', 'string', ''),
            'include_delivery_cost'  => (int) $this->request->post('include_delivery_cost', 'int', 0) === 1,
            'retained_delivery'      => (int) $this->request->post('retained_delivery', 'int', 0) === 1,
            'estimated_pallet_count' => (int) $this->request->post('estimated_pallet_count', 'int', 0),
            'po_change_to_status'    => (string) $this->request->post('po_change_to_status', 'string', ''),
            'supplier_code'          => (string) $this->request->post('supplier_code', 'string', ''),
            'supplier_reference'     => (string) $this->request->post('supplier_reference', 'string', ''),
            'del_company'            => (string) $this->request->post('del_company', 'string', ''),
            'del_address1'           => (string) $this->request->post('del_address1', 'string', ''),
            'del_address2'           => (string) $this->request->post('del_address2', 'string', ''),
            'del_address3'           => (string) $this->request->post('del_address3', 'string', ''),
            'del_city'               => (string) $this->request->post('del_city', 'string', ''),
            'del_county'             => (string) $this->request->post('del_county', 'string', ''),
            'del_country'            => (string) $this->request->post('del_country', 'string', ''),
            'del_postcode'           => (string) $this->request->post('del_postcode', 'string', ''),
            'sup_company'            => (string) $this->request->post('sup_company', 'string', ''),
            'sup_address1'           => (string) $this->request->post('sup_address1', 'string', ''),
            'sup_address2'           => (string) $this->request->post('sup_address2', 'string', ''),
            'sup_city'               => (string) $this->request->post('sup_city', 'string', ''),
            'sup_county'             => (string) $this->request->post('sup_county', 'string', ''),
            'sup_country'            => (string) $this->request->post('sup_country', 'string', ''),
            'sup_postcode'           => (string) $this->request->post('sup_postcode', 'string', ''),
            'sup_phone'              => (string) $this->request->post('sup_phone', 'string', ''),
            'sup_email'              => (string) $this->request->post('sup_email', 'string', ''),
            'sup_lead_time'          => (string) $this->request->post('sup_lead_time', 'string', ''),
            'sup_min_order_value'    => (float) $this->request->post('sup_min_order_value', 'float', 0),
        ];
    }


}
