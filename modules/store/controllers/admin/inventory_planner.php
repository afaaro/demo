<?php
declare(strict_types=1);

use Module\Store\Services\InventoryPlannerService;
use Module\Store\Services\InventoryService;
use Module\Store\Services\ProductRepository;
use Module\Store\Services\PurchaseOrderRepository;
use Module\Store\Services\SupplierRepository;
use Module\Store\Services\VariantRepository;
use Module\Store\Services\WarehouseRepository;
use System\Engine\Controller;

class StoreAdminInventoryPlanner extends Controller
{
    private InventoryPlannerService $planner;
    private PurchaseOrderRepository $purchaseOrders;
    private SupplierRepository $suppliers;
    private WarehouseRepository $warehouses;
    private VariantRepository $variants;
    private ProductRepository $products;
    private InventoryService $inventory;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->planner = new InventoryPlannerService($this->db);
        $this->purchaseOrders = new PurchaseOrderRepository($this->db);
        $this->suppliers = new SupplierRepository($this->db);
        $this->warehouses = new WarehouseRepository($this->db);
        $this->variants = new VariantRepository($this->db);
        $this->products = new ProductRepository($this->db);
        $this->inventory = new InventoryService($this->db);
    }

    public function indexAction(): void
    {
        $filters = [
            'q'         => trim((string) $this->request->get('q', 'string', '')),
            'supplier'  => trim((string) $this->request->get('supplier', 'string', '')),
            'warehouse' => trim((string) $this->request->get('warehouse', 'string', '')),
        ];

        $plan = $this->planner->build($filters);
        $supplierNames = array_map(
            static fn(array $s): string => (string) $s['name'],
            $this->suppliers->all('', true)
        );
        $warehouses = $this->inventory->warehousesInUse();

        echo $this->view->inline(function () use ($plan, $filters, $supplierNames, $warehouses) {
            $this->view->assign('title', 'Inventory Planner');
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $groups = $plan['groups'];
            $summary = $plan['summary'];

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1">Inventory Planner</h1>'
               . '<p class="text-muted small mb-0">Suggested replenishment by supplier (requisitions). '
               . 'Set each supplier&rsquo;s purchase order mode under Suppliers.</p></div>';
            echo '<div class="d-flex flex-wrap gap-2">'
               . '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/suppliers')) . '">Suppliers</a>'
               . '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/inventory/low')) . '">Low stock</a>'
               . '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/purchase-orders')) . '">Deliveries</a>'
               . '</div></div>';

            echo '<form method="get" class="row g-2 align-items-end mb-3">';
            echo '<div class="col-md-3"><label class="form-label small mb-0">Search</label>'
               . '<input type="search" name="q" class="form-control form-control-sm" value="'
               . $e($filters['q']) . '" placeholder="SKU or name"></div>';
            echo '<div class="col-md-3"><label class="form-label small mb-0">Supplier</label>'
               . '<select name="supplier" class="form-select form-select-sm"><option value="">All</option>';
            foreach ($supplierNames as $name) {
                $sel = $filters['supplier'] === $name ? ' selected' : '';
                echo '<option value="' . $e($name) . '"' . $sel . '>' . $e($name) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-3"><label class="form-label small mb-0">Warehouse</label>'
               . '<select name="warehouse" class="form-select form-select-sm"><option value="">All</option>';
            foreach ($warehouses as $wh) {
                $sel = $filters['warehouse'] === $wh ? ' selected' : '';
                echo '<option value="' . $e($wh) . '"' . $sel . '>' . $e($wh) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-3 d-flex gap-2">'
               . '<button type="submit" class="btn btn-sm btn-primary">Filter</button>'
               . '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/inventory/planner')) . '">Clear</a>'
               . '</div></form>';

            echo '<div class="row g-2 mb-3">';
            foreach ([
                ['Suppliers', (string) $summary['suppliers']],
                ['SKUs', (string) $summary['products']],
                ['Units', (string) $summary['units']],
                ['Subtotal', number_format((float) $summary['subtotal'], 2)],
            ] as [$label, $val]) {
                echo '<div class="col-6 col-md-3"><div class="border rounded bg-white p-2">'
                   . '<div class="text-muted small">' . $e($label) . '</div>'
                   . '<div class="fs-5 fw-semibold">' . $e($val) . '</div></div></div>';
            }
            echo '</div>';

            if ($groups === []) {
                echo '<div class="alert alert-success mb-0">No requisitions right now. '
                   . 'Enable <em>Include in Inventory Planner</em> on suppliers, assign products, '
                   . 'and set reorder levels / PO mode.</div>';
                return;
            }

            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/inventory/planner/raise'))
               . '" id="sf-planner-form">';
            echo $this->request->csrfField();
            echo '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">';
            echo '<button type="submit" class="btn btn-success btn-sm" id="sf-planner-raise" disabled>'
               . 'Raise selected purchase orders</button>';
            echo '<span class="small text-muted" id="sf-planner-count">0 groups selected</span>';
            echo '</div>';

            foreach ($groups as $g) {
                $sid = (int) $g['supplier_id'];
                $warn = !$g['meets_min_order']
                    ? '<span class="badge text-bg-warning ms-1">Below min order '
                      . $e(number_format((float) $g['min_order_value'], 2)) . '</span>'
                    : '';

                echo '<div class="card border-0 shadow-sm mb-3">';
                echo '<div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">';
                echo '<input type="checkbox" class="form-check-input sf-planner-group" name="supplier_ids[]" value="'
                   . $sid . '" id="sf-sup-' . $sid . '">';
                echo '<label class="fw-semibold mb-0" for="sf-sup-' . $sid . '">'
                   . $e((string) $g['supplier']) . '</label>';
                echo '<span class="badge text-bg-light">' . $e((string) $g['po_mode_label']) . '</span>';
                echo $warn;
                echo '<span class="ms-auto small text-muted">'
                   . (int) $g['product_count'] . ' SKUs · '
                   . (int) $g['unit_count'] . ' units · '
                   . $e((string) $g['currency']) . ' '
                   . $e(number_format((float) $g['subtotal'], 2))
                   . '</span></div>';

                echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
                echo '<thead class="table-light"><tr>'
                   . '<th>Product</th><th>SKU</th><th>Warehouse</th>'
                   . '<th class="text-end">Avail</th><th class="text-end">On PO</th>'
                   . '<th class="text-end">Reorder</th><th>Reason</th>'
                   . '<th class="text-end">Order qty</th><th class="text-end">Line</th>'
                   . '</tr></thead><tbody>';

                foreach ($g['lines'] as $line) {
                    $vid = (int) $line['variant_id'];
                    echo '<tr>';
                    echo '<td>' . $e((string) $line['product_name']);
                    if ((string) $line['variant_name'] !== '') {
                        echo '<div class="small text-muted">' . $e((string) $line['variant_name']) . '</div>';
                    }
                    echo '</td>';
                    echo '<td><code>' . $e((string) $line['variant_sku']) . '</code></td>';
                    echo '<td>' . $e((string) $line['warehouse']) . '</td>';
                    echo '<td class="text-end">' . (int) $line['available'] . '</td>';
                    echo '<td class="text-end">' . (int) $line['on_po'] . '</td>';
                    $reorderShow = $g['po_mode'] === InventoryPlannerService::MODE_CALCULATED
                        ? (int) $line['calculated_reorder']
                        : (int) $line['reorder_at'];
                    echo '<td class="text-end">' . $reorderShow . '</td>';
                    echo '<td class="small text-muted">' . $e((string) $line['reason']) . '</td>';
                    echo '<td class="text-end"><input type="number" min="1" class="form-control form-control-sm text-end"'
                       . ' name="qty[' . $vid . ']" value="' . (int) $line['suggested_qty']
                       . '" style="width:5.5rem"></td>';
                    echo '<td class="text-end small">'
                       . $e(number_format((float) $line['line_total'], 2)) . '</td>';
                    echo '</tr>';
                    // Hidden marker so raise knows which variants belong to selected suppliers.
                    echo '<input type="hidden" name="line_supplier[' . $vid . ']" value="' . $sid . '">';
                }
                echo '</tbody></table></div></div>';
            }
            echo '</form>';

            $this->view->block('scripts', <<<'JS'
            <script>
            (function () {
                const btn = document.getElementById('sf-planner-raise');
                const count = document.getElementById('sf-planner-count');
                const groups = () => Array.from(document.querySelectorAll('.sf-planner-group'));
                function sync() {
                    const n = groups().filter(c => c.checked).length;
                    if (btn) btn.disabled = n === 0;
                    if (count) count.textContent = n + ' group' + (n === 1 ? '' : 's') + ' selected';
                }
                document.addEventListener('change', function (e) {
                    if (e.target.classList && e.target.classList.contains('sf-planner-group')) sync();
                });
                sync();
            })();
            </script>
            JS);
        });
    }

    public function raiseAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/inventory/planner');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('store/admin/inventory/planner');
            return;
        }

        $supplierIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->request->post('supplier_ids', 'raw', [])),
            static fn(int $id) => $id > 0
        )));
        $qtys = (array) $this->request->post('qty', 'raw', []);
        $lineSupplier = (array) $this->request->post('line_supplier', 'raw', []);

        if ($supplierIds === []) {
            $this->notify->warning('Select at least one supplier group.');
            redirect_to('store/admin/inventory/planner');
            return;
        }

        $raisedBy = '';
        $user = $this->auth->user();
        if ($user !== null) {
            $raisedBy = method_exists($user, 'name')
                ? (string) $user->name()
                : (string) ($user->username ?? '');
        }

        /** @var array<int, list<array{variant_id:int, qty:int, warehouse:string, cost:float, sku:string}>> $bySupplier */
        $bySupplier = [];

        foreach ($lineSupplier as $vidRaw => $sidRaw) {
            $vid = (int) $vidRaw;
            $sid = (int) $sidRaw;
            if ($vid <= 0 || !in_array($sid, $supplierIds, true)) {
                continue;
            }
            $variant = $this->variants->find($vid);
            if ($variant === null) {
                continue;
            }
            $product = $this->products->find($variant->productId);
            if ($product === null) {
                continue;
            }
            $qty = max(1, (int) ($qtys[$vid] ?? $qtys[(string) $vid] ?? 1));
            $warehouse = trim($product->warehouse) !== '' ? $product->warehouse : 'Default';
            $bySupplier[$sid][] = [
                'variant_id' => $vid,
                'qty'        => $qty,
                'warehouse'  => $warehouse,
                'cost'       => (float) $variant->cost,
                'sku'        => $variant->sku,
            ];
        }

        if ($bySupplier === []) {
            $this->notify->error('No lines found for the selected groups.');
            redirect_to('store/admin/inventory/planner');
            return;
        }

        $createdIds = [];
        try {
            foreach ($bySupplier as $sid => $items) {
                $supplier = $this->suppliers->find((int) $sid);
                if ($supplier === null) {
                    continue;
                }
                $warehouse = $this->warehouses->ensure($items[0]['warehouse'] ?? 'Default');
                $poId = $this->purchaseOrders->create(
                    (string) $supplier['name'],
                    'Planner',
                    'Created from Inventory Planner',
                    null,
                    $warehouse,
                    $raisedBy,
                    0.0,
                    20.0,
                    ['created_via' => 'Inventory Planner']
                );
                foreach ($items as $item) {
                    $this->purchaseOrders->addLine(
                        $poId,
                        (int) $item['variant_id'],
                        (int) $item['qty'],
                        (float) $item['cost'],
                        (string) $item['sku']
                    );
                }
                $createdIds[] = $poId;
            }
        } catch (\Throwable $ex) {
            $this->notify->error('Could not raise purchase orders: ' . $ex->getMessage());
            redirect_to('store/admin/inventory/planner');
            return;
        }

        $n = count($createdIds);
        $this->notify->success(
            "Raised {$n} draft purchase order" . ($n === 1 ? '' : 's') . ' from the planner.'
        );
        if ($n === 1) {
            redirect_to('store/admin/purchase-orders/view', ['id' => $createdIds[0]]);
            return;
        }
        redirect_to('store/admin/purchase-orders', ['status' => 'draft']);
    }
}
