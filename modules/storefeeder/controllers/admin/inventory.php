<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\ChannelClientFactory;
use Module\StoreFeeder\Services\ChannelRepository;
use Module\StoreFeeder\Services\ChannelStockPublisher;
use Module\StoreFeeder\Services\CredentialsVault;
use Module\StoreFeeder\Services\InventoryService;
use Module\StoreFeeder\Services\ListingRepository;
use Module\StoreFeeder\Services\ProductRepository;
use Module\StoreFeeder\Services\PurchaseOrderRepository;
use Module\StoreFeeder\Services\SupplierRepository;
use Module\StoreFeeder\Services\TenantContext;
use Module\StoreFeeder\Services\Variant;
use Module\StoreFeeder\Services\VariantRepository;
use Module\StoreFeeder\Services\WarehouseRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Paginator;

class StoreFeederAdminInventory extends Controller
{
    private ProductRepository $products;
    private VariantRepository $variants;
    private InventoryService  $inventory;
    private PurchaseOrderRepository $purchaseOrders;
    private SupplierRepository $suppliers;
    private WarehouseRepository $warehouses;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->products  = new ProductRepository($this->db);
        $this->variants  = new VariantRepository($this->db);
        $this->purchaseOrders = new PurchaseOrderRepository($this->db);
        $this->suppliers = new SupplierRepository($this->db);
        $this->warehouses = new WarehouseRepository($this->db);

