<?php
declare(strict_types=1);

use Module\Store\Services\ChannelClientFactory;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\CourierShipmentService;
use Module\Store\Services\CredentialsVault;
use Module\Store\Services\OrderDespatchService;
use Module\Store\Services\OrderRepository;
use Module\Store\Services\BrandRepository;
use Module\Store\Services\PickwaveRepository;
use Module\Store\Services\PickwaveService;
use Module\Store\Services\ShipmentRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreAdminPickwaves extends Controller
{
    private PickwaveRepository   $pickwaves;
    private PickwaveService      $service;
    private OrderRepository      $orders;
    private BrandRepository      $brandsRepo;
    private ChannelRepository    $channels;
    private ChannelClientFactory $factory;
    private ShipmentRepository   $shipments;
    private CourierShipmentService $courier;
    private bool                 $courierReady = false;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $vault = CredentialsVault::forTenant(
            (string) $this->config->get('store.credentials_key', '')
        );
        $this->channels  = new ChannelRepository($this->db);
        $this->factory   = new ChannelClientFactory($vault, $this->channels);
        $despatch        = new OrderDespatchService($this->db, $this->channels, $this->factory);
        $this->shipments = new ShipmentRepository($this->db);
        $this->courier   = new CourierShipmentService(
            $this->channels,
            $this->factory,
            $this->shipments,
            BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store',
        );
        $this->courierReady = $this->courier->findDefaultCourierChannel() !== null;

        $this->pickwaves  = new PickwaveRepository($this->db);
        $this->service    = new PickwaveService($this->pickwaves, $despatch, $this->courier);
        $this->orders     = new OrderRepository($this->db);
        $this->brandsRepo = new BrandRepository($this->db);
        // Permissions: Action.php only.

        $helper = get_module_path('store') . '/helpers/functions.php';
        if (is_string($helper) && is_file($helper)) {
            require_once $helper;
        }
    }

    // =========================================================================
    // Index — Store-style: Stats + Pickwaves + Outstanding
    // =========================================================================

    public function indexAction(): void
    {
        $tab = (string) $this->request->get('tab', 'string', 'overview');
        if (!in_array($tab, ['overview', 'open', 'completed'], true)) {
            $tab = 'overview';
        }

        $waveTab = (string) $this->request->get('wave_tab', 'string', 'open');
        if (!in_array($waveTab, ['open', 'completed'], true)) {
            $waveTab = 'open';
        }

        $channels = array_values(array_filter(
            $this->channels->all(),
            static fn($c) => $c->enabled
        ));

        $stats     = $this->pickwaves->stats();
        $openWaves = $this->pickwaves->listByStatus('open', 50);
        $doneWaves = $this->pickwaves->listByStatus('completed', 50);

        $datasetHtml = '';
        $outstandingTotal = (int) ($stats['outstanding'] ?? 0);

        if ($tab === 'overview') {
            $channelOptions = ['0' => 'All channels'];
            foreach ($channels as $c) {
                $channelOptions[(string) (int) $c->id] = $c->label !== '' ? $c->label : $c->name;
            }

            $rawC = $this->request->query('c', 'raw', []);
            $skuFromCol = is_array($rawC) ? trim((string) ($rawC['product_skus'] ?? '')) : '';
            $brandOptions = $this->mergeBrandOptions(
                $this->pickwaves->outstandingBrandOptions([
                    'channel_id' => (int) $this->request->get('channel_id', 'int', 0),
                    'q'          => trim((string) $this->request->get('q', 'string', '')),
                    'sku'        => $skuFromCol !== ''
                        ? $skuFromCol
                        : trim((string) $this->request->get('sku', 'string', '')),
                ])
            );
            $brandSelect = ['' => 'All brands'];
            foreach ($brandOptions as $opt) {
                $b = (string) ($opt['brand'] ?? '');
                if ($b !== '') {
                    $brandSelect[$b] = $b . ' (' . (int) ($opt['order_count'] ?? 0) . ')';
                }
            }

            $productUrl = fn(int $id): string => $this->url->to('store/admin/products/edit', ['id' => $id]);

            $dataset = (new Dataset($this->registry))
                ->perPage(50)
                ->searchPlaceholder('Search order, SKU, title, buyer…')
                ->preserve(['tab' => 'overview', 'wave_tab' => $waveTab])
                ->sortable([
                    'channel', 'order_number', 'channel_ref', 'order_date', 'ship_by',
                    'product_skus', 'brand', 'total_units', 'multiple_lines',
                    'buyer', 'postcode', 'total', 'shipping_method', 'status',
                ])
                ->defaultSort('order_date', 'asc')
                ->filter('channel_id', 'select', $channelOptions)
                ->filter('brand', 'select', $brandSelect)
                ->searchable(['product_skus'])
                ->columns([
                    'sel'             => '',
                    'channel'         => 'Channel',
                    'order_number'    => 'Order number',
                    'channel_ref'     => 'Channel order ref',
                    'order_date'      => 'Order date',
                    'ship_by'         => 'Ship by date',
                    'product_skus'    => 'Product SKUs',
                    'brand'           => 'Brand',
                    'channel_name'    => 'Channel product name',
                    'total_units'     => 'Total units',
                    'multiple_lines'  => 'Multiple lines',
                    'packages'        => 'Packages',
                    'weight'          => 'Order weight',
                    'buyer'           => 'Buyer',
                    'postcode'        => 'Postcode',
                    'total'           => 'Total',
                    'shipping_method' => 'Shipping method',
                    'status'          => 'Status',
                ])
                ->query(function ($db, array $state): array {
                    $filters = $this->datasetFiltersToPickwaveFilters($state);
                    [, $sort, $dir] = $this->pickwaves->outstandingOrderBy(
                        (string) ($state['sort'] ?? 'order_date'),
                        (string) ($state['dir'] ?? 'asc')
                    );
                    $rows = $this->pickwaves->outstandingOrders(
                        $state['per_page'],
                        $state['offset'],
                        $filters,
                        $sort,
                        $dir
                    );
                    $total = $this->pickwaves->countOutstanding($filters);
                    $itemsBy = $this->orders->itemsByOrderIds(array_column($rows, 'id'));
                    $out = [];
                    foreach ($rows as $row) {
                        $oid = (int) $row['id'];
                        $out[] = [
                            '_row'   => $row,
                            '_items' => $itemsBy[$oid] ?? [],
                        ];
                    }
                    return ['rows' => $out, 'total' => $total];
                });

            $dataset->columns([
                'sel' => static function (array $row): string {
                    $oid = (int) ($row['_row']['id'] ?? 0);
                    return '<input type="checkbox" class="form-check-input pw-order" name="order_ids[]" value="'
                        . $oid . '" form="pw-create-form">';
                },
                'channel' => function (array $row): string {
                    $r = $row['_row'];
                    return $this->view->e((string) ($r['channel_label'] ?? $r['channel_name'] ?? ''));
                },
                'order_number' => function (array $row): string {
                    $r = $row['_row'];
                    $oid = (int) $r['id'];
                    $extId = (string) ($r['external_order_id'] ?? '');
                    return '<a href="' . $this->view->e($this->url->to('store/admin/orders/view', ['id' => $oid])) . '">'
                        . $this->view->e($extId) . '</a>';
                },
                'channel_ref' => function (array $row): string {
                    $extId = (string) ($row['_row']['external_order_id'] ?? '');
                    return '<code class="small">' . $this->view->e($extId) . '</code>';
                },
                'order_date' => function (array $row): string {
                    return $this->view->e($this->formatWhen((string) ($row['_row']['created_at'] ?? '')));
                },
                'ship_by' => function (array $row): string {
                    $shipBy = $this->extractShipBy($row['_row']['shipping_address'] ?? null);
                    return $shipBy !== ''
                        ? '<span class="small text-nowrap">' . $this->view->e($shipBy) . '</span>'
                        : '<span class="text-muted">—</span>';
                },
                'product_skus' => function (array $row) use ($productUrl): string {
                    return store_render_short_skus($row['_items'] ?? [], $productUrl);
                },
                'brand' => function (array $row): string {
                    $brandSet = [];
                    foreach ($row['_items'] ?? [] as $it) {
                        $b = trim((string) ($it['brand'] ?? ''));
                        if ($b !== '') {
                            $brandSet[$b] = true;
                        }
                    }
                    $brandList = array_keys($brandSet);
                    sort($brandList, SORT_STRING | SORT_FLAG_CASE);
                    return $brandList !== []
                        ? '<span class="small">' . $this->view->e(implode(', ', $brandList)) . '</span>'
                        : '<span class="text-muted">—</span>';
                },
                'channel_name' => function (array $row) use ($productUrl): string {
                    return '<span class="small">' . store_render_item_titles($row['_items'] ?? [], $productUrl) . '</span>';
                },
                'total_units' => static function (array $row): string {
                    $units = 0;
                    foreach ($row['_items'] ?? [] as $it) {
                        $units += max(1, (int) ($it['qty'] ?? 1));
                    }
                    return (string) $units;
                },
                'multiple_lines' => static function (array $row): string {
                    $lineCount = count($row['_items'] ?? []);
                    return $lineCount > 1
                        ? '<span class="badge text-bg-info">' . $lineCount . '</span>'
                        : '<span class="text-muted">1</span>';
                },
                'packages' => static function (array $row): string {
                    return (string) max(1, (int) ($row['_row']['package_count'] ?? 1));
                },
                'weight' => static function (array $row): string {
                    return number_format((float) ($row['_row']['order_weight_kg'] ?? 0), 3);
                },
                'buyer' => function (array $row): string {
                    $r = $row['_row'];
                    return '<span class="sf-pick-clip">' . $this->view->e((string) ($r['buyer_username'] ?? '')) . '</span>'
                        . '<span class="sf-pick-sub">' . $this->view->e((string) ($r['shipping_name'] ?? '')) . '</span>';
                },
                'postcode' => function (array $row): string {
                    $postcode = $this->extractPostcode($row['_row']['shipping_address'] ?? null);
                    return $this->view->e($postcode !== '' ? $postcode : '—');
                },
                'total' => function (array $row): string {
                    $r = $row['_row'];
                    return number_format((float) ($r['total'] ?? 0), 2) . ' '
                        . $this->view->e((string) ($r['currency'] ?? ''));
                },
                'shipping_method' => function (array $row): string {
                    $method = trim((string) ($row['_row']['shipping_method'] ?? ''));
                    return $method !== ''
                        ? '<span class="small">' . $this->view->e($method) . '</span>'
                        : '<span class="text-muted">—</span>';
                },
                'status' => function (array $row): string {
                    return '<span class="sf-pick-pill">' . $this->view->e((string) ($row['_row']['status'] ?? '')) . '</span>';
                },
            ]);

            // Count with current filters for the card header.
            $outstandingTotal = $this->pickwaves->countOutstanding(
                $this->datasetFiltersToPickwaveFilters([
                    'q'       => trim((string) $this->request->get('q', 'string', '')),
                    'filters' => [
                        'channel_id' => (string) $this->request->get('channel_id', 'string', '0'),
                        'brand'      => trim((string) $this->request->get('brand', 'string', '')),
                    ],
                    'columns' => is_array($this->request->query('c', 'raw', []))
                        ? $this->request->query('c', 'raw', [])
                        : [],
                ])
            );

            $datasetHtml = $dataset->render();
        }

        echo $this->view->inline(function () use (
            $tab, $waveTab, $openWaves, $doneWaves, $stats, $datasetHtml, $outstandingTotal
        ) {
            $this->view->assign('title', 'Picking');
            $this->view->block('right_sidebar', 'Picking');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo $this->pickStyles();
            echo '<style>.sf-sku-link{color:#111827;text-decoration:none}.sf-sku-link:hover{color:#2563eb;text-decoration:underline}</style>';
            echo '<div class="sf-pick">';
            echo '<div class="sf-pick-head"><h1>Picking</h1></div>';
            echo '<nav class="sf-pick-tabs">';
            foreach ([
                'overview'  => 'Overview',
                'open'      => 'Open pickwaves',
                'completed' => 'Completed pickwaves',
            ] as $key => $label) {
                $on = $tab === $key ? ' is-on' : '';
                echo '<a class="' . $on . '" href="'
                   . $e($this->url->to('store/admin/pickwaves', ['tab' => $key]))
                   . '">' . $e($label) . '</a>';
            }
            echo '</nav>';

            if ($tab === 'open') {
                $this->renderWavePanel($openWaves, true, 'open');
                echo '</div>';
                return;
            }
            if ($tab === 'completed') {
                $this->renderWavePanel($doneWaves, false, 'completed');
                echo '</div>';
                return;
            }

            echo '<div class="sf-pick-top">';
            $this->renderStatsPanel($stats);
            $waves = $waveTab === 'completed' ? $doneWaves : $openWaves;
            $this->renderWavePanel($waves, $waveTab === 'open', $waveTab, true);
            echo '</div>';

            echo '<section class="sf-pick-card">';
            echo '<div class="sf-pick-card-h"><h2>Outstanding orders</h2>'
               . '<span>' . number_format($outstandingTotal) . ' ready to pick</span></div>';
            $this->renderOutstandingTab($datasetHtml);
            echo '</section></div>';
        });
    }

    /**
     * @param array<string, mixed> $state
     * @return array{channel_id: int, q: string, sku: string, brands: array<int, string>}
     */
    private function datasetFiltersToPickwaveFilters(array $state): array
    {
        $f = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $cols = is_array($state['columns'] ?? null) ? $state['columns'] : [];
        $brand = trim((string) ($f['brand'] ?? ''));
        $sku = trim((string) ($cols['product_skus'] ?? ''));
        if ($sku === '') {
            $sku = trim((string) $this->request->get('sku', 'string', ''));
        }

        return [
            'channel_id' => (int) ($f['channel_id'] ?? 0),
            'q'          => trim((string) ($state['q'] ?? '')),
            'sku'        => $sku,
            'brands'     => $brand !== '' ? [$brand] : [],
        ];
    }

    // =========================================================================
    // Create
    // =========================================================================

    public function createAction(): void
    {
        $returnTo = trim((string) $this->request->post('return_to', 'string', ''));
        $failRoute = $returnTo === 'orders'
            ? 'store/admin/orders'
            : 'store/admin/pickwaves';
        $failParams = $returnTo === 'orders' ? [] : ['tab' => 'overview'];

        if (!$this->request->isPost()) {
            redirect_to($failRoute, $failParams);
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to($failRoute, $failParams);
            return;
        }

        $rawIds = $this->request->post('order_ids', 'raw', []);
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $orderIds = array_map('intval', $rawIds);
        $picker   = trim((string) $this->request->post('picker_name', 'string', ''));
        $notes    = trim((string) $this->request->post('notes', 'string', ''));

        try {
            $waveId = $this->pickwaves->create(
                $orderIds,
                $picker,
                $notes,
                $this->auth->id()
            );
            $this->notify->success('Pickwave #' . $waveId . ' created.');
            redirect_to('store/admin/pickwaves/view', ['id' => $waveId]);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
            redirect_to($failRoute, $failParams);
        }
    }

    // =========================================================================
    // View
    // =========================================================================

    public function viewAction(): void
    {
        $id   = (int) $this->request->get('id', 'int', 0);
        $data = $this->pickwaves->find($id);
        if ($data === null) {
            throw new NotFoundException('Pickwave not found.');
        }

        $wave      = $data['wave'];
        $orders    = $data['orders'];
        $flatLines = $this->service->flatPickLines($orders);
        $consol    = $this->service->consolidatePickingLines($orders);
        $isOpen    = strtolower((string) ($wave['status'] ?? '')) === 'open';
        $groupByOrders = (string) $this->request->get('group', 'string', '0') === '1';

        $fulfilled = 0;
        foreach ($orders as $entry) {
            if (strtoupper((string) ($entry['order']['status'] ?? '')) === 'FULFILLED') {
                $fulfilled++;
            }
        }
        $outstanding = max(0, count($orders) - $fulfilled);

        echo $this->view->inline(function () use (
            $wave, $orders, $flatLines, $consol, $isOpen, $groupByOrders, $outstanding, $fulfilled
        ) {
            $this->view->assign('title', 'Edit Pickwave ' . $wave['id']);
            $this->view->block('right_sidebar', 'Pickwaves');

            $wid = (int) $wave['id'];
            $created = (string) ($wave['created_at'] ?? '');
            $createdShort = $created !== '' ? date('d/m/Y', strtotime($created) ?: time()) : '—';

            echo '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">';
            echo '<h1 class="h3 mb-0">Edit Pickwave ' . $wid . '</h1>';
            echo '<div class="d-flex flex-wrap gap-2">';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $this->view->e($this->url->to('store/admin/pickwaves', ['tab' => 'overview']))
               . '">Back</a>';
            echo '<a class="btn btn-outline-dark btn-sm" target="_blank" href="'
               . $this->view->e($this->url->to('store/admin/pickwaves/picking-list', ['id' => $wid]))
               . '">Picking list</a>';
            echo '<a class="btn btn-outline-dark btn-sm" target="_blank" href="'
               . $this->view->e($this->url->to('store/admin/pickwaves/packing-list', ['id' => $wid]))
               . '">Packing list</a>';
            echo '<a class="btn btn-outline-dark btn-sm" target="_blank" href="'
               . $this->view->e($this->url->to('store/admin/pickwaves/labels', ['id' => $wid]))
               . '"><i class="bi bi-printer me-1"></i>Print Despatched Labels</a>';
            if ($isOpen) {
                echo '<form method="post" action="'
                   . $this->view->e($this->url->to('store/admin/pickwaves/despatch', ['id' => $wid]))
                   . '" class="d-inline-flex flex-wrap align-items-center gap-2"'
                   . ' onsubmit="return confirm(\'Despatch this pickwave and mark all orders as fulfilled?\');">';
                echo $this->request->csrfField();
                if ($this->courierReady) {
                    echo '<div class="form-check m-0">'
                       . '<input class="form-check-input" type="checkbox" name="create_courier_labels" value="1"'
                       . ' id="create_courier_labels_' . $wid . '" checked>'
                       . '<label class="form-check-label small" for="create_courier_labels_' . $wid . '">'
                       . 'Courier labels</label></div>';
                }
                echo '<button type="submit" class="btn btn-warning btn-sm">'
                   . '<i class="bi bi-upc-scan me-1"></i>Despatch pickwave</button>';
                echo '</form>';
            }
            echo '</div></div>';

            // —— Pickwave Details ——
            echo '<div class="card border-0 shadow-sm mb-3"><div class="card-body">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Pickwave Details</h2>';
            echo '<form method="post" action="'
               . $this->view->e($this->url->to('store/admin/pickwaves/update', ['id' => $wid]))
               . '">';
            echo $this->request->csrfField();
            echo '<div class="row g-3 small">';
            echo '<div class="col-md-3"><div class="text-muted">Pickwave ID</div><div class="fw-semibold">'
               . $wid . '</div></div>';
            echo '<div class="col-md-3"><div class="text-muted">Created Date</div><div>'
               . $this->view->e($createdShort) . '</div></div>';
            echo '<div class="col-md-3"><label class="text-muted" for="picker_name">Created By / Picker</label>'
               . '<input type="text" name="picker_name" id="picker_name" class="form-control form-control-sm"'
               . ' value="' . $this->view->e((string) ($wave['picker_name'] ?? '')) . '" maxlength="128"></div>';
            echo '<div class="col-md-3"><div class="text-muted">Outstanding / Total Orders</div><div class="fw-semibold">'
               . $outstanding . ' / ' . count($orders) . '</div></div>';
            echo '<div class="col-md-3"><div class="text-muted">Pickwave Status</div><div>'
               . '<span class="badge ' . ($isOpen ? 'text-bg-primary' : 'text-bg-success') . '">'
               . $this->view->e(ucfirst((string) $wave['status'])) . '</span></div></div>';
            echo '<div class="col-md-3"><label class="text-muted" for="tag">Tag</label>'
               . '<input type="text" name="tag" id="tag" class="form-control form-control-sm"'
               . ' value="' . $this->view->e((string) ($wave['tag'] ?? '')) . '" maxlength="128"></div>';
            echo '<div class="col-md-6"><label class="text-muted" for="notes">Pickwave Message</label>'
               . '<textarea name="notes" id="notes" class="form-control form-control-sm" rows="2" maxlength="500">'
               . $this->view->e((string) ($wave['notes'] ?? '')) . '</textarea></div>';
            echo '</div>';
            if ($isOpen) {
                echo '<div class="mt-3"><button type="submit" class="btn btn-primary btn-sm">Update</button></div>';
            }
            echo '</form></div></div>';

            // —— Scan Product ——
            if ($isOpen) {
                echo '<div class="card border-0 shadow-sm mb-3" id="scan-product"><div class="card-body">';
                echo '<h2 class="h6 text-uppercase text-muted mb-3">Scan Product</h2>';
                echo '<div class="row g-2 align-items-end">';
                echo '<div class="col-md-2"><label class="form-label small mb-0">Despatch Date</label>'
                   . '<input type="date" class="form-control form-control-sm" id="sf-despatch-date"'
                   . ' value="' . $this->view->e(date('Y-m-d')) . '"></div>';
                echo '<div class="col-md-4"><label class="form-label small mb-0" for="sf-scan-sku">Enter SKU/Barcode</label>'
                   . '<input type="text" class="form-control form-control-sm" id="sf-scan-sku"'
                   . ' autocomplete="off" autofocus></div>';
                echo '<div class="col-md-2"><label class="form-label small mb-0" for="sf-scan-qty">Enter Quantity</label>'
                   . '<input type="number" min="1" value="1" class="form-control form-control-sm" id="sf-scan-qty"></div>';
                echo '<div class="col-md-2"><button type="button" class="btn btn-primary btn-sm w-100" id="sf-scan-btn">'
                   . 'Scan</button></div>';
                echo '<div class="col-md-2"><div class="small text-muted" id="sf-scan-status">Ready</div></div>';
                echo '</div></div></div>';
            }

            // —— Lines table ——
            echo '<div class="card border-0 shadow-sm mb-3"><div class="card-body">';
            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<h2 class="h6 text-uppercase text-muted mb-0">Orders in pickwave</h2>';
            $groupUrl = $this->url->to('store/admin/pickwaves/view', [
                'id' => $wid,
                'group' => $groupByOrders ? '0' : '1',
            ]);
            echo '<div class="d-flex flex-wrap align-items-center gap-3">';
            if ($isOpen) {
                echo '<button type="submit" form="sf-remove-orders-form" class="btn btn-sm btn-outline-danger" id="sf-bulk-remove-btn" disabled'
                   . ' onclick="return confirm(\'Remove selected order(s) from this pickwave?\');">'
                   . 'Remove selected</button>';
            }
            echo '<div class="form-check form-switch m-0">'
               . '<input class="form-check-input" type="checkbox" role="switch" id="group_by_orders"'
               . ($groupByOrders ? ' checked' : '')
               . ' onchange="location.href=' . $this->view->e(json_encode($groupUrl)) . '">'
               . '<label class="form-check-label small" for="group_by_orders">Group By Orders</label></div>';
            echo '</div></div>';

            if ($isOpen) {
                echo '<form method="post" id="sf-remove-orders-form" action="'
                   . $this->view->e($this->url->to('store/admin/pickwaves/remove-order', ['id' => $wid]))
                   . '">';
                echo $this->request->csrfField();
            }

            echo '<div class="table-responsive"><table class="table table-sm table-striped align-middle" id="sf-pick-lines">';
            echo '<thead><tr>';
            if ($isOpen) {
                echo '<th style="width:2.5rem">'
                   . '<input type="checkbox" class="form-check-input" id="sf-pick-check-all" title="Select all">'
                   . '</th>';
            }
            echo '<th>Ordered SKU / Product Name</th>'
               . '<th>Order Number</th>'
               . '<th class="text-end">Quantity Ordered</th>'
               . '<th class="text-end">Scanned</th>'
               . '<th>Order Status</th>'
               . '<th>Shipping Method</th>'
               . '<th></th>'
               . '</tr></thead><tbody>';

            if ($groupByOrders) {
                foreach ($orders as $entry) {
                    $o = $entry['order'];
                    $this->renderPickOrderHeaderRow($wid, $o, $isOpen);
                    foreach ($entry['items'] as $item) {
                        $this->renderPickLineRow($wid, $o, $item, $isOpen, showRemove: false, showSelect: false);
                    }
                }
            } else {
                $seenOrders = [];
                foreach ($flatLines as $row) {
                    $oid = (int) ($row['order']['id'] ?? 0);
                    $firstOfOrder = $oid > 0 && !isset($seenOrders[$oid]);
                    $seenOrders[$oid] = true;
                    $this->renderPickLineRow(
                        $wid,
                        $row['order'],
                        $row['item'],
                        $isOpen,
                        showRemove: $firstOfOrder,
                        showSelect: $firstOfOrder,
                    );
                }
            }

            echo '</tbody></table></div>';
            if ($isOpen) {
                echo '</form>';
            }

            if ($consol !== []) {
                echo '<details class="mt-3"><summary class="small text-muted">Consolidated pick ('
                   . count($consol) . ' SKUs)</summary>';
                echo '<div class="table-responsive mt-2"><table class="table table-sm">';
                echo '<thead><tr><th>SKU</th><th>Title</th><th class="text-end">Qty</th></tr></thead><tbody>';
                foreach ($consol as $line) {
                    echo '<tr><td><code>' . $this->view->e($line['external_sku'] ?: '—') . '</code></td>'
                       . '<td>' . $this->view->e($line['title'] ?: '—') . '</td>'
                       . '<td class="text-end">' . (int) $line['qty'] . '</td></tr>';
                }
                echo '</tbody></table></div></details>';
            }
            echo '</div></div>';

            if ($isOpen) {
                $this->renderScanScript($wid);
                $this->renderPickSelectScript();
            }
        });
    }

    /**
     * @param array<string, mixed> $order
     */
    private function renderPickOrderHeaderRow(int $pickwaveId, array $order, bool $isOpen): void
    {
        $oid = (int) ($order['id'] ?? 0);
        $status = strtoupper((string) ($order['status'] ?? ''));
        $canEdit = $isOpen && $status !== 'FULFILLED' && $pickwaveId > 0;

        echo '<tr class="table-light">';
        if ($isOpen) {
            echo '<td>';
            if ($canEdit) {
                echo '<input type="checkbox" class="form-check-input sf-pick-order" name="order_ids[]" value="'
                   . $oid . '">';
            }
            echo '</td>';
        }
        echo '<td colspan="6" class="small fw-semibold">'
           . 'Order <a href="' . $this->view->e($this->url->to(
               'store/admin/orders/view',
               ['id' => $oid]
           )) . '"><code>' . $this->view->e((string) $order['external_order_id']) . '</code></a>'
           . ' · ' . $this->view->e((string) ($order['buyer_username'] ?? ''))
           . ' · ' . $this->view->e((string) ($order['shipping_method'] ?? '') ?: '—')
           . '</td><td class="text-end">';
        if ($canEdit) {
            echo $this->removeOrderButton($oid);
        }
        echo '</td></tr>';
    }

    private function removeOrderButton(int $orderId): string
    {
        // Submit via the bulk form (no nested <form>) — clears other selections first.
        return '<button type="submit" class="btn btn-sm btn-outline-danger" title="Remove from pickwave"'
            . ' name="order_id" value="' . $orderId . '"'
            . ' form="sf-remove-orders-form"'
            . ' onclick="document.querySelectorAll(\'.sf-pick-order\').forEach(function(c){c.checked=false;});'
            . ' return confirm(\'Remove this order from the pickwave?\');">'
            . '<i class="bi bi-x-lg"></i></button>';
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     */
    private function renderPickLineRow(
        int $pickwaveId,
        array $order,
        array $item,
        bool $isOpen,
        bool $showRemove = true,
        bool $showSelect = true,
    ): void {
        $oid = (int) ($order['id'] ?? 0);
        $sku = trim((string) ($item['external_sku'] ?? ''));
        $kitSku = trim((string) ($item['kit_sku'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $status = strtoupper((string) ($order['status'] ?? ''));
        $displayStatus = $status === 'FULFILLED' ? 'Fulfilled' : ($isOpen ? 'Assigned' : $status);
        $method = trim((string) ($order['shipping_method'] ?? ''));
        $ship = $this->shipments->findLatestForOrder($oid);
        $scanKey = strtolower($sku !== '' ? $sku : ('title:' . $title));
        $canEdit = $isOpen && $status !== 'FULFILLED' && $pickwaveId > 0;

        echo '<tr class="sf-pick-line" data-sku="' . $this->view->e(strtolower($sku)) . '"'
           . ' data-scan-key="' . $this->view->e($scanKey) . '"'
           . ' data-qty="' . $qty . '"'
           . ' data-order-id="' . $oid . '">';
        if ($isOpen) {
            echo '<td>';
            if ($showSelect && $canEdit) {
                echo '<input type="checkbox" class="form-check-input sf-pick-order" name="order_ids[]" value="'
                   . $oid . '">';
            }
            echo '</td>';
        }
        echo '<td><div class="fw-semibold">' . $this->view->e($title !== '' ? $title : '—') . '</div>'
           . ($sku !== '' ? '<code class="small">' . $this->view->e($sku) . '</code>' : '')
           . ($kitSku !== ''
                ? ' <span class="badge text-bg-secondary">kit ' . $this->view->e($kitSku) . '</span>'
                : '')
           . '</td>';
        echo '<td><a href="' . $this->view->e($this->url->to('store/admin/orders/view', ['id' => $oid])) . '">'
           . '<code>' . $this->view->e((string) $order['external_order_id']) . '</code></a></td>';
        echo '<td class="text-end sf-qty-ordered">' . $qty . '</td>';
        echo '<td class="text-end"><span class="sf-qty-scanned badge text-bg-light">0</span></td>';
        echo '<td><span class="badge ' . ($status === 'FULFILLED' ? 'text-bg-success' : 'text-bg-secondary') . '">'
           . $this->view->e($displayStatus) . '</span></td>';
        echo '<td class="small">' . $this->view->e($method !== '' ? $method : '—') . '</td>';
        echo '<td class="text-end text-nowrap">';
        if ($ship !== null && trim((string) ($ship['barcode'] ?? '')) !== '') {
            echo '<a class="btn btn-sm btn-outline-secondary" target="_blank" href="'
               . $this->view->e($this->url->to('store/admin/orders/label', ['id' => $oid]))
               . '">Label</a> ';
        }
        if ($showRemove && $canEdit) {
            echo $this->removeOrderButton($oid);
        }
        echo '</td></tr>';
    }

    private function renderPickSelectScript(): void
    {
        ?>
        <script>
        (function () {
          const all = document.getElementById('sf-pick-check-all');
          const btn = document.getElementById('sf-bulk-remove-btn');
          function boxes() {
            return Array.prototype.slice.call(document.querySelectorAll('.sf-pick-order'));
          }
          function sync() {
            const list = boxes();
            const checked = list.filter(function (c) { return c.checked; });
            if (all) {
              all.checked = list.length > 0 && checked.length === list.length;
              all.indeterminate = checked.length > 0 && checked.length < list.length;
            }
            if (btn) btn.disabled = checked.length === 0;
          }
          if (all) {
            all.addEventListener('change', function () {
              boxes().forEach(function (c) { c.checked = all.checked; });
              sync();
            });
          }
          boxes().forEach(function (c) { c.addEventListener('change', sync); });
          sync();
        })();
        </script>
        <?php
    }

    private function renderScanScript(int $pickwaveId): void
    {
        $key = 'store_pick_scan_' . $pickwaveId;
        ?>
        <script>
        (function () {
          const storageKey = <?= json_encode($key) ?>;
          const statusEl = document.getElementById('sf-scan-status');
          const skuEl = document.getElementById('sf-scan-sku');
          const qtyEl = document.getElementById('sf-scan-qty');
          const btn = document.getElementById('sf-scan-btn');
          let scanned = {};
          try { scanned = JSON.parse(localStorage.getItem(storageKey) || '{}') || {}; } catch (e) { scanned = {}; }

          function persist() {
            localStorage.setItem(storageKey, JSON.stringify(scanned));
          }

          function paint() {
            document.querySelectorAll('#sf-pick-lines tr.sf-pick-line').forEach(function (tr) {
              const k = tr.getAttribute('data-scan-key') || '';
              const need = parseInt(tr.getAttribute('data-qty') || '0', 10) || 0;
              const have = parseInt(scanned[k] || '0', 10) || 0;
              const badge = tr.querySelector('.sf-qty-scanned');
              if (badge) {
                badge.textContent = String(have);
                badge.className = 'sf-qty-scanned badge ' + (
                  have >= need ? 'text-bg-success' : (have > 0 ? 'text-bg-warning' : 'text-bg-light')
                );
              }
              tr.classList.toggle('table-success', have >= need && need > 0);
            });
          }

          function doScan() {
            const raw = (skuEl.value || '').trim();
            const qty = Math.max(1, parseInt(qtyEl.value || '1', 10) || 1);
            if (!raw) {
              statusEl.textContent = 'Enter a SKU';
              statusEl.className = 'small text-danger';
              return;
            }
            const needle = raw.toLowerCase();
            const rows = Array.prototype.slice.call(
              document.querySelectorAll('#sf-pick-lines tr.sf-pick-line')
            );
            let target = rows.find(function (tr) {
              return (tr.getAttribute('data-sku') || '') === needle;
            });
            if (!target) {
              target = rows.find(function (tr) {
                return (tr.getAttribute('data-sku') || '').indexOf(needle) >= 0
                  || (tr.getAttribute('data-scan-key') || '').indexOf(needle) >= 0;
              });
            }
            if (!target) {
              statusEl.textContent = 'SKU not in this pickwave';
              statusEl.className = 'small text-danger';
              skuEl.select();
              return;
            }
            const k = target.getAttribute('data-scan-key') || needle;
            const need = parseInt(target.getAttribute('data-qty') || '0', 10) || 0;
            const next = Math.min(need, (parseInt(scanned[k] || '0', 10) || 0) + qty);
            scanned[k] = next;
            persist();
            paint();
            statusEl.textContent = 'Scanned ' + raw + ' (' + next + '/' + need + ')';
            statusEl.className = 'small text-success';
            skuEl.value = '';
            qtyEl.value = '1';
            skuEl.focus();
            target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          }

          paint();
          if (btn) btn.addEventListener('click', doScan);
          if (skuEl) {
            skuEl.addEventListener('keydown', function (e) {
              if (e.key === 'Enter') { e.preventDefault(); doScan(); }
            });
          }
        })();
        </script>
        <?php
    }

    // =========================================================================
    // Remove order from pickwave
    // =========================================================================

    public function removeOrderAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/pickwaves');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/pickwaves');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $rawIds = $this->request->post('order_ids', 'raw', []);
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $single = (int) $this->request->post('order_id', 'int', 0);
        if ($single > 0) {
            $rawIds[] = $single;
        }
        $orderIds = array_values(array_unique(array_filter(
            array_map(static fn($v) => (int) $v, $rawIds),
            static fn(int $v) => $v > 0
        )));

        try {
            $result = $this->pickwaves->removeOrders($id, $orderIds);
            $msg = $result['removed'] === 1
                ? 'Order removed from pickwave. It is back in Outstanding.'
                : $result['removed'] . ' orders removed from pickwave. They are back in Outstanding.';
            if (!empty($result['skipped'])) {
                $msg .= ' ' . $result['skipped'] . ' skipped.';
            }
            if (!empty($result['wave_deleted'])) {
                $this->notify->success($msg . ' Pickwave was empty and has been deleted.');
                redirect_to('store/admin/pickwaves', ['tab' => 'open']);
                return;
            }
            $this->notify->success($msg);
            redirect_to('store/admin/pickwaves/view', ['id' => $id]);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
            redirect_to('store/admin/pickwaves/view', ['id' => $id]);
        }
    }

    // =========================================================================
    // Update pickwave details
    // =========================================================================

    public function updateAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/pickwaves');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/pickwaves');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);

        try {
            $this->pickwaves->updateDetails(
                $id,
                (string) $this->request->post('picker_name', 'string', ''),
                (string) $this->request->post('notes', 'string', ''),
                (string) $this->request->post('tag', 'string', ''),
            );
            $this->notify->success('Pickwave updated.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect_to('store/admin/pickwaves/view', ['id' => $id]);
    }

    // =========================================================================
    // Print Despatched Labels (Evri PDFs)
    // =========================================================================

    public function labelsAction(): void
    {
        $id   = (int) $this->request->get('id', 'int', 0);
        $data = $this->pickwaves->find($id);
        if ($data === null) {
            throw new NotFoundException('Pickwave not found.');
        }

        $format = strtolower(trim((string) $this->request->get('format', 'string', 'html')));

        /** @var array<int, array{order: array<string, mixed>, shipment: array<string, mixed>, path: string}> $ready */
        $ready = [];
        foreach ($data['orders'] as $entry) {
            $order = $entry['order'];
            $oid = (int) ($order['id'] ?? 0);
            $shipment = $this->shipments->findLatestForOrder($oid);
            if ($shipment === null) {
                continue;
            }
            try {
                $rel = $this->courier->ensureLabelFile($shipment);
                $full = BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store'
                    . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
                if (is_file($full)) {
                    $ready[] = [
                        'order'    => $order,
                        'shipment' => $shipment,
                        'path'     => $full,
                    ];
                }
            } catch (\Throwable $e) {
                error_log('Pickwave labels order #' . $oid . ': ' . $e->getMessage());
            }
        }

        if ($format === 'zip') {
            if ($ready === []) {
                $this->notify->error('No despatched labels found for this pickwave. Despatch with courier labels first.');
                redirect_to('store/admin/pickwaves/view', ['id' => $id]);
                return;
            }
            if (!class_exists(\ZipArchive::class)) {
                $this->notify->error('ZipArchive is not available on this PHP install.');
                redirect_to('store/admin/pickwaves/view', ['id' => $id]);
                return;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'store_labels_');
            if ($tmp === false) {
                $this->notify->error('Unable to create temp file for labels zip.');
                redirect_to('store/admin/pickwaves/view', ['id' => $id]);
                return;
            }
            @unlink($tmp);
            $zipPath = $tmp . '.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                $this->notify->error('Unable to create labels zip.');
                redirect_to('store/admin/pickwaves/view', ['id' => $id]);
                return;
            }
            foreach ($ready as $row) {
                $barcode = preg_replace('/\W+/', '', (string) ($row['shipment']['barcode'] ?? 'label')) ?: 'label';
                $extId = preg_replace('/\W+/', '', (string) ($row['order']['external_order_id'] ?? $row['order']['id'])) ?: 'order';
                $zip->addFile($row['path'], $extId . '_' . $barcode . '.pdf');
            }
            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="pickwave-' . $id . '-labels.zip"');
            header('Content-Length: ' . (string) filesize($zipPath));
            readfile($zipPath);
            @unlink($zipPath);
            exit;
        }

        $app = (string) $this->config->get('app.name', 'Store');
        $zipUrl = $this->url->to('store/admin/pickwaves/labels', ['id' => $id, 'format' => 'zip']);

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Despatched Labels — Pickwave #'
           . (int) $id . '</title>';
        echo '<style>
          body{font-family:system-ui,sans-serif;margin:1.5rem;color:#222}
          h1{font-size:1.25rem;margin:0 0 .75rem}
          .actions{margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap}
          a.btn{display:inline-block;padding:.4rem .75rem;border:1px solid #ccc;border-radius:.25rem;
            text-decoration:none;color:#222;background:#f8f8f8}
          a.btn-primary{background:#0d6efd;border-color:#0d6efd;color:#fff}
          table{border-collapse:collapse;width:100%;font-size:.9rem}
          th,td{border-bottom:1px solid #e5e5e5;padding:.5rem;text-align:left}
          .muted{color:#666;font-size:.85rem}
          @media print{.actions{display:none}}
        </style></head><body>';
        echo '<h1>' . htmlspecialchars($app) . ' — Despatched Labels · Pickwave #' . (int) $id . '</h1>';
        echo '<div class="actions">';
        if ($ready !== []) {
            echo '<a class="btn btn-primary" href="' . htmlspecialchars($zipUrl) . '">Download all (ZIP)</a>';
        }
        echo '<a class="btn" href="javascript:window.print()">Print this page</a>';
        echo '<a class="btn" href="' . htmlspecialchars($this->url->to('store/admin/pickwaves/view', ['id' => $id]))
           . '">Back to pickwave</a>';
        echo '</div>';

        if ($ready === []) {
            echo '<p class="muted">No courier labels yet. Despatch the pickwave with <strong>Courier labels</strong> enabled, '
               . 'or create labels from each order.</p>';
        } else {
            echo '<p class="muted">' . count($ready) . ' label(s) ready.</p>';
            echo '<table><thead><tr><th>Order</th><th>Shipping Method</th><th>Barcode</th><th></th></tr></thead><tbody>';
            foreach ($ready as $row) {
                $oid = (int) $row['order']['id'];
                $labelUrl = $this->url->to('store/admin/orders/label', ['id' => $oid]);
                echo '<tr>';
                echo '<td><code>' . htmlspecialchars((string) $row['order']['external_order_id']) . '</code></td>';
                echo '<td>' . htmlspecialchars((string) ($row['order']['shipping_method'] ?? '') ?: '—') . '</td>';
                echo '<td><code>' . htmlspecialchars((string) ($row['shipment']['barcode'] ?? '')) . '</code></td>';
                echo '<td><a class="btn" target="_blank" href="' . htmlspecialchars($labelUrl) . '">Open PDF</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</body></html>';
        exit;
    }

    // =========================================================================
    // Despatch
    // =========================================================================

    public function despatchAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/pickwaves');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/pickwaves');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $createLabels = (string) $this->request->post('create_courier_labels', 'string', '') === '1'
            || (string) $this->request->post('create_evri_labels', 'string', '') === '1';

        try {
            $result = $this->service->despatch($id, $createLabels);
            $msg = 'Pickwave #' . $id . ' despatched. '
                . $result['order_count'] . ' order(s) fulfilled';
            if (!empty($result['labels'])) {
                $msg .= ', ' . $result['labels'] . ' courier label(s)';
            }
            if (!empty($result['notified'])) {
                $msg .= ', ' . $result['notified'] . ' notified on channel';
            }
            if (!empty($result['local_only'])) {
                $msg .= ', ' . $result['local_only'] . ' local only';
            }
            $msg .= '.';
            if (!empty($result['label_errors'])) {
                $this->notify->warning(
                    $msg . ' ' . $result['label_errors'] . ' courier label(s) failed — check logs / order addresses.'
                );
            } else {
                $this->notify->success($msg);
            }
            redirect_to('store/admin/pickwaves/view', ['id' => $id]);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
            redirect_to('store/admin/pickwaves/view', ['id' => $id]);
        }
    }

    // =========================================================================
    // Print: picking list
    // =========================================================================

    public function pickingListAction(): void
    {
        $id   = (int) $this->request->get('id', 'int', 0);
        $data = $this->pickwaves->find($id);
        if ($data === null) {
            throw new NotFoundException('Pickwave not found.');
        }

        $wave  = $data['wave'];
        $lines = $this->service->consolidatePickingLines($data['orders']);
        $app   = (string) $this->config->get('app.name', 'Store');

        $this->renderPrintDocument(
            'Picking list — Pickwave #' . $id,
            function () use ($wave, $lines, $app, $data) {
                echo '<header class="print-header">';
                echo '<div><strong>' . $this->view->e($app) . '</strong><br>Picking list</div>';
                echo '<div class="text-end">'
                   . '<div>Pickwave <strong>#' . (int) $wave['id'] . '</strong></div>'
                   . '<div>' . count($data['orders']) . ' order(s) · ' . count($lines) . ' SKU(s)</div>'
                   . ($wave['picker_name'] !== '' ? '<div>Picker: ' . $this->view->e((string) $wave['picker_name']) . '</div>' : '')
                   . '<div>' . $this->view->e((string) $wave['created_at']) . '</div>'
                   . '</div>';
                echo '</header>';

                echo '<table class="print-table">';
                echo '<thead><tr><th style="width:3rem"></th><th>SKU</th><th>Title</th><th class="num">Qty</th></tr></thead><tbody>';
                foreach ($lines as $line) {
                    echo '<tr>';
                    echo '<td class="tick">☐</td>';
                    echo '<td class="sku">' . $this->view->e($line['external_sku'] !== '' ? $line['external_sku'] : '—') . '</td>';
                    echo '<td>' . $this->view->e($line['title'] !== '' ? $line['title'] : '—') . '</td>';
                    echo '<td class="num">' . (int) $line['qty'] . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        );
    }

    // =========================================================================
    // Print: packing list
    // =========================================================================

    public function packingListAction(): void
    {
        $id   = (int) $this->request->get('id', 'int', 0);
        $data = $this->pickwaves->find($id);
        if ($data === null) {
            throw new NotFoundException('Pickwave not found.');
        }

        $wave     = $data['wave'];
        $payloads = $this->service->packingPayloads($data['orders']);
        $app      = (string) $this->config->get('app.name', 'Store');

        $this->renderPrintDocument(
            'Packing list — Pickwave #' . $id,
            function () use ($wave, $payloads, $app) {
                echo '<header class="print-header no-break">';
                echo '<div><strong>' . $this->view->e($app) . '</strong><br>Packing list</div>';
                echo '<div class="text-end">'
                   . '<div>Pickwave <strong>#' . (int) $wave['id'] . '</strong></div>'
                   . '<div>' . count($payloads) . ' order(s)</div>'
                   . ($wave['picker_name'] !== '' ? '<div>Picker: ' . $this->view->e((string) $wave['picker_name']) . '</div>' : '')
                   . '<div>' . $this->view->e((string) $wave['created_at']) . '</div>'
                   . '</div>';
                echo '</header>';

                foreach ($payloads as $i => $pack) {
                    $o = $pack['order'];
                    $a = $pack['address'];
                    $cls = $i > 0 ? ' order-block page-break' : ' order-block';
                    echo '<section class="' . $cls . '">';
                    echo '<h2>Order <code>' . $this->view->e((string) $o['external_order_id']) . '</code></h2>';
                    echo '<div class="meta">';
                    echo '<div><strong>Buyer</strong><br>'
                       . $this->view->e((string) $o['buyer_username'])
                       . ($o['buyer_email'] !== '' ? '<br>' . $this->view->e((string) $o['buyer_email']) : '')
                       . '</div>';
                    echo '<div><strong>Ship to</strong><br>' . $this->formatAddressHtml($a, (string) $o['shipping_name']) . '</div>';
                    echo '<div><strong>Channel</strong><br>'
                       . $this->view->e((string) ($o['channel_label'] ?? $o['channel_name'] ?? ''))
                       . '<br>' . number_format((float) $o['total'], 2) . ' ' . $this->view->e((string) $o['currency']);
                    $trk = trim((string) ($o['tracking_number'] ?? ''));
                    if ($trk !== '') {
                        echo '<br><strong>Tracking</strong> <code>' . $this->view->e($trk) . '</code>';
                        if (!empty($o['carrier_code'])) {
                            echo ' (' . $this->view->e((string) $o['carrier_code']) . ')';
                        }
                    }
                    echo '</div>';
                    echo '</div>';

                    echo '<table class="print-table">';
                    echo '<thead><tr><th>SKU</th><th>Title</th><th class="num">Qty</th></tr></thead><tbody>';
                    foreach ($pack['items'] as $item) {
                        echo '<tr>';
                        echo '<td class="sku">' . $this->view->e((string) ($item['external_sku'] ?? '') ?: '—') . '</td>';
                        echo '<td>' . $this->view->e((string) ($item['title'] ?? '') ?: '—') . '</td>';
                        echo '<td class="num">' . max(1, (int) ($item['qty'] ?? 1)) . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                    echo '</section>';
                }
            }
        );
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Create-pickwave chrome + Dataset outstanding table.
     */
    private function renderOutstandingTab(string $datasetHtml): void
    {
        $colChooser = [
            'channel'         => ['label' => 'Channel', 'default' => true, 'locked' => true],
            'order_number'    => ['label' => 'Order number', 'default' => true, 'locked' => true],
            'channel_ref'     => ['label' => 'Channel order ref', 'default' => true],
            'order_date'      => ['label' => 'Order date', 'default' => true],
            'ship_by'         => ['label' => 'Ship by date', 'default' => true],
            'product_skus'    => ['label' => 'Product SKUs', 'default' => true],
            'brand'           => ['label' => 'Brand', 'default' => true],
            'channel_name'    => ['label' => 'Channel product name', 'default' => false],
            'total_units'     => ['label' => 'Total units', 'default' => false],
            'multiple_lines'  => ['label' => 'Multiple lines', 'default' => false],
            'packages'        => ['label' => 'Packages', 'default' => false],
            'weight'          => ['label' => 'Order weight', 'default' => false],
            'buyer'           => ['label' => 'Buyer', 'default' => true],
            'postcode'        => ['label' => 'Postcode', 'default' => true],
            'total'           => ['label' => 'Total', 'default' => true],
            'shipping_method' => ['label' => 'Shipping method', 'default' => true],
            'status'          => ['label' => 'Status', 'default' => true],
        ];

        echo '<div class="position-relative">';
        echo '<form method="post" action="' . $this->view->e($this->url->to('store/admin/pickwaves/create')) . '" id="pw-create-form">';
        echo $this->request->csrfField();
        echo '<div class="sf-pick-actions">';
        echo '<label>Action<select disabled><option>Create pickwave</option></select></label>';
        echo '<label>Picker<input type="text" name="picker_name" placeholder="Picker name" maxlength="128"></label>';
        echo '<label>Notes<input type="text" name="notes" placeholder="Optional" maxlength="500"></label>';
        echo '<button type="submit" class="sf-pick-btn is-primary">Confirm</button>';
        echo '<button type="button" class="sf-pick-btn" id="pw-select-all-btn">Select all</button>';
        echo '<span class="sf-pick-count" id="pw-selected-count">0 selected</span>';
        echo '<button type="button" class="sf-pick-btn is-icon ms-auto" id="pw-col-toggle" title="Columns">'
           . '<i class="bi bi-wrench"></i></button>';
        echo '</div>';
        echo '</form>';

        echo '<div id="pw-col-panel" class="sf-col-chooser border rounded bg-white shadow-sm p-3">';
        echo '<div class="fw-semibold small mb-2">Columns</div>';
        echo '<label class="d-flex align-items-center gap-2 small mb-2">'
           . '<input type="checkbox" class="form-check-input" id="pw-col-select-all"> Select all</label>';
        echo '<div class="sf-col-chooser-list">';
        foreach ($colChooser as $key => $meta) {
            $checked = !empty($meta['default']) ? ' checked' : '';
            $disabled = !empty($meta['locked']) ? ' disabled' : '';
            echo '<label class="d-flex align-items-center gap-2 small mb-1'
               . (!empty($meta['locked']) ? ' text-muted' : '') . '">'
               . '<input type="checkbox" class="form-check-input pw-col-check" data-col="'
               . $this->view->e($key) . '"' . $checked . $disabled . '>'
               . $this->view->e((string) $meta['label']) . '</label>';
        }
        echo '</div></div>';

        echo $datasetHtml;
        echo '</div>';

        $defaultCols = array_keys(array_filter(
            $colChooser,
            static fn(array $meta): bool => !empty($meta['default']) || !empty($meta['locked'])
        ));
        $lockedCols = array_keys(array_filter(
            $colChooser,
            static fn(array $meta): bool => !empty($meta['locked'])
        ));
        $defaultsJson = json_encode($defaultCols, JSON_UNESCAPED_SLASHES);
        $lockedJson = json_encode($lockedCols, JSON_UNESCAPED_SLASHES);

        echo <<<HTML
<script>
(function () {
  const KEY = 'sf.pickwaves.cols.v2';
  const defaults = {$defaultsJson};
  const locked = new Set({$lockedJson});
  const panel = document.getElementById('pw-col-panel');
  const toggle = document.getElementById('pw-col-toggle');
  const checks = () => Array.from(document.querySelectorAll('.pw-col-check'));
  const selectAllBtn = document.getElementById('pw-select-all-btn');
  const countEl = document.getElementById('pw-selected-count');

  function load() {
    try {
      const raw = localStorage.getItem(KEY);
      if (!raw) return defaults.slice();
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return defaults.slice();
      return Array.from(new Set(parsed.concat(Array.from(locked))));
    } catch (e) { return defaults.slice(); }
  }
  function apply(visible) {
    const set = new Set(visible.concat(Array.from(locked)));
    checks().forEach(function (cb) {
      if (locked.has(cb.dataset.col)) {
        cb.checked = true;
        return;
      }
      cb.checked = set.has(cb.dataset.col);
    });
    // Only toggle table cells/headers — never the chooser checkboxes (they also have data-col).
    document.querySelectorAll('.sf-pick-card table [data-col]').forEach(function (el) {
      const col = el.getAttribute('data-col');
      if (!col || col === 'sel') return;
      if (el.classList.contains('pw-col-check')) return;
      el.style.display = set.has(col) ? 'table-cell' : 'none';
    });
  }
  function save() {
    const visible = checks().filter(function (c) { return c.checked; }).map(function (c) { return c.dataset.col; });
    localStorage.setItem(KEY, JSON.stringify(visible));
    apply(visible);
  }

  function placePanel() {
    if (!toggle || !panel) return;
    const r = toggle.getBoundingClientRect();
    const spaceBelow = window.innerHeight - r.bottom - 12;
    const spaceAbove = r.top - 12;
    panel.style.position = 'fixed';
    panel.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
    panel.style.left = 'auto';
    panel.style.zIndex = '1080';
    if (spaceBelow < 180 && spaceAbove > spaceBelow) {
      panel.style.top = 'auto';
      panel.style.bottom = (window.innerHeight - r.top + 6) + 'px';
      panel.style.maxHeight = Math.max(160, spaceAbove) + 'px';
    } else {
      panel.style.bottom = 'auto';
      panel.style.top = (r.bottom + 6) + 'px';
      panel.style.maxHeight = Math.max(160, spaceBelow) + 'px';
    }
  }
  function syncSelectAll() {
    const all = document.getElementById('pw-col-select-all');
    if (!all) return;
    const toggleable = checks().filter(function (c) { return !locked.has(c.dataset.col); });
    const on = toggleable.filter(function (c) { return c.checked; }).length;
    all.checked = toggleable.length > 0 && on === toggleable.length;
    all.indeterminate = on > 0 && on < toggleable.length;
  }
  function openPanel() {
    panel.style.display = 'flex';
    placePanel();
    syncSelectAll();
  }
  function closePanel() {
    panel.style.display = 'none';
  }

  apply(load());
  syncSelectAll();
  if (toggle && panel) {
    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      if (panel.style.display === 'flex') closePanel();
      else openPanel();
    });
    checks().forEach(function (cb) {
      cb.addEventListener('change', function () { save(); syncSelectAll(); });
    });
    const selectAll = document.getElementById('pw-col-select-all');
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        checks().forEach(function (c) {
          if (!locked.has(c.dataset.col)) c.checked = selectAll.checked;
        });
        save();
        syncSelectAll();
      });
    }
    window.addEventListener('resize', function () {
      if (panel.style.display === 'flex') placePanel();
    });
    document.addEventListener('click', function (e) {
      if (!panel.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
        closePanel();
      }
    });
  }

  function orderBoxes() {
    return Array.prototype.slice.call(document.querySelectorAll('.pw-order'));
  }
  function syncSelect() {
    const list = orderBoxes();
    const n = list.filter(function (c) { return c.checked; }).length;
    if (countEl) countEl.textContent = n + ' selected';
    if (selectAllBtn) selectAllBtn.textContent = (n === list.length && list.length > 0) ? 'Clear all' : 'Select all';
  }
  function setAll(on) {
    orderBoxes().forEach(function (c) { c.checked = !!on; });
    syncSelect();
  }
  if (selectAllBtn) {
    selectAllBtn.addEventListener('click', function () {
      const list = orderBoxes();
      const allOn = list.length > 0 && list.every(function (c) { return c.checked; });
      setAll(!allOn);
    });
  }
  orderBoxes().forEach(function (c) { c.addEventListener('change', syncSelect); });
  syncSelect();
})();
</script>
HTML;
    }

    /**
     * @param array<string, int|float|string> $stats
     */
    private function renderStatsPanel(array $stats): void
    {
        $rows = [
            'Total outstanding orders'   => (int) ($stats['outstanding'] ?? 0),
            'Total open pickwaves'       => (int) ($stats['open_pickwaves'] ?? 0),
            'Orders picked today'        => (int) ($stats['picked_today'] ?? 0),
            'Orders picked last 48 hours'=> (int) ($stats['picked_48h'] ?? 0),
            'Orders picked last week'    => (int) ($stats['picked_week'] ?? 0),
            'Orders remaining to pick'   => (int) ($stats['remaining_in_open'] ?? 0),
        ];

        echo '<section class="sf-pick-card sf-pick-stats">';
        echo '<div class="sf-pick-card-h"><h2>Stats</h2></div>';
        echo '<ul>';
        foreach ($rows as $label => $value) {
            echo '<li><span>' . $this->view->e($label) . '</span><strong>'
               . number_format($value) . '</strong></li>';
        }
        echo '</ul></section>';
    }

    /**
     * @param array<int, array<string, mixed>> $waves
     */
    private function renderWavePanel(array $waves, bool $open, string $waveTab, bool $embedded = false): void
    {
        echo '<section class="sf-pick-card">';
        echo '<div class="sf-pick-card-h">';
        echo '<nav class="sf-pick-mini">';
        foreach (['open' => 'Open pickwaves', 'completed' => 'Completed pickwaves'] as $key => $label) {
            $on = $waveTab === $key ? ' is-on' : '';
            $href = $embedded
                ? $this->url->to('store/admin/pickwaves', ['tab' => 'overview', 'wave_tab' => $key])
                : $this->url->to('store/admin/pickwaves', ['tab' => $key]);
            echo '<a class="' . $on . '" href="'
               . $this->view->e($href) . '">' . $this->view->e($label) . '</a>';
        }
        echo '</nav></div>';

        if ($waves === []) {
            echo '<p class="sf-pick-empty">There are no '
               . ($open ? 'open' : 'completed') . ' pickwaves to display.</p>';
            echo '</section>';
            return;
        }

        echo '<div class="table-responsive"><table class="sf-pick-table">';
        echo '<thead><tr>'
           . '<th>Pickwave</th>'
           . '<th>Despatched</th>'
           . '<th>Picker</th>'
           . '<th>Started</th>'
           . ($open ? '' : '<th>Completed</th>')
           . '<th>Picking</th>'
           . '<th>Packing</th>'
           . ($open ? '<th>Despatch</th>' : '')
           . '</tr></thead><tbody>';

        foreach ($waves as $w) {
            $wid   = (int) $w['id'];
            $total = (int) ($w['order_count'] ?? 0);
            $done  = (int) ($w['fulfilled_count'] ?? 0);
            echo '<tr>';
            echo '<td><a href="' . $this->view->e($this->url->to('store/admin/pickwaves/view', ['id' => $wid])) . '">'
               . '<code>#' . $wid . '</code></a></td>';
            echo '<td>' . $done . ' / ' . $total . '</td>';
            echo '<td>' . $this->view->e((string) (($w['picker_name'] ?? '') !== '' ? $w['picker_name'] : '—')) . '</td>';
            echo '<td class="is-muted">' . $this->view->e($this->formatWhen((string) $w['created_at'])) . '</td>';
            if (!$open) {
                echo '<td class="is-muted">' . $this->view->e($this->formatWhen((string) ($w['completed_at'] ?? ''))) . '</td>';
            }
            echo '<td><a class="sf-pick-btn" target="_blank" href="'
               . $this->view->e($this->url->to('store/admin/pickwaves/picking-list', ['id' => $wid]))
               . '">Print</a></td>';
            echo '<td><a class="sf-pick-btn" target="_blank" href="'
               . $this->view->e($this->url->to('store/admin/pickwaves/packing-list', ['id' => $wid]))
               . '">Print</a></td>';
            if ($open) {
                echo '<td>';
                echo '<form method="post" action="'
                   . $this->view->e($this->url->to('store/admin/pickwaves/despatch', ['id' => $wid]))
                   . '" class="d-inline-flex flex-column align-items-start gap-1"'
                   . ' onsubmit="return confirm(\'Despatch pickwave #' . $wid . '?\');">';
                echo $this->request->csrfField();
                if ($this->courierReady) {
                    echo '<div class="form-check m-0">'
                       . '<input class="form-check-input" type="checkbox" name="create_courier_labels" value="1"'
                       . ' id="qe_courier_' . $wid . '" checked>'
                       . '<label class="form-check-label small" for="qe_courier_' . $wid . '">Courier labels</label>'
                       . '</div>';
                }
                echo '<button type="submit" class="btn btn-sm btn-primary">Despatch</button>';
                echo '</form></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '</section>';
    }

    /**
     * Merge outstanding brand counts with the store_brands catalog so the filter
     * lists every known brand (count 0 when none are outstanding).
     *
     * @param array<int, array{brand: string, order_count: int}> $fromOutstanding
     * @return array<int, array{brand: string, order_count: int}>
     */
    private function mergeBrandOptions(array $fromOutstanding): array
    {
        $counts = [];
        foreach ($fromOutstanding as $row) {
            $name = trim((string) ($row['brand'] ?? ''));
            if ($name === '') {
                continue;
            }
            $counts[$name] = (int) ($row['order_count'] ?? 0);
        }
        foreach ($this->brandsRepo->all() as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || isset($counts[$name])) {
                continue;
            }
            // Case-insensitive match to outstanding name
            $matched = false;
            foreach ($counts as $existing => $_) {
                if (strcasecmp($existing, $name) === 0) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $counts[$name] = 0;
            }
        }
        ksort($counts, SORT_STRING | SORT_FLAG_CASE);
        $out = [];
        foreach ($counts as $brand => $count) {
            $out[] = ['brand' => $brand, 'order_count' => $count];
        }
        return $out;
    }

    /**
     * @param array<int, array{external_sku?: string, title?: string, qty?: int}> $items
     */
    private function renderItemsSummary(array $items): string
    {
        return $this->renderSkuList($items);
    }

    /**
     * Store-style "Product SKUs" cell: short SKU x qty.
     *
     * @param array<int, array{external_sku?: string, title?: string, qty?: int, product_id?: int}> $items
     */
    private function renderSkuList(array $items): string
    {
        $productUrl = fn(int $id): string => $this->url->to('store/admin/products/edit', ['id' => $id]);
        return store_render_short_skus($items, $productUrl);
    }

    /**
     * Channel product name column — titles only.
     *
     * @param array<int, array{external_sku?: string, title?: string, qty?: int}> $items
     */
    private function renderTitleList(array $items): string
    {
        if ($items === []) {
            return '<span class="text-muted">—</span>';
        }
        $html  = '';
        $shown = 0;
        foreach ($items as $item) {
            if ($shown >= 3) {
                break;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $qty   = max(1, (int) ($item['qty'] ?? 1));
            if ($title === '') {
                continue;
            }
            $html .= ($html !== '' ? '<br>' : '');
            $html .= $this->view->e($this->truncate($title, 56)) . ' <span class="text-muted">x' . $qty . '</span>';
            $shown++;
        }
        $extra = count($items) - $shown;
        if ($extra > 0) {
            $html .= '<br><span class="text-muted">+' . $extra . ' more</span>';
        }
        return $html !== '' ? $html : '<span class="text-muted">—</span>';
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, max(1, $max - 1)) . '…';
    }

    private function extractPostcode(mixed $shippingAddress): string
    {
        if (is_string($shippingAddress)) {
            $decoded = json_decode($shippingAddress, true);
            $shippingAddress = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($shippingAddress)) {
            return '';
        }
        foreach (['postalCode', 'postal_code', 'zip', 'postcode'] as $key) {
            $val = trim((string) ($shippingAddress[$key] ?? ''));
            if ($val !== '') {
                return $val;
            }
        }
        return '';
    }

    private function extractShipBy(mixed $shippingAddress): string
    {
        if (is_string($shippingAddress)) {
            $decoded = json_decode($shippingAddress, true);
            $shippingAddress = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($shippingAddress)) {
            return '';
        }
        return trim((string) ($shippingAddress['_ship_by'] ?? ''));
    }

    /** @param array<string, mixed> $address */
    private function formatAddressHtml(array $address, string $fallbackName): string
    {
        $lines = [];
        $name = trim((string) ($address['fullName'] ?? $fallbackName));
        if ($name !== '') {
            $lines[] = $this->view->e($name);
        }
        foreach (['addressLine1', 'addressLine2', 'city', 'stateOrProvince', 'postalCode', 'countryCode'] as $key) {
            $val = trim((string) ($address[$key] ?? ''));
            if ($val !== '') {
                $lines[] = $this->view->e($val);
            }
        }
        // Some payloads use different keys
        foreach (['line1', 'line2', 'postal_code', 'country'] as $key) {
            $val = trim((string) ($address[$key] ?? ''));
            if ($val !== '' && !in_array($this->view->e($val), $lines, true)) {
                $lines[] = $this->view->e($val);
            }
        }

        return $lines !== [] ? implode('<br>', $lines) : '<span class="text-muted">—</span>';
    }

    private function renderPrintDocument(string $title, callable $body): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . $this->view->e($title) . '</title>';
        echo '<style>
            * { box-sizing: border-box; }
            body { font-family: Georgia, "Times New Roman", serif; font-size: 12px; color: #111; margin: 1.5rem; }
            .print-header { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: 1.25rem; border-bottom: 2px solid #111; padding-bottom: .75rem; }
            .print-table { width: 100%; border-collapse: collapse; margin-top: .5rem; }
            .print-table th, .print-table td { border: 1px solid #ccc; padding: .4rem .5rem; vertical-align: top; }
            .print-table th { background: #f3f3f3; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
            .print-table .num { text-align: right; width: 4rem; }
            .print-table .tick { text-align: center; font-size: 1.1rem; }
            .print-table .sku { font-family: ui-monospace, Consolas, monospace; white-space: nowrap; }
            .order-block { margin-top: 1.25rem; }
            .order-block h2 { font-size: 1.05rem; margin: 0 0 .5rem; }
            .meta { display: grid; grid-template-columns: 1fr 1.4fr 1fr; gap: .75rem; margin-bottom: .75rem; }
            .text-end { text-align: right; }
            .no-print { margin-bottom: 1rem; }
            @media print {
                body { margin: 0; }
                .no-print { display: none !important; }
                .page-break { break-before: page; page-break-before: always; }
            }
        </style></head><body>';
        echo '<div class="no-print"><button type="button" onclick="window.print()">Print</button></div>';
        $body();
        echo '</body></html>';
        exit;
    }

    private function formatWhen(?string $at): string
    {
        if ($at === null || trim($at) === '') {
            return '—';
        }
        $ts = strtotime($at);
        return $ts === false ? $at : date('d M H:i', $ts);
    }

    private function pickStyles(): string
    {
        return <<<'CSS'
<style>
.sf-pick{color:#1f2937}
.sf-pick-head{margin-bottom:.75rem}
.sf-pick-head h1{margin:0;font-size:1.35rem;font-weight:650;letter-spacing:-.02em}
.sf-pick-tabs,.sf-pick-mini{display:flex;align-items:center;gap:.2rem;flex-wrap:wrap}
.sf-pick-tabs{margin-bottom:1rem}
.sf-pick-tabs a,.sf-pick-mini a{color:#6b7280;text-decoration:none;padding:.35rem .7rem;border-radius:999px;font-size:.84rem;font-weight:600}
.sf-pick-tabs a:hover,.sf-pick-mini a:hover{background:#f3f4f6;color:#111827}
.sf-pick-tabs a.is-on,.sf-pick-mini a.is-on{background:#dbeafe;color:#1d4ed8}
.sf-pick-top{display:grid;grid-template-columns:16.5rem minmax(0,1fr);gap:.75rem;margin-bottom:.75rem}
.sf-pick-card{background:#fff;border:1px solid #e5e7eb;border-radius:.7rem;overflow:visible;box-shadow:0 1px 2px rgba(16,24,40,.04);min-width:0}
.sf-pick-card .table-responsive,.sf-pick-card .dataset .card{border-radius:.7rem;overflow:hidden}
.sf-col-chooser{display:none;flex-direction:column;width:18rem;max-width:calc(100vw - 1rem);background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 12px 32px rgba(15,23,42,.18);padding:.75rem;z-index:1080;overflow:hidden}
.sf-col-chooser-list{flex:1 1 auto;min-height:0;overflow:auto;padding-right:.15rem}
.sf-pick-card-h{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.65rem 1rem;border-bottom:1px solid #eef0f3}
.sf-pick-card-h h2{margin:0;font-size:.92rem;font-weight:650}
.sf-pick-card-h span{font-size:.78rem;color:#6b7280;font-weight:600}
.sf-pick-empty{margin:0;padding:1.25rem 1rem;color:#6b7280;font-size:.84rem;text-align:center}
.sf-pick-stats ul{list-style:none;margin:0;padding:.25rem .9rem .5rem}
.sf-pick-stats li{display:flex;justify-content:space-between;gap:.75rem;padding:.5rem 0;border-bottom:1px solid #f1f5f9;font-size:.8rem;color:#4b5563}
.sf-pick-stats li:last-child{border-bottom:0}
.sf-pick-stats strong{font-variant-numeric:tabular-nums;color:#111827}
.sf-pick-filters{display:grid;grid-template-columns:minmax(12rem,1.5fr) 12rem minmax(10rem,1fr) auto auto;gap:.5rem;align-items:center;padding:.75rem 1rem 0}
.sf-pick-filters input,.sf-pick-filters select,.sf-pick-actions input,.sf-pick-actions select{height:2.15rem;border:1px solid #d1d5db;border-radius:.45rem;padding:.2rem .65rem;font-size:.84rem;background:#fff;min-width:0}
.sf-pick-btn{display:inline-flex;align-items:center;justify-content:center;height:2.15rem;padding:0 .85rem;border:1px solid #d1d5db;border-radius:.45rem;background:#fff;color:#374151;font-size:.8rem;font-weight:600;text-decoration:none;white-space:nowrap}
.sf-pick-btn:hover{background:#f3f4f6;color:#111827}
.sf-pick-btn.is-primary{background:#2563eb;border-color:#2563eb;color:#fff}
.sf-pick-btn.is-primary:hover{background:#1d4ed8;color:#fff}
.sf-pick-btn.is-icon{width:2.15rem;padding:0;margin-left:auto}
.sf-pick-actions{display:flex;align-items:flex-end;gap:.5rem;flex-wrap:wrap;padding:.75rem 1rem;border-bottom:1px solid #eef0f3}
.sf-pick-actions label{display:flex;flex-direction:column;gap:.2rem;margin:0;font-size:.7rem;font-weight:600;color:#6b7280}
.sf-pick-actions label input{min-width:9rem}
.sf-pick-count{font-size:.75rem;color:#6b7280;font-weight:600;padding-bottom:.35rem}
.sf-pick-table{width:100%;border-collapse:collapse;margin:0}
.sf-pick-table thead th{background:#4b5563;color:#fff;font-weight:500;font-size:.72rem;letter-spacing:.02em;border:0;white-space:nowrap;padding:.55rem .65rem;vertical-align:middle}
.sf-pick-table tbody td{border-top:1px solid #f1f5f9;padding:.55rem .65rem;font-size:.8rem;vertical-align:middle}
.sf-pick-table tbody tr:hover{background:#f8fafc}
.sf-pick-table .is-muted{color:#6b7280;white-space:nowrap;font-variant-numeric:tabular-nums}
.sf-pick-table th.pw-sortable a{color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:.25rem;white-space:nowrap}
.sf-pick-table th.pw-sortable a:hover,.sf-pick-table th.pw-sort-active a{color:#dbeafe}
.sf-pick-table .pw-sort-icons{display:inline-flex;flex-direction:column;font-size:.55rem;line-height:1;opacity:.4}
.sf-pick-table th.pw-sort-active .pw-sort-icons{opacity:1}
.sf-pick-table .pw-col-filter{margin-top:.35rem}
.sf-pick-table .pw-col-filter input,.sf-pick-dd{width:100%;min-width:6.5rem;max-width:12rem;height:1.8rem;border:1px solid #e5e7eb;border-radius:.35rem;padding:.1rem .4rem;font-size:.72rem;background:#fff;color:#374151;text-align:left}
#pw-brand-dd{min-width:14rem;max-height:16rem;overflow:auto}
#pw-brand-dd .pw-brand-opt{display:none}
#pw-brand-dd .pw-brand-opt.show{display:flex}
.sf-pick-sku{display:block;max-width:16rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#4b5563}
.sf-pick-sku code{font-size:.72rem}
.sf-pick-clip{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:650}
.sf-pick-sub{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#6b7280;font-size:.72rem}
.sf-pick-pill{display:inline-flex;align-items:center;padding:.15rem .45rem;border-radius:999px;background:#f3f4f6;color:#374151;font-size:.68rem;font-weight:700;white-space:nowrap}
@media (max-width:1100px){
  .sf-pick-top{grid-template-columns:1fr}
  .sf-pick-filters{grid-template-columns:1fr 1fr}
}
</style>
CSS;
    }
}
