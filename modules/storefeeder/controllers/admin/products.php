<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\AttributeRepository;
use Module\StoreFeeder\Services\BrandRepository;
use Module\StoreFeeder\Services\CategoryRepository;
use Module\StoreFeeder\Services\ChannelClientFactory;
use Module\StoreFeeder\Services\ChannelRepository;
use Module\StoreFeeder\Services\ChannelStockPublisher;
use Module\StoreFeeder\Services\CredentialsVault;
use Module\StoreFeeder\Services\InventoryService;
use Module\StoreFeeder\Services\ListingRepository;
use Module\StoreFeeder\Services\ListingSyncService;
use Module\StoreFeeder\Services\OrderRepository;
use Module\StoreFeeder\Services\CatalogSetupService;
use Module\StoreFeeder\Services\PackInventory;
use Module\StoreFeeder\Services\Product;
use Module\StoreFeeder\Services\ProductAuditRepository;
use Module\StoreFeeder\Services\ProductBundleRepository;
use Module\StoreFeeder\Services\ProductDocumentRepository;
use Module\StoreFeeder\Services\ProductRepository;
use Module\StoreFeeder\Services\ProductSavedFilterRepository;
use Module\StoreFeeder\Services\PurchaseOrderRepository;
use Module\StoreFeeder\Services\SupplierRepository;
use Module\StoreFeeder\Services\TenantContext;
use Module\StoreFeeder\Services\TenantStorage;
use Module\StoreFeeder\Services\Variant;
use Module\StoreFeeder\Services\VariantRepository;
use Module\StoreFeeder\Services\WarehouseRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Paginator;
use System\Library\ImageUploader;