        $channels  = new ChannelRepository($this->db);
        $vault     = CredentialsVault::forTenant(
            (string) $this->config->get('storefeeder.credentials_key', '')
        );
        $publisher = new ChannelStockPublisher(
            $channels,
            new ChannelClientFactory($vault, $channels),
            new ListingRepository($this->db),
        );
        $this->inventory = new InventoryService($this->db, $publisher);
        // Permissions: Action.php only.
    }

    // =========================================================================
    // Overview — stock grid
    // =========================================================================

    public function indexAction(): void
    {
        $page    = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $filters = [
            'q'         => trim((string) $this->request->get('q', 'string', '')),
            'stock'     => trim((string) $this->request->get('stock', 'string', '')),
            'warehouse' => trim((string) $this->request->get('warehouse', 'string', '')),
            'active'    => trim((string) $this->request->get('active', 'string', '1')),
        ];

        $result     = $this->inventory->paginateStock($filters, $perPage, $offset);
        $rows       = $result['rows'];
        $total      = $result['total'];
        $warehouses = $this->inventory->warehousesInUse();

        $tid = TenantContext::get()->has() ? (int) TenantContext::get()->id() : 0;
        if ($tid <= 0) {
            $totalProducts = 0;
            $totalVariants = 0;
            $lowCount      = 0;
            $outCount      = 0;
            $stockValue    = 0.0;
        } else {
            $totalProducts = (int) $this->db->query(
                'SELECT COUNT(*) FROM `#__sf_products` WHERE `tenant_id` = ?',
                [$tid]
            )->value;
            $totalVariants = (int) $this->db->query(
                'SELECT COUNT(*) FROM `#__sf_product_variants` WHERE `tenant_id` = ?',
                [$tid]
            )->value;
            $lowCount = count($this->inventory->lowStock(1000));
            $outCount = (int) $this->db->query(
                'SELECT COUNT(*) FROM `#__sf_inventory` i
                   JOIN `#__sf_product_variants` v
                     ON v.`id` = i.`variant_id` AND v.`tenant_id` = i.`tenant_id`
                   JOIN `#__sf_products` p
                     ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
                  WHERE (i.`quantity` - i.`reserved`) <= 0 AND p.`tenant_id` = ?',
                [$tid]
            )->value;
            $stockValue = (float) $this->db->query(
                'SELECT COALESCE(SUM(GREATEST(i.`quantity` - i.`reserved`, 0) * v.`cost`), 0)
                   FROM `#__sf_inventory` i
                   JOIN `#__sf_product_variants` v
                     ON v.`id` = i.`variant_id` AND v.`tenant_id` = i.`tenant_id`
                   JOIN `#__sf_products` p
                     ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
                  WHERE p.`tenant_id` = ?',
                [$tid]
            )->value;
        }

        echo $this->view->inline(function () use (
            $rows, $total, $page, $perPage, $filters, $warehouses,
            $totalProducts, $totalVariants, $lowCount, $outCount, $stockValue
        ) {
            $this->view->assign('title', 'Inventory');
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">';
            echo '<div>';
            echo '<h1 class="h3 mb-1">Inventory</h1>';
            echo '<p class="text-muted small mb-0">Search stock, filter low/out SKUs, and jump to adjust.</p>';
            echo '</div>';
            echo '<div class="d-flex flex-wrap gap-2">';
            echo '<a class="btn btn-sm btn-primary" href="'
               . $e($this->url->to('storefeeder/admin/inventory/planner')) . '">Inventory Planner</a>';
            echo '<a class="btn btn-sm btn-outline-warning" href="'
               . $e($this->url->to('storefeeder/admin/inventory/low')) . '">Low stock</a>';
            echo '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('storefeeder/admin/inventory/bulk')) . '">Bulk adjust</a>';
            echo '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('storefeeder/admin/products')) . '">Products</a>';
            echo '</div></div>';

            echo '<div class="row g-3 mb-4">';
            $cards = [
                ['Products', $totalProducts, 'box', $this->url->to('storefeeder/admin/products')],
                ['Variants', $totalVariants, 'boxes', $this->url->to('storefeeder/admin/inventory', ['active' => '1'])],
                ['Low stock', $lowCount, 'exclamation-triangle', $this->url->to('storefeeder/admin/inventory', ['stock' => 'low'])],
                ['Out of stock', $outCount, 'x-circle', $this->url->to('storefeeder/admin/inventory', ['stock' => 'out'])],
                ['Stock value', '£' . number_format($stockValue, 2), 'currency-pound', null],
            ];
            foreach ($cards as [$label, $value, $icon, $url]) {
                echo '<div class="col-6 col-md">';
                $inner = '<div class="border rounded p-3 h-100 bg-body-tertiary">'
                    . '<div class="text-muted small"><i class="bi bi-' . $icon . ' me-1"></i>'
                    . $e($label) . '</div>'
                    . '<div class="h4 mb-0">' . $e((string) $value) . '</div></div>';
                echo $url
                    ? '<a href="' . $e($url) . '" class="text-decoration-none text-body">' . $inner . '</a>'
                    : $inner;
                echo '</div>';
            }
            echo '</div>';

            echo '<form method="get" action="' . $e($this->url->to('storefeeder/admin/inventory')) . '"'
               . ' class="row g-2 align-items-end mb-3">';
            echo '<div class="col-md-3">'
               . '<label class="form-label small mb-1">Search</label>'
               . '<input type="search" name="q" class="form-control" value="' . $e($filters['q']) . '"'
               . ' placeholder="SKU, product, variant…"></div>';
            echo '<div class="col-md-2"><label class="form-label small mb-1">Stock</label>'
               . '<select name="stock" class="form-select">';
            foreach ([
                '' => 'All levels', 'in' => 'Healthy', 'low' => 'Low stock',
                'out' => 'Out of stock', 'zero_reorder' => 'No reorder point',
            ] as $val => $label) {
                $sel = $filters['stock'] === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-2"><label class="form-label small mb-1">Warehouse</label>'
               . '<select name="warehouse" class="form-select">'
               . '<option value="">All warehouses</option>';
            foreach ($warehouses as $wh) {
                $sel = $filters['warehouse'] === $wh ? ' selected' : '';
                echo '<option value="' . $e($wh) . '"' . $sel . '>' . $e($wh) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-2"><label class="form-label small mb-1">Active</label>'
               . '<select name="active" class="form-select">';
            foreach (['1' => 'Active only', '0' => 'Inactive', '' => 'All'] as $val => $label) {
                $sel = $filters['active'] === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-auto">'
               . '<button type="submit" class="btn btn-outline-secondary">Apply</button> ';
            if ($filters['q'] !== '' || $filters['stock'] !== '' || $filters['warehouse'] !== '' || $filters['active'] !== '1') {
                echo '<a class="btn btn-outline-secondary" href="'
                   . $e($this->url->to('storefeeder/admin/inventory')) . '">Reset</a>';
            }
            echo '</div></form>';

            echo '<div class="small text-muted mb-2">' . (int) $total . ' variant(s)</div>';

            if ($rows === []) {
                echo '<div class="alert alert-info mb-0">No stock rows match your filters.</div>';
                return;
            }

            echo '<div class="table-responsive border rounded">';
            echo '<table class="table table-sm table-hover align-middle mb-0">';
            echo '<thead class="table-light"><tr>'
               . '<th>Product</th><th>Variant / SKU</th><th>Warehouse</th>'
               . '<th class="text-end">Qty</th><th class="text-end">Reserved</th>'
               . '<th class="text-end">Available</th><th class="text-end">Reorder</th>'
               . '<th class="text-end">Supplier inv.</th><th></th>'
               . '</tr></thead><tbody>';

            foreach ($rows as $r) {
                $rowClass = $r['is_out'] ? 'table-danger' : ($r['is_low'] ? 'table-warning' : '');
                $variantLabel = $r['variant_name'] !== '' ? $r['variant_name'] : '—';
                echo '<tr class="' . $rowClass . '">';
                echo '<td><a class="fw-semibold text-decoration-none" href="'
                   . $e($this->url->to('storefeeder/admin/products/edit', ['id' => $r['product_id']])) . '">'
                   . $e($r['product_name']) . '</a>'
                   . '<div class="small text-muted"><code>' . $e($r['product_sku']) . '</code></div></td>';
                echo '<td>' . $e($variantLabel)
                   . '<div class="small"><code>' . $e($r['variant_sku']) . '</code></div></td>';
                echo '<td class="small">' . $e($r['warehouse']);
                if ($r['supplier'] !== '') {
                    echo '<div class="text-muted">' . $e($r['supplier']) . '</div>';
                }
                echo '</td>';
                echo '<td class="text-end">' . (int) $r['quantity'] . '</td>';
                echo '<td class="text-end">' . (int) $r['reserved'] . '</td>';
                echo '<td class="text-end fw-semibold">' . (int) $r['available'];
                if ($r['is_out']) {
                    echo ' <span class="badge text-bg-danger">Out</span>';
                } elseif ($r['is_low']) {
                    echo ' <span class="badge text-bg-warning">Low</span>';
                }
                echo '</td>';
                echo '<td class="text-end">' . (int) $r['reorder_at'] . '</td>';
                echo '<td class="text-end">'
                   . ($r['supplier_inventory'] !== null ? (int) $r['supplier_inventory'] : '—')
                   . '</td>';
                echo '<td class="text-end text-nowrap">'
                   . '<a class="btn btn-sm btn-outline-primary" href="'
                   . $e($this->url->to('storefeeder/admin/inventory/adjust', ['id' => $r['variant_id']]))
                   . '">Adjust</a> '
                   . '<a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($this->url->to('storefeeder/admin/inventory/product', ['id' => $r['product_id']]))
                   . '">Product</a>'
                   . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';

            $base = array_filter([
                'q'         => $filters['q'],
                'stock'     => $filters['stock'],
                'warehouse' => $filters['warehouse'],
                'active'    => $filters['active'] !== '1' ? $filters['active'] : null,
            ], static fn($v) => $v !== null && $v !== '');
            echo Paginator::make($total, $page, $perPage)->render(
                fn (int $p) => $this->url->to('storefeeder/admin/inventory', $base + ['page' => $p]),
                ['theme' => 'bootstrap-sm', 'class' => 'mb-0']
            );
        });
    }

    // =========================================================================
    // Product stock
    // =========================================================================

    public function productAction(): void
    {
        $productId = (int) $this->request->get('id', 'int', 0);

        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        $variantList = $this->variants->forProduct($product->id);
        if ($variantList === []) {
            // Legacy simple products may predate default-variant creation.
            $this->variants->ensureDefaultVariant($product->id, [
                'sku'    => $product->sku,
                'name'   => $product->name,
                'price'  => $product->price,
                'cost'   => $product->cost,
                'active' => $product->active,
            ]);
            $variantList = $this->variants->forProduct($product->id);
        }
        $validIds = array_map(fn(Variant $v) => $v->id, $variantList);

        if ($this->request->isPost()) {
            $deltas = (array) $this->request->post('delta', 'raw', []);
            $supInv = (array) $this->request->post('supplier_inventory', 'raw', []);
            $reason = (string) $this->request->post('reason', 'string', 'manual');
            $note   = (string) $this->request->post('note',   'string', '');

            $changed = 0;
            foreach ($deltas as $variantId => $delta) {
                $variantId = (int) $variantId;
                $delta     = (int) $delta;

                // Guard: only variants belonging to this product.
                if (!in_array($variantId, $validIds, true)) {
                    continue;
                }
                if ($delta === 0) {
                    continue;
                }

                $this->inventory->adjust($variantId, $delta, $reason, $note, $this->auth->id());
                $changed++;
            }

            foreach ($supInv as $variantId => $raw) {
                $variantId = (int) $variantId;
                if (!in_array($variantId, $validIds, true)) {
                    continue;
                }
                $raw = is_string($raw) || is_numeric($raw) ? trim((string) $raw) : '';
                $current = $this->inventory->get($variantId);
                $prev = $current['supplier_inventory'] ?? null;
                if ($raw === '') {
                    if ($prev !== null) {
                        $this->inventory->setSupplierInventory($variantId, null);
                        $changed++;
                    }
                    continue;
                }
                $qty = max(0, (int) $raw);
                if ($prev !== $qty) {
                    $this->inventory->setSupplierInventory($variantId, $qty);
                    $changed++;
                }
            }

            if ($changed > 0) {
                $this->notify->success("Updated {$changed} variant field(s).");
            } else {
                $this->notify->warning('No changes submitted.');
            }
            redirect_to('storefeeder/admin/inventory/product', ['id' => $product->id]);
            return;
        }

        // Load stock for each variant.
        $stock = [];
        foreach ($variantList as $v) {
            $stock[$v->id] = $this->inventory->get($v->id) ?? [
                'quantity'           => 0,
                'reserved'           => 0,
                'available'          => 0,
                'reorder_at'         => 0,
                'supplier_inventory' => null,
            ];
        }

        echo $this->view->inline(function () use ($product, $variantList, $stock) {
            $this->view->assign('title', 'Stock: ' . $product->name);
            $this->view->block('right_sidebar', 'Stock');

            echo '<div class="d-flex justify-content-between align-items-center mb-4">';
            echo '<div>';
            echo '<h1 class="h3 mb-0">Stock: ' . $this->view->e($product->name) . '</h1>';
            echo '<p class="text-muted mb-0"><code>' . $this->view->e($product->sku) . '</code></p>';
            echo '</div>';
            echo '<a href="' . $this->view->e($this->url->to('storefeeder/admin/products')) . '"'
               . ' class="btn btn-outline-secondary">Back</a>';
            echo '</div>';

            if (empty($variantList)) {
                echo '<div class="alert alert-info">This product has no variants. '
                   . '<a href="' . $this->view->e($this->url->to('storefeeder/admin/products/edit', ['id' => $product->id])) . '">Add variants</a> first.</div>';
                return;
            }

            echo $this->form->open([
                'action' => $this->url->to('storefeeder/admin/inventory/product', ['id' => $product->id]),
                'method' => 'POST',
            ]);

            echo '<p class="small text-muted">Pack variants show available as floor(base stock ÷ pack size). Names like <code>8 x …</code>, size rolls like <code>8mm (3.7m x 1.37m)</code>, and length rolls like <code>10m</code> are detected automatically. Anything else uses the variant <strong>Pack Qty</strong>. Adjustments on pack rows are applied to the base SKU.</p>';
            echo '<div class="table-responsive"><table class="table table-striped align-middle">';
            echo '<thead><tr>'
               . '<th>Variant</th><th>SKU</th>'
               . '<th class="text-end">Quantity</th>'
               . '<th class="text-end">Reserved</th>'
               . '<th class="text-end">Available</th>'
               . '<th class="text-end">Reorder at</th>'
               . '<th style="width:110px" class="text-end">Supplier inv.</th>'
               . '<th style="width:100px" class="text-end">Adjust</th>'
               . '</tr></thead><tbody>';

            foreach ($variantList as $v) {
                $s = $stock[$v->id] ?? [
                    'quantity' => 0, 'reserved' => 0, 'available' => 0,
                    'reorder_at' => 0, 'supplier_inventory' => null,
                ];
                $s['available'] = $this->inventory->sellableAvailable((int) $v->id);
                $supVal = $s['supplier_inventory'] ?? null;

                echo '<tr>';
                echo '<td>' . $this->view->e($v->label() !== '' ? $v->label() : '—') . '</td>';
                echo '<td><code>' . $this->view->e($v->sku) . '</code></td>';
                echo '<td class="text-end">' . (int) $s['quantity'] . '</td>';
                echo '<td class="text-end">' . (int) $s['reserved'] . '</td>';
                echo '<td class="text-end">' . (int) $s['available'] . '</td>';
                echo '<td class="text-end">' . (int) $s['reorder_at'] . '</td>';
                echo '<td class="text-end">';
                echo '<input type="number" min="0" class="form-control form-control-sm text-end"'
                   . ' name="supplier_inventory[' . (int) $v->id . ']"'
                   . ' value="' . ($supVal !== null ? (int) $supVal : '') . '"'
                   . ' placeholder="—">';
                echo '</td>';
                echo '<td class="text-end">';
                echo '<input type="text" class="form-control form-control-sm text-end"'
                   . ' name="delta[' . (int) $v->id . ']" value="0" placeholder="+/-">';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';

            echo '<div class="row g-2 mb-3">';
            echo '<div class="col-md-3">'
               . '<label class="form-label small">Reason</label>'
               . '<select name="reason" class="form-select form-select-sm">'
               . '<option value="manual">Manual adjustment</option>'
               . '<option value="received">Received from supplier</option>'
               . '<option value="sold">Sold</option>'
               . '<option value="damaged">Damaged</option>'
               . '<option value="returned">Returned</option>'
               . '<option value="correction">Correction</option>'
               . '</select></div>';
            echo '<div class="col-md-6">'
               . '<label class="form-label small">Note</label>'
               . '<input type="text" name="note" class="form-control form-control-sm" value="">'
               . '</div>';
            echo '</div>';

            echo '<button type="submit" class="btn btn-primary">Apply adjustments</button> ';
            echo '<a href="' . $this->view->e($this->url->to('storefeeder/admin/products/edit', ['id' => $product->id])) . '"'
               . ' class="btn btn-outline-secondary">Edit product</a>';

            echo $this->form->close();
        });
    }

    // =========================================================================
    // Single variant detail
    // =========================================================================

    public function adjustAction(): void
    {
        $variantId = (int) $this->request->get('id', 'int', 0);

        $variant = $this->variants->find($variantId);
        if ($variant === null) {
            throw new NotFoundException('Variant not found.');
        }

        $product = $this->products->find($variant->productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        if ($this->request->isPost()) {
            $delta   = (int) $this->request->post('delta', 'int', 0);
            $reason  = trim((string) $this->request->post('reason', 'string', 'manual'));
            $note    = trim((string) $this->request->post('note',   'string', ''));
            $reorder = (int) $this->request->post('reorder_at', 'int', 0);

            $hadReorderInput = $this->request->post('reorder_at') !== null;
            $supRawPosted = $this->request->post('supplier_inventory', 'string', null);
            $hadSupInput = $supRawPosted !== null;
            $supRaw = trim((string) ($supRawPosted ?? ''));

            if ($delta === 0 && !$hadReorderInput && !$hadSupInput) {
                $this->notify->warning('Nothing to change.');
            } else {
                if ($delta !== 0) {
                    $this->inventory->adjust($variantId, $delta, $reason, $note, $this->auth->id());
                }
                if ($hadReorderInput) {
                    $this->inventory->setReorder($variantId, $reorder);
                }
                if ($hadSupInput) {
                    $this->inventory->setSupplierInventory(
                        $variantId,
                        $supRaw === '' ? null : max(0, (int) $supRaw)
                    );
                }
                $this->notify->success('Stock updated.');
                redirect_to('storefeeder/admin/inventory/adjust', ['id' => $variantId]);
                return;
            }
        }

        $stock = $this->inventory->get($variantId) ?? [
            'quantity'           => 0,
            'reserved'           => 0,
            'available'          => 0,
            'reorder_at'         => 0,
            'supplier_inventory' => null,
        ];
        $movements = $this->inventory->movements($variantId, 50);

        echo $this->view->inline(function () use ($variant, $product, $stock, $movements) {
            $this->view->assign('title', 'Stock: ' . $variant->sku);

            echo '<div class="mb-4">';
            echo '<a href="' . $this->view->e($this->url->to('storefeeder/admin/inventory/product', ['id' => $product->id])) . '"'
               . ' class="text-muted small text-decoration-none">&larr; Back to '
               . $this->view->e($product->name) . '</a>';
            echo '<h1 class="h3 mt-2 mb-0">' . $this->view->e($variant->label() !== '' ? $variant->label() : $variant->sku) . '</h1>';
            echo '<p class="text-muted mb-0"><code>' . $this->view->e($variant->sku) . '</code></p>';
            echo '</div>';

            echo '<div class="row g-3 mb-4">';
            $supDisp = $stock['supplier_inventory'] ?? null;
            foreach ([
                'Quantity'            => (string) (int) $stock['quantity'],
                'Reserved'            => (string) (int) $stock['reserved'],
                'Available'           => (string) (int) $stock['available'],
                'Reorder at'          => (string) (int) $stock['reorder_at'],
                'Supplier inventory'  => $supDisp !== null ? (string) (int) $supDisp : '—',
            ] as $label => $value) {
                echo '<div class="col-6 col-md-4 col-xl">';
                echo '<div class="card"><div class="card-body py-3">';
                echo '<div class="text-muted small">' . $label . '</div>';
                echo '<div class="h4 mb-0">' . $this->view->e($value) . '</div>';
                echo '</div></div></div>';
            }
            echo '</div>';

            echo $this->form->open([
                'action' => $this->url->to('storefeeder/admin/inventory/adjust', ['id' => $variant->id]),
                'method' => 'POST',
            ]);

            echo '<div class="row g-2">';
            echo '<div class="col-md-3">'
               . '<label class="form-label small">Adjustment (+/-)</label>'
               . '<input type="text" name="delta" class="form-control form-control-sm" value="0">'
               . '</div>';

            echo '<div class="col-md-3">'
               . '<label class="form-label small">Reason</label>'
               . '<select name="reason" class="form-select form-select-sm">'
               . '<option value="manual">Manual adjustment</option>'
               . '<option value="received">Received from supplier</option>'
               . '<option value="sold">Sold</option>'
               . '<option value="damaged">Damaged</option>'
               . '<option value="returned">Returned</option>'
               . '<option value="correction">Correction</option>'
               . '</select></div>';

            echo '<div class="col-md-3">'
               . '<label class="form-label small">Note</label>'
               . '<input type="text" name="note" class="form-control form-control-sm" value="">'
               . '</div>';

            echo '<div class="col-md-3">'
               . '<label class="form-label small">Reorder at</label>'
               . '<input type="text" name="reorder_at" class="form-control form-control-sm" value="'
               . (int) $stock['reorder_at'] . '">'
               . '</div>';

            $supVal = $stock['supplier_inventory'] ?? null;
            echo '<div class="col-md-3">'
               . '<label class="form-label small">Supplier inventory</label>'
               . '<input type="number" min="0" name="supplier_inventory" class="form-control form-control-sm"'
               . ' value="' . ($supVal !== null ? (int) $supVal : '') . '"'
               . ' placeholder="Leave blank for —">'
               . '</div>';
            echo '</div>';

            echo '<div class="mt-3">';
            echo '<button type="submit" class="btn btn-primary">Apply</button>';
            echo '</div>';

            echo $this->form->close();

            echo '<h2 class="h5 mt-5">Recent movements</h2>';

            if (empty($movements)) {
                echo '<p class="text-muted">No movements yet.</p>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm">';
            echo '<thead><tr><th>When</th><th class="text-end">Delta</th><th>Reason</th><th>Note</th><th>By</th></tr></thead><tbody>';
            foreach ($movements as $m) {
                echo '<tr>';
                echo '<td class="text-muted small">' . $this->view->e($m['created_at']) . '</td>';
                echo '<td class="text-end">' . ($m['delta'] > 0 ? '+' : '') . (int) $m['delta'] . '</td>';
                echo '<td>' . $this->view->e($m['reason']) . '</td>';
                echo '<td class="text-muted small">' . $this->view->e($m['note']) . '</td>';
                echo '<td class="text-muted small">' . ($m['user_id'] ? '#' . (int) $m['user_id'] : '—') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    // =========================================================================
    // Low stock
    // =========================================================================

    public function lowAction(): void
    {
        $rows = $this->inventory->lowStock(200);

        echo $this->view->inline(function () use ($rows) {
            $this->view->assign('title', 'Low Stock');
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<h1 class="h3 mb-0">Low Stock</h1>';
            echo '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('storefeeder/admin/purchase-orders'))
               . '">Deliveries</a>';
            echo '</div>';

            if ($rows === []) {
                echo '<div class="alert alert-success mb-0">No variants are below their reorder threshold.</div>';
                return;
            }

            echo '<p class="text-muted small">Select SKUs to raise draft purchase orders grouped by supplier. '
               . 'Suggested qty targets the reorder level minus available stock and open PO qty.</p>';

            echo '<form method="post" action="'
               . $e($this->url->to('storefeeder/admin/inventory/create-po'))
               . '" id="sf-low-po-form">';
            echo $this->request->csrfField();
            echo '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">';
            echo '<button type="submit" class="btn btn-success btn-sm" id="sf-low-po-btn" disabled>'
               . 'Create purchase order(s)</button>';
            echo '<span class="small text-muted" id="sf-low-po-count">0 selected</span>';
            echo '</div>';

            echo '<div class="table-responsive border rounded bg-white">';
            echo '<table class="table table-sm table-striped align-middle mb-0">';
            echo '<thead class="table-light"><tr>'
               . '<th style="width:2rem"><input type="checkbox" class="form-check-input" id="sf-low-all"></th>'
               . '<th>Product</th><th>Variant</th><th>SKU</th><th>Supplier</th>'
               . '<th class="text-end">Available</th><th class="text-end">On PO</th>'
               . '<th class="text-end">Reorder at</th><th class="text-end">Order qty</th><th></th>'
               . '</tr></thead><tbody>';

            foreach ($rows as $r) {
                $vid = (int) $r['variant']->id;
                $suggest = (int) $r['suggested_qty'];
                echo '<tr>';
                echo '<td><input type="checkbox" class="form-check-input sf-low-check" name="variant_ids[]" value="'
                   . $vid . '"></td>';
                echo '<td>' . $e($r['product']->name) . '</td>';
                echo '<td>' . $e($r['variant']->label() !== '' ? $r['variant']->label() : '—') . '</td>';
                echo '<td><code>' . $e($r['variant']->sku) . '</code></td>';
                echo '<td>' . $e((string) $r['supplier']) . '</td>';
                echo '<td class="text-end">' . (int) $r['available'] . '</td>';
                echo '<td class="text-end">' . (int) $r['on_po'] . '</td>';
                echo '<td class="text-end">' . (int) $r['reorder_at'] . '</td>';
                echo '<td class="text-end"><input type="number" class="form-control form-control-sm text-end"'
                   . ' name="qty[' . $vid . ']" min="1" value="' . max(1, $suggest)
                   . '" style="width:5.5rem" form="sf-low-po-form"></td>';
                echo '<td class="text-end">'
                   . '<a href="' . $e($this->url->to('storefeeder/admin/inventory/adjust', ['id' => $vid])) . '"'
                   . ' class="btn btn-sm btn-outline-primary">Adjust</a>'
                   . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div></form>';

            $this->view->block('scripts', <<<'JS'
            <script>
            (function () {
                const all = document.getElementById('sf-low-all');
                const btn = document.getElementById('sf-low-po-btn');
                const count = document.getElementById('sf-low-po-count');
                const checks = () => Array.from(document.querySelectorAll('.sf-low-check'));
                function sync() {
                    const n = checks().filter(c => c.checked).length;
                    if (btn) btn.disabled = n === 0;
                    if (count) count.textContent = n + ' selected';
                }
                if (all) {
                    all.addEventListener('change', function () {
                        checks().forEach(c => { c.checked = all.checked; });
                        sync();
                    });
                }
                document.addEventListener('change', function (e) {
                    if (e.target.classList && e.target.classList.contains('sf-low-check')) sync();
                });
                sync();
            })();
            </script>
            JS);
        });
    }

    public function createPoAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/inventory/low');
            return;
        }

        $ids = array_values(array_filter(
            array_map('intval', (array) $this->request->post('variant_ids', 'raw', [])),
            static fn(int $id) => $id > 0
        ));
        $qtys = (array) $this->request->post('qty', 'raw', []);

        if ($ids === []) {
            $this->notify->warning('Select at least one SKU.');
            redirect_to('storefeeder/admin/inventory/low');
            return;
        }

        $raisedBy = '';
        $user = $this->auth->user();
        if ($user !== null) {
            $raisedBy = method_exists($user, 'name')
                ? (string) $user->name()
                : (string) ($user->username ?? '');
        }

        /** @var array<string, list<array{variant:Variant, qty:int, warehouse:string}>> $bySupplier */
        $bySupplier = [];

        foreach ($ids as $vid) {
            $variant = $this->variants->find($vid);
            if ($variant === null) {
                continue;
            }
            $product = $this->products->find($variant->productId);
            if ($product === null) {
                continue;
            }
            $qty = max(1, (int) ($qtys[$vid] ?? $qtys[(string) $vid] ?? 1));
            $supplier = trim($product->supplierName);
            if ($supplier === '') {
                $supplier = 'Unassigned';
            }
            $warehouse = trim($product->warehouse) !== '' ? $product->warehouse : 'Default';
            $bySupplier[$supplier][] = [
                'variant'   => $variant,
                'qty'       => $qty,
                'warehouse' => $warehouse,
            ];
        }

        if ($bySupplier === []) {
            $this->notify->error('No valid variants selected.');
            redirect_to('storefeeder/admin/inventory/low');
            return;
        }

        $createdIds = [];
        try {
            foreach ($bySupplier as $supplierName => $items) {
                $supplier = $this->suppliers->ensure((string) $supplierName);
                // Prefer warehouse from first line; fall back to Default.
                $warehouse = $this->warehouses->ensure($items[0]['warehouse'] ?? 'Default');
                $poId = $this->purchaseOrders->create(
                    $supplier,
                    'Reorder',
                    'Created from low stock',
                    null,
                    $warehouse,
                    $raisedBy,
                    0.0,
                    20.0,
                    ['created_via' => 'Low stock']
                );
                foreach ($items as $item) {
                    /** @var Variant $variant */
                    $variant = $item['variant'];
                    $this->purchaseOrders->addLine(
                        $poId,
                        (int) $variant->id,
                        (int) $item['qty'],
                        (float) $variant->cost,
                        $variant->sku
                    );
                }
                $createdIds[] = $poId;
            }
        } catch (\Throwable $ex) {
            $this->notify->error('Could not create purchase orders: ' . $ex->getMessage());
            redirect_to('storefeeder/admin/inventory/low');
            return;
        }

        $n = count($createdIds);
        $this->notify->success(
            "Created {$n} draft purchase order" . ($n === 1 ? '' : 's')
            . ' from low stock (grouped by supplier).'
        );

        if ($n === 1) {
            redirect_to('storefeeder/admin/purchase-orders/view', ['id' => $createdIds[0]]);
            return;
        }
        redirect_to('storefeeder/admin/purchase-orders', ['status' => 'draft']);
    }

    public function bulkAction(): void
    {
        if ($this->request->isPost()) {
            try {
                $this->request->checkToken();
            } catch (\RuntimeException) {
                $this->notify->error('Session expired. Please try again.');
                redirect_to('storefeeder/admin/inventory/bulk');
                return;
            }

            $variantIds = (array) $this->request->post('variant_ids', 'raw', []);
            $delta      = (int) $this->request->post('delta', 'int', 0);
            $reason     = trim((string) $this->request->post('reason', 'string', 'manual'));
            $note       = trim((string) $this->request->post('note', 'string', ''));

            if ($delta === 0 || empty($variantIds)) {
                $this->notify->warning('Nothing to adjust.');
                redirect_to('storefeeder/admin/inventory/bulk');
                return;
            }

            // Validate against existing variants.
            $changed = 0;
            $skipped = 0;
            foreach ($variantIds as $id) {
                $id = (int) $id;
                if ($id <= 0) { continue; }

                $variant = $this->variants->find($id);
                if ($variant === null) {
                    $skipped++;
                    continue;
                }

                $this->inventory->adjust($id, $delta, $reason, $note, $this->auth->id());
                $changed++;
            }

            if ($changed > 0) {
                $this->notify->success("Adjusted {$changed} variant(s)." .
                    ($skipped > 0 ? " {$skipped} skipped." : ''));
            } else {
                $this->notify->warning('No variants were adjusted.');
            }

            redirect_to('storefeeder/admin/inventory/bulk');
            return;
        }

        // GET — searchable variant picker
        $filters = [
            'q'         => trim((string) $this->request->get('q', 'string', '')),
            'stock'     => trim((string) $this->request->get('stock', 'string', '')),
            'warehouse' => trim((string) $this->request->get('warehouse', 'string', '')),
            'active'    => '1',
        ];
        $result = $this->inventory->paginateStock($filters, 200, 0);
        $rows = $result['rows'];
        $warehouses = $this->inventory->warehousesInUse();

        echo $this->view->inline(function () use ($rows, $filters, $warehouses, $result) {
            $this->view->assign('title', 'Bulk stock adjustment');
            $this->view->block('right_sidebar', 'Bulk stock');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1">Bulk stock adjustment</h1>'
               . '<p class="text-muted small mb-0">Search, select SKUs, apply one delta to all selected.</p></div>';
            echo '<a href="' . $e($this->url->to('storefeeder/admin/inventory'))
               . '" class="btn btn-outline-secondary btn-sm">Back to inventory</a>';
            echo '</div>';

            echo '<form method="get" action="' . $e($this->url->to('storefeeder/admin/inventory/bulk')) . '"'
               . ' class="row g-2 align-items-end mb-3">';
            echo '<div class="col-md-4"><label class="form-label small mb-1">Search</label>'
               . '<input type="search" name="q" class="form-control" value="' . $e($filters['q']) . '"'
               . ' placeholder="SKU or product…"></div>';
            echo '<div class="col-md-3"><label class="form-label small mb-1">Stock</label>'
               . '<select name="stock" class="form-select">';
            foreach (['' => 'All', 'low' => 'Low', 'out' => 'Out of stock', 'in' => 'Healthy'] as $val => $label) {
                $sel = $filters['stock'] === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-3"><label class="form-label small mb-1">Warehouse</label>'
               . '<select name="warehouse" class="form-select"><option value="">All</option>';
            foreach ($warehouses as $wh) {
                $sel = $filters['warehouse'] === $wh ? ' selected' : '';
                echo '<option value="' . $e($wh) . '"' . $sel . '>' . $e($wh) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-auto"><button type="submit" class="btn btn-outline-secondary">Filter</button></div>';
            echo '</form>';

            echo $this->form->open([
                'action' => $this->url->to('storefeeder/admin/inventory/bulk'),
                'method' => 'POST',
            ]);

            echo '<div class="row g-2 mb-3">';
            echo '<div class="col-md-3">'
               . '<label class="form-label small">Adjustment (+/-)</label>'
               . '<input type="text" name="delta" class="form-control form-control-sm" value="0" required>'
               . '</div>';
            echo '<div class="col-md-3">'
               . '<label class="form-label small">Reason</label>'
               . '<select name="reason" class="form-select form-select-sm">'
               . '<option value="manual">Manual adjustment</option>'
               . '<option value="received">Received from supplier</option>'
               . '<option value="damaged">Damaged</option>'
               . '<option value="correction">Correction</option>'
               . '</select></div>';
            echo '<div class="col-md-6">'
               . '<label class="form-label small">Note</label>'
               . '<input type="text" name="note" class="form-control form-control-sm" value="">'
               . '</div>';
            echo '</div>';

            echo '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">';
            echo '<button type="submit" class="btn btn-primary btn-sm" id="sf-bulk-apply" disabled>Apply to selected</button>';
            echo '<span class="small text-muted" id="sf-bulk-count">0 selected</span>';
            echo '<span class="small text-muted">· showing ' . count($rows)
               . ' of ' . (int) $result['total'] . ' (max 200)</span>';
            echo '</div>';

            if ($rows === []) {
                echo '<div class="alert alert-info">No variants match.</div>';
                echo $this->form->close();
                return;
            }

            echo '<div class="table-responsive border rounded"><table class="table table-sm align-middle mb-0">';
            echo '<thead class="table-light"><tr>'
               . '<th style="width:2rem"><input type="checkbox" class="form-check-input" id="sf-bulk-all"></th>'
               . '<th>Product</th><th>Variant</th><th>SKU</th><th>Warehouse</th>'
               . '<th class="text-end">Available</th>'
               . '</tr></thead><tbody>';

            foreach ($rows as $r) {
                echo '<tr>';
                echo '<td><input type="checkbox" class="form-check-input sf-bulk-check" name="variant_ids[]" value="'
                   . (int) $r['variant_id'] . '"></td>';
                echo '<td>' . $e($r['product_name']) . '</td>';
                echo '<td>' . $e($r['variant_name'] !== '' ? $r['variant_name'] : '—') . '</td>';
                echo '<td><code>' . $e($r['variant_sku']) . '</code></td>';
                echo '<td class="small">' . $e($r['warehouse']) . '</td>';
                echo '<td class="text-end">' . (int) $r['available'] . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo $this->form->close();

            $this->view->block('scripts', <<<'JS'
            <script>
            (function () {
                const all = document.getElementById('sf-bulk-all');
                const btn = document.getElementById('sf-bulk-apply');
                const count = document.getElementById('sf-bulk-count');
                const checks = () => Array.from(document.querySelectorAll('.sf-bulk-check'));
                function sync() {
                    const n = checks().filter(c => c.checked).length;
                    if (btn) btn.disabled = n === 0;
                    if (count) count.textContent = n + ' selected';
                }
                if (all) all.addEventListener('change', function () {
                    checks().forEach(c => { c.checked = all.checked; });
                    sync();
                });
                document.addEventListener('change', function (e) {
                    if (e.target.classList && e.target.classList.contains('sf-bulk-check')) sync();
                });
                sync();
            })();
            </script>
            JS);
        });
    }
}