class StoreFeederAdminProducts extends Controller
{
    private ProductRepository   $products;
    private VariantRepository   $variants;
    private AttributeRepository $attributes;
    private CategoryRepository  $categories;
    private BrandRepository     $brands;
    private SupplierRepository  $suppliers;
    private WarehouseRepository $warehouses;
    private InventoryService    $inventory;
    private ImageUploader       $uploader;
    private ChannelRepository   $channelRepo;
    private CredentialsVault    $vault;
    private OrderRepository     $orders;
    private ProductAuditRepository $audit;
    private ProductDocumentRepository $documents;
    private ProductBundleRepository $bundles;
    private PurchaseOrderRepository $purchaseOrders;
    private ProductSavedFilterRepository $savedFilters;
    private string $storageDir = '';

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->products   = new ProductRepository($this->db);
        $this->variants   = new VariantRepository($this->db);
        $this->attributes = new AttributeRepository($this->db);
        $this->categories = new CategoryRepository($this->db);
        $this->brands     = new BrandRepository($this->db);
        $this->suppliers  = new SupplierRepository($this->db);
        $this->warehouses = new WarehouseRepository($this->db);
        $this->orders     = new OrderRepository($this->db);
        $this->audit      = new ProductAuditRepository($this->db);
        $this->channelRepo = new ChannelRepository($this->db);
        $this->vault      = CredentialsVault::forTenant(
            (string) $this->config->get('storefeeder.credentials_key', '')
        );
        $publisher = new ChannelStockPublisher(
            $this->channelRepo,
            new ChannelClientFactory($this->vault, $this->channelRepo),
            new ListingRepository($this->db),
        );
        $this->inventory  = new InventoryService($this->db, $publisher);
        $this->storageDir = TenantStorage::fromBaseDir()->root(
            TenantContext::get()->id() ?? 1
        );
        $this->uploader   = new ImageUploader($this->storageDir);
        $this->documents  = new ProductDocumentRepository($this->db, $this->storageDir);
        $this->bundles    = new ProductBundleRepository($this->db);
        $this->purchaseOrders = new PurchaseOrderRepository($this->db);
        $this->savedFilters   = new ProductSavedFilterRepository($this->db);
        $this->view->addThemeStyle('css/modules/products.css');
        // Permissions: Action.php only.
    }

    // =========================================================================
    // List
    // =========================================================================

    public function indexAction(): void
    {
        $searchInput  = trim((string) $this->request->get('q', 'string', ''));
        $search       = mb_strlen($searchInput) >= 3 ? $searchInput : '';
        $categoryId   = (int) $this->request->get('category_id', 'int', 0);
        $stockFilter  = (string) $this->request->get('stock', 'string', '');
        $activeFilter = (string) $this->request->get('active', 'string', '');
        $sort         = (string) $this->request->get('sort', 'string', 'name');
        $dir          = strtolower((string) $this->request->get('dir', 'string', 'asc')) === 'desc' ? 'desc' : 'asc';
        $page         = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage      = 50;
        $offset       = ($page - 1) * $perPage;

        $typeFilter = strtolower(trim((string) $this->request->get('f_type', 'string', '')));
        if (!in_array($typeFilter, ['simple', 'variable', 'kit'], true)) {
            $typeFilter = '';
        }

        $onPoFilter = strtolower(trim((string) $this->request->get('f_on_po', 'string', '')));
        if (!in_array($onPoFilter, ['yes', 'no'], true)) {
            $onPoFilter = '';
        }
        $onBackOrderFilter = strtolower(trim((string) $this->request->get('f_on_back_order', 'string', '')));
        if (!in_array($onBackOrderFilter, ['yes', 'no'], true)) {
            $onBackOrderFilter = '';
        }

        $columnFilters = [
            'sku'           => trim((string) $this->request->get('f_sku', 'string', '')),
            'name'          => trim((string) $this->request->get('f_name', 'string', '')),
            'brand'         => trim((string) $this->request->get('f_brand', 'string', '')),
            'ean'           => trim((string) $this->request->get('f_ean', 'string', '')),
            'mpn'           => trim((string) $this->request->get('f_mpn', 'string', '')),
            'supplier_sku'  => trim((string) $this->request->get('f_supplier_sku', 'string', '')),
            'supplier'      => trim((string) $this->request->get('f_supplier', 'string', '')),
            'warehouse'     => trim((string) $this->request->get('f_warehouse', 'string', '')),
            'pickbin'       => trim((string) $this->request->get('f_pickbin', 'string', '')),
            'on_po'         => $onPoFilter,
            'on_back_order' => $onBackOrderFilter,
            'type'          => $typeFilter,
        ];

        $total    = $this->products->count($search, $categoryId, $stockFilter, $activeFilter, $columnFilters);
        $products = $this->products->all(
            $perPage,
            $offset,
            $search,
            $categoryId,
            $stockFilter,
            $activeFilter,
            $columnFilters,
            $sort,
            $dir
        );
        $lastPage = max(1, (int) ceil($total / $perPage));

        $productIds = array_values(array_filter(array_map(
            static fn(Product $p) => (int) ($p->id ?? 0),
            $products
        ), static fn(int $id) => $id > 0));

        $soldMap = $this->soldLast30DaysMap($productIds);
        $daysMap = $this->daysSinceLastOrderedMap($productIds);
        $variantSoldMap = $this->soldLast30DaysByVariantMap($productIds);
        $productStockMap = $this->inventory->productStockSummaries($productIds);

        $allVariantIds = [];
        $variantsByProduct = [];
        foreach ($products as $p) {
            $pid = (int) $p->id;
            $variantsByProduct[$pid] = $this->variants->forProduct($pid);
            foreach ($variantsByProduct[$pid] as $v) {
                if ($v->id !== null) {
                    $allVariantIds[] = (int) $v->id;
                }
            }
        }
        $variantStockDetail = $this->inventory->variantStockSummaries($allVariantIds);
        $poByVariant = $this->purchaseOrders->openQtyByVariant($allVariantIds);
        $poByProduct = $this->purchaseOrders->openQtyByProduct($productIds);

        $summaries = [];
        foreach ($products as $p) {
            $pid = (int) $p->id;
            $variants = $variantsByProduct[$pid] ?? [];
            $stockSummary = $productStockMap[$pid] ?? [
                'stock' => 0, 'reserved' => 0, 'out_of_stock_at' => null, 'supplier_inventory' => null,
            ];
            $pickBin = '';
            $isKit = $this->bundles->forProduct($pid) !== [];
            $variantRows = [];
            foreach ($variants as $v) {
                if ($pickBin === '' && trim($v->pickBin) !== '') {
                    $pickBin = $v->pickBin;
                }
                $vid = (int) ($v->id ?? 0);
                $vs = $variantStockDetail[$vid] ?? [
                    'available' => 0, 'reserved' => 0, 'out_of_stock_at' => null, 'supplier_inventory' => null,
                ];
                $label = $v->label();
                $vName = $v->name !== '' ? $v->name : ($label !== '' ? $label : $v->sku);
                $variantRows[] = [
                    'id'                 => $vid,
                    'sku'                => $v->sku,
                    'name'               => $vName,
                    'stock'              => (int) $vs['available'],
                    'reserved'           => (int) $vs['reserved'],
                    'on_po'              => (int) ($poByVariant[$vid] ?? 0),
                    'supplier_inventory' => $vs['supplier_inventory'] ?? null,
                    'out_of_stock_at'    => $vs['out_of_stock_at'],
                    'pick_bin'           => $v->pickBin !== '' ? $v->pickBin : 'N/A',
                    'price'              => (float) $v->price,
                    'active'             => $v->active,
                    'sold_30'            => (int) ($variantSoldMap[$vid] ?? 0),
                ];
            }
            $summaries[$pid] = [
                'count'              => count($variants),
                'stock'              => (int) $stockSummary['stock'],
                'reserved'           => (int) $stockSummary['reserved'],
                'on_po'              => (int) ($poByProduct[$pid] ?? 0),
                'supplier_inventory' => $stockSummary['supplier_inventory'] ?? null,
                'out_of_stock_at'    => $stockSummary['out_of_stock_at'],
                'pick_bin'           => $pickBin !== '' ? $pickBin : 'N/A',
                'is_kit'             => $isKit,
                'is_variable'        => count($variants) > 1,
                'sold_30'            => (int) ($soldMap[$pid] ?? 0),
                'days_since'         => array_key_exists($pid, $daysMap) ? $daysMap[$pid] : null,
                'variants'           => $variantRows,
            ];
        }

        $categoryTree = $this->categories->flat();
        $brandList = $this->brands->all();
        $supplierList = $this->suppliers->all();
        $this->warehouses->ensure('Default');
        $warehouseList = $this->warehouses->all();
        $baseParams = array_filter([
            'q'              => $search !== '' ? $search : null,
            'category_id'    => $categoryId > 0 ? $categoryId : null,
            'stock'          => $stockFilter !== '' ? $stockFilter : null,
            'active'         => $activeFilter !== '' ? $activeFilter : null,
            'sort'           => $sort !== 'name' ? $sort : null,
            'dir'            => $dir !== 'asc' ? $dir : null,
            'f_sku'          => $columnFilters['sku'] !== '' ? $columnFilters['sku'] : null,
            'f_name'         => $columnFilters['name'] !== '' ? $columnFilters['name'] : null,
            'f_brand'        => $columnFilters['brand'] !== '' ? $columnFilters['brand'] : null,
            'f_ean'          => $columnFilters['ean'] !== '' ? $columnFilters['ean'] : null,
            'f_mpn'          => $columnFilters['mpn'] !== '' ? $columnFilters['mpn'] : null,
            'f_supplier_sku' => $columnFilters['supplier_sku'] !== '' ? $columnFilters['supplier_sku'] : null,
            'f_supplier'       => $columnFilters['supplier'] !== '' ? $columnFilters['supplier'] : null,
            'f_warehouse'      => $columnFilters['warehouse'] !== '' ? $columnFilters['warehouse'] : null,
            'f_pickbin'        => $columnFilters['pickbin'] !== '' ? $columnFilters['pickbin'] : null,
            'f_on_po'          => $columnFilters['on_po'] !== '' ? $columnFilters['on_po'] : null,
            'f_on_back_order'  => $columnFilters['on_back_order'] !== '' ? $columnFilters['on_back_order'] : null,
            'f_type'           => $typeFilter !== '' ? $typeFilter : null,
        ], static fn($v) => $v !== null && $v !== '');

        $userId = (int) ($this->auth->id() ?? 0);
        $savedFilterList = $this->savedFilters->forUser($userId);
        $appliedFilterId = (int) $this->request->get('saved_filter', 'int', 0);

        echo $this->view->inline(function () use (
            $products,
            $summaries,
            $categoryTree,
            $search,
            $searchInput,
            $categoryId,
            $stockFilter,
            $activeFilter,
            $columnFilters,
            $typeFilter,
            $sort,
            $dir,
            $page,
            $lastPage,
            $total,
            $baseParams,
            $savedFilterList,
            $appliedFilterId,
            $brandList,
            $supplierList,
            $warehouseList
        ) {
            $this->view->assign('title', 'Products');
            $this->view->block('right_sidebar', 'Products');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            $sortLink = function (string $key, string $label) use ($baseParams, $sort, $dir, $e): string {
                $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
                $url = $this->url->to('storefeeder/admin/products', $baseParams + [
                    'sort' => $key,
                    'dir'  => $nextDir,
                    'page' => 1,
                ]);
                $icon = 'bi-arrow-down-up';
                if ($sort === $key) {
                    $icon = $dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
                }
                return '<a href="' . $e($url) . '">'
                    . $e($label) . ' <i class="bi ' . $icon . '"></i></a>';
            };

            echo '<div class="sf-pm">';
            echo '<div class="sf-pm-head">';
            echo '<h1>Products</h1>';
            echo '<div class="sf-pm-tools">';
            echo '<form method="get" action="' . $e($this->url->to('storefeeder/admin/products')) . '"'
               . ' class="sf-pm-search" id="sf-pm-search-form">';
            foreach ($baseParams as $k => $v) {
                if ($k === 'q' || $k === 'page') {
                    continue;
                }
                echo '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e((string) $v) . '">';
            }
            echo '<input type="search" name="q" value="' . $e($searchInput) . '"'
               . ' placeholder="Search SKU, name, EAN, MPN…" minlength="3">';
            echo '<button type="submit" title="Search"><i class="bi bi-search"></i></button>';
            echo '</form>';
            echo '<a href="' . $e($this->url->to('storefeeder/admin/products/export')) . '" class="sf-btn">Export</a>';
            echo '<a href="' . $e($this->url->to('storefeeder/admin/products/import')) . '" class="sf-btn">Import</a>';
            echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/products/clear')) . '"'
               . ' onsubmit="return confirm(\'Delete ALL products for this tenant? Variants, listings and stock will be removed. Orders are kept. This cannot be undone.\');">';
            echo $this->request->csrfField();
            echo '<button type="submit" class="sf-btn is-danger">Clear all</button>';
            echo '</form>';
            echo '<a href="' . $e($this->url->to('storefeeder/admin/products/add')) . '" class="sf-btn is-primary">'
               . '<i class="bi bi-plus-lg"></i>New product</a>';
            echo '</div></div>';

            // Column filters (GET) — sibling of bulk form; inputs use form="sf-pm-col-filters"
            echo '<form id="sf-pm-col-filters" method="get" action="'
               . $e($this->url->to('storefeeder/admin/products')) . '" class="d-none">';
            foreach ($baseParams as $k => $v) {
                if (str_starts_with((string) $k, 'f_') || $k === 'page'
                    || in_array((string) $k, ['stock', 'active', 'category_id'], true)
                ) {
                    continue;
                }
                echo '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e((string) $v) . '">';
            }
            echo '</form>';

            // Save-filter form (POST) — filled by toolbar JS
            echo '<form id="sf-pm-save-filter" method="post" action="'
               . $e($this->url->to('storefeeder/admin/products/save-filter'))
               . '" class="d-none">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="name" id="sf-pm-save-filter-name" value="">';
            foreach ($baseParams as $k => $v) {
                echo '<input type="hidden" name="params[' . $e((string) $k) . ']" value="'
                   . $e((string) $v) . '">';
            }
            echo '</form>';
            echo '<form id="sf-pm-delete-filter" method="post" action="'
               . $e($this->url->to('storefeeder/admin/products/delete-filter'))
               . '" class="d-none">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="id" id="sf-pm-delete-filter-id" value="">';
            echo '</form>';

            // Bulk + filter toolbar
            echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/products/bulk')) . '" id="bulk-form">';
            echo $this->request->csrfField();
            echo '<div class="sf-pm-toolbar">';
            echo '<div class="d-flex flex-wrap align-items-center gap-2">';
            echo '<span class="sf-pm-count" id="bulk-count">0 selected</span>';
            echo '<select name="action" id="bulk-action" style="min-width:10rem">';
            echo '<option value="">Bulk actions</option>';
            echo '<option value="activate">Activate</option>';
            echo '<option value="deactivate">Deactivate</option>';
            echo '<option value="add_category">Add to category…</option>';
            echo '<option value="remove_category">Remove from category…</option>';
            echo '<option value="set_brand">Set brand…</option>';
            echo '<option value="set_supplier">Set supplier…</option>';
            echo '<option value="set_warehouse">Set warehouse…</option>';
            echo '<option value="delete">Delete</option>';
            echo '</select>';
            echo '<select name="category_id" id="bulk-category" class="d-none">';
            echo '<option value="">(choose category)</option>';
            foreach ($categoryTree as $row) {
                $c = $row['category'];
                $pad = str_repeat('&nbsp;&nbsp;&nbsp;', $row['depth']);
                echo '<option value="' . (int) $c->id . '">' . $pad . $e($c->name) . '</option>';
            }
            echo '</select>';
            echo '<select name="brand" id="bulk-brand" class="d-none" style="min-width:10rem">';
            echo '<option value="">(clear brand)</option>';
            foreach ($brandList as $b) {
                echo '<option value="' . $e($b['name']) . '">' . $e($b['name']) . '</option>';
            }
            echo '</select>';
            echo '<select name="supplier_name" id="bulk-supplier" class="d-none" style="min-width:10rem">';
            echo '<option value="">(clear supplier)</option>';
            foreach ($supplierList as $s) {
                echo '<option value="' . $e($s['name']) . '">' . $e($s['name']) . '</option>';
            }
            echo '</select>';
            echo '<select name="warehouse" id="bulk-warehouse" class="d-none" style="min-width:10rem">';
            echo '<option value="">(choose warehouse)</option>';
            foreach ($warehouseList as $w) {
                echo '<option value="' . $e($w['name']) . '">' . $e($w['name']) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="sf-btn" id="bulk-apply" disabled>Confirm</button>';
            echo '</div>';
            echo '<div class="d-flex flex-wrap align-items-center gap-2">';

            // Quick filters (already supported by ProductRepository)
            echo '<select name="stock" form="sf-pm-col-filters">';
            foreach ([
                '' => 'Stock: All',
                'in' => 'In stock',
                'out' => 'Out of stock',
                'low' => 'Low stock',
            ] as $val => $label) {
                $sel = $stockFilter === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select>';
            echo '<select name="active" form="sf-pm-col-filters">';
            foreach ([
                ['', 'Status: All'],
                ['1', 'Active'],
                ['0', 'Inactive'],
            ] as [$val, $label]) {
                $sel = $activeFilter === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select>';
            echo '<select name="category_id" form="sf-pm-col-filters" style="max-width:12rem">';
            echo '<option value="">Category: All</option>';
            foreach ($categoryTree as $row) {
                $c = $row['category'];
                $pad = str_repeat('— ', $row['depth']);
                $sel = $categoryId === (int) $c->id ? ' selected' : '';
                echo '<option value="' . (int) $c->id . '"' . $sel . '>'
                   . $e($pad . $c->name) . '</option>';
            }
            echo '</select>';

            echo '<select id="sf-saved-filters" style="min-width:11rem"'
               . ' data-applied="' . (int) $appliedFilterId . '">';
            echo '<option value="">Saved Filters</option>';
            echo '<option value="__save__">Save current filters…</option>';
            if ($savedFilterList !== []) {
                echo '<option value="__delete__">Delete current saved filter…</option>';
            }
            foreach ($savedFilterList as $sf) {
                $sid = (int) $sf['id'];
                $url = $this->url->to(
                    'storefeeder/admin/products',
                    $sf['params'] + ['saved_filter' => $sid]
                );
                $sel = $appliedFilterId === $sid ? ' selected' : '';
                echo '<option value="' . $sid . '" data-url="' . $e($url) . '"' . $sel . '>'
                   . $e($sf['name']) . '</option>';
            }
            echo '</select>';
            echo '<a class="sf-btn" href="' . $e($this->url->to('storefeeder/admin/products')) . '">Reset</a>';
            echo '<span class="sf-pm-count">' . (int) $total . ' products</span>';
            echo '</div></div>';

            echo '<div class="sf-pm-card table-responsive">';
            echo '<table class="sf-pm-table" id="sf-pm-table">';
            echo '<thead><tr>';
            echo '<th style="width:2rem"><input type="checkbox" id="bulk-select-all" class="form-check-input"></th>';
            echo '<th style="width:2rem"></th>'; // variant expand
            echo '<th class="text-center" title="Type"><i class="bi bi-box"></i></th>';
            echo '<th>Image</th>';
            echo '<th>' . $sortLink('sku', 'SKU') . '</th>';
            echo '<th>' . $sortLink('name', 'Name') . '</th>';
            echo '<th>' . $sortLink('ean', 'EAN') . '</th>';
            echo '<th>' . $sortLink('mpn', 'MPN') . '</th>';
            echo '<th>' . $sortLink('supplier_sku', 'Supplier SKU') . '</th>';
            echo '<th class="text-end">Inventory</th>';
            echo '<th class="text-end">Supplier Inventory</th>';
            echo '<th>Out Of Stock Date</th>';
            echo '<th class="text-end">On Purchase Order</th>';
            echo '<th class="text-end">On Back Order</th>';
            echo '<th class="text-end">Sold Last 30 Days</th>';
            echo '<th class="text-end">Days Since Last Ordered</th>';
            echo '<th>Pickbin</th>';
            echo '<th class="text-end">' . $sortLink('price', 'Price') . '</th>';
            echo '<th>' . $sortLink('created', 'Created') . '</th>';
            echo '<th>' . $sortLink('supplier', 'Supplier') . '</th>';
            echo '<th>' . $sortLink('warehouse', 'Warehouse') . '</th>';
            echo '</tr>';

            // Column filter row — inputs belong to sibling GET form via form=
            echo '<tr class="sf-pm-filters">';
            echo '<th></th><th></th>'; // checkbox + expand
            echo '<th><select class="form-select form-select-sm" name="f_type" form="sf-pm-col-filters"'
               . ' id="sf-pm-type-filter">';
            echo '<option value="">All</option>';
            echo '<option value="simple"' . ($typeFilter === 'simple' ? ' selected' : '') . '>Simple</option>';
            echo '<option value="variable"' . ($typeFilter === 'variable' ? ' selected' : '') . '>Variable</option>';
            echo '<option value="kit"' . ($typeFilter === 'kit' ? ' selected' : '') . '>Kit</option>';
            echo '</select></th>';
            echo '<th></th>'; // image
            $textFilters = [
                'f_sku' => 'sku',
                'f_name' => 'name',
                'f_ean' => 'ean',
                'f_mpn' => 'mpn',
                'f_supplier_sku' => 'supplier_sku',
            ];
            foreach ($textFilters as $param => $key) {
                echo '<th><input type="text" class="form-control form-control-sm" name="'
                   . $e($param) . '" value="' . $e($columnFilters[$key]) . '"'
                   . ' form="sf-pm-col-filters" placeholder="Filter"></th>';
            }
            // Inventory, Supplier Inventory, OOS date — use toolbar Stock filter
            echo '<th></th><th></th><th></th>';
            // On Purchase Order
            echo '<th><select class="form-select form-select-sm" name="f_on_po" form="sf-pm-col-filters">';
            echo '<option value="">All</option>';
            echo '<option value="yes"' . ($columnFilters['on_po'] === 'yes' ? ' selected' : '') . '>Has</option>';
            echo '<option value="no"' . ($columnFilters['on_po'] === 'no' ? ' selected' : '') . '>None</option>';
            echo '</select></th>';
            // On Back Order (reserved)
            echo '<th><select class="form-select form-select-sm" name="f_on_back_order" form="sf-pm-col-filters">';
            echo '<option value="">All</option>';
            echo '<option value="yes"' . ($columnFilters['on_back_order'] === 'yes' ? ' selected' : '') . '>Has</option>';
            echo '<option value="no"' . ($columnFilters['on_back_order'] === 'no' ? ' selected' : '') . '>None</option>';
            echo '</select></th>';
            // Sold 30d, Days since last ordered
            echo '<th></th><th></th>';
            // Pickbin — use "-" / empty / none for unset bins
            echo '<th><input type="text" class="form-control form-control-sm" name="f_pickbin"'
               . ' value="' . $e($columnFilters['pickbin']) . '" form="sf-pm-col-filters"'
               . ' placeholder="Filter" title="Pickbin (use - or empty for unset)"></th>';
            echo '<th></th>'; // price
            echo '<th></th>'; // create date
            echo '<th><input type="text" class="form-control form-control-sm" name="f_supplier"'
               . ' value="' . $e($columnFilters['supplier']) . '" form="sf-pm-col-filters" placeholder="Filter"></th>';
            echo '<th><input type="text" class="form-control form-control-sm" name="f_warehouse"'
               . ' value="' . $e($columnFilters['warehouse']) . '" form="sf-pm-col-filters" placeholder="Filter"></th>';
            echo '</tr></thead><tbody>';

            if ($products === []) {
                echo '<tr><td colspan="21" class="text-center text-muted py-4">No products found.</td></tr>';
            }

            $csrfField = $this->request->csrfField();
            $dash = '<span class="text-muted">--</span>';
            $archiveForms = '';

            foreach ($products as $p) {
                $pid = (int) $p->id;
                $s = $summaries[$pid] ?? [
                    'count' => 0, 'stock' => 0, 'pick_bin' => 'N/A', 'is_kit' => false,
                    'is_variable' => false, 'sold_30' => 0, 'days_since' => null, 'variants' => [],
                ];
                $editUrl = $this->url->to('storefeeder/admin/products/edit', ['id' => $pid]);
                $archiveUrl = $this->url->to('storefeeder/admin/products/archive', ['id' => $pid]);
                $variantRows = is_array($s['variants'] ?? null) ? $s['variants'] : [];
                $hasVariants = $variantRows !== [];
                $isVariable = !empty($s['is_variable']);
                $typeLabel = !empty($s['is_kit'])
                    ? 'Kit'
                    : ($isVariable ? 'Variable' : 'Simple');
                $typeIcon = $typeLabel === 'Simple' ? 'bi-box' : 'bi-boxes';
                $displaySku = $p->sku !== '' ? $p->sku : 'N/A';
                $supplier = $p->supplierName;
                $warehouse = $p->warehouse !== '' ? $p->warehouse : 'Default';

                $created = $p->createdAt ?? '';
                if ($created !== '') {
                    try {
                        $created = (new \DateTimeImmutable($created))->format('d/m/Y H:i');
                    } catch (\Throwable $ex) {
                        // keep raw
                    }
                }

                echo '<tr class="sf-pm-product" data-product-id="' . $pid . '">';
                echo '<td><input type="checkbox" name="ids[]" value="' . $pid
                   . '" class="form-check-input row-check"></td>';
                echo '<td class="text-center">';
                if ($hasVariants && $isVariable) {
                    echo '<button type="button" class="sf-pm-expand" data-product-id="' . $pid
                       . '" title="Show variants" aria-expanded="false">'
                       . '<i class="bi bi-plus-lg"></i></button>';
                } else {
                    // Simple products (one default variant) — no expand row clutter.
                    echo '<button type="button" class="sf-pm-expand" disabled title="No variants">'
                       . '<i class="bi bi-plus-lg"></i></button>';
                }
                echo '</td>';
                echo '<td class="text-center"><i class="bi ' . $typeIcon . ' text-secondary" title="'
                   . $e($typeLabel) . '"></i></td>';
                echo '<td>';
                if ($p->image !== '') {
                    echo '<img class="sf-pm-thumb" src="'
                       . $e($this->url->asset(TenantStorage::fromBaseDir()->publicPrefix() . '/' . $p->image))
                       . '" alt="">';
                } else {
                    echo '<div class="sf-pm-noimg">No image available</div>';
                }
                echo '</td>';
                $pArchiveFormId = 'sf-archive-product-' . $pid;
                $archiveForms .= '<form id="' . $pArchiveFormId . '" method="post" action="'
                    . $e($archiveUrl) . '" class="d-none">' . $csrfField . '</form>';
                echo '<td class="sf-pm-sku-cell">';
                echo '<a href="' . $e($editUrl) . '" title="' . $e($displaySku) . '">' . $e($displaySku) . '</a>';
                echo '<div class="sf-pm-row-actions">';
                echo '<a href="' . $e($editUrl) . '">Edit</a>';
                echo '<button type="submit" form="' . $pArchiveFormId . '"'
                   . ' onclick="return confirm(\'Archive this product?\');">Archive</button>';
                echo '</div></td>';
                echo '<td><span class="sf-pm-name" title="' . $e($p->name) . '">' . $e($p->name) . '</span></td>';
                echo '<td>' . $e($p->barcode) . '</td>';
                echo '<td>' . $e($p->mpn) . '</td>';
                echo '<td>' . $e($p->supplierSku) . '</td>';
                $oosAt = $s['out_of_stock_at'] ?? null;
                $oosDisplay = '';
                if (is_string($oosAt) && $oosAt !== '') {
                    try {
                        $oosDisplay = (new \DateTimeImmutable($oosAt))->format('d/m/Y H:i');
                    } catch (\Throwable $ex) {
                        $oosDisplay = $oosAt;
                    }
                }

                echo '<td class="text-end">' . (int) $s['stock'] . '</td>';
                $supInv = $s['supplier_inventory'] ?? null;
                echo '<td class="text-end">'
                   . ($supInv !== null ? (int) $supInv : $dash)
                   . '</td>';
                echo '<td class="text-nowrap">' . ($oosDisplay !== '' ? $e($oosDisplay) : '') . '</td>';
                echo '<td class="text-end">' . (int) ($s['on_po'] ?? 0) . '</td>';
                echo '<td class="text-end">' . (int) ($s['reserved'] ?? 0) . '</td>';
                echo '<td class="text-end">' . (int) $s['sold_30'] . '</td>';
                echo '<td class="text-end">'
                   . ($s['days_since'] !== null ? (int) $s['days_since'] : $dash)
                   . '</td>';
                echo '<td>' . $e((string) $s['pick_bin']) . '</td>';
                echo '<td class="text-end">£' . number_format((float) $p->price, 2) . '</td>';
                echo '<td class="text-nowrap">' . $e($created) . '</td>';
                echo '<td>' . $e($supplier) . '</td>';
                echo '<td>' . $e($warehouse) . '</td>';
                echo '</tr>';

                // Expandable child rows only for true multi-variant products.
                if (!$isVariable) {
                    continue;
                }

                foreach ($variantRows as $vr) {
                    $vid = (int) $vr['id'];
                    $vEditUrl = $this->url->to('storefeeder/admin/products/edit', [
                        'id' => $pid,
                        'panel' => 'general',
                    ]);
                    $vArchiveUrl = $this->url->to('storefeeder/admin/products/archive-variant', [
                        'id' => $vid,
                    ]);
                    $vOos = '';
                    if (!empty($vr['out_of_stock_at'])) {
                        try {
                            $vOos = (new \DateTimeImmutable((string) $vr['out_of_stock_at']))->format('d/m/Y H:i');
                        } catch (\Throwable $ex) {
                            $vOos = (string) $vr['out_of_stock_at'];
                        }
                    }
                    echo '<tr class="sf-pm-variant-row d-none" data-parent-id="' . $pid . '">';
                    echo '<td></td><td></td><td></td><td></td>';
                    echo '<td class="sf-pm-sku-cell"><a href="' . $e($vEditUrl) . '" title="'
                       . $e((string) $vr['sku']) . '">'
                       . $e((string) $vr['sku']) . '</a></td>';
                    $vArchiveFormId = 'sf-archive-variant-' . $vid;
                    $archiveForms .= '<form id="' . $vArchiveFormId . '" method="post" action="'
                        . $e($vArchiveUrl) . '" class="d-none">' . $csrfField . '</form>';
                    echo '<td class="sf-pm-name-cell">';
                    echo '<a href="' . $e($vEditUrl) . '">' . $e((string) $vr['name']) . '</a>';
                    echo '<div class="sf-pm-row-actions">';
                    echo '<a href="' . $e($vEditUrl) . '">Edit</a>';
                    echo '<button type="submit" form="' . $vArchiveFormId . '"'
                       . ' onclick="return confirm(\'Archive this variant?\');">Archive</button>';
                    echo '</div></td>';
                    echo '<td></td><td></td><td></td>';
                    echo '<td class="text-end">' . (int) $vr['stock'] . '</td>';
                    $vSup = $vr['supplier_inventory'] ?? null;
                    echo '<td class="text-end">'
                       . ($vSup !== null ? (int) $vSup : $dash)
                       . '</td>';
                    echo '<td class="text-nowrap">' . ($vOos !== '' ? $e($vOos) : '') . '</td>';
                    echo '<td class="text-end">' . (int) ($vr['on_po'] ?? 0) . '</td>';
                    echo '<td class="text-end">' . (int) ($vr['reserved'] ?? 0) . '</td>';
                    echo '<td class="text-end">' . (int) ($vr['sold_30'] ?? 0) . '</td>';
                    echo '<td class="text-end">' . $dash . '</td>';
                    echo '<td>' . $e((string) $vr['pick_bin']) . '</td>';
                    echo '<td class="text-end">£' . number_format((float) $vr['price'], 2) . '</td>';
                    echo '<td></td>';
                    echo '<td>' . $e($supplier) . '</td>';
                    echo '<td>' . $e($warehouse) . '</td>';
                    echo '</tr>';
                }
            }

            echo '</tbody></table></div>';
            echo '</form>'; // bulk-form
            echo $archiveForms;

            echo Paginator::make($total, $page, 50)->render(
                fn (int $p) => $this->url->to('storefeeder/admin/products', $baseParams + ['page' => $p]),
                ['theme' => 'bootstrap']
            );
            echo '</div>';

            $this->view->block('scripts', <<<'JS'
            <script>
            (function () {
                const form = document.getElementById('bulk-form');
                const selectAll = document.getElementById('bulk-select-all');
                const checkboxes = () => Array.from(document.querySelectorAll('.row-check'));
                const bulkAction = document.getElementById('bulk-action');
                const bulkCategory = document.getElementById('bulk-category');
                const bulkBrand = document.getElementById('bulk-brand');
                const bulkSupplier = document.getElementById('bulk-supplier');
                const bulkWarehouse = document.getElementById('bulk-warehouse');
                const applyBtn = document.getElementById('bulk-apply');
                const countLabel = document.getElementById('bulk-count');
                const colFilters = document.getElementById('sf-pm-col-filters');

                function syncBulkExtras() {
                    const action = bulkAction ? bulkAction.value : '';
                    const needsCategory = ['add_category', 'remove_category'].includes(action);
                    const needsBrand = action === 'set_brand';
                    const needsSupplier = action === 'set_supplier';
                    const needsWarehouse = action === 'set_warehouse';
                    if (bulkCategory) {
                        bulkCategory.classList.toggle('d-none', !needsCategory);
                        bulkCategory.required = needsCategory;
                    }
                    if (bulkBrand) {
                        bulkBrand.classList.toggle('d-none', !needsBrand);
                    }
                    if (bulkSupplier) {
                        bulkSupplier.classList.toggle('d-none', !needsSupplier);
                    }
                    if (bulkWarehouse) {
                        bulkWarehouse.classList.toggle('d-none', !needsWarehouse);
                        bulkWarehouse.required = needsWarehouse;
                    }
                }

                function updateCount() {
                    const n = checkboxes().filter(cb => cb.checked).length;
                    countLabel.textContent = n + ' selected';
                    applyBtn.disabled = n === 0 || !bulkAction.value;
                }

                if (selectAll) {
                    selectAll.addEventListener('change', function () {
                        checkboxes().forEach(cb => { cb.checked = this.checked; });
                        updateCount();
                    });
                }

                document.addEventListener('change', function (e) {
                    if (!e.target.classList.contains('row-check')) return;
                    updateCount();
                    const all = checkboxes();
                    const checked = all.filter(cb => cb.checked).length;
                    if (selectAll) {
                        selectAll.indeterminate = checked > 0 && checked < all.length;
                        selectAll.checked = checked === all.length && all.length > 0;
                    }
                });

                bulkAction.addEventListener('change', function () {
                    syncBulkExtras();
                    updateCount();
                });
                syncBulkExtras();

                form.addEventListener('submit', function (e) {
                    const action = bulkAction.value;
                    const n = checkboxes().filter(cb => cb.checked).length;
                    if (action === '') {
                        e.preventDefault();
                        return;
                    }
                    if (action === 'set_warehouse' && bulkWarehouse && !bulkWarehouse.value) {
                        e.preventDefault();
                        window.alert('Choose a warehouse.');
                        return;
                    }
                    if (action === 'delete') {
                        if (!confirm('Delete ' + n + ' product(s) and all their variants? This cannot be undone.')) {
                            e.preventDefault();
                        }
                    }
                });

                if (colFilters) {
                    document.querySelectorAll('[form="sf-pm-col-filters"]').forEach(function (input) {
                        if (input.tagName === 'SELECT') {
                            input.addEventListener('change', function () { colFilters.submit(); });
                            return;
                        }
                        input.addEventListener('keydown', function (e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                colFilters.submit();
                            }
                        });
                        let t = null;
                        input.addEventListener('input', function () {
                            clearTimeout(t);
                            t = setTimeout(function () { colFilters.submit(); }, 500);
                        });
                    });
                }

                document.querySelectorAll('.sf-pm-expand:not(:disabled)').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const pid = this.getAttribute('data-product-id');
                        const rows = document.querySelectorAll('.sf-pm-variant-row[data-parent-id="' + pid + '"]');
                        const open = this.getAttribute('aria-expanded') === 'true';
                        const next = !open;
                        this.setAttribute('aria-expanded', next ? 'true' : 'false');
                        this.title = next ? 'Hide variants' : 'Show variants';
                        const icon = this.querySelector('i');
                        if (icon) {
                            icon.className = next ? 'bi bi-dash-lg' : 'bi bi-plus-lg';
                        }
                        rows.forEach(function (row) {
                            row.classList.toggle('d-none', !next);
                        });
                    });
                });

                const table = document.getElementById('sf-pm-table');
                if (table) {
                    table.addEventListener('click', function (e) {
                        const row = e.target.closest('tr.sf-pm-product, tr.sf-pm-variant-row');
                        if (!row || e.target.closest('a, button, input, form')) return;
                        table.querySelectorAll('.sf-pm-focus').forEach(function (r) {
                            r.classList.remove('sf-pm-focus');
                        });
                        row.classList.add('sf-pm-focus');
                    });
                }

                const savedSel = document.getElementById('sf-saved-filters');
                if (savedSel) {
                    const prevValue = savedSel.value;
                    savedSel.addEventListener('change', function () {
                        const v = this.value;
                        if (v === '') {
                            return;
                        }
                        if (v === '__save__') {
                            this.value = prevValue;
                            const name = window.prompt('Name for this saved filter:');
                            if (!name || !name.trim()) {
                                return;
                            }
                            const nameInput = document.getElementById('sf-pm-save-filter-name');
                            const saveForm = document.getElementById('sf-pm-save-filter');
                            if (nameInput && saveForm) {
                                nameInput.value = name.trim();
                                saveForm.submit();
                            }
                            return;
                        }
                        if (v === '__delete__') {
                            this.value = prevValue;
                            const applied = parseInt(this.getAttribute('data-applied') || '0', 10);
                            if (!applied) {
                                window.alert('Apply a saved filter first, then delete it.');
                                return;
                            }
                            if (!window.confirm('Delete this saved filter?')) {
                                return;
                            }
                            const idInput = document.getElementById('sf-pm-delete-filter-id');
                            const delForm = document.getElementById('sf-pm-delete-filter');
                            if (idInput && delForm) {
                                idInput.value = String(applied);
                                delForm.submit();
                            }
                            return;
                        }
                        const opt = this.selectedOptions[0];
                        const url = opt ? opt.getAttribute('data-url') : '';
                        if (url) {
                            window.location = url;
                        }
                    });
                }

                updateCount();
            })();
            </script>
            JS);

        });
    }

    public function saveFilterAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }

        $userId = (int) ($this->auth->id() ?? 0);
        $name = trim((string) $this->request->post('name', 'string', ''));
        $params = (array) $this->request->post('params', 'raw', []);

        try {
            $id = $this->savedFilters->save($userId, $name, $params);
            $filter = $this->savedFilters->findForUser($id, $userId);
            $this->notify->success("Saved filter '{$name}'.");
            redirect_to('storefeeder/admin/products', ($filter['params'] ?? []) + [
                'saved_filter' => $id,
            ]);
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            redirect_to('storefeeder/admin/products', $this->savedFilters->sanitizeParams($params));
        }
    }

    public function deleteFilterAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }

        $userId = (int) ($this->auth->id() ?? 0);
        $id = (int) $this->request->post('id', 'int', 0);

        try {
            $this->savedFilters->deleteForUser($id, $userId);
            $this->notify->success('Saved filter deleted.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('storefeeder/admin/products');
    }

    /**
     * @param list<int> $productIds
     * @return array<int, int> product_id => qty sold in last 30 days
     */
    private function soldLast30DaysMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $tid = TenantContext::get()->has() ? (int) TenantContext::get()->id() : 0;
        if ($tid <= 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $params = array_merge($productIds, [$tid]);
        $rows = $this->db->query(
            "SELECT v.`product_id`, COALESCE(SUM(i.`qty`), 0) AS sold
               FROM `#__sf_order_items` i
               INNER JOIN `#__sf_orders` o ON o.`id` = i.`order_id`
               INNER JOIN `#__sf_product_variants` v ON v.`id` = i.`variant_id`
              WHERE v.`product_id` IN ({$placeholders})
                AND o.`created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND o.`tenant_id` = ?
              GROUP BY v.`product_id`",
            $params
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['product_id']] = (int) $row['sold'];
        }
        return $out;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, int> variant_id => qty sold in last 30 days
     */
    private function soldLast30DaysByVariantMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $tid = TenantContext::get()->has() ? (int) TenantContext::get()->id() : 0;
        if ($tid <= 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $params = array_merge($productIds, [$tid]);
        $rows = $this->db->query(
            "SELECT i.`variant_id`, COALESCE(SUM(i.`qty`), 0) AS sold
               FROM `#__sf_order_items` i
               INNER JOIN `#__sf_orders` o ON o.`id` = i.`order_id`
               INNER JOIN `#__sf_product_variants` v ON v.`id` = i.`variant_id`
              WHERE v.`product_id` IN ({$placeholders})
                AND i.`variant_id` IS NOT NULL
                AND o.`created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND o.`tenant_id` = ?
              GROUP BY i.`variant_id`",
            $params
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['variant_id']] = (int) $row['sold'];
        }
        return $out;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, int|null> product_id => days since last order (null = never)
     */
    private function daysSinceLastOrderedMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $tid = TenantContext::get()->has() ? (int) TenantContext::get()->id() : 0;
        if ($tid <= 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $params = array_merge($productIds, [$tid]);
        $rows = $this->db->query(
            "SELECT v.`product_id`, MAX(o.`created_at`) AS last_ordered
               FROM `#__sf_order_items` i
               INNER JOIN `#__sf_orders` o ON o.`id` = i.`order_id`
               INNER JOIN `#__sf_product_variants` v ON v.`id` = i.`variant_id`
              WHERE v.`product_id` IN ({$placeholders})
                AND o.`tenant_id` = ?
              GROUP BY v.`product_id`",
            $params
        )->rows;

        $out = [];
        $now = new \DateTimeImmutable('now');
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            $last = (string) ($row['last_ordered'] ?? '');
            if ($last === '') {
                $out[$pid] = null;
                continue;
            }
            try {
                $dt = new \DateTimeImmutable($last);
                $out[$pid] = (int) $dt->diff($now)->days;
            } catch (\Throwable $e) {
                $out[$pid] = null;
            }
        }
        return $out;
    }

    // =========================================================================
    // Add
    // =========================================================================

    public function addAction(): void
    {
        $old        = $this->blankProductInput();
        $selected   = [];
        (new \Module\StoreFeeder\Services\CatalogSetupService($this->db, $this->attributes))->ensure();
        $attributes = $this->attributes->all();
        $flat       = $this->categories->flat();

        if ($this->request->isPost()) {
            $old      = $this->readProductInput();
            $selected = array_map('intval', (array) $this->request->post('product_attributes', 'raw', []));

            try {
                $product = $this->products->create($this->productPayloadFromInput($old));

                // Image upload.
                $newImage = $this->uploader->save($this->request->file('image'));
                if ($newImage !== null) {
                    $this->products->update($product->id, ['image' => $newImage]);
                    $old['image'] = $newImage;
                }

                $this->products->setAttributesFor($product->id, $selected);
                $this->persistProductChannelAttributes((int) $product->id);

                $this->persistVariants(
                    $product->id,
                    $selected,
                    $old,
                    createDefaultIfSimple: true,
                );

                $shipKeys = $this->readShippingUpdateKeys();
                if ($shipKeys !== []) {
                    $this->variants->mergeShippingForProduct(
                        (int) $product->id,
                        is_array($old['shipping'] ?? null) ? $old['shipping'] : $this->defaultShipping(),
                        $shipKeys
                    );
                }
                $infoKeys = $this->readInfoUpdateKeys();
                if ($infoKeys !== []) {
                    $this->variants->mergeProductFieldsForProduct((int) $product->id, [
                        'price'        => (float) ($old['price'] ?? 0),
                        'cost'         => (float) ($old['cost'] ?? 0),
                        'weight_grams' => (int) ($old['weight_grams'] ?? 0),
                        'name'         => (string) ($old['name'] ?? ''),
                    ], $infoKeys);
                }

                $categoryIds = array_map('intval', (array) $this->request->post('categories', 'raw', []));
                $this->categories->setCategoriesFor($product->id, $categoryIds);

                $this->logProductAudit((int) $product->id, 'created', 'Product created.', [
                    'sku'  => $product->sku,
                    'name' => $product->name,
                ]);

                $this->notify->success("Product '{$product->name}' created.");
                redirect_to('storefeeder/admin/products/edit', ['id' => $product->id]);
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        $selectedAttrs       = $this->attributesById($attributes, $selected);
        $attributePickerHtml = $this->renderAttributePicker($attributes, $selected);
        $categoryPickerHtml  = $this->renderCategoryPicker($flat, []);
        $variantsHtml        = $this->renderVariantsSection(null, $selectedAttrs, $attributes);

        echo $this->view->inline(function () use ($old, $attributePickerHtml, $categoryPickerHtml, $variantsHtml) {
            $this->view->assign('title', 'Add Product');
            echo $this->productForm(
                $old,
                $this->url->to('storefeeder/admin/products/add'),
                $attributePickerHtml,
                $categoryPickerHtml,
                $variantsHtml,
            );
        });
    }

    // =========================================================================
    // Edit
    // =========================================================================

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);

        $product = $this->products->find($id);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        (new \Module\StoreFeeder\Services\CatalogSetupService($this->db, $this->attributes))->ensure();
        $attributes  = $this->attributes->all();
        $selectedIds = $this->products->attributeIdsFor($id);

        $flat         = $this->categories->flat();
        $assignedCats = $this->categories->categoryIdsFor($id);

        if ($this->request->isPost()) {
            $old          = $this->readProductInput();
            $old['image'] = $product->image;
            $selected     = array_map('intval', (array) $this->request->post('product_attributes', 'raw', []));

            try {
                $before = $product->toArray();
                $this->products->update($id, $this->productPayloadFromInput($old));

                // Image handling — update DB first, then delete the old file.
                $newImage = $this->uploader->save($this->request->file('image'));
                if ($newImage !== null) {
                    $this->products->update($id, ['image' => $newImage]);
                    $old['image'] = $newImage;

                    if ($product->image !== '') {
                        $this->uploader->delete($product->image);
                    }
                } elseif ($this->request->post('remove_image') === '1' && $product->image !== '') {
                    $this->products->update($id, ['image' => '']);
                    $old['image'] = '';
                    $this->uploader->delete($product->image);
                }

                $this->products->setAttributesFor($id, $selected);
                $this->persistProductChannelAttributes($id);

                $categoryIds = array_map('intval', (array) $this->request->post('categories', 'raw', []));
                $this->categories->setCategoriesFor($id, $categoryIds);
                $assignedCats = $categoryIds;

                $this->persistVariants($id, $selected, $old, createDefaultIfSimple: true);
                $this->publishPricesForProduct($id);

                $shipKeys = $this->readShippingUpdateKeys();
                $shipCopied = 0;
                if ($shipKeys !== []) {
                    $shipCopied = $this->variants->mergeShippingForProduct(
                        $id,
                        is_array($old['shipping'] ?? null) ? $old['shipping'] : $this->defaultShipping(),
                        $shipKeys
                    );
                }

                $infoKeys = $this->readInfoUpdateKeys();
                $infoCopied = 0;
                if ($infoKeys !== []) {
                    $infoCopied = $this->variants->mergeProductFieldsForProduct($id, [
                        'price'        => (float) ($old['price'] ?? 0),
                        'cost'         => (float) ($old['cost'] ?? 0),
                        'weight_grams' => (int) ($old['weight_grams'] ?? 0),
                        'name'         => (string) ($old['name'] ?? ''),
                    ], $infoKeys);
                }

                $action = (string) ($old['product_action'] ?? '');
                $msg = match ($action) {
                    'archive'     => 'Product archived.',
                    'discontinue' => 'Product discontinued.',
                    default       => 'Product updated.',
                };
                if ($infoCopied > 0) {
                    $msg .= ' Product fields copied to ' . $infoCopied . ' variant(s).';
                }
                if ($shipCopied > 0) {
                    $msg .= ' Shipping copied to ' . $shipCopied . ' variant(s).';
                }

                $after = $this->products->find($id);
                $changes = $this->diffProductAudit($before, $after?->toArray() ?? []);
                if ($action === 'archive' || $action === 'discontinue') {
                    $changes['active'] = ['from' => !empty($before['active']), 'to' => false];
                }
                if ($infoCopied > 0) {
                    $changes['info_to_variants'] = ['from' => '', 'to' => implode(', ', $infoKeys)];
                }
                if ($shipCopied > 0) {
                    $changes['shipping_to_variants'] = ['from' => '', 'to' => implode(', ', $shipKeys)];
                }
                $this->logProductAudit(
                    $id,
                    $action !== '' ? $action : 'updated',
                    $msg,
                    $changes
                );

                $this->notify->success($msg);
                $redirect = ['id' => $id];
                if ($shipCopied > 0 && $infoCopied === 0) {
                    $redirect['panel'] = 'shipping';
                }
                redirect_to('storefeeder/admin/products/edit', $redirect);
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
                // Fall through with the user's input preserved.
            }

            $selectedIds = $selected;
            $product     = $this->products->find($id) ?? $product;
        } else {
            $old = [
                'sku'                => $product->sku,
                'name'               => $product->name,
                'brand'              => $product->brand,
                'image'              => $product->image,
                'description'        => $product->description,
                'barcode'            => $product->barcode,
                'upc'                => $product->upc,
                'mpn'                => $product->mpn,
                'supplier_sku'       => $product->supplierSku,
                'supplier_name'      => $product->supplierName,
                'warehouse'          => $product->warehouse !== '' ? $product->warehouse : 'Default',
                'price'              => (string) $product->price,
                'rrp'                => (string) $product->rrp,
                'cost'               => (string) $product->cost,
                'tax_class'          => $product->taxClass,
                'price_includes_vat' => $product->priceIncludesVat ? 1 : 0,
                'weight_grams'       => $product->weightGrams,
                'length_mm'          => $product->lengthMm > 0 ? (string) $product->lengthMm : '',
                'width_mm'           => $product->widthMm > 0 ? (string) $product->widthMm : '',
                'height_mm'          => $product->heightMm > 0 ? (string) $product->heightMm : '',
                'shipping'           => array_merge($this->defaultShipping(), $product->shipping),
                'notes'              => $product->notes,
                'regulatory'         => array_merge($this->defaultRegulatory(), $product->regulatory),
                'ebay_category_id'   => $product->ebayCategoryId,
                'ebay_category_name' => $product->ebayCategoryName,
                'ebay_condition'     => $product->ebayCondition,
                'channel_attributes' => (new \Module\StoreFeeder\Services\ChannelAttributeRepository($this->db))
                    ->productOverridesByChannel($id),
                'kit_display_on_order' => $product->kitDisplayOnOrder ? 1 : 0,
                'active'             => $product->active ? 1 : 0,
            ];
        }

        $selectedAttrs       = $this->attributesById($attributes, $selectedIds);
        $attributePickerHtml = $this->renderAttributePicker($attributes, $selectedIds);
        $categoryPickerHtml  = $this->renderCategoryPicker($flat, $assignedCats);
        $stockMap            = $this->variantStockMap($id);
        $variantsHtml        = $this->renderVariantsSection($product, $selectedAttrs, $attributes, $stockMap);
        $listingsHtml        = $this->renderListingsTab($id, (string) $old['sku'], (string) $old['name']);
        $ordersHtml          = $this->renderProductOrdersTab($id);
        $auditHtml           = $this->renderProductAuditTab($id);
        $documentsHtml       = $this->renderProductDocumentsTab($id);
        $bundleHtml          = $this->renderProductBundleTab($id, $old);
        $inventoryTotal      = $this->productInventoryTotal($id);

        echo $this->view->inline(function () use (
            $old, $id, $attributePickerHtml, $categoryPickerHtml, $variantsHtml,
            $listingsHtml, $ordersHtml, $auditHtml, $documentsHtml, $bundleHtml, $inventoryTotal
        ) {
            $this->view->assign('title', (string) ($old['name'] ?: 'Product details'));
            echo $this->productForm(
                $old,
                $this->url->to('storefeeder/admin/products/edit', ['id' => $id]),
                $attributePickerHtml,
                $categoryPickerHtml,
                $variantsHtml,
                $id,
                $listingsHtml,
                $inventoryTotal,
                $ordersHtml,
                $auditHtml,
                $documentsHtml,
                $bundleHtml,
            );
        });
    }

    public function addBundleComponentAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $productId = (int) $this->request->get('id', 'int', 0);
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        $sku = trim((string) $this->request->post('component_sku', 'string', ''));
        $qty = max(1, (int) $this->request->post('component_qty', 'int', 1));
        $deleteOriginal = (int) $this->request->post('delete_original', 'int', 0) === 1;
        $updateKitInv = (int) $this->request->post('update_kit_inventory', 'int', 0) === 1;

        try {
            if ($sku === '') {
                throw new \InvalidArgumentException('Enter a component product SKU.');
            }
            $component = $this->resolveBundleComponentProduct($sku);
            if ($component === null || $component->id === null) {
                throw new \InvalidArgumentException("No product found for SKU '{$sku}'.");
            }
            $row = $this->bundles->add(
                $productId,
                (int) $component->id,
                $component->sku,
                $qty,
                $deleteOriginal,
                $updateKitInv
            );
            $this->logProductAudit($productId, 'bundle_component_added', 'Bundle component added: ' . $component->sku, [
                'component_id' => $row['id'],
                'sku'          => $component->sku,
                'qty'          => $qty,
            ]);
            $this->notify->success('Component added to bundle.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'bundle']);
    }

    public function deleteBundleComponentAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $productId = (int) $this->request->get('id', 'int', 0);
        $compId = (int) $this->request->post('component_id', 'int', 0);
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        $row = $this->bundles->find($compId);
        if ($row === null || (int) $row['product_id'] !== $productId) {
            $this->notify->error('Bundle component not found.');
            redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'bundle']);
            return;
        }

        $this->bundles->deleteForProduct($productId, $compId);
        $this->logProductAudit($productId, 'bundle_component_removed', 'Bundle component removed: ' . $row['component_sku'], [
            'component_id' => $compId,
            'sku'          => $row['component_sku'],
        ]);
        $this->notify->success('Component removed from bundle.');
        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'bundle']);
    }

    public function updateBundleComponentAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $productId = (int) $this->request->get('id', 'int', 0);
        $compId = (int) $this->request->post('component_id', 'int', 0);
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        $row = $this->bundles->find($compId);
        if ($row === null || (int) $row['product_id'] !== $productId) {
            $this->notify->error('Bundle component not found.');
            redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'bundle']);
            return;
        }

        $qty = max(1, (int) $this->request->post('component_qty', 'int', 1));
        $deleteOriginal = (int) $this->request->post('delete_original', 'int', 0) === 1;
        $updateKitInv = (int) $this->request->post('update_kit_inventory', 'int', 0) === 1;

        $before = [
            'qty'                  => (int) $row['qty'],
            'delete_original'      => !empty($row['delete_original']) ? 1 : 0,
            'update_kit_inventory' => !empty($row['update_kit_inventory']) ? 1 : 0,
        ];
        $this->bundles->update($productId, $compId, $qty, $deleteOriginal, $updateKitInv);
        $after = [
            'qty'                  => $qty,
            'delete_original'      => $deleteOriginal ? 1 : 0,
            'update_kit_inventory' => $updateKitInv ? 1 : 0,
        ];
        $changes = [];
        foreach ($after as $k => $v) {
            if ((string) ($before[$k] ?? '') !== (string) $v) {
                $changes[$k] = ['from' => $before[$k], 'to' => $v];
            }
        }
        if ($changes !== []) {
            $this->logProductAudit(
                $productId,
                'bundle_component_updated',
                'Bundle component updated: ' . $row['component_sku'],
                ['component_id' => $compId, 'sku' => $row['component_sku'], 'changes' => $changes]
            );
        }
        $this->notify->success('Component updated.');
        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'bundle']);
    }

    public function updateKitSettingsAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $productId = (int) $this->request->get('id', 'int', 0);
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        $display = (int) $this->request->post('kit_display_on_order', 'int', 0) === 1;
        $before = $product->kitDisplayOnOrder;
        $this->products->update($productId, ['kit_display_on_order' => $display]);
        if ($before !== $display) {
            $this->logProductAudit($productId, 'kit_settings', 'Kit display-on-order updated.', [
                'kit_display_on_order' => ['from' => $before, 'to' => $display],
            ]);
        }
        $this->notify->success('Kit settings saved.');
        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'bundle']);
    }

    private function resolveBundleComponentProduct(string $sku): ?Product
    {
        $product = $this->products->findBySku($sku);
        if ($product !== null) {
            return $product;
        }
        $variant = $this->variants->findBySku($sku);
        if ($variant === null || $variant->productId === null) {
            return null;
        }
        return $this->products->find((int) $variant->productId);
    }

    public function uploadDocumentAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $productId = (int) $this->request->get('id', 'int', 0);
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        try {
            $file = $this->request->file('document');
            if (!is_array($file)) {
                throw new \InvalidArgumentException('Choose a file to upload.');
            }
            $title = trim((string) $this->request->post('document_title', 'string', ''));
            $doc = $this->documents->upload($productId, $file, $title, $this->auth->id());
            $this->logProductAudit($productId, 'document_uploaded', 'Document uploaded: ' . $doc['title'], [
                'document_id' => $doc['id'],
                'title'       => $doc['title'],
            ]);
            $this->notify->success('Document uploaded.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'documents']);
    }

    public function downloadDocumentAction(): void
    {
        $productId = (int) $this->request->get('id', 'int', 0);
        $docId = (int) $this->request->get('doc_id', 'int', 0);
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }
        $doc = $this->documents->find($docId);
        if ($doc === null || (int) $doc['product_id'] !== $productId) {
            throw new NotFoundException('Document not found.');
        }
        $path = $this->documents->absolutePath((string) $doc['stored_path']);
        if ($path === null) {
            $this->notify->error('File missing on disk.');
            redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'documents']);
            return;
        }

        $name = (string) ($doc['original_name'] !== '' ? $doc['original_name'] : $doc['title']);
        $mime = (string) ($doc['mime'] !== '' ? $doc['mime'] : 'application/octet-stream');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function deleteDocumentAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $productId = (int) $this->request->get('id', 'int', 0);
        $docId = (int) $this->request->post('doc_id', 'int', 0);
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }
        $doc = $this->documents->find($docId);
        if ($doc === null || (int) $doc['product_id'] !== $productId) {
            $this->notify->error('Document not found.');
            redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'documents']);
            return;
        }

        $title = (string) $doc['title'];
        $this->documents->delete($docId);
        $this->logProductAudit($productId, 'document_deleted', 'Document deleted: ' . $title, [
            'document_id' => $docId,
            'title'       => $title,
        ]);
        $this->notify->success('Document deleted.');
        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'documents']);
    }

    public function updateListingsAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $productId = (int) $this->request->get('id', 'int', 0);
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        $raw = $this->request->post('listings', 'raw', []);
        if (!is_array($raw)) {
            $raw = [];
        }

        $listingsRepo = new ListingRepository($this->db);
        $updated = 0;
        $pushedPrices = 0;

        foreach ($raw as $listingId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $listingId = (int) $listingId;
            if ($listingId <= 0) {
                continue;
            }
            $listing = $listingsRepo->findById($listingId);
            if ($listing === null || $listing->variantId === null) {
                continue;
            }
            // Ensure listing belongs to this product
            $variant = $this->variants->find((int) $listing->variantId);
            if ($variant === null || (int) $variant->productId !== $productId) {
                continue;
            }

            $priceRaw = trim((string) ($row['price'] ?? ''));
            $price = $priceRaw === '' ? null : (float) $priceRaw;
            $oversell = max(0, (int) ($row['oversell'] ?? 0));
            $controlled = !empty($row['controlled']);
            $title = trim((string) ($row['channel_title'] ?? ''));

            $listingsRepo->updateFields($listingId, [
                'channel_title' => $title,
                'price'         => $price,
                'oversell'      => $oversell,
                'controlled'    => $controlled,
            ]);
            $updated++;

            if ($controlled && $price !== null && $price > 0) {
                try {
                    $result = $this->listingSync()->publishPriceToEnabledChannels(
                        (int) $listing->variantId,
                        $price
                    );
                    $pushedPrices += (int) ($result['pushed'] ?? 0);
                } catch (\Throwable $e) {
                    error_log('updateListings price push #' . $listingId . ': ' . $e->getMessage());
                }
            }
        }

        $msg = $updated === 1 ? '1 listing updated.' : $updated . ' listings updated.';
        if ($pushedPrices > 0) {
            $msg .= ' Prices pushed to ' . $pushedPrices . ' channel(s).';
        }
        $this->notify->success($msg);
        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'listings']);
    }

    public function bulkListingsAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $productId = (int) $this->request->get('id', 'int', 0);
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        $action = (string) $this->request->post('bulk_action', 'string', '');
        $idsRaw = $this->request->post('listing_ids', 'raw', []);
        $ids = [];
        if (is_array($idsRaw)) {
            foreach ($idsRaw as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        $ids = array_values($ids);

        if ($action === '' || $ids === []) {
            $this->notify->error('Select at least one listing and a bulk action.');
            redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'listings']);
            return;
        }

        $listingsRepo = new ListingRepository($this->db);
        $channels = $this->channelRepo;
        $ok = 0;
        $fail = 0;
        $skipped = 0;

        foreach ($ids as $listingId) {
            $listing = $listingsRepo->findById($listingId);
            if ($listing === null || $listing->variantId === null) {
                $skipped++;
                continue;
            }
            $variant = $this->variants->find((int) $listing->variantId);
            if ($variant === null || (int) $variant->productId !== $productId) {
                $skipped++;
                continue;
            }

            try {
                switch ($action) {
                    case 'control_on':
                        $listingsRepo->updateFields($listingId, ['controlled' => true]);
                        $ok++;
                        break;
                    case 'control_off':
                        $listingsRepo->updateFields($listingId, ['controlled' => false]);
                        $ok++;
                        break;
                    case 'sync_inventory':
                        $channel = $channels->find((int) $listing->channelId);
                        if ($channel === null || !$channel->isConnected()) {
                            $fail++;
                            break;
                        }
                        $this->listingSync()->syncInventory($channel, (int) $listing->variantId);
                        $ok++;
                        break;
                    case 'repush':
                        $channel = $channels->find((int) $listing->channelId);
                        if ($channel === null || !$channel->isConnected()) {
                            $fail++;
                            break;
                        }
                        $this->listingSync()->pushVariant($channel, (int) $listing->variantId);
                        $ok++;
                        break;
                    case 'remove':
                        $listingsRepo->delete($listingId);
                        $ok++;
                        break;
                    default:
                        $this->notify->error('Unknown bulk action.');
                        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'listings']);
                        return;
                }
            } catch (\Throwable $e) {
                $fail++;
                error_log('bulkListings #' . $listingId . ' (' . $action . '): ' . $e->getMessage());
            }
        }

        if ($action === 'remove' && $ok > 0) {
            $this->logProductAudit($productId, 'listings_removed', $ok . ' listing(s) removed from StoreFeeder.', [
                'count'  => $ok,
                'action' => $action,
            ]);
        } elseif (in_array($action, ['control_on', 'control_off'], true) && $ok > 0) {
            $this->logProductAudit($productId, 'listings_bulk', 'Bulk listing control updated.', [
                'count'  => $ok,
                'action' => $action,
            ]);
        }

        $parts = [];
        if ($ok > 0) {
            $parts[] = $ok . ' listing(s) updated.';
        }
        if ($fail > 0) {
            $parts[] = $fail . ' failed.';
        }
        if ($skipped > 0) {
            $parts[] = $skipped . ' skipped.';
        }
        if ($parts === []) {
            $this->notify->error('No listings were updated.');
        } elseif ($fail > 0) {
            $this->notify->error(implode(' ', $parts));
        } else {
            $this->notify->success(implode(' ', $parts));
        }

        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'listings']);
    }

    // =========================================================================
    // Push variant to channel
    // =========================================================================

    public function pushAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $productId = (int) $this->request->get('id', 'int', 0);
        $variantId = (int) $this->request->post('variant_id', 'int', 0);
        $channelId = (int) $this->request->post('channel_id', 'int', 0);

        $product = $this->products->find($productId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        $vault    = CredentialsVault::forTenant((string) $this->config->get('storefeeder.credentials_key', ''));
        $channels = new ChannelRepository($this->db);
        $channel  = $channels->find($channelId);

        if ($channel === null || !$channel->isConnected()) {
            $this->notify->error('Channel not found or not connected.');
            redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'listings']);
            return;
        }

        try {
            $sync = $this->listingSync($vault, $channels);
            $result = $sync->pushVariant($channel, $variantId);
            $this->notify->success('Pushed to ' . $channel->label . ' — listing ' . $result['listing_id']);
        } catch (\Throwable $e) {
            $this->notify->error('Push failed: ' . $e->getMessage());
        }

        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'listings']);
    }


    public function syncInventoryAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $productId = (int) $this->request->get('id', 'int', 0);
        $variantId = (int) $this->request->post('variant_id', 'int', 0);
        $channelId = (int) $this->request->post('channel_id', 'int', 0);

        $vault    = CredentialsVault::forTenant((string) $this->config->get('storefeeder.credentials_key', ''));
        $channels = new ChannelRepository($this->db);
        $channel  = $channels->find($channelId);

        if ($channel === null || !$channel->isConnected()) {
            $this->notify->error('Channel not found or not connected.');
            redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'listings']);
            return;
        }

        try {
            $sync = $this->listingSync($vault, $channels);
            $sync->syncInventory($channel, $variantId);
            $this->notify->success('Inventory synced to ' . $channel->label . '.');
        } catch (\Throwable $e) {
            $this->notify->error('Inventory sync failed: ' . $e->getMessage());
        }

        redirect_to('storefeeder/admin/products/edit', ['id' => $productId, 'panel' => 'listings']);
    }

    private function listingSync(?CredentialsVault $vault = null, ?ChannelRepository $channels = null): ListingSyncService
    {
        $vault    = $vault ?? $this->vault;
        $channels = $channels ?? $this->channelRepo;

        return new ListingSyncService(
            $this->db,
            $channels,
            new ChannelClientFactory($vault, $channels),
            new ListingRepository($this->db),
            $this->variants,
            $this->products,
            $this->inventory,
            new \Module\StoreFeeder\Services\ListingTemplateRepository($this->db),
        );
    }

    /** Push prices to channels with Automatic Price Updates enabled. */
    private function publishPricesForProduct(int $productId): void
    {
        try {
            $sync = $this->listingSync();
            foreach ($this->variants->forProduct($productId) as $variant) {
                $price = $variant->price > 0 ? $variant->price : 0.0;
                if ($price <= 0) {
                    $product = $this->products->find($productId);
                    $price   = $product !== null ? (float) $product->price : 0.0;
                }
                if ($price <= 0) {
                    continue;
                }
                $sync->publishPriceToEnabledChannels((int) $variant->id, $price);
            }
        } catch (\Throwable $e) {
            error_log('publishPricesForProduct #' . $productId . ': ' . $e->getMessage());
        }
    }

    private function renderListingsTab(int $productId, string $productSku, string $productName): string
    {
        $variants = $this->variants->forProduct($productId);
        $factory = new ChannelClientFactory($this->vault, $this->channelRepo);
        $channels = array_values(array_filter(
            $this->channelRepo->all(),
            fn($c) => $c->enabled && !$factory->isCourier($c)
        ));
        $connected = array_values(array_filter($channels, static fn($c) => $c->isConnected()));
        $listingsRepo = new ListingRepository($this->db);
        $stockMap = $this->variantStockMap($productId);

        $variantsById = [];
        foreach ($variants as $v) {
            if ($v->id !== null) {
                $variantsById[(int) $v->id] = $v;
            }
        }

        // Group existing listings by channel
        $byChannel = [];
        foreach ($listingsRepo->forProduct($productId) as $listing) {
            $cid = (int) $listing->channelId;
            $byChannel[$cid][] = $listing;
        }

        $channelMap = [];
        foreach ($channels as $c) {
            $channelMap[(int) $c->id] = $c;
        }

        ob_start();
        echo '<form method="post" action="'
           . $this->view->e($this->url->to('storefeeder/admin/products/bulk-listings', ['id' => $productId]))
           . '" id="sf-listings-bulk-form" class="d-none">' . $this->request->csrfField() . '</form>';
        echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">';
        echo '<h2 class="h5 mb-0">Existing Listings</h2>';
        echo '<div class="d-flex flex-wrap gap-2 align-items-center">';
        echo '<select name="bulk_action" id="sf-listings-bulk-action" class="form-select form-select-sm" style="width:auto"'
           . ' form="sf-listings-bulk-form">';
        echo '<option value="">Bulk actions</option>';
        echo '<option value="control_on">Turn control On</option>';
        echo '<option value="control_off">Turn control Off</option>';
        echo '<option value="sync_inventory">Sync inventory</option>';
        echo '<option value="repush">Re-push</option>';
        echo '<option value="remove">Remove from StoreFeeder</option>';
        echo '</select>';
        echo '<button type="submit" class="btn btn-sm btn-outline-secondary" id="sf-listings-bulk-confirm"'
           . ' form="sf-listings-bulk-form" disabled>Confirm</button>';
        echo '<span class="text-muted small" id="sf-listings-bulk-count">0 selected</span>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="sf-listings-clear-filter">Clear Filtering</button>';
        echo '<button type="button" class="btn btn-sm btn-success" id="sf-listings-new-toggle">'
           . '<i class="bi bi-plus-lg"></i> New Listing</button>';
        echo '</div></div>';

        // New listing / push panel (collapsed by default)
        echo '<div id="sf-listings-new" class="card border-0 shadow-sm mb-3" style="display:none">';
        echo '<div class="card-body">';
        echo '<h3 class="h6">Push variant to channel</h3>';
        if ($connected === [] || $variants === []) {
            echo '<p class="text-muted small mb-0">Connect a sales channel and add variants to create listings.</p>';
        } else {
            echo '<p class="text-muted small">Requires channel policies / location where applicable.</p>';
            echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
            echo '<thead><tr><th>Variant</th><th>SKU</th><th>Channel</th><th>Status</th><th></th></tr></thead><tbody>';
            foreach ($variants as $v) {
                foreach ($connected as $c) {
                    $listing = $listingsRepo->find((int) $v->id, (int) $c->id);
                    echo '<tr>';
                    echo '<td>' . $this->view->e($v->label() !== '' ? $v->label() : ($v->name ?: $v->sku)) . '</td>';
                    echo '<td><code>' . $this->view->e($v->sku) . '</code></td>';
                    echo '<td>' . $this->view->e($c->label !== '' ? $c->label : $c->name) . '</td>';
                    echo '<td>';
                    if ($listing !== null) {
                        $badge = match ($listing->status) {
                            'pushed' => 'text-bg-success',
                            'error'  => 'text-bg-danger',
                            default  => 'text-bg-secondary',
                        };
                        echo '<span class="badge ' . $badge . '">' . $this->view->e($listing->status) . '</span>';
                    } else {
                        echo '<span class="text-muted">not pushed</span>';
                    }
                    echo '</td><td class="text-end text-nowrap">';
                    echo '<form method="post" action="'
                       . $this->view->e($this->url->to('storefeeder/admin/products/push', ['id' => $productId]))
                       . '" class="d-inline">' . $this->request->csrfField()
                       . '<input type="hidden" name="variant_id" value="' . (int) $v->id . '">'
                       . '<input type="hidden" name="channel_id" value="' . (int) $c->id . '">'
                       . '<button type="submit" class="btn btn-sm btn-outline-primary">'
                       . ($listing?->isPushed() ? 'Re-push' : 'Push') . '</button></form> ';
                    if ($listing !== null && $listing->isPushed()) {
                        echo '<form method="post" action="'
                           . $this->view->e($this->url->to('storefeeder/admin/products/sync-inventory', ['id' => $productId]))
                           . '" class="d-inline">' . $this->request->csrfField()
                           . '<input type="hidden" name="variant_id" value="' . (int) $v->id . '">'
                           . '<input type="hidden" name="channel_id" value="' . (int) $c->id . '">'
                           . '<button type="submit" class="btn btn-sm btn-outline-secondary">Sync qty</button></form>';
                    }
                    echo '</td></tr>';
                }
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';

        echo '<form method="post" action="'
           . $this->view->e($this->url->to('storefeeder/admin/products/update-listings', ['id' => $productId]))
           . '" id="sf-listings-update-form">';
        echo $this->request->csrfField();

        if ($byChannel === []) {
            echo '<div class="alert alert-info">No listings yet. Use <strong>+ New Listing</strong> to push a variant to a channel.</div>';
            echo '</form>';
            echo '<script>(function(){var t=document.getElementById("sf-listings-new-toggle"),p=document.getElementById("sf-listings-new");'
               . 'if(t&&p)t.addEventListener("click",function(){p.style.display=p.style.display==="none"?"":"none";});})();</script>';
            return (string) ob_get_clean();
        }

        $editUrl = $this->url->to('storefeeder/admin/products/edit', ['id' => $productId]);
        echo '<style>
          #sf-listings-table .sf-listing-link{color:#c2185b;text-decoration:none;font-size:.8125rem}
          #sf-listings-table .sf-listing-link:hover{text-decoration:underline;color:#ad1457}
          #sf-listings-table .sf-listing-group td{font-size:.875rem}
          #sf-listings-table .sf-listing-status-err{color:#dc3545;font-size:.75rem;line-height:1.25}
        </style>';
        echo '<div class="table-responsive border rounded bg-white">';
        echo '<table class="table table-sm align-middle mb-0" id="sf-listings-table">';
        echo '<thead class="table-light"><tr>';
        echo '<th style="width:2rem"><input type="checkbox" class="form-check-input" id="sf-listings-select-all"'
           . ' title="Select all visible"></th>';
        echo '<th>Channel Identifier</th><th>Channel</th><th>Channel Sku</th><th>Channel Name</th>';
        echo '<th style="min-width:6rem">Price</th><th style="min-width:5rem">Oversell</th>';
        echo '<th>StoreFeeder SKU</th><th>StoreFeeder Inventory</th>';
        echo '<th>Listing Controlled by StoreFeeder</th><th>Listing Status</th>';
        echo '</tr>';
        echo '<tr class="sf-listings-filter">';
        echo '<th></th>';
        echo '<th><input type="search" class="form-control form-control-sm" data-col="ext" placeholder="Filter"></th>';
        echo '<th><select class="form-select form-select-sm" data-col="channel"><option value="">All</option>';
        foreach ($channelMap as $c) {
            if (!isset($byChannel[(int) $c->id])) {
                continue;
            }
            echo '<option value="' . (int) $c->id . '">' . $this->view->e($c->label !== '' ? $c->label : $c->name) . '</option>';
        }
        echo '</select></th>';
        echo '<th><input type="search" class="form-control form-control-sm" data-col="sku" placeholder="Filter"></th>';
        echo '<th><input type="search" class="form-control form-control-sm" data-col="title" placeholder="Filter"></th>';
        echo '<th></th><th></th><th></th><th></th>';
        echo '<th><select class="form-select form-select-sm" data-col="controlled"><option value="">All</option>'
           . '<option value="1">On</option><option value="0">Off</option></select></th>';
        echo '<th><select class="form-select form-select-sm" data-col="status"><option value="">All</option>'
           . '<option value="pushed">Pushed</option><option value="pending">Pending</option>'
           . '<option value="error">Error</option><option value="unlisted">Unlisted</option></select></th>';
        echo '</tr></thead><tbody>';

        foreach ($byChannel as $channelId => $channelListings) {
            $channel = $channelMap[$channelId] ?? null;
            $chLabel = $channel !== null
                ? ($channel->label !== '' ? $channel->label : $channel->name)
                : ('Channel #' . $channelId);
            $chType = $channel !== null ? strtoupper((string) $channel->type) : 'CHANNEL';
            $groupId = 'sf-lg-' . $channelId;
            $chIcon = match (strtolower((string) ($channel->type ?? ''))) {
                'ebay' => 'bi-bag',
                'amazon' => 'bi-amazon',
                'bnq' => 'bi-tools',
                'shopify' => 'bi-shop',
                default => 'bi-broadcast',
            };

            echo '<tr class="sf-listing-group table-secondary" data-channel="' . (int) $channelId . '">';
            echo '<td><button type="button" class="btn btn-sm btn-link p-0 sf-listing-toggle" data-target="'
               . $groupId . '" aria-expanded="true"><i class="bi bi-caret-down-fill"></i></button></td>';
            echo '<td colspan="10">'
               . '<i class="bi ' . $chIcon . ' text-primary me-1"></i>'
               . '<strong class="text-uppercase">' . $this->view->e($chType) . '</strong> '
               . '<strong>' . $this->view->e($chLabel) . '</strong>'
               . ' <span class="text-muted">(' . count($channelListings) . ')</span></td>';
            echo '</tr>';

            foreach ($channelListings as $listing) {
                $vid = (int) $listing->variantId;
                $variant = $variantsById[$vid] ?? null;
                $vSku = $variant !== null ? $variant->sku : $productSku;
                $vLabel = '';
                if ($variant !== null) {
                    $vLabel = $variant->label();
                    if ($vLabel === '') {
                        $vLabel = $variant->name !== '' ? $variant->name : $variant->sku;
                    }
                }
                $vName = $vLabel !== '' ? $vLabel : $productName;
                $channelSku = $listing->externalSku !== '' ? $listing->externalSku : $vSku;
                $sfSkuDisplay = trim($productName) !== ''
                    ? ($productName . ($vSku !== '' && strcasecmp($vSku, $productSku) !== 0 ? ' * ' . $vSku : ''))
                    : ($productSku !== '' ? $productSku : $vSku);
                if ($sfSkuDisplay === '' && $vLabel !== '') {
                    $sfSkuDisplay = $vLabel;
                }
                $qty = $stockMap[$vid] ?? 0;
                $displayPrice = $listing->price !== null
                    ? (string) $listing->price
                    : ($variant !== null ? (string) $variant->price : '');
                $title = $listing->channelTitle !== '' ? $listing->channelTitle : $vName;
                $lid = (int) $listing->id;
                $statusIcon = match ($listing->status) {
                    'pushed' => '<i class="bi bi-check-circle-fill text-success" title="Pushed"></i>',
                    'error'  => '<i class="bi bi-x-circle-fill text-danger" title="Error"></i>',
                    'unlisted' => '<i class="bi bi-dash-circle text-secondary" title="Unlisted"></i>',
                    default  => '<i class="bi bi-clock text-warning" title="Pending"></i>',
                };
                $extUrl = '';
                if ($listing->externalId !== '' && $channel !== null
                    && strtolower((string) $channel->type) === 'ebay') {
                    $extUrl = 'https://www.ebay.co.uk/itm/' . rawurlencode($listing->externalId);
                }

                echo '<tr class="sf-listing-row ' . $groupId . '"'
                   . ' data-channel="' . (int) $channelId . '"'
                   . ' data-ext="' . $this->view->e(strtolower($listing->externalId)) . '"'
                   . ' data-sku="' . $this->view->e(strtolower($channelSku)) . '"'
                   . ' data-title="' . $this->view->e(strtolower($title)) . '"'
                   . ' data-status="' . $this->view->e($listing->status) . '"'
                   . ' data-controlled="' . ($listing->controlled ? '1' : '0') . '">';
                echo '<td><input type="checkbox" class="form-check-input sf-listing-check" name="listing_ids[]"'
                   . ' value="' . $lid . '" form="sf-listings-bulk-form"></td>';
                echo '<td>';
                if ($listing->externalId !== '') {
                    if ($extUrl !== '') {
                        echo '<a class="sf-listing-link" href="' . $this->view->e($extUrl)
                           . '" target="_blank" rel="noopener">'
                           . $this->view->e($listing->externalId) . '</a>';
                    } else {
                        echo '<span class="sf-listing-link">' . $this->view->e($listing->externalId) . '</span>';
                    }
                } else {
                    echo '<span class="text-muted">—</span>';
                }
                echo '</td>';
                echo '<td class="small">' . $this->view->e($chLabel) . '</td>';
                echo '<td><span class="sf-listing-link">' . $this->view->e($channelSku) . '</span></td>';
                echo '<td><input type="text" class="form-control form-control-sm" name="listings[' . $lid . '][channel_title]" value="'
                   . $this->view->e($title) . '"></td>';
                echo '<td><div class="input-group input-group-sm">'
                   . '<span class="input-group-text">£</span>'
                   . '<input type="text" class="form-control" name="listings[' . $lid . '][price]" value="'
                   . $this->view->e($displayPrice) . '"></div></td>';
                echo '<td><input type="number" min="0" class="form-control form-control-sm" name="listings[' . $lid . '][oversell]" value="'
                   . (int) $listing->oversell . '"></td>';
                echo '<td><a class="sf-listing-link" href="' . $this->view->e($editUrl) . '">'
                   . $this->view->e($sfSkuDisplay) . '</a></td>';
                echo '<td class="fw-semibold">' . (int) $qty . '</td>';
                echo '<td><div class="form-check form-switch mb-0">'
                   . '<input type="hidden" name="listings[' . $lid . '][controlled]" value="0">'
                   . '<input class="form-check-input sf-listing-controlled" type="checkbox" role="switch"'
                   . ' name="listings[' . $lid . '][controlled]" value="1" id="listing_ctrl_' . $lid . '"'
                   . ($listing->controlled ? ' checked' : '') . '>'
                   . '<label class="form-check-label small" for="listing_ctrl_' . $lid . '">'
                   . ($listing->controlled ? 'On' : 'Off') . '</label></div></td>';
                echo '<td><div class="d-flex align-items-start gap-1">' . $statusIcon;
                if ($listing->lastError !== '') {
                    echo '<span class="sf-listing-status-err">'
                       . $this->view->e(mb_substr($listing->lastError, 0, 160))
                       . '</span>';
                } elseif ($listing->status === 'pushed') {
                    echo '<span class="text-success small">Pushed</span>';
                } elseif ($listing->status === 'pending') {
                    echo '<span class="text-warning small">Pending</span>';
                } elseif ($listing->status === 'unlisted') {
                    echo '<span class="text-muted small">Unlisted</span>';
                }
                echo '</div></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
        echo '<div class="d-flex justify-content-end mt-3">';
        echo '<button type="submit" class="btn btn-primary">Update listings</button>';
        echo '</div></form>';

        echo <<<'JS'
<script>
(function () {
  const toggleNew = document.getElementById('sf-listings-new-toggle');
  const newPanel = document.getElementById('sf-listings-new');
  if (toggleNew && newPanel) {
    toggleNew.addEventListener('click', function () {
      newPanel.style.display = newPanel.style.display === 'none' ? '' : 'none';
    });
  }
  document.querySelectorAll('.sf-listing-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const cls = btn.getAttribute('data-target');
      const open = btn.getAttribute('aria-expanded') !== 'false';
      document.querySelectorAll('tr.' + cls).forEach(function (tr) {
        tr.style.display = open ? 'none' : '';
      });
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      const icon = btn.querySelector('i');
      if (icon) icon.className = open ? 'bi bi-caret-right-fill' : 'bi bi-caret-down-fill';
    });
  });
  function applyFilters() {
    const ext = (document.querySelector('.sf-listings-filter [data-col="ext"]') || {}).value || '';
    const channel = (document.querySelector('.sf-listings-filter [data-col="channel"]') || {}).value || '';
    const sku = (document.querySelector('.sf-listings-filter [data-col="sku"]') || {}).value || '';
    const title = (document.querySelector('.sf-listings-filter [data-col="title"]') || {}).value || '';
    const status = (document.querySelector('.sf-listings-filter [data-col="status"]') || {}).value || '';
    const controlled = (document.querySelector('.sf-listings-filter [data-col="controlled"]') || {}).value || '';
    const extQ = ext.trim().toLowerCase();
    const skuQ = sku.trim().toLowerCase();
    const titleQ = title.trim().toLowerCase();
    document.querySelectorAll('#sf-listings-table .sf-listing-row').forEach(function (tr) {
      const ok =
        (!extQ || (tr.getAttribute('data-ext') || '').indexOf(extQ) >= 0) &&
        (!channel || tr.getAttribute('data-channel') === channel) &&
        (!skuQ || (tr.getAttribute('data-sku') || '').indexOf(skuQ) >= 0) &&
        (!titleQ || (tr.getAttribute('data-title') || '').indexOf(titleQ) >= 0) &&
        (!status || tr.getAttribute('data-status') === status) &&
        (controlled === '' || tr.getAttribute('data-controlled') === controlled);
      tr.style.display = ok ? '' : 'none';
      const c = tr.querySelector('.sf-listing-check');
      if (c) {
        c.disabled = !ok;
        if (!ok) c.checked = false;
      }
    });
    // Hide empty channel groups when all child rows are filtered out.
    document.querySelectorAll('#sf-listings-table .sf-listing-group').forEach(function (group) {
      const cid = group.getAttribute('data-channel');
      const rows = document.querySelectorAll('#sf-listings-table .sf-listing-row[data-channel="' + cid + '"]');
      let any = false;
      rows.forEach(function (tr) { if (tr.style.display !== 'none') any = true; });
      group.style.display = any ? '' : 'none';
    });
    if (typeof syncBulkUi === 'function') syncBulkUi();
  }
  document.querySelectorAll('.sf-listings-filter input, .sf-listings-filter select').forEach(function (el) {
    el.addEventListener('input', applyFilters);
    el.addEventListener('change', applyFilters);
  });
  const clearBtn = document.getElementById('sf-listings-clear-filter');
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      document.querySelectorAll('.sf-listings-filter input').forEach(function (el) { el.value = ''; });
      document.querySelectorAll('.sf-listings-filter select').forEach(function (el) { el.value = ''; });
      applyFilters();
    });
  }

  const bulkForm = document.getElementById('sf-listings-bulk-form');
  const bulkAction = document.getElementById('sf-listings-bulk-action');
  const bulkConfirm = document.getElementById('sf-listings-bulk-confirm');
  const bulkCount = document.getElementById('sf-listings-bulk-count');
  const selectAll = document.getElementById('sf-listings-select-all');

  function visibleChecks() {
    return Array.prototype.slice.call(document.querySelectorAll('#sf-listings-table .sf-listing-row'))
      .filter(function (tr) { return tr.style.display !== 'none'; })
      .map(function (tr) { return tr.querySelector('.sf-listing-check'); })
      .filter(Boolean);
  }
  function syncBulkUi() {
    const checks = visibleChecks();
    const selected = checks.filter(function (c) { return c.checked; }).length;
    if (bulkCount) bulkCount.textContent = selected + ' selected';
    if (bulkConfirm) {
      bulkConfirm.disabled = !(selected > 0 && bulkAction && bulkAction.value);
    }
    if (selectAll) {
      selectAll.checked = checks.length > 0 && checks.every(function (c) { return c.checked; });
      selectAll.indeterminate = selected > 0 && selected < checks.length;
    }
  }
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      visibleChecks().forEach(function (c) { c.checked = selectAll.checked; });
      syncBulkUi();
    });
  }
  document.querySelectorAll('.sf-listing-check').forEach(function (c) {
    c.addEventListener('change', syncBulkUi);
  });
  if (bulkAction) bulkAction.addEventListener('change', syncBulkUi);
  if (bulkForm) {
    bulkForm.addEventListener('submit', function (e) {
      const action = bulkAction ? bulkAction.value : '';
      const selected = visibleChecks().filter(function (c) { return c.checked; }).length;
      if (!action || selected === 0) {
        e.preventDefault();
        return;
      }
      if (action === 'remove' && !confirm('Remove ' + selected + ' listing(s) from StoreFeeder? Channel listings are not ended.')) {
        e.preventDefault();
      }
    });
  }
  syncBulkUi();
})();
</script>
JS;

        return (string) ob_get_clean();
    }

    // =========================================================================
    // Delete
    // =========================================================================

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $product = $this->products->find($id);

        if ($product !== null) {
            if ($product->image !== '') {
                $this->uploader->delete($product->image);
            }
            $this->products->delete($id);
            $this->notify->success("Product '{$product->name}' deleted.");
        } else {
            $this->notify->error('Product not found.');
        }

        redirect_to('storefeeder/admin/products');
    }

    public function clearAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        try {
            $n = $this->products->clearAll();
            $this->notify->success(
                $n === 0
                    ? 'No products to clear.'
                    : "Cleared {$n} product" . ($n === 1 ? '' : 's') . '.'
            );
        } catch (\Throwable $e) {
            $this->notify->error('Could not clear products: ' . $e->getMessage());
        }
        redirect_to('storefeeder/admin/products');
    }

    public function archiveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $product = $this->products->find($id);
        if ($product === null) {
            $this->notify->error('Product not found.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $this->products->update($id, ['active' => false]);
        $this->logProductAudit($id, 'archive', 'Product archived.', [
            'active' => ['from' => $product->active, 'to' => false],
        ]);
        $this->notify->success("Product '{$product->name}' archived.");
        redirect_to('storefeeder/admin/products');
    }

    public function archiveVariantAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $variant = $this->variants->find($id);
        if ($variant === null) {
            $this->notify->error('Variant not found.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $attrs = [];
        foreach ($variant->attributes as $attrId => $row) {
            $attrs[(int) $attrId] = (int) ($row['value_id'] ?? 0);
        }
        $this->variants->update($id, [
            'sku'          => $variant->sku,
            'name'         => $variant->name,
            'pick_bin'     => $variant->pickBin,
            'price'        => $variant->price,
            'cost'         => $variant->cost,
            'weight_grams' => $variant->weightGrams,
            'active'       => false,
            'attributes'   => $attrs,
        ]);
        $productId = (int) ($variant->productId ?? 0);
        if ($productId > 0) {
            $this->logProductAudit($productId, 'archive_variant', 'Variant archived: ' . $variant->sku, [
                'variant_id' => ['from' => $id, 'to' => $id],
                'active'     => ['from' => $variant->active, 'to' => false],
            ]);
        }
        $this->notify->success("Variant '{$variant->sku}' archived.");
        redirect_to('storefeeder/admin/products');
    }

    // =========================================================================
    // Export
    // =========================================================================

    public function exportAction(): void
    {
        $exporter = new \Module\StoreFeeder\Services\CsvExporter($this->db);

        $buffer = fopen('php://temp', 'r+');
        $count  = $exporter->exportProducts($buffer);
        rewind($buffer);
        $csv = stream_get_contents($buffer);
        fclose($buffer);

        $filename = 'storefeeder-products-' . date('Y-m-d-His') . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        header('Cache-Control: no-store');

        echo "\xEF\xBB\xBF" . $csv;

        $this->log->info(
            "CSV export: {$count} rows",
            ['user_id' => $this->auth->id()]
        );

        exit;
    }

    // =========================================================================
    // Import
    // =========================================================================

    public function importAction(): void
    {
        if (!$this->request->isPost()) {
            echo $this->view->inline(function () {
                $this->view->assign('title', 'Import Products');
                echo '<h1 class="h3 mb-4">Import Products</h1>';

                echo $this->form->open([
                    'action' => $this->url->to('storefeeder/admin/products/import'),
                    'method' => 'POST',
                    'files'  => true,
                ]);

                echo '<div class="alert alert-info">';
                echo 'Upload the official <code>StoreFeeder Products.xls</code> template '
                   . '(SKU, Parent SKU, Name, Inventory, Price, Category) or a '
                   . '<code>storefeeder-products-*.csv</code> export. '
                   . 'Leave Parent SKU blank for a simple product; fill it to attach a variant. '
                   . 'The READONLY hint row is ignored. '
                   . '<a href="' . $this->view->e($this->url->to('storefeeder/admin/products/export')) . '">'
                   . 'Download a sample export</a>.';
                echo '</div>';

                echo $this->form->field([
                    'name'     => 'csv',
                    'label'    => 'Spreadsheet',
                    'type'     => 'file',
                    'required' => true,
                    'accept'   => '.csv,.xls,.xlsx',
                    'help'     => 'CSV or StoreFeeder Products.xls. Max 10 MB.',
                ]);

                echo '<button type="submit" class="btn btn-primary">Import</button> ';
                echo '<a href="' . $this->view->e($this->url->to('storefeeder/admin/products')) . '"'
                   . ' class="btn btn-outline-secondary">Cancel</a>';

                echo $this->form->close();
            });
            return;
        }

        $file = $this->request->file('csv');
        if ($file === null) {
            $this->notify->error('Please choose a CSV file.');
            redirect_to('storefeeder/admin/products/import');
            return;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            $this->notify->error('CSV must be under 10 MB.');
            redirect_to('storefeeder/admin/products/import');
            return;
        }

        $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedExt = ['csv', 'xls', 'xlsx'];
        $allowedTypes = [
            'text/csv',
            'text/plain',
            'text/html',
            'application/csv',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/octet-stream',
            '',
        ];
        if (!in_array($ext, $allowedExt, true) && !in_array((string) ($file['type'] ?? ''), $allowedTypes, true)) {
            $this->notify->error('Upload a CSV or StoreFeeder Products.xls file (got ' . ($file['type'] ?: $ext ?: 'unknown') . ').');
            redirect_to('storefeeder/admin/products/import');
            return;
        }

        try {
            $importer = new \Module\StoreFeeder\Services\CsvImporter(
                $this->db,
                $this->products,
                $this->variants,
                $this->categories,
                $this->attributes,
            );

            $result = $importer->import($file['tmp_name']);
        } catch (\Throwable $e) {
            $this->notify->error('Import failed: ' . $e->getMessage());
            redirect_to('storefeeder/admin/products/import');
            return;
        }

        echo $this->view->inline(function () use ($result) {
            $this->view->assign('title', 'Import Results');

            echo '<h1 class="h3 mb-4">Import Results</h1>';

            echo '<div class="row g-3 mb-4">';
            $cards = [
                ['Rows read', $result['rows_total'],  'list'],
                ['Created',   $result['created'],     'plus-circle'],
                ['Updated',   $result['updated'],     'arrow-clockwise'],
                ['Skipped',   $result['skipped'] ?? 0, 'dash-circle'],
                ['Errors',    $result['rows_errors'], 'exclamation-triangle'],
            ];
            foreach ($cards as [$label, $value, $icon]) {
                $style = $label === 'Errors' && $value > 0 ? 'text-danger' : '';
                echo '<div class="col-6 col-md">';
                echo '<div class="card"><div class="card-body">';
                echo '<div class="text-muted small"><i class="bi bi-' . $icon . ' me-1"></i>' . $label . '</div>';
                echo '<div class="h4 mb-0 ' . $style . '">' . (int) $value . '</div>';
                echo '</div></div></div>';
            }
            echo '</div>';

            if (($result['rows_total'] ?? 0) === 0 && empty($result['errors'])) {
                echo '<div class="alert alert-warning">This looks like a blank StoreFeeder template. '
                   . 'Add rows with <strong>SKU</strong> and <strong>Name</strong>, save, then import again. '
                   . 'The READONLY row is only a hint — it is not a product.</div>';
            }

            if (!empty($result['skips'])) {
                echo '<h2 class="h5">Skipped</h2>';
                echo '<div class="table-responsive mb-4"><table class="table table-sm">';
                echo '<thead><tr><th style="width:80px">Row</th><th>Message</th></tr></thead><tbody>';
                foreach ($result['skips'] as $skip) {
                    echo '<tr>';
                    echo '<td>' . (int) $skip['row'] . '</td>';
                    echo '<td class="text-muted">' . $this->view->e($skip['message']) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }

            if (!empty($result['errors'])) {
                echo '<h2 class="h5">Errors</h2>';
                echo '<div class="table-responsive"><table class="table table-sm">';
                echo '<thead><tr><th style="width:80px">Row</th><th>Message</th></tr></thead><tbody>';
                foreach ($result['errors'] as $err) {
                    echo '<tr>';
                    echo '<td>' . (int) $err['row'] . '</td>';
                    echo '<td class="text-danger">' . $this->view->e($err['message']) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            } elseif (($result['rows_total'] ?? 0) > 0) {
                echo '<div class="alert alert-success">All rows imported successfully.</div>';
            }

            echo '<hr class="my-4">';
            echo '<a href="' . $this->view->e($this->url->to('storefeeder/admin/products')) . '"'
               . ' class="btn btn-primary">Back to products</a> ';
            echo '<a href="' . $this->view->e($this->url->to('storefeeder/admin/products/import')) . '"'
               . ' class="btn btn-outline-secondary">Import another</a>';
        });
    }

    public function bulkAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/products');
            return;
        }

        $ids    = array_map('intval', (array) $this->request->post('ids', 'raw', []));
        $action = (string) $this->request->post('action', 'string', '');

        $ids = array_values(array_filter($ids, fn($id) => $id > 0));

        if ($ids === []) {
            $this->notify->warning('No products selected.');
            redirect_to('storefeeder/admin/products');
            return;
        }

        $affected = 0;

        try {
            switch ($action) {
                case 'activate':
                case 'deactivate':
                    $active = ($action === 'activate');
                    foreach ($ids as $id) {
                        $this->products->update($id, ['active' => $active]);
                        $affected++;
                    }
                    $this->notify->success(
                        ucfirst($action) . "d {$affected} product" . ($affected === 1 ? '' : 's') . '.'
                    );
                    break;

                case 'add_category':
                    $categoryId = (int) $this->request->post('category_id', 'int', 0);
                    if ($categoryId <= 0) {
                        $this->notify->error('Please choose a category.');
                        break;
                    }
                    foreach ($ids as $id) {
                        $existing = $this->categories->categoryIdsFor($id);
                        if (!in_array($categoryId, $existing, true)) {
                            $existing[] = $categoryId;
                            $this->categories->setCategoriesFor($id, $existing);
                        }
                        $affected++;
                    }
                    $this->notify->success(
                        "Added {$affected} product" . ($affected === 1 ? '' : 's') . ' to the category.'
                    );
                    break;

                case 'remove_category':
                    $categoryId = (int) $this->request->post('category_id', 'int', 0);
                    if ($categoryId <= 0) {
                        $this->notify->error('Please choose a category.');
                        break;
                    }
                    foreach ($ids as $id) {
                        $existing = $this->categories->categoryIdsFor($id);
                        $filtered = array_values(array_diff($existing, [$categoryId]));
                        if ($filtered !== $existing) {
                            $this->categories->setCategoriesFor($id, $filtered);
                            $affected++;
                        }
                    }
                    $this->notify->success(
                        "Removed the category from {$affected} product" . ($affected === 1 ? '' : 's') . '.'
                    );
                    break;

                case 'set_brand':
                    $brandRaw = trim((string) $this->request->post('brand', 'string', ''));
                    $brand = $brandRaw === '' ? '' : $this->brands->ensure($brandRaw);
                    foreach ($ids as $id) {
                        $before = $this->products->find($id);
                        if ($before === null) {
                            continue;
                        }
                        $this->products->update($id, ['brand' => $brand]);
                        $this->logProductAudit($id, 'bulk_brand', 'Brand set via bulk action.', [
                            'brand' => ['from' => $before->brand, 'to' => $brand],
                        ]);
                        $affected++;
                    }
                    $this->notify->success(
                        $brand === ''
                            ? "Cleared brand on {$affected} product" . ($affected === 1 ? '' : 's') . '.'
                            : "Set brand on {$affected} product" . ($affected === 1 ? '' : 's') . '.'
                    );
                    break;

                case 'set_supplier':
                    $supRaw = trim((string) $this->request->post('supplier_name', 'string', ''));
                    $supplier = $supRaw === '' ? '' : $this->suppliers->ensure($supRaw);
                    foreach ($ids as $id) {
                        $before = $this->products->find($id);
                        if ($before === null) {
                            continue;
                        }
                        $this->products->update($id, ['supplier_name' => $supplier]);
                        $this->logProductAudit($id, 'bulk_supplier', 'Supplier set via bulk action.', [
                            'supplier_name' => ['from' => $before->supplierName, 'to' => $supplier],
                        ]);
                        $affected++;
                    }
                    $this->notify->success(
                        $supplier === ''
                            ? "Cleared supplier on {$affected} product" . ($affected === 1 ? '' : 's') . '.'
                            : "Set supplier on {$affected} product" . ($affected === 1 ? '' : 's') . '.'
                    );
                    break;

                case 'set_warehouse':
                    $whRaw = trim((string) $this->request->post('warehouse', 'string', ''));
                    if ($whRaw === '') {
                        $this->notify->error('Please choose a warehouse.');
                        break;
                    }
                    $warehouse = $this->warehouses->ensure($whRaw);
                    foreach ($ids as $id) {
                        $before = $this->products->find($id);
                        if ($before === null) {
                            continue;
                        }
                        $this->products->update($id, ['warehouse' => $warehouse]);
                        $this->logProductAudit($id, 'bulk_warehouse', 'Warehouse set via bulk action.', [
                            'warehouse' => ['from' => $before->warehouse, 'to' => $warehouse],
                        ]);
                        $affected++;
                    }
                    $this->notify->success(
                        "Set warehouse on {$affected} product" . ($affected === 1 ? '' : 's') . '.'
                    );
                    break;

                case 'delete':
                    foreach ($ids as $id) {
                        $product = $this->products->find($id);
                        if ($product === null) {
                            continue;
                        }
                        if ($product->image !== '') {
                            $this->uploader->delete($product->image);
                        }
                        $this->products->delete($id);
                        $affected++;
                    }
                    $this->notify->success(
                        "Deleted {$affected} product" . ($affected === 1 ? '' : 's') . '.'
                    );
                    break;

                default:
                    $this->notify->error('Unknown bulk action.');
            }
        } catch (\Throwable $e) {
            $this->notify->error('Bulk action failed: ' . $e->getMessage());
        }

        redirect_to('storefeeder/admin/products');
    }

    // =========================================================================
    // Input parsing
    // =========================================================================

    private function blankProductInput(): array
    {
        return [
            'sku'                => '',
            'name'               => '',
            'brand'              => '',
            'image'              => '',
            'description'        => '',
            'barcode'            => '',
            'upc'                => '',
            'mpn'                => '',
            'supplier_sku'       => '',
            'supplier_name'      => '',
            'warehouse'          => 'Default',
            'price'              => '',
            'rrp'                => '',
            'cost'               => '',
            'tax_class'          => '20',
            'price_includes_vat' => 0,
            'weight_grams'       => 0,
            'length_mm'          => '',
            'width_mm'           => '',
            'height_mm'          => '',
            'shipping'           => $this->defaultShipping(),
            'notes'              => '',
            'regulatory'         => $this->defaultRegulatory(),
            'ebay_category_id'   => '',
            'ebay_category_name' => '',
            'ebay_condition'     => '',
            'kit_display_on_order' => 1,
            'active'             => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function defaultRegulatory(): array
    {
        return [
            // Regulatory Information
            'age_restriction'           => '',
            'safety_warnings'           => '',
            'instructions_included'     => 0,
            'ce_ukca_marking'           => '',
            'gpsr_compliant'            => 0,
            // Responsible Person (EU / UK)
            'rp_name'                   => '',
            'rp_email'                  => '',
            'rp_phone'                  => '',
            'rp_address'                => '',
            'rp_country'                => '',
            // Company Identifiers
            'company_name'              => '',
            'company_number'            => '',
            'vat_number'                => '',
            'eori_number'               => '',
            'manufacturer_name'         => '',
            'manufacturer_address'      => '',
        ];
    }

    /** @return array<string, mixed> */
    private function readRegulatoryInput(): array
    {
        $r = $this->defaultRegulatory();
        $boolKeys = ['instructions_included', 'gpsr_compliant'];
        $textKeys = [
            'age_restriction', 'safety_warnings', 'ce_ukca_marking',
            'rp_name', 'rp_email', 'rp_phone', 'rp_address', 'rp_country',
            'company_name', 'company_number', 'vat_number', 'eori_number',
            'manufacturer_name', 'manufacturer_address',
        ];
        foreach ($boolKeys as $key) {
            $r[$key] = (int) $this->request->post('reg_' . $key, 'int', 0) ? 1 : 0;
        }
        foreach ($textKeys as $key) {
            $r[$key] = trim((string) $this->request->post('reg_' . $key, 'string', ''));
        }
        return $r;
    }

    /** @return array<string, mixed> */
    private function defaultShipping(): array
    {
        return [
            'package_size'              => '',
            'packaging_qty'             => '',
            'record_serial_on_despatch' => 0,
            'shipping_cost'             => '',
            'is_fragile'                => 0,
            'contains_firearm'          => 0,
            'firearm_type'              => '',
            'contains_hazardous'        => 0,
            'un_id_code'                => '',
            'override_lqdg_royal_mail'  => 0,
            'is_lqdg'                   => 0,
            'is_bladed'                 => 0,
            'customs_product_type'      => '',
            'customs_product_type_detail'=> '',
            'product_composition'       => '',
            'harmonised_code'           => '',
            'country_of_manufacture'    => '',
            'customs_declaration_category' => 'ecommerce_sale',
            'cpsc_regulated'            => 0,
            'cpsc_certifier_id'         => '',
            'cpsc_certificate_version_id' => '',
            'cpsc_product_id'           => '',
            'cpsc_intended_use_code'    => '',
            'cpsc_intended_use_desc'    => '',
            'cpsc_disclaimer_reason'    => '',
            'eu_product_mid_code'       => '',
            'eu_non_standard_mid_code'  => '',
            'pga_reference_number'      => '',
        ];
    }

    /** @return array<string, mixed> */
    private function readShippingInput(): array
    {
        $s = $this->defaultShipping();
        $boolKeys = [
            'record_serial_on_despatch', 'is_fragile', 'contains_firearm', 'contains_hazardous',
            'override_lqdg_royal_mail', 'is_lqdg', 'is_bladed', 'cpsc_regulated',
        ];
        $textKeys = [
            'package_size', 'packaging_qty', 'shipping_cost', 'firearm_type', 'un_id_code',
            'customs_product_type', 'customs_product_type_detail', 'product_composition',
            'harmonised_code', 'country_of_manufacture', 'customs_declaration_category',
            'cpsc_certifier_id', 'cpsc_certificate_version_id', 'cpsc_product_id',
            'cpsc_intended_use_code', 'cpsc_intended_use_desc', 'cpsc_disclaimer_reason',
            'eu_product_mid_code', 'eu_non_standard_mid_code', 'pga_reference_number',
        ];
        foreach ($boolKeys as $key) {
            $s[$key] = (int) $this->request->post('ship_' . $key, 'int', 0) ? 1 : 0;
        }
        foreach ($textKeys as $key) {
            $s[$key] = trim((string) $this->request->post('ship_' . $key, 'string', ''));
        }
        if ($s['customs_declaration_category'] === '') {
            $s['customs_declaration_category'] = 'ecommerce_sale';
        }
        return $s;
    }

    /**
     * Shipping field keys ticked as "Update variant products".
     *
     * @return array<int, string>
     */
    private function readShippingUpdateKeys(): array
    {
        $raw = $this->request->post('ship_update', 'raw', []);
        if (!is_array($raw)) {
            return [];
        }
        $allowed = array_fill_keys(array_keys($this->defaultShipping()), true);
        $keys = [];
        foreach ($raw as $key => $val) {
            $key = (string) $key;
            if ($key === '' || !isset($allowed[$key])) {
                continue;
            }
            if ($val === '1' || $val === 1 || $val === true || $val === 'on') {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Product Information fields ticked as "Update variant products".
     *
     * @return array<int, string>
     */
    private function readInfoUpdateKeys(): array
    {
        $raw = $this->request->post('info_update', 'raw', []);
        if (!is_array($raw)) {
            return [];
        }
        $allowed = ['price' => true, 'cost' => true, 'weight_grams' => true, 'name' => true];
        $keys = [];
        foreach ($raw as $key => $val) {
            $key = (string) $key;
            if (!isset($allowed[$key])) {
                continue;
            }
            if ($val === '1' || $val === 1 || $val === true || $val === 'on') {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    private function readProductInput(): array
    {
        $brandSelect = trim((string) $this->request->post('brand', 'string', ''));
        $brandNew    = trim((string) $this->request->post('brand_new', 'string', ''));
        $brand = '';
        if ($brandSelect === '__new__') {
            $brand = $this->brands->ensure($brandNew);
        } elseif ($brandSelect !== '') {
            $brand = $this->brands->ensure($brandSelect);
        }

        $supplierSelect = trim((string) $this->request->post('supplier_name', 'string', ''));
        $supplierNew    = trim((string) $this->request->post('supplier_new', 'string', ''));
        $supplierName = '';
        if ($supplierSelect === '__new__') {
            $supplierName = $this->suppliers->ensure($supplierNew);
        } elseif ($supplierSelect !== '') {
            $supplierName = $this->suppliers->ensure($supplierSelect);
        }

        $weightKg = trim((string) $this->request->post('weight_kg', 'raw', ''));
        if ($weightKg !== '') {
            $weightGrams = (int) max(0, (int) round((float) $weightKg * 1000));
        } else {
            $weightGrams = max(0, (int) $this->request->post('weight_grams', 'int', 0));
        }

        $action = trim((string) $this->request->post('product_action', 'string', ''));
        $active = (int) $this->request->post('active', 'int', 0);
        if (in_array($action, ['archive', 'discontinue'], true)) {
            $active = 0;
        }

        return [
            'sku'                => trim((string) $this->request->post('sku', 'string', '')),
            'name'               => trim((string) $this->request->post('name', 'string', '')),
            'brand'              => $brand,
            'image'              => '',
            'description'        => trim((string) $this->request->post('description', 'string', '')),
            'barcode'            => trim((string) $this->request->post('barcode', 'string', '')),
            'upc'                => trim((string) $this->request->post('upc', 'string', '')),
            'mpn'                => trim((string) $this->request->post('mpn', 'string', '')),
            'supplier_sku'       => trim((string) $this->request->post('supplier_sku', 'string', '')),
            'supplier_name'      => $supplierName,
            'warehouse'          => $this->warehouses->ensure(
                (string) $this->request->post('warehouse', 'string', 'Default')
            ),
            'price'              => (string) $this->request->post('price', 'raw', '0'),
            'rrp'                => (string) $this->request->post('rrp', 'raw', '0'),
            'cost'               => (string) $this->request->post('cost', 'raw', '0'),
            'tax_class'          => trim((string) $this->request->post('tax_class', 'string', '20')) ?: '20',
            'price_includes_vat' => (int) $this->request->post('price_includes_vat', 'int', 0),
            'weight_grams'       => $weightGrams,
            'length_mm'          => max(0, (int) $this->request->post('length_mm', 'int', 0)),
            'width_mm'           => max(0, (int) $this->request->post('width_mm', 'int', 0)),
            'height_mm'          => max(0, (int) $this->request->post('height_mm', 'int', 0)),
            'shipping'           => $this->readShippingInput(),
            'notes'              => trim((string) $this->request->post('notes', 'string', '')),
            'regulatory'         => $this->readRegulatoryInput(),
            'ebay_category_id'   => trim((string) $this->request->post('ebay_category_id', 'string', '')),
            'ebay_category_name' => trim((string) $this->request->post('ebay_category_name', 'string', '')),
            'ebay_condition'     => \Module\StoreFeeder\Services\EbayCondition::normalize(
                (string) $this->request->post('ebay_condition', 'string', '')
            ),
            'channel_attributes' => $this->readProductChannelAttributesInput(),
            'active'             => $active,
            'product_action'     => $action,
        ];
    }

    /** @return array<int, array<string, list<string>>> */
    private function readProductChannelAttributesInput(): array
    {
        $raw = $this->request->post('pca', 'raw', []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $channelId => $payload) {
            $channelId = (int) $channelId;
            if ($channelId <= 0 || !is_array($payload)) {
                continue;
            }
            $names = is_array($payload['name'] ?? null) ? $payload['name'] : [];
            $values = is_array($payload['value'] ?? null) ? $payload['value'] : [];
            $rows = [];
            $count = max(count($names), count($values));
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'name'  => trim((string) ($names[$i] ?? '')),
                    'value' => trim((string) ($values[$i] ?? '')),
                ];
            }
            $out[$channelId] = \Module\StoreFeeder\Services\EbayAspectResolver::normalizeProductAspects($rows);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function productPayloadFromInput(array $old): array
    {
        return [
            'sku'                => $old['sku'],
            'name'               => $old['name'],
            'brand'              => $old['brand'],
            'description'        => $old['description'],
            'barcode'            => $old['barcode'],
            'upc'                => $old['upc'] ?? '',
            'mpn'                => $old['mpn'] ?? '',
            'supplier_sku'       => $old['supplier_sku'] ?? '',
            'supplier_name'      => $old['supplier_name'] ?? '',
            'warehouse'          => ($old['warehouse'] ?? '') !== '' ? (string) $old['warehouse'] : 'Default',
            'price'              => (float) $old['price'],
            'rrp'                => (float) ($old['rrp'] ?? 0),
            'cost'               => (float) $old['cost'],
            'tax_class'          => (string) ($old['tax_class'] ?? '20'),
            'price_includes_vat' => !empty($old['price_includes_vat']),
            'weight_grams'       => (int) $old['weight_grams'],
            'length_mm'          => (int) ($old['length_mm'] ?? 0),
            'width_mm'           => (int) ($old['width_mm'] ?? 0),
            'height_mm'          => (int) ($old['height_mm'] ?? 0),
            'shipping'           => is_array($old['shipping'] ?? null)
                ? $old['shipping']
                : $this->defaultShipping(),
            'notes'              => (string) ($old['notes'] ?? ''),
            'regulatory'         => is_array($old['regulatory'] ?? null)
                ? $old['regulatory']
                : $this->defaultRegulatory(),
            'ebay_category_id'   => (string) ($old['ebay_category_id'] ?? ''),
            'ebay_category_name' => (string) ($old['ebay_category_name'] ?? ''),
            'ebay_condition'     => \Module\StoreFeeder\Services\EbayCondition::normalize(
                (string) ($old['ebay_condition'] ?? '')
            ),
            'active'             => (bool) $old['active'],
        ];
    }

    private function persistProductChannelAttributes(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }
        $raw = $this->request->post('pca', 'raw', []);
        if (!is_array($raw)) {
            return;
        }
        $repo = new \Module\StoreFeeder\Services\ChannelAttributeRepository($this->db);
        $factory = new ChannelClientFactory($this->vault, $this->channelRepo);

        foreach ($raw as $channelId => $payload) {
            $channelId = (int) $channelId;
            if ($channelId <= 0 || !is_array($payload)) {
                continue;
            }
            $channel = $this->channelRepo->find($channelId);
            if ($channel === null || $factory->isCourier($channel)) {
                continue;
            }
            $names = is_array($payload['name'] ?? null) ? $payload['name'] : [];
            $values = is_array($payload['value'] ?? null) ? $payload['value'] : [];
            $rows = [];
            $count = max(count($names), count($values));
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'name'  => trim((string) ($names[$i] ?? '')),
                    'value' => trim((string) ($values[$i] ?? '')),
                ];
            }
            $repo->replaceProductOverrides($productId, $channelId, $rows);
        }
    }

    /**
     * @param array<int, array<string, list<string>>>|null $posted
     */
    private function renderProductChannelAttributesEditor(int $productId, ?array $posted = null): string
    {
        $factory = new ChannelClientFactory($this->vault, $this->channelRepo);
        $channels = [];
        foreach ($this->channelRepo->all() as $ch) {
            if ($factory->isCourier($ch)) {
                continue;
            }
            $channels[] = $ch;
        }

        $e = fn(string $s): string => $this->view->e($s);
        ob_start();

        if ($channels === []) {
            echo '<p class="small text-muted mb-0">Add a marketplace or webstore integration to edit per-channel attributes.</p>';
            return (string) ob_get_clean();
        }

        $repo = new \Module\StoreFeeder\Services\ChannelAttributeRepository($this->db);
        $byChannel = is_array($posted) ? $posted : (
            $productId > 0 ? $repo->productOverridesByChannel($productId) : []
        );

        echo '<ul class="nav nav-tabs mb-3" role="tablist">';
        foreach ($channels as $i => $ch) {
            $cid = (int) $ch->id;
            $active = $i === 0 ? ' active' : '';
            $label = $ch->label !== '' ? $ch->label : $ch->name;
            echo '<li class="nav-item" role="presentation">';
            echo '<button class="nav-link' . $active . '" type="button" data-bs-toggle="tab"'
               . ' data-bs-target="#sf-pca-tab-' . $cid . '" role="tab">'
               . $e($label)
               . ' <span class="text-muted small">(' . $e($ch->type) . ')</span></button>';
            echo '</li>';
        }
        echo '</ul>';

        echo '<div class="tab-content">';
        foreach ($channels as $i => $ch) {
            $cid = (int) $ch->id;
            $active = $i === 0 ? ' show active' : '';
            $attrs = is_array($byChannel[$cid] ?? null) ? $byChannel[$cid] : [];
            $rows = [];
            foreach ($attrs as $name => $vals) {
                foreach ($vals as $val) {
                    $rows[] = ['name' => $name, 'value' => $val];
                }
            }
            if ($rows === []) {
                $rows[] = ['name' => '', 'value' => ''];
            }

            echo '<div class="tab-pane fade' . $active . '" id="sf-pca-tab-' . $cid . '" role="tabpanel">';
            echo '<p class="small text-muted mb-2">Product overrides for <strong>'
               . $e($ch->label !== '' ? $ch->label : $ch->name)
               . '</strong> (<code>#__sf_product_channel_attributes</code>). '
               . 'Leave blank to inherit channel General Attributes '
               . '(<code>#__sf_channel_attributes</code>).</p>';
            $defs = $repo->defsForChannel($cid);
            if ($defs !== []) {
                echo '<p class="small text-muted mb-2">Channel defaults: ';
                $bits = [];
                foreach (array_slice($defs, 0, 8) as $def) {
                    $bits[] = '<code>' . $e((string) $def['name']) . '</code>';
                }
                echo implode(', ', $bits);
                if (count($defs) > 8) {
                    echo ' …';
                }
                echo '</p>';
            }

            $aspectsUrl = '';
            if ($ch->type === 'ebay' && $ch->isConnected()) {
                $aspectsUrl = $this->url->to(
                    'storefeeder/admin/channels/category-aspects',
                    ['id' => $cid]
                );
                echo '<div class="mb-2"><button type="button" class="btn btn-sm btn-outline-primary sf-pca-load-aspects"'
                   . ' data-channel="' . $cid . '" data-url="' . $e($aspectsUrl) . '">'
                   . '<i class="bi bi-cloud-download me-1"></i>Load required from eBay category</button> '
                   . '<span class="small text-muted sf-pca-aspects-status" data-channel="' . $cid . '"></span></div>';
            }

            echo '<div class="table-responsive"><table class="table table-sm align-middle mb-2" id="sf-pca-table-' . $cid . '">';
            echo '<thead><tr><th>Attribute</th><th>Value</th><th></th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr>';
                echo '<td><input type="text" class="form-control form-control-sm" name="pca[' . $cid . '][name][]"'
                   . ' maxlength="64" value="' . $e($row['name']) . '"></td>';
                echo '<td><input type="text" class="form-control form-control-sm" name="pca[' . $cid . '][value][]"'
                   . ' maxlength="255" value="' . $e($row['value']) . '"></td>';
                echo '<td><button type="button" class="btn btn-sm btn-outline-danger sf-pca-remove">&times;</button></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary sf-pca-add" data-channel="'
               . $cid . '"><i class="bi bi-plus-lg me-1"></i>Add attribute</button>';
            echo '</div>';
        }
        echo '</div>';

        echo '<script>
(function () {
  function addPcaRow(cid, name, value) {
    const tbody = document.querySelector("#sf-pca-table-" + cid + " tbody");
    if (!tbody) return;
    const tr = document.createElement("tr");
    const n = String(name || "").replace(/"/g, "&quot;");
    const v = String(value || "").replace(/"/g, "&quot;");
    tr.innerHTML = \'<td><input type="text" class="form-control form-control-sm" name="pca[\' + cid + \'][name][]" maxlength="64" value="\' + n + \'"></td>\'
      + \'<td><input type="text" class="form-control form-control-sm" name="pca[\' + cid + \'][value][]" maxlength="255" value="\' + v + \'"></td>\'
      + \'<td><button type="button" class="btn btn-sm btn-outline-danger sf-pca-remove">&times;</button></td>\';
    tbody.appendChild(tr);
    tr.querySelector(".sf-pca-remove").addEventListener("click", function () {
      if (tbody.querySelectorAll("tr").length > 1) tr.remove();
    });
  }
  document.querySelectorAll(".sf-pca-add").forEach(function (btn) {
    btn.addEventListener("click", function () {
      addPcaRow(btn.getAttribute("data-channel"), "", "");
    });
  });
  document.querySelectorAll(".sf-pca-remove").forEach(function (btn) {
    btn.addEventListener("click", function () {
      const tr = btn.closest("tr");
      const tbody = tr && tr.parentElement;
      if (tr && tbody && tbody.querySelectorAll("tr").length > 1) tr.remove();
    });
  });
  document.querySelectorAll(".sf-pca-load-aspects").forEach(function (btn) {
    btn.addEventListener("click", function () {
      const cid = btn.getAttribute("data-channel");
      const url = btn.getAttribute("data-url");
      const status = document.querySelector(".sf-pca-aspects-status[data-channel=\\"" + cid + "\\"]");
      if (!cid || !url) return;
      btn.disabled = true;
      if (status) status.textContent = "Loading…";
      fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) throw new Error((j && j.error) || "Failed");
          const tbody = document.querySelector("#sf-pca-table-" + cid + " tbody");
          const have = new Set();
          if (tbody) {
            tbody.querySelectorAll("input").forEach(function (inp) {
              const nm = inp.getAttribute("name") || "";
              if (nm.indexOf("pca[" + cid + "][name]") !== 0) return;
              const n = (inp.value || "").trim().toLowerCase();
              if (n) have.add(n);
            });
          }
          let added = 0;
          (j.aspects || []).forEach(function (a) {
            if (!a.required) return;
            const name = (a.name || "").trim();
            if (!name || have.has(name.toLowerCase())) return;
            addPcaRow(cid, name, "");
            have.add(name.toLowerCase());
            added++;
          });
          if (status) status.textContent = "Added " + added + " required Item Specific(s). Save the product.";
        })
        .catch(function (err) {
          if (status) status.textContent = err.message || "Load failed.";
        })
        .finally(function () { btn.disabled = false; });
    });
  });
})();
</script>';

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $old */
    private function renderEbayCategoryConditionFields(array $old): string
    {
        $catId = trim((string) ($old['ebay_category_id'] ?? ''));
        $catName = trim((string) ($old['ebay_category_name'] ?? ''));
        $condition = \Module\StoreFeeder\Services\EbayCondition::normalize(
            (string) ($old['ebay_condition'] ?? '')
        );

        $suggestUrl = '';
        try {
            foreach ($this->channelRepo->all() as $ch) {
                if ($ch->type === 'ebay' && $ch->enabled && $ch->isConnected()) {
                    $suggestUrl = $this->url->to(
                        'storefeeder/admin/channels/category-suggest',
                        ['id' => (int) $ch->id]
                    );
                    break;
                }
            }
        } catch (\Throwable) {
        }

        $e = fn(string $s): string => $this->view->e($s);
        ob_start();
        ?>
        <p class="small text-muted mb-3">
            Overrides the channel default category and condition when publishing to eBay.
            Leave blank to use the integration defaults (condition defaults to New).
        </p>
        <div class="row g-3 mb-3">
            <div class="col-md-8">
                <label class="form-label small mb-1">eBay category</label>
                <div class="small mb-1">
                    <?php if ($catId !== ''): ?>
                        <strong id="sf-pd-cat-label"><?= $e($catName !== '' ? $catName : ('Category ' . $catId)) ?></strong>
                        <span class="text-muted"> · ID <code id="sf-pd-cat-id-display"><?= $e($catId) ?></code></span>
                    <?php else: ?>
                        <span class="text-muted" id="sf-pd-cat-label">Channel default</span>
                        <code id="sf-pd-cat-id-display" class="d-none"></code>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="ebay_category_id" id="ebay_category_id" value="<?= $e($catId) ?>">
                <input type="hidden" name="ebay_category_name" id="ebay_category_name" value="<?= $e($catName) ?>">
                <?php if ($suggestUrl !== ''): ?>
                    <input type="search" id="sf-pd-cat-search" class="form-control form-control-sm"
                           placeholder="Search eBay categories…" autocomplete="off">
                    <div id="sf-pd-cat-status" class="small text-muted mt-1"></div>
                    <div id="sf-pd-cat-results" class="list-group list-group-flush border rounded mt-1"
                         style="max-height:12rem;overflow:auto;display:none"></div>
                    <button type="button" class="btn btn-link btn-sm px-0 mt-1" id="sf-pd-cat-clear">Clear category</button>
                <?php else: ?>
                    <input type="text" class="form-control form-control-sm" name="ebay_category_id_manual"
                           id="ebay_category_id_manual" value="<?= $e($catId) ?>"
                           placeholder="eBay leaf category ID" maxlength="32">
                    <div class="form-text">Connect an eBay channel to enable category search.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1" for="ebay_condition">Condition</label>
                <select class="form-select form-select-sm" name="ebay_condition" id="ebay_condition">
                    <option value=""<?= $condition === '' ? ' selected' : '' ?>>New (default)</option>
                    <?php foreach (\Module\StoreFeeder\Services\EbayCondition::OPTIONS as $code => $label): ?>
                        <option value="<?= $e($code) ?>"<?= $condition === $code ? ' selected' : '' ?>>
                            <?= $e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if ($suggestUrl !== ''): ?>
        <script>
        (function () {
          const suggestUrl = <?= json_encode($suggestUrl) ?>;
          const search = document.getElementById('sf-pd-cat-search');
          const results = document.getElementById('sf-pd-cat-results');
          const status = document.getElementById('sf-pd-cat-status');
          const idInput = document.getElementById('ebay_category_id');
          const nameInput = document.getElementById('ebay_category_name');
          const labelEl = document.getElementById('sf-pd-cat-label');
          const idEl = document.getElementById('sf-pd-cat-id-display');
          const clearBtn = document.getElementById('sf-pd-cat-clear');
          if (!search || !results || !idInput) return;
          let timer = null, seq = 0;

          function setSelected(id, name, path) {
            idInput.value = id || '';
            nameInput.value = path || name || '';
            if (!id) {
              labelEl.textContent = 'Channel default';
              labelEl.classList.add('text-muted');
              idEl.textContent = '';
              idEl.classList.add('d-none');
              return;
            }
            labelEl.textContent = path || name || ('Category ' + id);
            labelEl.classList.remove('text-muted');
            idEl.textContent = id;
            idEl.classList.remove('d-none');
          }

          function render(items) {
            results.innerHTML = '';
            if (!items.length) {
              results.style.display = 'none';
              status.textContent = 'No leaf categories found.';
              return;
            }
            status.textContent = items.length + ' result(s)';
            results.style.display = '';
            items.forEach(function (item) {
              const a = document.createElement('button');
              a.type = 'button';
              a.className = 'list-group-item list-group-item-action py-2';
              a.innerHTML = '<div class="fw-semibold">' + (item.name || item.id) + '</div>'
                + '<div class="small text-muted">' + (item.path || '') + ' · ' + item.id + '</div>';
              a.addEventListener('click', function () {
                setSelected(String(item.id || ''), item.name || '', item.path || '');
                results.style.display = 'none';
                search.value = '';
                status.textContent = 'Category selected.';
              });
              results.appendChild(a);
            });
          }

          search.addEventListener('input', function () {
            const q = search.value.trim();
            clearTimeout(timer);
            if (q.length < 2) {
              results.style.display = 'none';
              status.textContent = '';
              return;
            }
            const my = ++seq;
            timer = setTimeout(function () {
              status.textContent = 'Searching…';
              fetch(suggestUrl + (suggestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
              }).then(function (r) { return r.json(); }).then(function (data) {
                if (my !== seq) return;
                render(Array.isArray(data.results) ? data.results : (Array.isArray(data) ? data : []));
              }).catch(function () {
                if (my !== seq) return;
                status.textContent = 'Search failed.';
                results.style.display = 'none';
              });
            }, 280);
          });

          if (clearBtn) {
            clearBtn.addEventListener('click', function () {
              setSelected('', '', '');
              search.value = '';
              status.textContent = '';
              results.style.display = 'none';
            });
          }
        })();
        </script>
        <?php else: ?>
        <script>
        (function () {
          const manual = document.getElementById('ebay_category_id_manual');
          const idInput = document.getElementById('ebay_category_id');
          if (!manual || !idInput) return;
          manual.addEventListener('input', function () {
            idInput.value = manual.value.trim();
          });
        })();
        </script>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    private function productInventoryTotal(int $productId): int
    {
        $total = 0;
        foreach ($this->variants->forProduct($productId) as $v) {
            if ($v->id === null) {
                continue;
            }
            $stock = $this->inventory->get($v->id);
            $total += (int) ($stock['available'] ?? 0);
        }
        return $total;
    }

    /** @return array<int, int> variant_id => available */
    private function variantStockMap(int $productId): array
    {
        $map = [];
        foreach ($this->variants->forProduct($productId) as $v) {
            if ($v->id === null) {
                continue;
            }
            $map[$v->id] = $this->inventory->sellableAvailable($v->id);
        }
        return $map;
    }

    /**
     * Save variant rows when the variants table was submitted; otherwise leave
     * existing variants alone. Simple (no-attribute) products get a default
     * variant so inventory has something to attach to.
     *
     * @param array<int, int> $selectedAttributeIds
     * @param array<string, mixed> $productInput
     */
    private function persistVariants(
        int $productId,
        array $selectedAttributeIds,
        array $productInput,
        bool $createDefaultIfSimple,
    ): void {
        $selectedAttributeIds = array_values(array_filter(
            array_map('intval', $selectedAttributeIds),
            fn(int $id) => $id > 0
        ));

        $submitted = (string) $this->request->post('variants_submitted', 'string', '') === '1';

        if ($submitted) {
            $variants = $this->resolveTypedAttributes(
                $this->parseVariantsInput(
                    (array) $this->request->post('variants', 'raw', [])
                ),
                $selectedAttributeIds
            );
            if ($variants !== []) {
                $this->variants->saveForProduct($productId, $variants);
                $this->syncVariantStock($productId, $variants);
                $attrIds = $selectedAttributeIds;
                foreach ($variants as $row) {
                    foreach (array_keys($row['attributes'] ?? []) as $aid) {
                        $aid = (int) $aid;
                        if ($aid > 0) {
                            $attrIds[] = $aid;
                        }
                    }
                }
                $this->products->setAttributesFor($productId, array_values(array_unique($attrIds)));
                return;
            }
            // Table posted but every SKU blank — clear existing rows.
            $this->variants->saveForProduct($productId, []);
        }

        if ($selectedAttributeIds === [] && $createDefaultIfSimple) {
            $this->variants->ensureDefaultVariant($productId, [
                'sku'    => (string) ($productInput['sku'] ?? ''),
                'name'   => (string) ($productInput['name'] ?? ''),
                'price'  => (float)  ($productInput['price'] ?? 0),
                'cost'   => (float)  ($productInput['cost'] ?? 0),
                'active' => !empty($productInput['active']),
            ]);
        }
    }

    /**
     * @param array<int|string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    private function parseVariantsInput(array $input): array
    {
        $out = [];

        foreach ($input as $v) {
            if (!is_array($v)) {
                continue;
            }

            $sku = trim((string) ($v['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $attrs = [];
            if (!empty($v['attributes']) && is_array($v['attributes'])) {
                foreach ($v['attributes'] as $attributeId => $valueId) {
                    $attributeId = (int) $attributeId;
                    $valueId     = (int) $valueId;
                    if ($attributeId > 0 && $valueId > 0) {
                        $attrs[$attributeId] = $valueId;
                    }
                }
            }
            $attrText = [];
            $rawText = $v['attr_text'] ?? [];
            if (is_array($rawText)) {
                foreach ($rawText as $attributeId => $text) {
                    $attributeId = (int) $attributeId;
                    $text = trim((string) $text);
                    if ($attributeId > 0 && $text !== '') {
                        $attrText[$attributeId] = $text;
                    }
                }
            }

            $weightKg = trim((string) ($v['weight_kg'] ?? ''));
            if ($weightKg !== '') {
                $weightGrams = (int) max(0, (int) round((float) $weightKg * 1000));
            } else {
                $weightGrams = max(0, (int) ($v['weight_grams'] ?? 0));
            }

            $name = trim((string) ($v['name'] ?? ''));
            $packQty = max(0, min(20000, (int) ($v['pack_qty'] ?? 0)));
            $qtyRaw = $v['quantity'] ?? '';
            $quantity = ($qtyRaw === '' || $qtyRaw === null) ? null : max(0, (int) $qtyRaw);

            // Pack Qty is units in the pack (1/8/16), not on-hand stock.
            // If someone types stock into Units on a 1× row, treat it as inventory.
            $parsed = PackInventory::parseQty($sku, $name);
            if ($packQty > 48 && $parsed <= 1 && ($quantity === null || $quantity === 0)) {
                $quantity = $packQty;
                $packQty = 0;
            }

            $out[] = [
                'id'           => isset($v['id']) && $v['id'] !== '' ? (int) $v['id'] : null,
                'sku'          => $sku,
                'name'         => $name,
                'pick_bin'     => trim((string) ($v['pick_bin'] ?? '')),
                'price'        => (float) ($v['price'] ?? 0),
                'cost'         => (float) ($v['cost']  ?? 0),
                'weight_grams' => $weightGrams,
                'pack_qty'     => $packQty,
                'quantity'     => $quantity,
                'attributes'   => $attrs,
                'attr_text'    => $attrText,
                'active'       => !empty($v['active']),
            ];
        }

        return $out;
    }

    /**
     * Fill blank variant attribute dropdowns from SKU/name (Diameter 15m, 8 x …).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<int> $selectedIds
     * @return list<array<string, mixed>>
     */
    private function fillGuessedAttributes(array $rows, array $selectedIds): array
    {
        if ($rows === [] || $selectedIds === []) {
            return $rows;
        }
        $wanted = [];
        foreach ($selectedIds as $id) {
            $wanted[(int) $id] = true;
        }
        $byId = [];
        foreach ($this->attributes->all() as $attr) {
            $id = (int) ($attr->id ?? 0);
            if ($id > 0 && isset($wanted[$id])) {
                $byId[$id] = $attr;
            }
        }
        foreach ($rows as $i => $row) {
            $guess = CatalogSetupService::guessFromText(
                (string) ($row['sku'] ?? ''),
                (string) ($row['name'] ?? '')
            );
            if ($guess === []) {
                continue;
            }
            $attrs = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
            $texts = is_array($row['attr_text'] ?? null) ? $row['attr_text'] : [];
            foreach ($byId as $id => $attr) {
                if (!empty($texts[$id]) || !empty($attrs[$id])) {
                    continue;
                }
                $wantedVal = $guess[$attr->code] ?? '';
                if ($wantedVal === '') {
                    continue;
                }
                $texts[$id] = $wantedVal;
            }
            $rows[$i]['attr_text'] = $texts;
            $rows[$i]['attributes'] = $attrs;
        }

        return $rows;
    }

    /**
     * Turn typed attribute cells (15mm, 1m, 8 ×) into saved values.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<int> $selectedIds
     * @return list<array<string, mixed>>
     */
    private function resolveTypedAttributes(array $rows, array $selectedIds): array
    {
        $rows = $this->fillGuessedAttributes($rows, $selectedIds);
        foreach ($rows as $i => $row) {
            $attrs = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
            $texts = is_array($row['attr_text'] ?? null) ? $row['attr_text'] : [];
            foreach ($texts as $attrId => $text) {
                $attrId = (int) $attrId;
                $text = trim((string) $text);
                if ($attrId <= 0 || $text === '') {
                    continue;
                }
                $av = $this->attributes->ensureValue($attrId, $text);
                if ($av !== null && $av->id !== null) {
                    $attrs[$attrId] = (int) $av->id;
                }
            }
            $rows[$i]['attributes'] = $attrs;
        }

        return $rows;
    }

    /**
     * Apply on-hand qty from the variant grid onto each 1× / 1m SKU.
     * Pack rows are skippped — their stock is derived from the base.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function syncVariantStock(int $productId, array $rows): void
    {
        $bySku = [];
        foreach ($this->variants->forProduct($productId) as $v) {
            $bySku[strtolower($v->sku)] = $v;
        }

        foreach ($rows as $row) {
            if (!array_key_exists('quantity', $row) || $row['quantity'] === null) {
                continue;
            }
            $sku = strtolower(trim((string) ($row['sku'] ?? '')));
            if ($sku === '' || !isset($bySku[$sku])) {
                continue;
            }
            $units = PackInventory::qtyFor($row);
            if ($units > 1) {
                continue;
            }
            $this->inventory->setQuantity((int) $bySku[$sku]->id, (int) $row['quantity']);
        }
    }

    // =========================================================================
    // Views
    // =========================================================================

    private function renderBrandField(string $current, bool $compact = false): string
    {
        $brands = $this->brands->all();
        $current = trim($current);
        $known = false;
        foreach ($brands as $b) {
            if (strcasecmp($b['name'], $current) === 0) {
                $known = true;
                $current = $b['name'];
                break;
            }
        }

        ob_start();
        ?>
        <div class="<?= $compact ? '' : 'mb-3 ' ?>" id="sf-brand-field">
            <?php if (!$compact): ?>
            <label class="form-label" for="brand">Brand</label>
            <?php endif; ?>
            <select name="brand" id="brand" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?= $this->view->e($b['name']) ?>"
                        <?= strcasecmp($b['name'], $current) === 0 ? ' selected' : '' ?>>
                        <?= $this->view->e($b['name']) ?>
                    </option>
                <?php endforeach; ?>
                <option value="__new__"<?= ($current !== '' && !$known) ? ' selected' : '' ?>>+ Add new brand…</option>
            </select>
            <div class="mt-2 d-none" id="sf-brand-new-wrap">
                <input type="text" name="brand_new" id="brand_new" class="form-control"
                       value="<?= $this->view->e(($current !== '' && !$known) ? $current : '') ?>"
                       placeholder="New brand name" maxlength="128">
            </div>
            <?php if (!$compact): ?>
            <div class="form-text">Catalog brands (StoreFeeder-style). Used on Outstanding Orders filters.</div>
            <?php endif; ?>
        </div>
        <script>
        (function () {
          const sel = document.getElementById('brand');
          const wrap = document.getElementById('sf-brand-new-wrap');
          const input = document.getElementById('brand_new');
          function sync() {
            const isNew = sel && sel.value === '__new__';
            if (wrap) wrap.classList.toggle('d-none', !isNew);
            if (isNew && input) input.focus();
          }
          if (sel) sel.addEventListener('change', sync);
          sync();
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    private function renderSupplierField(string $current): string
    {
        $suppliers = $this->suppliers->all();
        $current = trim($current);
        $known = false;
        foreach ($suppliers as $s) {
            if (strcasecmp($s['name'], $current) === 0) {
                $known = true;
                $current = $s['name'];
                break;
            }
        }

        ob_start();
        ?>
        <div id="sf-supplier-field">
            <select name="supplier_name" id="supplier_name" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?= $this->view->e($s['name']) ?>"
                        <?= strcasecmp($s['name'], $current) === 0 ? ' selected' : '' ?>>
                        <?= $this->view->e($s['name']) ?>
                    </option>
                <?php endforeach; ?>
                <option value="__new__"<?= ($current !== '' && !$known) ? ' selected' : '' ?>>+ Add new supplier…</option>
            </select>
            <div class="mt-2 d-none" id="sf-supplier-new-wrap">
                <input type="text" name="supplier_new" id="supplier_new" class="form-control"
                       value="<?= $this->view->e(($current !== '' && !$known) ? $current : '') ?>"
                       placeholder="New supplier name" maxlength="128">
            </div>
        </div>
        <script>
        (function () {
          const sel = document.getElementById('supplier_name');
          const wrap = document.getElementById('sf-supplier-new-wrap');
          const input = document.getElementById('supplier_new');
          function sync() {
            const isNew = sel && sel.value === '__new__';
            if (wrap) wrap.classList.toggle('d-none', !isNew);
            if (isNew && input) input.focus();
          }
          if (sel) sel.addEventListener('change', sync);
          sync();
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    private function renderWarehouseField(string $current): string
    {
        $this->warehouses->ensure('Default');
        $warehouses = $this->warehouses->all();
        $current = trim($current) !== '' ? trim($current) : 'Default';
        $known = false;
        foreach ($warehouses as $w) {
            if (strcasecmp($w['name'], $current) === 0) {
                $known = true;
                $current = $w['name'];
                break;
            }
        }
        if (!$known) {
            $current = $this->warehouses->ensure($current);
            $warehouses = $this->warehouses->all();
        }

        ob_start();
        ?>
        <select name="warehouse" id="warehouse" class="form-select">
            <?php foreach ($warehouses as $w): ?>
                <option value="<?= $this->view->e($w['name']) ?>"
                    <?= strcasecmp($w['name'], $current) === 0 ? ' selected' : '' ?>>
                    <?= $this->view->e($w['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
        return (string) ob_get_clean();
    }

    private function productForm(
        array $old,
        string $action,
        string $attributePickerHtml,
        string $categoryPickerHtml,
        string $variantsHtml,
        ?int $productId = null,
        string $listingsHtml = '',
        int $inventoryTotal = 0,
        string $ordersHtml = '',
        string $auditHtml = '',
        string $documentsHtml = '',
        string $bundleHtml = '',
    ): string {
        $isEdit = $productId !== null && $productId > 0;
        $title  = trim((string) ($old['name'] ?? '')) !== '' ? (string) $old['name'] : ($isEdit ? 'Product details' : 'Add Product');
        $weightKg = ((int) ($old['weight_grams'] ?? 0)) > 0
            ? rtrim(rtrim(number_format(((int) $old['weight_grams']) / 1000, 3, '.', ''), '0'), '.')
            : '';
        $taxClass = (string) ($old['tax_class'] ?? '20');
        $taxOptions = ['0', '5', '12.5', '20'];
        if ($taxClass !== '' && !in_array($taxClass, $taxOptions, true)) {
            $taxOptions[] = $taxClass;
        }

        ob_start();

        echo '<style>
          .sf-pd-layout{display:grid;grid-template-columns:220px 1fr;gap:1.25rem}
          @media (max-width:991.98px){.sf-pd-layout{grid-template-columns:1fr}}
          .sf-pd-nav{position:sticky;top:1rem}
          .sf-pd-nav .list-group-item{border:0;border-radius:.375rem;padding:.45rem .75rem;color:inherit}
          .sf-pd-nav .list-group-item.active{background:var(--bs-primary);color:#fff}
          .sf-pd-nav .list-group-item.disabled{opacity:.45}
          .sf-pd-section{scroll-margin-top:1rem}
          .sf-pd-field-row{display:grid;grid-template-columns:160px 1fr;gap:.75rem;align-items:center;margin-bottom:.75rem}
          @media (max-width:575.98px){.sf-pd-field-row{grid-template-columns:1fr}}
          .sf-pd-field-row>label{margin:0;font-size:.875rem;color:var(--bs-secondary-color)}
          .sf-pd-dims{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.5rem}
          @media (max-width:767.98px){.sf-pd-dims{grid-template-columns:1fr 1fr}}
          .sf-pd-listings{display:flex;flex-wrap:wrap;gap:.75rem}
          .sf-pd-listing{min-width:7.5rem;border:1px solid var(--bs-border-color);border-radius:.5rem;padding:.5rem .65rem;background:#fff}
          .sf-pd-listing .sf-pd-ch{font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:var(--bs-secondary-color)}
          .sf-pd-panel{display:none}
          .sf-pd-panel.is-active{display:block}
          #sf-sec-variants{scroll-margin-top:1rem}
          .sf-ship-card{border:1px solid var(--bs-border-color);border-radius:.5rem;overflow:hidden;margin-bottom:1rem;background:#f8f9fa}
          .sf-ship-card>.sf-ship-head{background:var(--bs-primary);color:#fff;padding:.65rem 1rem;display:flex;justify-content:space-between;align-items:center}
          .sf-ship-card>.sf-ship-body{padding:1rem}
          .sf-ship-row{display:grid;grid-template-columns:minmax(12rem,38%) 1fr auto;gap:.75rem;align-items:center;margin-bottom:.65rem}
          @media (max-width:767.98px){.sf-ship-row{grid-template-columns:1fr}}
          .sf-ship-row>label{margin:0;font-size:.875rem}
          .sf-ship-sub{border:1px solid var(--bs-border-color);border-radius:.375rem;padding:.75rem;margin-top:.75rem;background:#fff}
        </style>';

        echo $this->form->open(['action' => $action, 'method' => 'POST', 'files' => true, 'id' => 'sf-product-form']);
        echo '<input type="hidden" name="product_action" id="sf-product-action" value="">';

        echo '<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">';
        echo '<div>';
        echo '<div class="text-muted small"><a href="' . $this->view->e($this->url->to('storefeeder/admin/products'))
           . '">&laquo; Back to Product Manager</a></div>';
        echo '<h1 class="h3 mb-0">' . $this->view->e($title)
           . ($isEdit ? ' <span class="fs-6 fw-normal text-muted">(Edit Product)</span>' : '') . '</h1>';
        if ($isEdit && empty($old['active'])) {
            echo '<span class="badge text-bg-secondary mt-1">Inactive</span>';
        }
        echo '</div>';
        echo '<div class="d-flex flex-wrap gap-2">';
        if ($isEdit) {
            echo '<button type="submit" class="btn btn-outline-secondary btn-sm" name="product_action_btn" value="discontinue"'
               . ' onclick="document.getElementById(\'sf-product-action\').value=\'discontinue\';'
               . ' return confirm(\'Discontinue this product? It will be set inactive.\');">Discontinue Product</button>';
            echo '<button type="submit" class="btn btn-outline-secondary btn-sm" name="product_action_btn" value="archive"'
               . ' onclick="document.getElementById(\'sf-product-action\').value=\'archive\';'
               . ' return confirm(\'Archive this product? It will be set inactive.\');">Archive Product</button>';
        }
        echo '<button type="submit" class="btn btn-primary" onclick="document.getElementById(\'sf-product-action\').value=\'\';">'
           . 'Save</button>';
        echo '</div></div>';

        echo '<div class="sf-pd-layout">';

        // —— Sidebar ——
        echo '<aside class="sf-pd-nav">';
        echo '<div class="list-group list-group-flush small" id="sf-pd-tabs">';
        // [label, panel, enabled, scrollTo]
        // General scrolls to variants (top of general panel) — not Product Information,
        // or variants sit above the fold and look "missing".
        $nav = [
            ['General', 'general', true, 'sf-sec-variants'],
            ['Description', 'general', true, 'sf-sec-description'],
            ['Images', 'general', true, 'sf-sec-images'],
            ['Additional Information', 'general', true, 'sf-sec-info'],
            ['Channel Attributes', 'general', true, 'sf-sec-channel-attrs'],
            ['Shipping & Handling', 'shipping', true, ''],
            ['Listings', 'listings', $isEdit, ''],
            ['Categories', 'general', true, 'sf-sec-categories'],
            ['Attributes', 'general', true, 'sf-sec-attributes'],
            ['Notes', 'notes', true, ''],
            ['Orders', 'orders', $isEdit, ''],
            ['Audit Trail', 'audit', $isEdit, ''],
            ['Regulatory Information', 'regulatory', true, 'sf-reg-regulatory-information'],
            ['Responsible Person', 'regulatory', true, 'sf-reg-responsible-person'],
            ['Company Identifiers', 'regulatory', true, 'sf-reg-company-identifiers'],
            ['Documents', 'documents', $isEdit, ''],
            ['Additions & Swaps', 'bundle', $isEdit, ''],
        ];
        foreach ($nav as [$label, $panel, $enabled, $scrollTo]) {
            $active = $label === 'General' ? ' active' : '';
            $cls = 'list-group-item list-group-item-action' . $active;
            if (!$enabled || $panel === '') {
                echo '<span class="' . $cls . ' disabled">' . $this->view->e($label) . '</span>';
            } else {
                echo '<a class="' . $cls . '" href="#' . $this->view->e($scrollTo !== '' ? $scrollTo : ('sf-panel-' . $panel)) . '"'
                   . ' data-sf-panel="' . $this->view->e($panel) . '"'
                   . ($scrollTo !== '' ? ' data-sf-scroll="' . $this->view->e($scrollTo) . '"' : '')
                   . '>' . $this->view->e($label) . '</a>';
            }
        }
        echo '</div></aside>';

        echo '<div>';

        echo '<div class="sf-pd-panel is-active" id="sf-panel-general" data-sf-panel-body="general">';

        echo '<section class="card border-0 shadow-sm mb-3 sf-pd-section" id="sf-sec-variants">';
        echo '<div class="card-body">';
        echo '<h2 class="h6 text-uppercase text-muted mb-3">Product Variants</h2>';
        echo $variantsHtml;
        echo '</div></section>';

        echo '<section class="card border-0 shadow-sm mb-3 sf-pd-section" id="sf-sec-general">';
        echo '<div class="card-body">';
        echo '<div class="d-flex justify-content-between align-items-center mb-3">';
        echo '<h2 class="h6 text-uppercase text-muted mb-0" id="sf-sec-info">Product Information</h2>';
        echo '<div class="form-check mb-0"><input type="checkbox" class="form-check-input" id="sf-update-all-variants">'
           . '<label class="form-check-label small text-muted" for="sf-update-all-variants">'
           . 'All fields — update variant products</label></div>';
        echo '</div>';
        echo '<p class="small text-muted mb-3">Tick fields (or All fields) to copy name, price, cost, and weight onto every variant on Save.</p>';

        $infoUpdate = function (string $field, string $label = 'Update variants'): string {
            $id = 'info_update_' . $field;
            return '<div class="form-check mb-0 ms-2 text-nowrap">'
                . '<input type="checkbox" class="form-check-input sf-info-field-update" name="info_update['
                . $this->view->e($field) . ']" value="1" id="' . $this->view->e($id) . '">'
                . '<label class="form-check-label small text-muted" for="' . $this->view->e($id) . '">'
                . $this->view->e($label) . '</label></div>';
        };

        echo '<div class="row g-4">';
        echo '<div class="col-lg-8">';

        echo '<div class="sf-pd-field-row"><label for="name">Product name *</label>'
           . '<div class="d-flex align-items-center gap-2 flex-wrap">'
           . '<input type="text" class="form-control" name="name" id="name" required value="'
           . $this->view->e((string) $old['name']) . '">' . $infoUpdate('name') . '</div></div>';
        echo '<div class="sf-pd-field-row"><label for="sku">SKU *</label>'
           . '<input type="text" class="form-control" name="sku" id="sku" required value="'
           . $this->view->e((string) $old['sku']) . '"></div>';
        echo '<div class="sf-pd-field-row"><label>Inventory</label>'
           . '<div class="fw-semibold">' . ($isEdit ? (string) $inventoryTotal : '—') . '</div></div>';

        echo '<div class="sf-pd-field-row"><label for="price">Price *</label>'
           . '<div class="d-flex align-items-center gap-2 flex-wrap">'
           . '<div class="input-group"><span class="input-group-text">£</span>'
           . '<input type="text" class="form-control" name="price" id="price" value="'
           . $this->view->e((string) $old['price']) . '"></div>' . $infoUpdate('price') . '</div></div>';
        echo '<div class="sf-pd-field-row"><label for="rrp">RRP</label>'
           . '<div class="input-group"><span class="input-group-text">£</span>'
           . '<input type="text" class="form-control" name="rrp" id="rrp" value="'
           . $this->view->e((string) ($old['rrp'] ?? '')) . '"></div></div>';
        echo '<div class="sf-pd-field-row"><label for="cost">Cost</label>'
           . '<div class="d-flex align-items-center gap-2 flex-wrap">'
           . '<div class="input-group"><span class="input-group-text">£</span>'
           . '<input type="text" class="form-control" name="cost" id="cost" value="'
           . $this->view->e((string) $old['cost']) . '"></div>' . $infoUpdate('cost') . '</div></div>';

        echo '<div class="sf-pd-field-row"><label for="tax_class">Tax class</label><select name="tax_class" id="tax_class" class="form-select">';
        foreach ($taxOptions as $opt) {
            $sel = $taxClass === $opt ? ' selected' : '';
            echo '<option value="' . $this->view->e($opt) . '"' . $sel . '>' . $this->view->e($opt) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="sf-pd-field-row"><label>Price includes VAT</label>'
           . '<div class="form-check form-switch mb-0">'
           . '<input type="hidden" name="price_includes_vat" value="0">'
           . '<input class="form-check-input" type="checkbox" role="switch" name="price_includes_vat" value="1" id="price_includes_vat"'
           . (!empty($old['price_includes_vat']) ? ' checked' : '') . '>'
           . '<label class="form-check-label" for="price_includes_vat">'
           . (!empty($old['price_includes_vat']) ? 'Yes' : 'No') . '</label></div></div>';

        // Brand early so it stays visible beside the image (StoreFeeder Product Information).
        echo '<div class="sf-pd-field-row"><label for="warehouse">Warehouse</label><div>'
           . $this->renderWarehouseField((string) (($old['warehouse'] ?? '') !== '' ? $old['warehouse'] : 'Default'))
           . '</div></div>';
        echo '<div class="sf-pd-field-row"><label for="brand">Brand</label><div>'
           . $this->renderBrandField((string) ($old['brand'] ?? ''), true) . '</div></div>';
        echo '<div class="sf-pd-field-row"><label for="barcode">EAN</label>'
           . '<input type="text" class="form-control" name="barcode" id="barcode" value="'
           . $this->view->e((string) $old['barcode']) . '"></div>';
        echo '<div class="sf-pd-field-row"><label for="upc">UPC</label>'
           . '<input type="text" class="form-control" name="upc" id="upc" value="'
           . $this->view->e((string) ($old['upc'] ?? '')) . '"></div>';
        echo '<div class="sf-pd-field-row"><label for="mpn">MPN</label>'
           . '<input type="text" class="form-control" name="mpn" id="mpn" value="'
           . $this->view->e((string) ($old['mpn'] ?? '')) . '"></div>';
        echo '<div class="sf-pd-field-row"><label for="supplier_sku">Supplier SKU</label>'
           . '<input type="text" class="form-control" name="supplier_sku" id="supplier_sku" value="'
           . $this->view->e((string) ($old['supplier_sku'] ?? '')) . '"></div>';
        echo '<div class="sf-pd-field-row"><label for="supplier_name">Supplier</label><div>'
           . $this->renderSupplierField((string) ($old['supplier_name'] ?? '')) . '</div></div>';

        echo '<div class="sf-pd-field-row" id="sf-sec-dims"><label>Dimensions</label>'
           . '<div class="d-flex align-items-start gap-2 flex-wrap">'
           . '<div class="sf-pd-dims flex-grow-1">';
        echo '<div><label class="form-label small mb-0" for="weight_kg">Weight (kg)</label>'
           . '<input type="text" class="form-control form-control-sm" name="weight_kg" id="weight_kg" value="'
           . $this->view->e($weightKg) . '"></div>';
        echo '<div><label class="form-label small mb-0" for="length_mm">Length (mm)</label>'
           . '<input type="number" min="0" class="form-control form-control-sm" name="length_mm" id="length_mm" value="'
           . $this->view->e((string) ($old['length_mm'] ?? '')) . '"></div>';
        echo '<div><label class="form-label small mb-0" for="width_mm">Width (mm)</label>'
           . '<input type="number" min="0" class="form-control form-control-sm" name="width_mm" id="width_mm" value="'
           . $this->view->e((string) ($old['width_mm'] ?? '')) . '"></div>';
        echo '<div><label class="form-label small mb-0" for="height_mm">Height/Depth (mm)</label>'
           . '<input type="number" min="0" class="form-control form-control-sm" name="height_mm" id="height_mm" value="'
           . $this->view->e((string) ($old['height_mm'] ?? '')) . '"></div>';
        echo '</div>' . $infoUpdate('weight_grams', 'Update weight on variants') . '</div></div>';

        echo '<div class="sf-pd-field-row"><label for="active">Active</label>'
           . '<div class="form-check mb-0"><input type="hidden" name="active" value="0">'
           . '<input class="form-check-input" type="checkbox" name="active" value="1" id="active"'
           . (!empty($old['active']) ? ' checked' : '') . '>'
           . '<label class="form-check-label" for="active">Sellable / listed</label></div></div>';

        echo '</div>'; // col-lg-8

        echo '<div class="col-lg-4 sf-pd-section" id="sf-sec-images">';
        echo '<div class="text-muted small mb-2">Default image</div>';
        if (($old['image'] ?? '') !== '') {
            echo '<img src="' . $this->view->e($this->url->asset(
                    TenantStorage::fromBaseDir()->publicPrefix() . '/' . $old['image']
                )) . '"'
               . ' alt="" class="img-thumbnail mb-2" style="max-width:100%;height:auto;">';
            echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_image" value="1" id="remove-image">'
               . '<label class="form-check-label small" for="remove-image">Remove this image</label></div>';
        } else {
            echo '<div class="border rounded bg-light d-flex align-items-center justify-content-center mb-2" style="min-height:160px">'
               . '<i class="bi bi-image text-muted fs-1"></i></div>';
        }
        echo '<input type="file" class="form-control form-control-sm" name="image" accept="image/*">';
        echo '<div class="form-text">JPG, PNG, WEBP, or GIF. Max 5 MB.</div>';
        echo '</div>';

        echo '</div></div></section>'; // info card

        echo '<section class="card border-0 shadow-sm mb-3 sf-pd-section" id="sf-sec-description">';
        echo '<div class="card-body">';
        echo '<h2 class="h6 text-uppercase text-muted mb-3">Description</h2>';
        echo '<textarea class="form-control" name="description" rows="5">'
           . $this->view->e((string) $old['description']) . '</textarea>';
        echo '</div></section>';

        echo '<section class="card border-0 shadow-sm mb-3 sf-pd-section" id="sf-sec-channel-attrs">';
        echo '<div class="card-body">';
        echo '<h2 class="h6 text-uppercase text-muted mb-3">Channel Attributes</h2>';
        echo '<p class="small text-muted mb-3">Per-product overrides only. '
           . 'Shared mappings stay on each integration → General Attributes '
           . '(<code>#__sf_channel_attributes</code>). Empty here = inherit those defaults.</p>';
        // eBay-only listing fields stay with the eBay channel block below when present.
        echo $this->renderEbayCategoryConditionFields($old);
        echo $this->renderProductChannelAttributesEditor(
            (int) ($productId ?? 0),
            is_array($old['channel_attributes'] ?? null) ? $old['channel_attributes'] : null
        );
        echo '</div></section>';

        echo '<section class="card border-0 shadow-sm mb-3 sf-pd-section" id="sf-sec-attributes">';
        echo '<div class="card-body">' . $attributePickerHtml . '</div></section>';

        echo '<section class="card border-0 shadow-sm mb-3 sf-pd-section" id="sf-sec-categories">';
        echo '<div class="card-body">' . $categoryPickerHtml . '</div></section>';

        echo '</div>'; // sf-panel-general

        // —— Shipping & Handling tab ——
        echo '<div class="sf-pd-panel" id="sf-panel-shipping" data-sf-panel-body="shipping">';
        echo $this->renderShippingHandlingPanel(
            is_array($old['shipping'] ?? null)
                ? array_merge($this->defaultShipping(), $old['shipping'])
                : $this->defaultShipping()
        );
        echo '</div>';

        // —— Notes ——
        echo '<div class="sf-pd-panel" id="sf-panel-notes" data-sf-panel-body="notes">';
        echo '<div class="sf-ship-card"><div class="sf-ship-head"><strong>Notes</strong></div>';
        echo '<div class="sf-ship-body">';
        echo '<p class="small text-muted mb-2">Internal notes for warehouse / picking. Not sent to marketplaces.</p>';
        echo '<textarea class="form-control" name="notes" id="product_notes" rows="10" placeholder="Add notes…">'
           . $this->view->e((string) ($old['notes'] ?? '')) . '</textarea>';
        echo '</div></div></div>';

        // —— Regulatory / Responsible Person / Company Identifiers ——
        echo '<div class="sf-pd-panel" id="sf-panel-regulatory" data-sf-panel-body="regulatory">';
        echo $this->renderRegulatoryPanel(
            is_array($old['regulatory'] ?? null)
                ? array_merge($this->defaultRegulatory(), $old['regulatory'])
                : $this->defaultRegulatory()
        );
        echo '</div>';

        echo '<div class="d-flex justify-content-between align-items-center mt-2 mb-4 sf-pd-product-footer">';
        echo '<span class="small text-muted">* required field</span>';
        echo '<div><a class="btn btn-link" href="' . $this->view->e($this->url->to('storefeeder/admin/products'))
           . '">Cancel</a> '
           . '<button type="submit" class="btn btn-primary" onclick="document.getElementById(\'sf-product-action\').value=\'\';">Save</button></div>';
        echo '</div>';

        echo $this->form->close();

        // Listings / Orders / Audit / Documents sit outside the product form
        // (listings/documents have nested POST forms; orders/audit are read-only).
        if ($isEdit && $listingsHtml !== '') {
            echo '<div class="sf-pd-panel" id="sf-panel-listings" data-sf-panel-body="listings">';
            echo $listingsHtml;
            echo '</div>';
        }
        if ($isEdit && $ordersHtml !== '') {
            echo '<div class="sf-pd-panel" id="sf-panel-orders" data-sf-panel-body="orders">';
            echo $ordersHtml;
            echo '</div>';
        }
        if ($isEdit && $auditHtml !== '') {
            echo '<div class="sf-pd-panel" id="sf-panel-audit" data-sf-panel-body="audit">';
            echo $auditHtml;
            echo '</div>';
        }
        if ($isEdit && $documentsHtml !== '') {
            echo '<div class="sf-pd-panel" id="sf-panel-documents" data-sf-panel-body="documents">';
            echo $documentsHtml;
            echo '</div>';
        }
        if ($isEdit && $bundleHtml !== '') {
            echo '<div class="sf-pd-panel" id="sf-panel-bundle" data-sf-panel-body="bundle">';
            echo $bundleHtml;
            echo '</div>';
        }

        echo '</div></div>'; // main + layout

        echo '<script>
        (function () {
          const sw = document.getElementById("price_includes_vat");
          const lab = sw && sw.parentElement ? sw.parentElement.querySelector("label") : null;
          if (sw && lab) {
            sw.addEventListener("change", function () { lab.textContent = sw.checked ? "Yes" : "No"; });
          }

          function showPanel(name, activeLink, scrollId) {
            document.querySelectorAll("[data-sf-panel-body]").forEach(function (el) {
              el.classList.toggle("is-active", el.getAttribute("data-sf-panel-body") === name);
            });
            document.querySelectorAll("#sf-pd-tabs [data-sf-panel]").forEach(function (a) {
              a.classList.toggle("active", activeLink ? a === activeLink : false);
            });
            if (!activeLink) {
              const first = document.querySelector("#sf-pd-tabs [data-sf-panel=\"" + name + "\"]");
              if (first) first.classList.add("active");
            }
            const foot = document.querySelector(".sf-pd-product-footer");
            if (foot) {
              foot.style.display = (name === "listings" || name === "orders" || name === "audit" || name === "documents" || name === "bundle")
                ? "none" : "";
            }
            if (scrollId) {
              const target = document.getElementById(scrollId);
              if (target) {
                setTimeout(function () {
                  target.scrollIntoView({ behavior: "smooth", block: "start" });
                }, 40);
              }
            }
            try { history.replaceState(null, "", "#sf-panel-" + name); } catch (e) {}
          }
          document.querySelectorAll("#sf-pd-tabs [data-sf-panel]").forEach(function (a) {
            a.addEventListener("click", function (e) {
              e.preventDefault();
              showPanel(
                a.getAttribute("data-sf-panel") || "general",
                a,
                a.getAttribute("data-sf-scroll") || ""
              );
            });
          });
          const params = new URLSearchParams(location.search || "");
          const panelParam = params.get("panel") || "";
          let hash = (location.hash || "").replace(/^#/, "");
          if (hash.indexOf("sf-panel-") === 0) hash = hash.slice("sf-panel-".length);
          const initial = panelParam || hash;
          if (["shipping", "notes", "regulatory", "listings", "orders", "audit", "documents", "bundle"].indexOf(initial) >= 0) {
            showPanel(initial, null, "");
          } else {
            // Ensure general (incl. Product Variants) is visible on load.
            showPanel("general", document.querySelector("#sf-pd-tabs [data-sf-panel=\"general\"]"), "");
          }

          document.querySelectorAll(".sf-ship-switch").forEach(function (inp) {
            const labEl = inp.parentElement && inp.parentElement.querySelector("label");
            const on = inp.getAttribute("data-on") || "Yes";
            const off = inp.getAttribute("data-off") || "No";
            function sync() { if (labEl) labEl.textContent = inp.checked ? on : off; }
            inp.addEventListener("change", sync);
            sync();
          });

          document.querySelectorAll(".sf-listing-controlled").forEach(function (inp) {
            const labEl = inp.parentElement && inp.parentElement.querySelector("label");
            function sync() { if (labEl) labEl.textContent = inp.checked ? "On" : "Off"; }
            inp.addEventListener("change", sync);
            sync();
          });

          (function () {
            const all = document.getElementById("sf-update-all-variants");
            const fields = document.querySelectorAll(".sf-info-field-update");
            if (!all || !fields.length) return;
            function syncAll() {
              const n = Array.prototype.filter.call(fields, function (c) { return c.checked; }).length;
              all.checked = n === fields.length;
              all.indeterminate = n > 0 && n < fields.length;
            }
            all.addEventListener("change", function () {
              fields.forEach(function (c) { c.checked = all.checked; });
              all.indeterminate = false;
            });
            fields.forEach(function (c) { c.addEventListener("change", syncAll); });
            syncAll();
          })();
        })();
        </script>';

        return (string) ob_get_clean();
    }

    private function currentAuditUserLabel(): string
    {
        $user = $this->auth->user();
        if ($user === null) {
            return 'System';
        }
        $label = method_exists($user, 'displayLabel') ? (string) $user->displayLabel() : '';
        if ($label === '' && isset($user->username)) {
            $label = (string) $user->username;
        }
        if ($label === '') {
            $label = 'User #' . (int) ($this->auth->id() ?? 0);
        }
        return $label;
    }

    /** @param array<string, mixed> $changes */
    private function logProductAudit(int $productId, string $action, string $summary, array $changes = []): void
    {
        try {
            $this->audit->log(
                $productId,
                $action,
                $summary,
                $changes,
                $this->auth->id(),
                $this->currentAuditUserLabel(),
            );
        } catch (\Throwable $e) {
            error_log('Product audit #' . $productId . ': ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diffProductAudit(array $before, array $after): array
    {
        $keys = [
            'sku', 'name', 'brand', 'barcode', 'upc', 'mpn', 'supplier_sku',
            'supplier_name', 'warehouse', 'price', 'rrp', 'cost',
            'tax_class', 'price_includes_vat', 'weight_grams', 'length_mm',
            'width_mm', 'height_mm', 'active', 'notes', 'image',
        ];
        $out = [];
        foreach ($keys as $key) {
            $from = $before[$key] ?? null;
            $to   = $after[$key] ?? null;
            if (is_bool($from)) {
                $from = $from ? 1 : 0;
            }
            if (is_bool($to)) {
                $to = $to ? 1 : 0;
            }
            if ((string) $from === (string) $to) {
                continue;
            }
            $out[$key] = ['from' => $from, 'to' => $to];
        }
        return $out;
    }

    private function renderProductOrdersTab(int $productId): string
    {
        $rows = $this->orders->forProduct($productId, 75);
        ob_start();
        echo '<div class="sf-ship-card"><div class="sf-ship-head"><strong>Orders</strong></div>';
        echo '<div class="sf-ship-body">';
        echo '<p class="small text-muted mb-3">Orders that include a variant of this product (most recent first).</p>';
        if ($rows === []) {
            echo '<div class="alert alert-info mb-0">No orders linked to this product yet. '
               . 'Orders appear here after sync when line items match product variants.</div>';
            echo '</div></div>';
            return (string) ob_get_clean();
        }

        echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
        echo '<thead><tr>'
           . '<th>Order</th><th>Channel</th><th>Date</th><th>Buyer</th>'
           . '<th>SKUs</th><th class="text-end">Qty</th><th class="text-end">Total</th><th>Status</th>'
           . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $oid = (int) $r['id'];
            $badge = match (strtoupper((string) $r['status'])) {
                'FULFILLED', 'SHIPPED', 'COMPLETED' => 'text-bg-success',
                'CANCELLED', 'CANCELED' => 'text-bg-danger',
                'NOT_STARTED', 'PENDING', 'AWAITING_PAYMENT' => 'text-bg-secondary',
                default => 'text-bg-primary',
            };
            echo '<tr>';
            echo '<td><a href="' . $this->view->e($this->url->to('storefeeder/admin/orders/view', ['id' => $oid])) . '">'
               . $this->view->e((string) $r['external_order_id']) . '</a></td>';
            echo '<td class="small">' . $this->view->e((string) $r['channel_label']) . '</td>';
            echo '<td class="small text-nowrap">' . $this->view->e((string) $r['created_at']) . '</td>';
            echo '<td class="small">' . $this->view->e((string) ($r['buyer_username'] !== '' ? $r['buyer_username'] : $r['shipping_name'])) . '</td>';
            echo '<td class="small"><code>' . $this->view->e((string) ($r['line_skus'] !== '' ? $r['line_skus'] : '—')) . '</code></td>';
            echo '<td class="text-end">' . (int) $r['line_qty'] . '</td>';
            echo '<td class="text-end text-nowrap">' . number_format((float) $r['total'], 2)
               . ' ' . $this->view->e((string) $r['currency']) . '</td>';
            echo '<td><span class="badge ' . $badge . '">' . $this->view->e((string) $r['status']) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div></div>';
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $old */
    private function renderProductBundleTab(int $productId, array $old): string
    {
        $rows = $this->bundles->forProduct($productId);
        $product = $this->products->find($productId);
        $displayOnOrder = $product !== null
            ? $product->kitDisplayOnOrder
            : !empty($old['kit_display_on_order'] ?? 1);

        ob_start();

        echo '<div class="sf-ship-card"><div class="sf-ship-head"><strong>Additions &amp; Swaps</strong></div>';
        echo '<div class="sf-ship-body">';
        echo '<p class="small text-muted mb-3">Classic StoreFeeder kit rules. When this SKU is ordered, '
           . 'components expand onto the order. <strong>Delete original = Yes</strong> hides the kit parent line; '
           . '<strong>Display kit on order</strong> must also be Yes to keep the kit SKU visible.</p>';

        echo '<form method="post" action="'
           . $this->view->e($this->url->to('storefeeder/admin/products/update-kit-settings', ['id' => $productId]))
           . '" class="mb-4">';
        echo $this->request->csrfField();
        echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">';
        echo '<div class="form-check form-switch mb-0">'
           . '<input type="hidden" name="kit_display_on_order" value="0">'
           . '<input class="form-check-input sf-ship-switch" type="checkbox" role="switch" name="kit_display_on_order"'
           . ' value="1" id="kit_display_on_order"' . ($displayOnOrder ? ' checked' : '')
           . ' data-on="Yes" data-off="No">'
           . '<label class="form-check-label" for="kit_display_on_order">Display kit product on order: '
           . ($displayOnOrder ? 'Yes' : 'No') . '</label></div>';
        echo '<button type="submit" class="btn btn-sm btn-outline-primary">Save kit settings</button>';
        echo '</div></form>';

        echo '<h3 class="h6">Add component</h3>';
        echo '<form method="post" action="'
           . $this->view->e($this->url->to('storefeeder/admin/products/add-bundle-component', ['id' => $productId]))
           . '" class="row g-2 align-items-end mb-0">';
        echo $this->request->csrfField();
        echo '<div class="col-md-3"><label class="form-label small mb-1" for="component_sku">Product addition (SKU)</label>'
           . '<input type="text" class="form-control form-control-sm" name="component_sku" id="component_sku"'
           . ' required placeholder="Component SKU" autocomplete="off"></div>';
        echo '<div class="col-md-2"><label class="form-label small mb-1" for="component_qty">Qty</label>'
           . '<input type="number" min="1" class="form-control form-control-sm" name="component_qty"'
           . ' id="component_qty" value="1" required></div>';
        echo '<div class="col-md-3"><label class="form-label small mb-1" for="delete_original">Delete original from order</label>'
           . '<select class="form-select form-select-sm" name="delete_original" id="delete_original">'
           . '<option value="1" selected>Yes</option><option value="0">No</option></select></div>';
        echo '<div class="col-md-3"><label class="form-label small mb-1" for="update_kit_inventory">Update kit inventory</label>'
           . '<select class="form-select form-select-sm" name="update_kit_inventory" id="update_kit_inventory">'
           . '<option value="0" selected>No</option><option value="1">Yes</option></select></div>';
        echo '<div class="col-md-1"><button type="submit" class="btn btn-sm btn-primary w-100">Add</button></div>';
        echo '</form></div></div>';

        echo '<div class="sf-ship-card"><div class="sf-ship-head"><strong>Bundle components</strong></div>';
        echo '<div class="sf-ship-body">';
        if ($rows === []) {
            echo '<div class="alert alert-info mb-0">No components yet. Add a product SKU above to build this kit. '
               . 'Size-roll SKUs without components still auto-expand to the 1m base SKU on import.</div>';
            echo '</div></div>';
            return (string) ob_get_clean();
        }

        echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
        echo '<thead><tr><th>SKU</th><th>Name</th><th style="width:5rem">Qty</th>'
           . '<th>Delete original</th><th>Update kit stock</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $cid = (int) $r['id'];
            $compPid = (int) $r['component_product_id'];
            $delYes = !empty($r['delete_original']);
            $updYes = !empty($r['update_kit_inventory']);
            echo '<tr>';
            echo '<td><a href="' . $this->view->e($this->url->to('storefeeder/admin/products/edit', ['id' => $compPid]))
               . '"><code>' . $this->view->e((string) $r['component_sku']) . '</code></a></td>';
            echo '<td>' . $this->view->e((string) $r['component_name']) . '</td>';
            echo '<td colspan="4">';
            echo '<form method="post" action="'
               . $this->view->e($this->url->to('storefeeder/admin/products/update-bundle-component', ['id' => $productId]))
               . '" class="row g-1 align-items-center">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="component_id" value="' . $cid . '">';
            echo '<div class="col-auto"><input type="number" min="1" class="form-control form-control-sm"'
               . ' name="component_qty" value="' . (int) $r['qty'] . '" style="width:4.5rem" required></div>';
            echo '<div class="col-auto"><select class="form-select form-select-sm" name="delete_original">'
               . '<option value="1"' . ($delYes ? ' selected' : '') . '>Yes</option>'
               . '<option value="0"' . (!$delYes ? ' selected' : '') . '>No</option></select></div>';
            echo '<div class="col-auto"><select class="form-select form-select-sm" name="update_kit_inventory">'
               . '<option value="0"' . (!$updYes ? ' selected' : '') . '>No</option>'
               . '<option value="1"' . ($updYes ? ' selected' : '') . '>Yes</option></select></div>';
            echo '<div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-primary">Save</button></div>';
            echo '<div class="col-auto"><button type="submit" formaction="'
               . $this->view->e($this->url->to('storefeeder/admin/products/delete-bundle-component', ['id' => $productId]))
               . '" class="btn btn-sm btn-outline-danger"'
               . ' onclick="return confirm(\'Remove this component from the bundle?\');">Remove</button></div>';
            echo '</form></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div></div>';
        return (string) ob_get_clean();
    }

    private function renderProductDocumentsTab(int $productId): string
    {
        $rows = $this->documents->forProduct($productId);
        ob_start();

        echo '<div class="sf-ship-card"><div class="sf-ship-head"><strong>Upload document</strong></div>';
        echo '<div class="sf-ship-body">';
        echo '<p class="small text-muted mb-3">PDF, Word, Excel, CSV, TXT, or images. Max 10 MB.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="'
           . $this->view->e($this->url->to('storefeeder/admin/products/upload-document', ['id' => $productId]))
           . '">';
        echo $this->request->csrfField();
        echo '<div class="row g-2 align-items-end">';
        echo '<div class="col-md-4"><label class="form-label small mb-1" for="document_title">Title</label>'
           . '<input type="text" class="form-control form-control-sm" name="document_title" id="document_title"'
           . ' placeholder="Optional — defaults to filename"></div>';
        echo '<div class="col-md-5"><label class="form-label small mb-1" for="document">File</label>'
           . '<input type="file" class="form-control form-control-sm" name="document" id="document" required></div>';
        echo '<div class="col-md-3"><button type="submit" class="btn btn-sm btn-primary w-100">Upload</button></div>';
        echo '</div></form></div></div>';

        echo '<div class="sf-ship-card"><div class="sf-ship-head"><strong>Documents</strong></div>';
        echo '<div class="sf-ship-body">';
        if ($rows === []) {
            echo '<div class="alert alert-info mb-0">No documents yet. Upload a file above.</div>';
            echo '</div></div>';
            return (string) ob_get_clean();
        }

        echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
        echo '<thead><tr><th>Title</th><th>File</th><th>Type</th><th class="text-end">Size</th>'
           . '<th>Uploaded</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $docId = (int) $r['id'];
            $dl = $this->url->to('storefeeder/admin/products/download-document', [
                'id'     => $productId,
                'doc_id' => $docId,
            ]);
            $size = $this->formatDocumentBytes((int) $r['size_bytes']);
            $mime = (string) $r['mime'];
            $typeLabel = $mime !== '' ? strtoupper((string) (pathinfo((string) $r['original_name'], PATHINFO_EXTENSION) ?: $mime)) : '—';
            echo '<tr>';
            echo '<td>' . $this->view->e((string) $r['title']) . '</td>';
            echo '<td class="small"><code>' . $this->view->e((string) $r['original_name']) . '</code></td>';
            echo '<td class="small">' . $this->view->e($typeLabel) . '</td>';
            echo '<td class="text-end small text-nowrap">' . $this->view->e($size) . '</td>';
            echo '<td class="small text-nowrap">' . $this->view->e((string) $r['created_at']) . '</td>';
            echo '<td class="text-end text-nowrap">';
            echo '<a class="btn btn-sm btn-outline-primary" href="' . $this->view->e($dl) . '">Download</a> ';
            echo '<form method="post" action="'
               . $this->view->e($this->url->to('storefeeder/admin/products/delete-document', ['id' => $productId]))
               . '" class="d-inline" onsubmit="return confirm(\'Delete this document?\');">'
               . $this->request->csrfField()
               . '<input type="hidden" name="doc_id" value="' . $docId . '">'
               . '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div></div>';
        return (string) ob_get_clean();
    }

    private function formatDocumentBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
        }
        return rtrim(rtrim(number_format($bytes / (1024 * 1024), 2, '.', ''), '0'), '.') . ' MB';
    }

    private function renderProductAuditTab(int $productId): string
    {
        $rows = $this->audit->forProduct($productId, 100);
        ob_start();
        echo '<div class="sf-ship-card"><div class="sf-ship-head"><strong>Audit Trail</strong></div>';
        echo '<div class="sf-ship-body">';
        echo '<p class="small text-muted mb-3">Changes saved on this product (newest first).</p>';
        if ($rows === []) {
            echo '<div class="alert alert-info mb-0">No audit entries yet. Save the product to start tracking changes.</div>';
            echo '</div></div>';
            return (string) ob_get_clean();
        }

        echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
        echo '<thead><tr><th>When</th><th>User</th><th>Action</th><th>Summary</th><th>Changes</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td class="small text-nowrap">' . $this->view->e((string) $r['created_at']) . '</td>';
            echo '<td class="small">' . $this->view->e((string) ($r['user_label'] !== '' ? $r['user_label'] : '—')) . '</td>';
            echo '<td><span class="badge text-bg-secondary">' . $this->view->e((string) $r['action']) . '</span></td>';
            echo '<td class="small">' . $this->view->e((string) $r['summary']) . '</td>';
            echo '<td class="small">';
            $changes = is_array($r['changes'] ?? null) ? $r['changes'] : [];
            if ($changes === []) {
                echo '<span class="text-muted">—</span>';
            } else {
                echo '<ul class="mb-0 ps-3">';
                $n = 0;
                foreach ($changes as $field => $change) {
                    if ($n >= 8) {
                        echo '<li class="text-muted">+' . (count($changes) - 8) . ' more</li>';
                        break;
                    }
                    $from = is_array($change) ? ($change['from'] ?? '') : '';
                    $to   = is_array($change) ? ($change['to'] ?? '') : '';
                    if (is_bool($from)) {
                        $from = $from ? 'yes' : 'no';
                    }
                    if (is_bool($to)) {
                        $to = $to ? 'yes' : 'no';
                    }
                    if (is_array($from) || is_array($to)) {
                        continue;
                    }
                    echo '<li><code>' . $this->view->e((string) $field) . '</code>: '
                       . $this->view->e((string) $from) . ' → ' . $this->view->e((string) $to) . '</li>';
                    $n++;
                }
                echo '</ul>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div></div>';
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $ship */
    private function renderShippingHandlingPanel(array $ship): string
    {
        $packageSizes = [
            '' => 'Please Select',
            'letter' => 'Letter',
            'large_letter' => 'Large Letter',
            'small_parcel' => 'Small Parcel',
            'medium_parcel' => 'Medium Parcel',
            'large_parcel' => 'Large Parcel',
            'custom' => 'Custom',
        ];
        $countries = [
            '' => 'Please Select',
            'GB' => 'United Kingdom',
            'CN' => 'China',
            'US' => 'United States',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'PL' => 'Poland',
            'IN' => 'India',
            'VN' => 'Vietnam',
            'OTHER' => 'Other',
        ];
        $customsCats = [
            'ecommerce_sale' => 'E-Commerce Sale Of Good',
            'gift' => 'Gift',
            'documents' => 'Documents',
            'commercial_sample' => 'Commercial Sample',
            'returned_goods' => 'Returned Goods',
            'other' => 'Other',
        ];

        $e = fn(string $s): string => $this->view->e($s);

        $row = function (string $label, string $controlHtml, string $field, string $cardId) use ($e): string {
            $cid = 'ship_update_' . $field;
            return '<div class="sf-ship-row"><label>' . $e($label) . '</label><div>' . $controlHtml . '</div>'
                . '<div class="form-check mb-0">'
                . '<input type="checkbox" class="form-check-input sf-ship-field-update" name="ship_update['
                . $e($field) . ']" value="1" id="' . $e($cid) . '" data-ship-card="' . $e($cardId) . '">'
                . '<label class="form-check-label small text-muted" for="' . $e($cid)
                . '">Update variant products</label></div></div>';
        };

        $toggle = function (string $name, mixed $val, string $on = 'Yes', string $off = 'No') use ($e): string {
            $id = 'ship_' . $name;
            $checked = !empty($val) ? ' checked' : '';
            return '<div class="form-check form-switch mb-0">'
                . '<input type="hidden" name="' . $e($id) . '" value="0">'
                . '<input class="form-check-input sf-ship-switch" type="checkbox" role="switch" name="'
                . $e($id) . '" value="1" id="' . $e($id) . '"' . $checked
                . ' data-on="' . $e($on) . '" data-off="' . $e($off) . '">'
                . '<label class="form-check-label" for="' . $e($id) . '">'
                . $e(!empty($val) ? $on : $off) . '</label></div>';
        };

        $text = function (string $name, mixed $val) use ($e): string {
            $id = 'ship_' . $name;
            return '<input type="text" class="form-control form-control-sm" name="' . $e($id)
                . '" id="' . $e($id) . '" value="' . $e((string) $val) . '">';
        };

        $select = function (string $name, array $options, mixed $val) use ($e): string {
            $id = 'ship_' . $name;
            $html = '<select class="form-select form-select-sm" name="' . $e($id) . '" id="' . $e($id) . '">';
            foreach ($options as $k => $label) {
                $sel = (string) $val === (string) $k ? ' selected' : '';
                $html .= '<option value="' . $e((string) $k) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            return $html . '</select>';
        };

        $cardOpen = function (string $title, string $allId) use ($e): string {
            return '<div class="sf-ship-card" data-ship-card="' . $e($allId) . '">'
                . '<div class="sf-ship-head"><strong>' . $e($title) . '</strong>'
                . '<div class="form-check mb-0 text-white">'
                . '<input type="checkbox" class="form-check-input sf-ship-all-update" id="' . $e($allId)
                . '" data-ship-card="' . $e($allId) . '">'
                . '<label class="form-check-label small" for="' . $e($allId)
                . '">All fields - update variant products</label></div></div><div class="sf-ship-body">';
        };

        ob_start();

        $c1 = 'sf-ship-all-1';
        echo $cardOpen('Size & Shipping Details', $c1);
        echo $row('Package size', $select('package_size', $packageSizes, $ship['package_size'] ?? ''), 'package_size', $c1);
        echo $row('Packaging Quantity', $text('packaging_qty', $ship['packaging_qty'] ?? ''), 'packaging_qty', $c1);
        echo $row(
            'Record Serial Number On Barcode Despatch',
            $toggle('record_serial_on_despatch', $ship['record_serial_on_despatch'] ?? 0, 'On', 'Off'),
            'record_serial_on_despatch',
            $c1
        );
        echo $row(
            'Shipping Cost',
            '<div class="input-group input-group-sm"><span class="input-group-text">£</span>'
            . $text('shipping_cost', $ship['shipping_cost'] ?? '') . '</div>',
            'shipping_cost',
            $c1
        );
        echo $row('Is Fragile', $toggle('is_fragile', $ship['is_fragile'] ?? 0), 'is_fragile', $c1);
        echo '</div></div>';

        $c2 = 'sf-ship-all-2';
        echo $cardOpen('Hazardous Goods Information', $c2);
        echo $row('Contains Firearm', $toggle('contains_firearm', $ship['contains_firearm'] ?? 0), 'contains_firearm', $c2);
        echo $row('Firearm Type', $text('firearm_type', $ship['firearm_type'] ?? ''), 'firearm_type', $c2);
        echo $row(
            'Contains Hazardous Materials',
            $toggle('contains_hazardous', $ship['contains_hazardous'] ?? 0),
            'contains_hazardous',
            $c2
        );
        echo $row('UN/ID Code', $text('un_id_code', $ship['un_id_code'] ?? ''), 'un_id_code', $c2);
        echo $row(
            'Override Domestic Limited Quantity Dangerous Goods (LQDG) for Royal Mail',
            $toggle('override_lqdg_royal_mail', $ship['override_lqdg_royal_mail'] ?? 0),
            'override_lqdg_royal_mail',
            $c2
        );
        echo $row(
            'Is Limited Quantity Dangerous Goods (LQDG)',
            $toggle('is_lqdg', $ship['is_lqdg'] ?? 0),
            'is_lqdg',
            $c2
        );
        echo $row('Is Bladed', $toggle('is_bladed', $ship['is_bladed'] ?? 0), 'is_bladed', $c2);
        echo '</div></div>';

        $c3 = 'sf-ship-all-3';
        echo $cardOpen('Customs Details', $c3);
        echo '<p class="small text-muted mb-3">If you need help finding the Harmonisation code for your product, visit '
            . '<a href="https://www.gov.uk/trade-tariff" target="_blank" rel="noopener">gov.uk/trade-tariff</a>.</p>';
        echo $row(
            'Product Type Description',
            $text('customs_product_type', $ship['customs_product_type'] ?? ''),
            'customs_product_type',
            $c3
        );
        echo $row(
            'Detailed Product Type Description',
            $text('customs_product_type_detail', $ship['customs_product_type_detail'] ?? ''),
            'customs_product_type_detail',
            $c3
        );
        echo $row(
            'Product Composition',
            $text('product_composition', $ship['product_composition'] ?? ''),
            'product_composition',
            $c3
        );
        echo $row(
            'Product Harmonised Code',
            $text('harmonised_code', $ship['harmonised_code'] ?? ''),
            'harmonised_code',
            $c3
        );
        echo $row(
            'Country Of Manufacture',
            $select('country_of_manufacture', $countries, $ship['country_of_manufacture'] ?? ''),
            'country_of_manufacture',
            $c3
        );
        echo $row(
            'Customs Declaration Category',
            $select(
                'customs_declaration_category',
                $customsCats,
                $ship['customs_declaration_category'] ?? 'ecommerce_sale'
            ),
            'customs_declaration_category',
            $c3
        );

        echo '<div class="sf-ship-sub"><div class="fw-semibold small mb-2">US CPSC filing</div>';
        echo '<div class="sf-ship-row mb-2"><div class="form-check mb-0">'
            . '<input type="hidden" name="ship_cpsc_regulated" value="0">'
            . '<input class="form-check-input" type="checkbox" name="ship_cpsc_regulated" value="1" id="ship_cpsc_regulated"'
            . (!empty($ship['cpsc_regulated']) ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="ship_cpsc_regulated">This is a CPSC-regulated product</label></div>'
            . '<div></div>'
            . '<div class="form-check mb-0">'
            . '<input type="checkbox" class="form-check-input sf-ship-field-update" name="ship_update[cpsc_regulated]"'
            . ' value="1" id="ship_update_cpsc_regulated" data-ship-card="' . $e($c3) . '">'
            . '<label class="form-check-label small text-muted" for="ship_update_cpsc_regulated">'
            . 'Update variant products</label></div></div>';
        echo $row('Certifier ID', $text('cpsc_certifier_id', $ship['cpsc_certifier_id'] ?? ''), 'cpsc_certifier_id', $c3);
        echo $row(
            'Certificate Version ID',
            $text('cpsc_certificate_version_id', $ship['cpsc_certificate_version_id'] ?? ''),
            'cpsc_certificate_version_id',
            $c3
        );
        echo $row(
            'Product ID (standardised identifier)',
            $text('cpsc_product_id', $ship['cpsc_product_id'] ?? ''),
            'cpsc_product_id',
            $c3
        );
        echo $row(
            'Intended Use Code',
            $text('cpsc_intended_use_code', $ship['cpsc_intended_use_code'] ?? ''),
            'cpsc_intended_use_code',
            $c3
        );
        echo $row(
            'Intended Use Description',
            $text('cpsc_intended_use_desc', $ship['cpsc_intended_use_desc'] ?? ''),
            'cpsc_intended_use_desc',
            $c3
        );
        echo $row(
            'Disclaimer Reason',
            $text('cpsc_disclaimer_reason', $ship['cpsc_disclaimer_reason'] ?? ''),
            'cpsc_disclaimer_reason',
            $c3
        );
        echo '</div>';

        echo '<div class="sf-ship-sub"><div class="fw-semibold small mb-2">EU Customs Product Identifiers</div>';
        echo $row(
            'Product Mid Code',
            $text('eu_product_mid_code', $ship['eu_product_mid_code'] ?? ''),
            'eu_product_mid_code',
            $c3
        );
        echo $row(
            'Non Standard Mid Code',
            $text('eu_non_standard_mid_code', $ship['eu_non_standard_mid_code'] ?? ''),
            'eu_non_standard_mid_code',
            $c3
        );
        echo '</div>';

        echo '<div class="sf-ship-sub"><div class="fw-semibold small mb-2">US Partner Government Agency (PGA)</div>';
        echo $row(
            'PGA Reference Number',
            $text('pga_reference_number', $ship['pga_reference_number'] ?? ''),
            'pga_reference_number',
            $c3
        );
        echo '</div>';

        echo '</div></div>';

        echo '<script>
        (function () {
          function syncCard(cardId) {
            const fields = document.querySelectorAll(".sf-ship-field-update[data-ship-card=\"" + cardId + "\"]");
            const all = document.querySelector(".sf-ship-all-update[data-ship-card=\"" + cardId + "\"]");
            if (!all || !fields.length) return;
            const checked = Array.prototype.filter.call(fields, function (c) { return c.checked; }).length;
            all.checked = checked === fields.length;
            all.indeterminate = checked > 0 && checked < fields.length;
          }
          document.querySelectorAll(".sf-ship-all-update").forEach(function (all) {
            all.addEventListener("change", function () {
              const cardId = all.getAttribute("data-ship-card") || "";
              document.querySelectorAll(".sf-ship-field-update[data-ship-card=\"" + cardId + "\"]")
                .forEach(function (c) { c.checked = all.checked; });
              all.indeterminate = false;
            });
          });
          document.querySelectorAll(".sf-ship-field-update").forEach(function (c) {
            c.addEventListener("change", function () {
              syncCard(c.getAttribute("data-ship-card") || "");
            });
          });
          document.querySelectorAll(".sf-ship-all-update").forEach(function (all) {
            syncCard(all.getAttribute("data-ship-card") || "");
          });
        })();
        </script>';

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $reg */
    private function renderRegulatoryPanel(array $reg): string
    {
        $e = fn(string $s): string => $this->view->e($s);
        $countries = [
            '' => 'Please Select',
            'GB' => 'United Kingdom',
            'IE' => 'Ireland',
            'DE' => 'Germany',
            'FR' => 'France',
            'NL' => 'Netherlands',
            'OTHER' => 'Other',
        ];

        $row = function (string $label, string $controlHtml) use ($e): string {
            return '<div class="sf-ship-row"><label>' . $e($label) . '</label><div>' . $controlHtml
                . '</div><div></div></div>';
        };
        $text = function (string $name, mixed $val) use ($e): string {
            $id = 'reg_' . $name;
            return '<input type="text" class="form-control form-control-sm" name="' . $e($id)
                . '" id="' . $e($id) . '" value="' . $e((string) $val) . '">';
        };
        $area = function (string $name, mixed $val, int $rows = 3) use ($e): string {
            $id = 'reg_' . $name;
            return '<textarea class="form-control form-control-sm" name="' . $e($id) . '" id="'
                . $e($id) . '" rows="' . $rows . '">' . $e((string) $val) . '</textarea>';
        };
        $toggle = function (string $name, mixed $val) use ($e): string {
            $id = 'reg_' . $name;
            $checked = !empty($val) ? ' checked' : '';
            return '<div class="form-check form-switch mb-0">'
                . '<input type="hidden" name="' . $e($id) . '" value="0">'
                . '<input class="form-check-input sf-ship-switch" type="checkbox" role="switch" name="'
                . $e($id) . '" value="1" id="' . $e($id) . '"' . $checked
                . ' data-on="Yes" data-off="No">'
                . '<label class="form-check-label" for="' . $e($id) . '">'
                . $e(!empty($val) ? 'Yes' : 'No') . '</label></div>';
        };
        $select = function (string $name, array $options, mixed $val) use ($e): string {
            $id = 'reg_' . $name;
            $html = '<select class="form-select form-select-sm" name="' . $e($id) . '" id="' . $e($id) . '">';
            foreach ($options as $k => $label) {
                $sel = (string) $val === (string) $k ? ' selected' : '';
                $html .= '<option value="' . $e((string) $k) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            return $html . '</select>';
        };
        $cardOpen = function (string $title) use ($e): string {
            return '<div class="sf-ship-card" id="sf-reg-'
                . $e(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? 'card'))
                . '"><div class="sf-ship-head"><strong>' . $e($title)
                . '</strong></div><div class="sf-ship-body">';
        };

        ob_start();

        echo $cardOpen('Regulatory Information');
        echo '<p class="small text-muted mb-3">Safety and compliance details used for marketplace / GPSR listings.</p>';
        echo $row('Age restriction', $text('age_restriction', $reg['age_restriction'] ?? ''));
        echo $row('Safety warnings', $area('safety_warnings', $reg['safety_warnings'] ?? ''));
        echo $row('Instructions included', $toggle('instructions_included', $reg['instructions_included'] ?? 0));
        echo $row('CE / UKCA marking', $text('ce_ukca_marking', $reg['ce_ukca_marking'] ?? ''));
        echo $row('GPSR compliant', $toggle('gpsr_compliant', $reg['gpsr_compliant'] ?? 0));
        echo '</div></div>';

        echo $cardOpen('Responsible Person');
        echo '<p class="small text-muted mb-3">EU / UK responsible person for product safety (GPSR).</p>';
        echo $row('Name', $text('rp_name', $reg['rp_name'] ?? ''));
        echo $row('Email', $text('rp_email', $reg['rp_email'] ?? ''));
        echo $row('Phone', $text('rp_phone', $reg['rp_phone'] ?? ''));
        echo $row('Address', $area('rp_address', $reg['rp_address'] ?? '', 2));
        echo $row('Country', $select('rp_country', $countries, $reg['rp_country'] ?? ''));
        echo '</div></div>';

        echo $cardOpen('Company Identifiers');
        echo $row('Company name', $text('company_name', $reg['company_name'] ?? ''));
        echo $row('Company number', $text('company_number', $reg['company_number'] ?? ''));
        echo $row('VAT number', $text('vat_number', $reg['vat_number'] ?? ''));
        echo $row('EORI number', $text('eori_number', $reg['eori_number'] ?? ''));
        echo $row('Manufacturer name', $text('manufacturer_name', $reg['manufacturer_name'] ?? ''));
        echo $row('Manufacturer address', $area('manufacturer_address', $reg['manufacturer_address'] ?? '', 2));
        echo '</div></div>';

        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array{category:\Module\StoreFeeder\Services\Category, depth:int}> $flat
     * @param array<int, int> $assigned
     */
    private function renderCategoryPicker(array $flat, array $assigned): string
    {
        ob_start();
        ?>
        <fieldset class="my-4">
            <legend class="h6">Categories</legend>
            <p class="text-muted small">
                Select which categories this product belongs to.
                <a href="<?= $this->view->e($this->url->to('storefeeder/admin/categories')) ?>">Manage categories</a>
            </p>

            <?php if (empty($flat)): ?>
                <p class="text-muted">No categories defined yet.</p>
            <?php else: ?>
                <div class="row g-1">
                    <?php foreach ($flat as $row):
                        $c       = $row['category'];
                        $indent  = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $row['depth']);
                        $checked = in_array($c->id, $assigned, true);
                    ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="form-check">
                                <input class="form-check-input"
                                       type="checkbox"
                                       name="categories[]"
                                       id="cat-<?= (int) $c->id ?>"
                                       value="<?= (int) $c->id ?>"
                                       <?= $checked ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="cat-<?= (int) $c->id ?>">
                                    <?= $indent ?><?= $this->view->e($c->name) ?>
                                    <code class="text-muted small"><?= $this->view->e($c->slug) ?></code>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<int, \Module\StoreFeeder\Services\Attribute> $attributes
     * @param array<int, int> $selectedIds
     */
    private function renderAttributePicker(array $attributes, array $selectedIds): string
    {
        ob_start();
        ?>
        <div id="product-attr-ids" class="d-none">
            <?php foreach ($attributes as $a):
                if (!in_array($a->id, $selectedIds, true)) {
                    continue;
                } ?>
                <input type="hidden" class="product-attribute" name="product_attributes[]"
                       data-code="<?= $this->view->e($a->code) ?>"
                       value="<?= (int) $a->id ?>">
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param Product|null $product
     * @param array<int, \Module\StoreFeeder\Services\Attribute> $selectedAttributes
     * @param array<int, \Module\StoreFeeder\Services\Attribute> $allAttributes
     */
    /**
     * @param array<int, int> $stockMap variant_id => available qty
     */
    private function renderVariantsSection(
        ?Product $product,
        array $selectedAttributes,
        array $allAttributes = [],
        array $stockMap = [],
    ): string {
        $variantList = [];
        if ($product !== null && $product->id !== null) {
            $variantList = $this->variants->forProduct($product->id);
        }

        if ($variantList === []) {
            $blank = new Variant();
            $blank->sku = $product?->sku ?? '';
            $blank->name = $product?->name ?? '';
            $blank->price = $product?->price ?? 0.0;
            $blank->cost = $product?->cost ?? 0.0;
            $blank->weightGrams = $product?->weightGrams ?? 0;
            $blank->active = true;
            $variantList = [$blank];
        }

        $attrsJson = json_encode(array_map(
            fn($a) => [
                'id'     => (int) $a->id,
                'code'   => (string) $a->code,
                'name'   => (string) $a->name,
                'values' => array_map(
                    fn($v) => [
                        'id'    => (int) $v->id,
                        'value' => (string) $v->value,
                        'label' => $v->labelOrValue(),
                    ],
                    $a->values
                ),
            ],
            $allAttributes
        ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);

        $fmtKg = static function (int $grams): string {
            if ($grams <= 0) {
                return '';
            }
            return rtrim(rtrim(number_format($grams / 1000, 3, '.', ''), '0'), '.');
        };

        ob_start();
        ?>
        <div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <p class="text-muted small mb-0">
                    Easiest: <strong>Fill from SKUs</strong> reads Diameter / Thick / Lenght / 8 x from each row.
                    Or add a column and type <code>15mm</code>, <code>1m</code>. Stock stays on the 1× / 1m Inventory cell.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="fill-attrs-from-sku">
                        Fill from SKUs
                    </button>
                    <button type="button" class="btn btn-sm btn-success" id="add-variant-attr"
                            data-bs-toggle="modal" data-bs-target="#add-variant-attr-modal">
                        <i class="bi bi-plus-lg me-1"></i>New Variant Attribute
                    </button>
                </div>
            </div>
            <div id="variant-attr-meta"
                 data-csrf="<?= $this->view->e($this->request->csrfToken()) ?>"
                 data-attr-url="<?= $this->view->e($this->url->to('storefeeder/admin/attributes/quick')) ?>"></div>
            <input type="hidden" name="variants_submitted" value="1">
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="variants-table">
                    <thead>
                        <tr>
                            <th style="width:12%">SKU</th>
                            <th style="width:14%">Name</th>
                            <?php foreach ($selectedAttributes as $a): ?>
                                <th class="variant-attr-col" data-attr-id="<?= (int) $a->id ?>">
                                    <?= $this->view->e($a->name) ?>
                                    <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-1 remove-attr-col"
                                            data-attr-id="<?= (int) $a->id ?>" title="Remove column">×</button>
                                </th>
                            <?php endforeach; ?>
                            <th style="width:8%">Price (£)</th>
                            <th style="width:8%">Inventory</th>
                            <th style="width:8%">Weight (kg)</th>
                            <th style="width:8%">Pick Bin</th>
                            <th style="width:8%">Cost</th>
                            <th style="width:7%" title="How many singles are in this SKU (1, 8, 16). Not stock. Blank = detect from name.">Units of 1×</th>
                            <th style="width:6%">Active</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($variantList as $i => $v):
                        $qty = ($v->id && isset($stockMap[$v->id])) ? (int) $stockMap[$v->id] : 0;
                        $units = PackInventory::qtyFor([
                            'sku'      => $v->sku,
                            'name'     => $v->name,
                            'pack_qty' => $v->packQty,
                        ]);
                        $isPack = $units > 1;
                        $invUrl = ($v->id && $product?->id)
                            ? $this->url->to('storefeeder/admin/inventory/adjust', ['id' => $v->id])
                            : '';
                        ?>
                        <tr class="variant-row">
                            <td>
                                <?php if ($v->id): ?>
                                    <input type="hidden" name="variants[<?= $i ?>][id]" value="<?= (int) $v->id ?>">
                                <?php endif; ?>
                                <input type="text" class="form-control form-control-sm"
                                       name="variants[<?= $i ?>][sku]"
                                       value="<?= $this->view->e($v->sku) ?>">
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       name="variants[<?= $i ?>][name]"
                                       value="<?= $this->view->e($v->name !== '' ? $v->name : $v->sku) ?>">
                            </td>

                            <?php foreach ($selectedAttributes as $a):
                                $ass = $v->attributes[$a->id] ?? null;
                                $cur = '';
                                if (is_array($ass)) {
                                    $cur = (string) (($ass['label'] ?? '') !== '' ? $ass['label'] : ($ass['value'] ?? ''));
                                }
                                ?>
                                <td class="variant-attr-col" data-attr-id="<?= (int) $a->id ?>">
                                    <input type="text" class="form-control form-control-sm"
                                           list="attr-dl-<?= (int) $a->id ?>"
                                           name="variants[<?= $i ?>][attr_text][<?= (int) $a->id ?>]"
                                           value="<?= $this->view->e($cur) ?>"
                                           placeholder="<?= $this->view->e($a->name) ?>">
                                </td>
                            <?php endforeach; ?>

                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       name="variants[<?= $i ?>][price]"
                                       value="<?= $this->view->e((string) $v->price) ?>">
                            </td>
                            <td class="text-nowrap">
                                <?php if ($isPack): ?>
                                    <?php if ($invUrl !== ''): ?>
                                        <a href="<?= $this->view->e($invUrl) ?>" class="fw-semibold" title="Derived from the 1× SKU"><?= $qty ?></a>
                                    <?php else: ?>
                                        <span class="text-muted" title="Derived from the 1× SKU"><?= $qty ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <input type="number" min="0" class="form-control form-control-sm"
                                           name="variants[<?= $i ?>][quantity]"
                                           value="<?= $qty ?>"
                                           title="On-hand stock for this 1× / 1m SKU">
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       name="variants[<?= $i ?>][weight_kg]"
                                       value="<?= $this->view->e($fmtKg($v->weightGrams)) ?>">
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       name="variants[<?= $i ?>][pick_bin]"
                                       value="<?= $this->view->e($v->pickBin) ?>">
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       name="variants[<?= $i ?>][cost]"
                                       value="<?= $this->view->e((string) $v->cost) ?>">
                            </td>
                            <td>
                                <input type="number" min="0" max="20000" class="form-control form-control-sm"
                                       name="variants[<?= $i ?>][pack_qty]"
                                       value="<?= $v->packQty > 0 ? (int) $v->packQty : '' ?>"
                                       placeholder="auto"
                                       title="Pack size only (1, 8, 16). Leave auto if the name is 8 x …">
                            </td>
                            <td class="text-center">
                                <input type="hidden" name="variants[<?= $i ?>][active]" value="0">
                                <input type="checkbox" class="form-check-input"
                                       name="variants[<?= $i ?>][active]" value="1"
                                       <?= $v->active ? 'checked' : '' ?>>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger remove-variant">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php foreach ($selectedAttributes as $a): ?>
                <datalist id="attr-dl-<?= (int) $a->id ?>">
                    <?php foreach ($a->values as $av): ?>
                        <option value="<?= $this->view->e($av->labelOrValue()) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            <?php endforeach; ?>

            <div class="d-flex flex-wrap gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-success" id="add-variant">
                    <i class="bi bi-plus-lg me-1"></i>Add New Variant
                </button>
            </div>

            <div class="modal fade" id="add-variant-attr-modal" tabindex="-1" aria-labelledby="add-variant-attr-title">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h5 class="modal-title" id="add-variant-attr-title">Add New Variant Attribute</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted mb-2">Pick a common one, or type a new name. Then type the default (15mm, 1m, 8 ×) — no value list needed.</p>
                            <div id="new-attr-presets" class="d-flex flex-wrap gap-1 mb-3"></div>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="new-attr-id">Existing attribute</label>
                                <select id="new-attr-id" class="form-select form-select-sm" aria-label="Attribute">
                                    <option value="">Please Select...</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="new-attr-name">Or new name</label>
                                <input type="text" id="new-attr-name" class="form-control form-control-sm"
                                       placeholder="e.g. Pipe Diameter">
                            </div>
                            <div>
                                <label class="form-label small mb-1" for="new-attr-default">Default value (optional)</label>
                                <input type="text" id="new-attr-default" class="form-control form-control-sm"
                                       placeholder="e.g. 15mm or 1m">
                            </div>
                        </div>
                        <div class="modal-footer py-2">
                            <button type="button" class="btn btn-link text-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="new-attr-add">Add</button>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            (function () {
                const tableEl = document.getElementById('variants-table');
                if (!tableEl) return;
                const theadRow = tableEl.querySelector('thead tr');
                const table = tableEl.querySelector('tbody');
                const btn = document.getElementById('add-variant');
                const addAttrBtn = document.getElementById('add-variant-attr');
                const meta = document.getElementById('variant-attr-meta');
                let allAttrs = <?= $attrsJson ?: '[]' ?>;
                const csrf = meta ? meta.getAttribute('data-csrf') : '';
                const attrUrl = meta ? meta.getAttribute('data-attr-url') : '';

                function esc(s) {
                    return String(s).replace(/[&<>"']/g, function (c) {
                        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                    });
                }

                function selectedAttrIds() {
                    return Array.from(document.querySelectorAll('#product-attr-ids input[name="product_attributes[]"]'))
                        .map(function (el) { return parseInt(el.value, 10); })
                        .filter(function (id) { return id > 0; });
                }

                function selectedAttrs() {
                    const ids = selectedAttrIds();
                    return allAttrs.filter(function (a) { return ids.indexOf(a.id) !== -1; });
                }

                function datalistHtml(attr) {
                    let opts = '';
                    (attr.values || []).forEach(function (v) {
                        opts += '<option value="' + esc(v.label || v.value) + '"></option>';
                    });
                    return '<datalist id="attr-dl-' + attr.id + '">' + opts + '</datalist>';
                }

                function ensureDatalist(attr) {
                    let el = document.getElementById('attr-dl-' + attr.id);
                    if (!el) {
                        tableEl.insertAdjacentHTML('afterend', datalistHtml(attr));
                        return;
                    }
                    el.outerHTML = datalistHtml(attr);
                }

                function attrCellHtml(attr, idx, value) {
                    return '<td class="variant-attr-col" data-attr-id="' + attr.id + '">'
                        + '<input type="text" class="form-control form-control-sm" list="attr-dl-' + attr.id + '"'
                        + ' name="variants[' + idx + '][attr_text][' + attr.id + ']"'
                        + ' value="' + esc(value || '') + '" placeholder="' + esc(attr.name) + '">'
                        + '</td>';
                }

                function attrHeadHtml(attr) {
                    const th = document.createElement('th');
                    th.className = 'variant-attr-col';
                    th.setAttribute('data-attr-id', String(attr.id));
                    th.innerHTML = esc(attr.name)
                        + ' <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-1 remove-attr-col" data-attr-id="'
                        + attr.id + '" title="Remove column">×</button>';
                    return th;
                }

                function reindex() {
                    table.querySelectorAll('.variant-row').forEach(function (row, i) {
                        row.querySelectorAll('input, select').forEach(function (el) {
                            el.name = el.name.replace(/variants\[\d+\]/, 'variants[' + i + ']');
                        });
                    });
                }

                function productPrice() {
                    const el = document.getElementById('price');
                    return el && el.value !== '' ? String(el.value) : '0';
                }

                function addRow(sku, name, attrText) {
                    const idx = table.querySelectorAll('.variant-row').length;
                    const attrs = selectedAttrs();
                    let attrsHtml = '';
                    attrs.forEach(function (a) {
                        attrsHtml += attrCellHtml(a, idx, (attrText && attrText[a.id]) ? attrText[a.id] : '');
                    });
                    const row = document.createElement('tr');
                    row.className = 'variant-row';
                    row.innerHTML =
                        '<td><input type="text" class="form-control form-control-sm" name="variants[' + idx + '][sku]" value="' + esc(sku || '') + '"></td>'
                        + '<td><input type="text" class="form-control form-control-sm" name="variants[' + idx + '][name]" value="' + esc(name || '') + '"></td>'
                        + attrsHtml
                        + '<td><input type="text" class="form-control form-control-sm" name="variants[' + idx + '][price]" value="' + esc(productPrice()) + '"></td>'
                        + '<td><input type="number" min="0" class="form-control form-control-sm" name="variants[' + idx + '][quantity]" value="0"></td>'
                        + '<td><input type="text" class="form-control form-control-sm" name="variants[' + idx + '][weight_kg]" value=""></td>'
                        + '<td><input type="text" class="form-control form-control-sm" name="variants[' + idx + '][pick_bin]" value=""></td>'
                        + '<td><input type="text" class="form-control form-control-sm" name="variants[' + idx + '][cost]" value="0"></td>'
                        + '<td><input type="number" min="0" max="20000" class="form-control form-control-sm" name="variants[' + idx + '][pack_qty]" value="" placeholder="auto"></td>'
                        + '<td class="text-center">'
                        +   '<input type="hidden" name="variants[' + idx + '][active]" value="0">'
                        +   '<input type="checkbox" class="form-check-input" name="variants[' + idx + '][active]" value="1" checked>'
                        + '</td>'
                        + '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-variant"><i class="bi bi-x-lg"></i></button></td>';
                    table.appendChild(row);
                    return row;
                }

                function guessFromText(text) {
                    const out = {};
                    let m;
                    const hasDiameter = /\bdiameter\s+/i.test(text);
                    if ((m = text.match(/\bdiameter\s+(\d+(?:\.\d+)?)\s*m{1,2}\b/i))) {
                        out.pipe_diameter = m[1] + 'mm';
                    }
                    if ((m = text.match(/\bthick(?:ness)?\s+(\d+(?:\.\d+)?)\s*m{1,2}\b/i))) {
                        if (hasDiameter) out.insulation_thickness = m[1] + 'mm';
                        else out.thickness = m[1] + 'mm';
                    } else if ((m = text.match(/^(\d+(?:\.\d+)?mm)\b/i)) && /\(/.test(text)) {
                        out.thickness = m[1];
                    }
                    if ((m = text.match(/\bleng(?:th|ht)\s+(\d+(?:\.\d+)?)\s*m\b/i))) {
                        out.metres = m[1] + 'm';
                    } else if ((m = text.match(/\bmetres?\s*[:\-]?\s*(\d+(?:\.\d+)?)\s*m\b/i))) {
                        out.metres = m[1] + 'm';
                    }
                    if ((m = text.match(/(\d+(?:\.\d+)?)\s*m\s*[xX×]\s*(\d+(?:\.\d+)?)\s*m/i))) {
                        out.size = m[1] + 'm x ' + m[2] + 'm';
                    }
                    if ((m = text.match(/(?:^|[^0-9.])(\d+)\s*[xX×](?:\s+|$)/))) {
                        out.pack_size = m[1] + ' ×';
                    } else if ((m = text.match(/\b(?:pack|box|case|set)\s+of\s+(\d+)\b/i))) {
                        out.pack_size = m[1] + ' ×';
                    }
                    return out;
                }

                function applyGuessToRow(row) {
                    if (!row) return;
                    const skuEl = row.querySelector('input[name*="[sku]"]');
                    const nameEl = row.querySelector('input[name*="[name]"]');
                    const g = guessFromText(((skuEl && skuEl.value) || '') + ' ' + ((nameEl && nameEl.value) || ''));
                    selectedAttrs().forEach(function (attr) {
                        const wanted = g[attr.code];
                        if (!wanted) return;
                        const input = row.querySelector('td.variant-attr-col[data-attr-id="' + attr.id + '"] input');
                        if (input && !String(input.value || '').trim()) input.value = wanted;
                    });
                }

                function attachAttr(attr, defaultValue) {
                    defaultValue = defaultValue || '';
                    if (!allAttrs.some(function (a) { return a.id === attr.id; })) {
                        allAttrs.push(attr);
                    }
                    const box = document.getElementById('product-attr-ids');
                    if (box && !box.querySelector('input[value="' + attr.id + '"]')) {
                        const inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.className = 'product-attribute';
                        inp.name = 'product_attributes[]';
                        inp.value = String(attr.id);
                        inp.setAttribute('data-code', attr.code || '');
                        box.appendChild(inp);
                    }
                    ensureDatalist(attr);
                    if (!theadRow.querySelector('th.variant-attr-col[data-attr-id="' + attr.id + '"]')) {
                        let insertAfter = theadRow.children[1];
                        theadRow.querySelectorAll('th.variant-attr-col').forEach(function (th) { insertAfter = th; });
                        insertAfter.after(attrHeadHtml(attr));
                    }
                    table.querySelectorAll('.variant-row').forEach(function (row, idx) {
                        let cell = row.querySelector('td.variant-attr-col[data-attr-id="' + attr.id + '"]');
                        if (!cell) {
                            const nameTd = row.children[1];
                            let anchor = nameTd;
                            row.querySelectorAll('td.variant-attr-col').forEach(function (td) { anchor = td; });
                            const wrap = document.createElement('tbody');
                            wrap.innerHTML = attrCellHtml(attr, idx, defaultValue);
                            anchor.after(wrap.firstElementChild);
                            cell = row.querySelector('td.variant-attr-col[data-attr-id="' + attr.id + '"]');
                        } else if (defaultValue) {
                            const input = cell.querySelector('input');
                            if (input && !String(input.value || '').trim()) input.value = defaultValue;
                        }
                        if (!defaultValue) applyGuessToRow(row);
                    });
                    reindex();
                }

                function detachAttr(attrId) {
                    document.querySelectorAll('.variant-attr-col[data-attr-id="' + attrId + '"]').forEach(function (el) {
                        el.remove();
                    });
                    const box = document.getElementById('product-attr-ids');
                    if (box) {
                        box.querySelectorAll('input[value="' + attrId + '"]').forEach(function (el) { el.remove(); });
                    }
                }

                btn.addEventListener('click', function () { addRow('', '', {}); });

                const attrSel = document.getElementById('new-attr-id');
                const attrName = document.getElementById('new-attr-name');
                const defSel = document.getElementById('new-attr-default');
                const addConfirm = document.getElementById('new-attr-add');
                const attrModalEl = document.getElementById('add-variant-attr-modal');
                const presetBox = document.getElementById('new-attr-presets');
                const fillSkuBtn = document.getElementById('fill-attrs-from-sku');
                const commonAttrs = [
                    { code: 'pack_size', name: 'Pack Size' },
                    { code: 'pipe_diameter', name: 'Pipe Diameter' },
                    { code: 'insulation_thickness', name: 'Insulation Thickness' },
                    { code: 'metres', name: 'Metres' },
                    { code: 'thickness', name: 'Thickness' },
                    { code: 'size', name: 'Size' }
                ];

                async function ensureAttrByName(name) {
                    const existing = allAttrs.find(function (a) {
                        return String(a.name).toLowerCase() === String(name).toLowerCase()
                            || String(a.code).toLowerCase() === String(name).toLowerCase().replace(/\s+/g, '_');
                    });
                    if (existing) return existing;
                    if (!attrUrl) return null;
                    const fd = new FormData();
                    fd.append('_csrf_token', csrf);
                    fd.append('name', name);
                    const res = await fetch(attrUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await res.json();
                    if (!data.ok) {
                        alert(data.error || 'Could not add attribute');
                        return null;
                    }
                    const attr = { id: data.id, code: data.code, name: data.name, values: data.values || [] };
                    if (!allAttrs.some(function (a) { return a.id === attr.id; })) {
                        allAttrs.push(attr);
                    }
                    return attr;
                }

                function fillAttrSelect() {
                    if (!attrSel) return;
                    const used = selectedAttrIds();
                    attrSel.innerHTML = '<option value="">Please Select...</option>';
                    allAttrs.slice().sort(function (a, b) {
                        return String(a.name).localeCompare(String(b.name));
                    }).forEach(function (a) {
                        if (used.indexOf(a.id) !== -1) return;
                        const opt = document.createElement('option');
                        opt.value = String(a.id);
                        opt.textContent = a.name;
                        attrSel.appendChild(opt);
                    });
                    if (defSel) defSel.value = '';
                    if (attrName) attrName.value = '';
                    if (presetBox) {
                        presetBox.innerHTML = '';
                        commonAttrs.forEach(function (c) {
                            const found = allAttrs.find(function (a) { return a.code === c.code; });
                            if (found && used.indexOf(found.id) !== -1) return;
                            const b = document.createElement('button');
                            b.type = 'button';
                            b.className = 'btn btn-sm btn-outline-secondary';
                            b.textContent = c.name;
                            b.addEventListener('click', async function () {
                                const attr = found || await ensureAttrByName(c.name);
                                if (attr) {
                                    attachAttr(attr);
                                    closeAttrModal();
                                }
                            });
                            presetBox.appendChild(b);
                        });
                    }
                }

                function closeAttrModal() {
                    if (window.bootstrap && attrModalEl) {
                        const modal = window.bootstrap.Modal.getInstance(attrModalEl)
                            || new window.bootstrap.Modal(attrModalEl);
                        modal.hide();
                    }
                }

                if (attrModalEl) {
                    attrModalEl.addEventListener('show.bs.modal', fillAttrSelect);
                }
                if (addConfirm) {
                    addConfirm.addEventListener('click', async function () {
                        const def = defSel ? String(defSel.value || '').trim() : '';
                        let attr = allAttrs.find(function (a) { return String(a.id) === String(attrSel && attrSel.value); });
                        if (!attr && attrName && attrName.value.trim()) {
                            attr = await ensureAttrByName(attrName.value.trim());
                        }
                        if (!attr) {
                            alert('Pick a suggested attribute, choose one from the list, or type a new name.');
                            return;
                        }
                        attachAttr(attr, def);
                        closeAttrModal();
                    });
                }
                if (fillSkuBtn) {
                    fillSkuBtn.addEventListener('click', async function () {
                        const needed = {};
                        table.querySelectorAll('.variant-row').forEach(function (row) {
                            const skuEl = row.querySelector('input[name*="[sku]"]');
                            const nameEl = row.querySelector('input[name*="[name]"]');
                            const g = guessFromText(((skuEl && skuEl.value) || '') + ' ' + ((nameEl && nameEl.value) || ''));
                            Object.keys(g).forEach(function (code) { needed[code] = true; });
                        });
                        const codes = Object.keys(needed);
                        if (codes.length === 0) {
                            alert('No Diameter / Thick / Lenght / 8 x / 1m x pattern found on the SKUs.');
                            return;
                        }
                        const oldLabel = fillSkuBtn.textContent;
                        fillSkuBtn.disabled = true;
                        fillSkuBtn.textContent = 'Reading SKUs…';
                        try {
                            for (let i = 0; i < commonAttrs.length; i++) {
                                const c = commonAttrs[i];
                                if (!needed[c.code]) continue;
                                const attr = await ensureAttrByName(c.name);
                                if (attr) attachAttr(attr);
                            }
                            table.querySelectorAll('.variant-row').forEach(applyGuessToRow);
                            fillSkuBtn.textContent = 'Filled';
                            setTimeout(function () {
                                fillSkuBtn.textContent = oldLabel;
                                fillSkuBtn.disabled = false;
                            }, 1400);
                        } catch (err) {
                            fillSkuBtn.textContent = oldLabel;
                            fillSkuBtn.disabled = false;
                            alert((err && err.message) || 'Could not fill attributes');
                        }
                    });
                }

                tableEl.addEventListener('click', function (e) {
                    const rmCol = e.target.closest('.remove-attr-col');
                    if (rmCol) {
                        e.preventDefault();
                        detachAttr(rmCol.getAttribute('data-attr-id'));
                    }
                });

                table.addEventListener('click', function (e) {
                    const rm = e.target.closest('.remove-variant');
                    if (!rm) return;
                    const rows = table.querySelectorAll('.variant-row');
                    if (rows.length <= 1) return;
                    rm.closest('tr').remove();
                    reindex();
                });

                table.addEventListener('focusout', function (e) {
                    const t = e.target;
                    if (!t || !t.name) return;
                    if (/\[sku\]$/.test(t.name) || /\[name\]$/.test(t.name)) {
                        applyGuessToRow(t.closest('tr'));
                    }
                });
            })();
            </script>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<int, \Module\StoreFeeder\Services\Attribute> $all
     * @param array<int, int> $ids
     * @return array<int, \Module\StoreFeeder\Services\Attribute>
     */
    private function attributesById(array $all, array $ids): array
    {
        $ids = array_map('intval', $ids);
        $out = [];
        foreach ($all as $a) {
            if ($a->id !== null && in_array($a->id, $ids, true)) {
                $out[] = $a;
            }
        }
        return $out;
    }
}