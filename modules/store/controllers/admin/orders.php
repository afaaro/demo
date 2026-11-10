<?php
declare(strict_types=1);

use Module\Store\Services\ChannelClientFactory;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\ChannelSettings;
use Module\Store\Services\CourierShipmentService;
use Module\Store\Services\CredentialsVault;
use Module\Store\Services\CustomerRepository;
use Module\Store\Services\OrderAuditRepository;
use Module\Store\Services\OrderBulkService;
use Module\Store\Services\OrderChannelActionService;
use Module\Store\Services\OrderDespatchService;
use Module\Store\Services\OrderRepository;
use Module\Store\Services\SerialRepository;
use Module\Store\Services\OrderSavedFilterRepository;
use Module\Store\Services\OrderSyncService;
use Module\Store\Services\PackagingSizeRepository;
use Module\Store\Services\ReturnRepository;
use Module\Store\Services\ShipmentRepository;
use Module\Store\Services\ShippingMethodRepository;
use Module\Store\Services\SyncLogRepository;
use Module\Store\Services\WarehouseRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreAdminOrders extends Controller
{
    private OrderRepository            $orders;
    private ChannelRepository          $channels;
    private ChannelClientFactory       $factory;
    private OrderSavedFilterRepository $savedFilters;
    private PackagingSizeRepository    $packaging;
    private ShippingMethodRepository   $methods;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $vault = CredentialsVault::forTenant(
            (string) $this->config->get('store.credentials_key', '')
        );

        $this->orders       = new OrderRepository($this->db);
        $this->channels     = new ChannelRepository($this->db);
        $this->factory      = new ChannelClientFactory($vault, $this->channels);
        $this->savedFilters = new OrderSavedFilterRepository($this->db);
        $this->packaging    = new PackagingSizeRepository($this->db);
        $this->methods      = new ShippingMethodRepository($this->db);
        // Permissions: Action.php only.

        $helper = get_module_path('store') . '/helpers/functions.php';
        if (is_string($helper) && is_file($helper)) {
            require_once $helper;
        }
    }

    // =========================================================================
    // List
    // =========================================================================

    public function indexAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $appliedFilterId = (int) $this->request->get('saved_filter', 'int', 0);
        if ($appliedFilterId > 0) {
            $preset = $this->savedFilters->findForUser($appliedFilterId, $userId);
            if ($preset !== null) {
                foreach ($preset['params'] as $k => $v) {
                    if (is_array($v)) {
                        if (!isset($_GET[$k]) || $_GET[$k] === '' || $_GET[$k] === []) {
                            $_GET[$k] = $v;
                        }
                        continue;
                    }
                    if ($this->request->get((string) $k, 'string', '') === '') {
                        $_GET[$k] = $v;
                    }
                }
            }
        }

        $view = trim((string) $this->request->get('view', 'string', ''));
        $channels = $this->channels->all();
        $connected = array_values(array_filter(
            $channels,
            static fn($c) => $c->enabled && $c->isConnected()
        ));
        $packagingSizes = $this->packaging->all();
        $shippingMethods = $this->methods->all();
        $savedFilterList = $this->savedFilters->forUser($userId);

        $channelOptions = ['0' => 'All channels'];
        foreach ($channels as $c) {
            $channelOptions[(string) (int) $c->id] = $c->label !== '' ? $c->label : $c->name;
        }
        $statusOptions = [
            ''            => 'All statuses',
            'NOT_STARTED' => 'Complete [Ready To Pick]',
            'IN_PROGRESS' => 'In progress',
            'FULFILLED'   => 'Fulfilled',
            'CANCELLED'   => 'Cancelled',
        ];
        $packagingOptions = ['' => 'All packaging'];
        foreach ($packagingSizes as $p) {
            $packagingOptions[(string) (int) $p['id']] = (string) $p['name'];
        }
        $shippingOptions = ['' => 'All shipping'];
        $seenShip = [];
        foreach ($shippingMethods as $m) {
            $method = (string) $m['method'];
            if ($method === '' || isset($seenShip[$method])) {
                continue;
            }
            $seenShip[$method] = true;
            $shippingOptions[$method] = $method;
        }

        $preserve = [];
        if ($view !== '') {
            $preserve['view'] = $view;
        }
        if ($appliedFilterId > 0) {
            $preserve['saved_filter'] = (string) $appliedFilterId;
        }

        $productUrl = fn(int $id): string => $this->url->to('store/admin/products/edit', ['id' => $id]);

        $dataset = (new Dataset($this->registry))
            ->perPage(50)
            ->searchPlaceholder('Search orders…')
            ->preserve($preserve)
            ->filter('channel_id', 'select', $channelOptions)
            ->filter('status', 'select', $statusOptions)
            ->filter('packaging', 'select', $packagingOptions)
            ->filter('shipping', 'select', $shippingOptions)
            ->searchable(['order', 'skus', 'customer', 'address', 'postcode', 'email', 'total', 'packages', 'weight', 'pickwave', 'notes', 'order_date'])
            ->query(function ($db, array $state) use ($view): array {
                $filters = $this->datasetFiltersToOrderFilters($state, $view);
                $rows = $this->orders->paginate($state['per_page'], $state['offset'], $filters);
                $total = $this->orders->count($filters);
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

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $dataset->columns([
            'sel' => [
                'label'  => '',
                'render' => static function (array $row): string {
                    $oid = (int) ($row['_row']['id'] ?? 0);
                    return '<input type="checkbox" class="form-check-input sf-orders-row" name="ids[]" value="'
                        . $oid . '" form="sf-orders-bulk">';
                },
            ],
            'order' => [
                'label'  => 'Order',
                'render' => function (array $row) use ($e): string {
                    $r = $row['_row'];
                    $oid = (int) $r['id'];
                    $tone = $this->orderStatusTone($r);
                    return '<span class="sf-dot sf-dot-' . $e($tone) . '"></span>'
                        . '<a class="sf-order-no" href="'
                        . $e($this->url->to('store/admin/orders/view', ['id' => $oid])) . '">'
                        . $e((string) $oid) . '</a>';
                },
            ],
            'channel' => [
                'label'  => 'Channel',
                'render' => function (array $row): string {
                    $r = $row['_row'];
                    return $this->renderChannelMark(
                        (string) ($r['channel_type'] ?? ''),
                        (string) ($r['channel_label'] ?? $r['channel_name'] ?? '')
                    );
                },
            ],
            'channel_ref' => [
                'label'  => 'Channel order details',
                'render' => function (array $row) use ($e): string {
                    $extRef = trim((string) ($row['_row']['external_order_id'] ?? ''));
                    return $extRef !== ''
                        ? '<span class="sf-muted">#</span> ' . $e($extRef)
                        : '<span class="text-muted">—</span>';
                },
            ],
            'items' => [
                'label'  => 'Items',
                'render' => function (array $row) use ($productUrl): string {
                    return store_render_item_titles($row['_items'] ?? [], $productUrl);
                },
            ],
            'skus' => [
                'label'  => 'Product SKUs',
                'render' => function (array $row) use ($productUrl): string {
                    return '<span class="small">' . store_render_short_skus($row['_items'] ?? [], $productUrl) . '</span>';
                },
            ],
            'tags' => [
                'label'  => 'Tags',
                'render' => function (array $row): string {
                    $r = $row['_row'];
                    $tags = '';
                    if (!empty($r['on_hold'])) {
                        $tags .= '<span class="sf-tag sf-tag-hold">Hold</span>';
                    }
                    if (!empty($r['is_priority'])) {
                        $tags .= '<span class="sf-tag sf-tag-priority">Priority</span>';
                    }
                    if (!empty($r['attention_required'])) {
                        $tags .= '<span class="sf-tag sf-tag-attention">Attention</span>';
                    }
                    return $tags !== '' ? $tags : '<span class="text-muted">—</span>';
                },
            ],
            'customer' => [
                'label'  => 'Customer',
                'render' => function (array $row) use ($e): string {
                    $r = $row['_row'];
                    $customer = trim((string) ($r['shipping_name'] ?? ''));
                    if ($customer === '') {
                        $customer = empty($r['is_paid']) ? 'Unpaid Order / N/A' : 'N/A';
                    }
                    return $e($customer);
                },
            ],
            'address' => [
                'label'  => 'Shipping address',
                'render' => function (array $row): string {
                    return $this->renderOrderAddressCell($row['_row']['shipping_address'] ?? null, $row['_row']);
                },
            ],
            'order_date' => [
                'label'  => 'Order Date',
                'render' => function (array $row) use ($e): string {
                    $created = (string) ($row['_row']['created_at'] ?? '');
                    $label = $created !== ''
                        ? date('d/m/Y H:i', strtotime($created) ?: time())
                        : '—';
                    return '<span class="text-nowrap small">' . $e($label) . '</span>';
                },
            ],
            'postcode' => [
                'label'  => 'Postcode',
                'render' => function (array $row) use ($e): string {
                    $postcode = $this->extractOrderPostcode($row['_row']['shipping_address'] ?? null);
                    return $e($postcode !== '' ? $postcode : '—');
                },
            ],
            'email' => [
                'label'  => 'Email Address',
                'render' => function (array $row) use ($e): string {
                    $r = $row['_row'];
                    $email = trim((string) ($r['buyer_email'] ?? ''));
                    if ($email === '') {
                        $addr = is_string($r['shipping_address'] ?? null)
                            ? (json_decode((string) $r['shipping_address'], true) ?: [])
                            : (is_array($r['shipping_address'] ?? null) ? $r['shipping_address'] : []);
                        $email = trim((string) ($addr['email'] ?? $addr['emailAddress'] ?? ''));
                    }
                    return $email !== ''
                        ? '<span class="small">' . $e($email) . '</span>'
                        : '<span class="text-muted">—</span>';
                },
            ],
            'total' => [
                'label'  => 'Total',
                'render' => static function (array $row): string {
                    return '£' . number_format((float) ($row['_row']['total'] ?? 0), 2);
                },
            ],
            'packaging' => [
                'label'  => 'Packaging',
                'render' => function (array $row) use ($e): string {
                    $name = trim((string) ($row['_row']['packaging_name'] ?? ''));
                    return $e($name !== '' ? $name : '—');
                },
            ],
            'packages' => [
                'label'  => 'Number of Packages',
                'render' => static function (array $row): string {
                    return (string) max(1, (int) ($row['_row']['package_count'] ?? 1));
                },
            ],
            'shipping' => [
                'label'  => 'Shipping Method',
                'render' => function (array $row) use ($e): string {
                    $method = trim((string) ($row['_row']['shipping_method'] ?? ''));
                    return $e($method !== '' ? $method : '—');
                },
            ],
            'weight' => [
                'label'  => 'Order Weight',
                'render' => static function (array $row): string {
                    return number_format((float) ($row['_row']['order_weight_kg'] ?? 0), 3) . ' kg';
                },
            ],
            'pickwave' => [
                'label'  => 'Pickwave ID',
                'render' => function (array $row) use ($e): string {
                    $pwId = (int) ($row['_row']['pickwave_id'] ?? 0);
                    if ($pwId <= 0) {
                        return '<span class="text-muted">—</span>';
                    }
                    return '<a href="' . $e($this->url->to('store/admin/pickwaves/view', ['id' => $pwId])) . '">'
                        . $pwId . '</a>';
                },
            ],
            'status' => [
                'label'  => 'Status',
                'render' => function (array $row) use ($e): string {
                    $r = $row['_row'];
                    $created = (string) ($r['created_at'] ?? '');
                    $tone = $this->orderStatusTone($r);
                    return '<div class="sf-status">'
                        . '<span class="sf-status-when">' . $e($this->formatOrderWhen($created)) . '</span>'
                        . '<span class="sf-pill sf-pill-' . $e($tone) . '">' . $e($this->orderStatusLabel($r)) . '</span>'
                        . '</div>';
                },
            ],
            'notes' => [
                'label'  => 'Notes',
                'render' => function (array $row) use ($e): string {
                    $notes = trim((string) ($row['_row']['notes'] ?? ''));
                    return $notes !== '' ? $e($notes) : '<span class="text-muted">—</span>';
                },
            ],
        ]);

        $datasetHtml = $dataset->render();

        // Snapshot active filters for saved-filter / tab URLs.
        $activeParams = array_filter([
            'q'            => trim((string) $this->request->get('q', 'string', '')),
            'channel_id'   => (int) $this->request->get('channel_id', 'int', 0),
            'status'       => trim((string) $this->request->get('status', 'string', '')),
            'packaging'    => trim((string) $this->request->get('packaging', 'string', '')),
            'shipping'     => trim((string) $this->request->get('shipping', 'string', '')),
            'view'         => $view,
            'saved_filter' => $appliedFilterId > 0 ? $appliedFilterId : '',
        ], static fn($v) => $v !== '' && $v !== 0 && $v !== '0');
        $rawCols = $this->request->get('c', 'raw', []);
        if (is_array($rawCols)) {
            $cParams = [];
            foreach ($rawCols as $k => $v) {
                $v = trim((string) $v);
                if ($v !== '' && (is_string($k) || is_int($k))) {
                    $key = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $k) ?? '';
                    if ($key !== '') {
                        $cParams[$key] = $v;
                    }
                }
            }
            if ($cParams !== []) {
                $activeParams['c'] = $cParams;
            }
        }

        echo $this->view->inline(function () use (
            $datasetHtml, $connected, $view, $packagingSizes, $shippingMethods,
            $savedFilterList, $appliedFilterId, $activeParams, $e
        ) {
            $this->view->assign('title', 'Orders');
            $this->view->block('right_sidebar', 'Orders');

            echo '<style>
.sf-orders-page{margin:-.25rem -0.25rem 0;color:#1f2937}
body.admin-body:has(.sf-orders-page) .admin-main{background:#f3f4f6}
body.admin-body:has(.sf-orders-page) .admin-main__inner{background:#f3f4f6;box-shadow:none;padding:1rem 1.15rem}
.sf-orders-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem;flex-wrap:wrap}
.sf-orders-head h1{font-size:1.35rem;font-weight:650;margin:0;letter-spacing:-.02em}
.sf-orders-tabs{display:flex;align-items:center;gap:.15rem;flex-wrap:wrap}
.sf-orders-tabs a{color:#6b7280;text-decoration:none;padding:.35rem .7rem;border-radius:999px;font-size:.84rem;font-weight:500}
.sf-orders-tabs a:hover{background:#f3f4f6;color:#111827}
.sf-orders-tabs a.is-on{background:#dbeafe;color:#1d4ed8}
.sf-orders-create{background:#2563eb;border:0;color:#fff;border-radius:999px;padding:.45rem 1rem;font-weight:600;font-size:.875rem;text-decoration:none}
.sf-orders-create:hover{background:#1d4ed8;color:#fff}
.sf-orders-toolbar{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:.55rem .85rem;margin-bottom:.75rem;display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;position:relative}
.sf-col-chooser{display:none;flex-direction:column;width:18rem;max-width:calc(100vw - 1rem);background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 12px 32px rgba(15,23,42,.18);padding:.75rem;z-index:1080;overflow:hidden}
.sf-col-chooser-list{flex:1 1 auto;min-height:0;overflow:auto;padding-right:.15rem}
.sf-chan{display:inline-flex;align-items:center;gap:.45rem}
.sf-chan-mark{width:1.35rem;height:1.35rem;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:.62rem;font-weight:700;flex:none}
.sf-chan-amazon{background:#232f3e}
.sf-chan-ebay{background:#e53238}
.sf-chan-shopify{background:#96bf48}
.sf-chan-other{background:#64748b}
.sf-dot{width:.55rem;height:.55rem;border-radius:50%;display:inline-block;margin-right:.4rem;vertical-align:middle}
.sf-dot-ok{background:#22c55e}
.sf-dot-wait{background:#f59e0b}
.sf-dot-bad{background:#ef4444}
.sf-dot-off{background:#94a3b8}
.sf-status{display:flex;flex-direction:column;align-items:flex-end;gap:.2rem;min-width:7rem}
.sf-status-when{font-size:.72rem;color:#6b7280}
.sf-pill{display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;padding:.12rem .55rem;font-size:.7rem;font-weight:600;white-space:nowrap}
.sf-pill-ok{background:#ecfdf3;color:#16794c}
.sf-pill-wait{background:#fff7ed;color:#c2410c}
.sf-pill-bad{background:#fef2f2;color:#b91c1c}
.sf-pill-off{background:#f1f5f9;color:#475569}
.sf-order-no{font-weight:600;color:#111827;text-decoration:none}
.sf-order-no:hover{color:#2563eb}
.sf-sku-link{color:#111827;text-decoration:none}
.sf-sku-link:hover{color:#2563eb;text-decoration:underline}
.sf-muted{color:#6b7280}
.sf-addr{display:flex;align-items:flex-start;gap:.35rem;line-height:1.25;max-width:18rem}
.sf-flag{width:16px;height:11px;display:inline-block;flex:none;margin-top:.15rem;border-radius:1px;box-shadow:0 0 0 1px rgba(15,23,42,.08)}
.sf-flag-gb{background:
    linear-gradient(#fff 0 0) 50% 50%/100% 33% no-repeat,
    linear-gradient(#fff 0 0) 50% 50%/33% 100% no-repeat,
    linear-gradient(60deg,transparent 46%,#fff 46%,#fff 54%,transparent 54%),
    linear-gradient(-60deg,transparent 46%,#fff 46%,#fff 54%,transparent 54%),
    linear-gradient(#c8102e 0 0) 50% 50%/100% 20% no-repeat,
    linear-gradient(#c8102e 0 0) 50% 50%/20% 100% no-repeat,
    #012169}
.sf-flag-us{background:repeating-linear-gradient(#b22234 0 8%,#fff 8% 16%)}
.sf-flag-ie{background:linear-gradient(90deg,#169b62 33.3%,#fff 33.3% 66.6%,#ff883e 66.6%)}
.sf-flag-other{background:#e5e7eb}
.sf-tag{display:inline-flex;align-items:center;border-radius:.3rem;padding:.08rem .4rem;font-size:.65rem;font-weight:650;margin:.08rem .12rem .08rem 0}
.sf-tag-hold{background:#fef3c7;color:#92400e}
.sf-tag-priority{background:#fee2e2;color:#991b1b}
.sf-tag-attention{background:#dbeafe;color:#1e40af}
</style>';

            $tabUrl = function (string $name) use ($activeParams): string {
                $params = $activeParams;
                unset($params['view'], $params['status'], $params['page'], $params['saved_filter']);
                if ($name !== '') {
                    $params['view'] = $name;
                }
                return $this->url->to('store/admin/orders', $params);
            };
            $tabOn = static fn(string $name): string => ($view === $name ? 'is-on' : '');

            echo '<div class="sf-orders-page">';
            echo '<div class="sf-orders-head">';
            echo '<div class="d-flex align-items-center gap-3 flex-wrap">';
            echo '<h1>Orders</h1>';
            echo '<nav class="sf-orders-tabs">';
            echo '<a class="' . $tabOn('') . '" href="' . $e($tabUrl('')) . '">All</a>';
            echo '<a class="' . $tabOn('ready') . '" href="' . $e($tabUrl('ready')) . '">Ready to pick</a>';
            echo '<a class="' . $tabOn('priority') . '" href="' . $e($tabUrl('priority')) . '">Priority</a>';
            echo '<a class="' . $tabOn('today') . '" href="' . $e($tabUrl('today')) . '">Placed today</a>';
            echo '<a class="' . $tabOn('unpaid') . '" href="' . $e($tabUrl('unpaid')) . '">Unpaid</a>';
            echo '</nav></div>';
            echo '<a class="sf-orders-create" href="' . $e($this->url->to('store/admin/orders/new')) . '">Create order</a>';
            echo '</div>';

            echo '<form method="post" action="' . $e($this->url->to('store/admin/orders/sync'))
               . '" class="d-flex flex-wrap align-items-center gap-2 mb-2">';
            echo $this->request->csrfField();
            echo '<select name="channel_id" class="form-select form-select-sm" style="width:auto">';
            echo '<option value="0">All connected channels</option>';
            foreach ($connected as $c) {
                echo '<option value="' . (int) $c->id . '">' . $e($c->label) . '</option>';
            }
            echo '</select>';
            echo '<select name="days" class="form-select form-select-sm" style="width:auto">';
            foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $d => $label) {
                $sel = $d === 30 ? ' selected' : '';
                echo '<option value="' . $d . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="btn btn-link btn-sm text-decoration-none px-1">'
               . '<i class="bi bi-cloud-download me-1"></i>Pull from channels</button>';
            echo '</form>';

            echo '<form id="sf-orders-save-filter" method="post" action="'
               . $e($this->url->to('store/admin/orders/save-filter')) . '" class="d-none">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="name" id="sf-orders-save-filter-name" value="">';
            foreach ($activeParams as $k => $v) {
                if ($k === 'saved_filter') {
                    continue;
                }
                if (is_array($v)) {
                    foreach ($v as $ck => $cv) {
                        echo '<input type="hidden" name="params[' . $e((string) $k) . ']['
                           . $e((string) $ck) . ']" value="' . $e((string) $cv) . '">';
                    }
                    continue;
                }
                echo '<input type="hidden" name="params[' . $e((string) $k) . ']" value="'
                   . $e((string) $v) . '">';
            }
            echo '</form>';
            echo '<form id="sf-orders-delete-filter" method="post" action="'
               . $e($this->url->to('store/admin/orders/delete-filter')) . '" class="d-none">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="id" id="sf-orders-delete-filter-id" value="">';
            echo '</form>';

            echo '<form method="post" action="' . $e($this->url->to('store/admin/orders/bulk'))
               . '" id="sf-orders-bulk">';
            echo $this->request->csrfField();
            echo '<div class="sf-orders-toolbar">';
            echo '<div class="d-flex flex-wrap align-items-center gap-2">';
            echo '<select name="action" id="sf-orders-bulk-action" class="form-select form-select-sm"'
               . ' style="width:auto;min-width:16rem">';
            echo '<option value="">Bulk Action</option>';
            foreach ([
                'alter_packaging'   => 'Alter packaging size',
                'alter_shipping'    => 'Alter shipping method',
                'cancel'            => 'Cancel orders',
                'cancel_keep'       => 'Cancel orders - Keep Stock Allocated',
                'cancel_channel'    => 'Cancel on channel + local',
                'clone'             => 'Clone orders',
                'delete'            => 'Delete orders',
                'pre_despatch'      => 'Pre-Despatch orders',
                'despatch'          => 'Despatch orders',
                'export'            => 'Export order information',
                'hold'              => 'Hold orders',
                'unhold'            => 'Unhold orders',
                'attention'         => 'Mark orders as attention required',
                'paid'              => 'Mark orders as paid',
                'priority'          => 'Mark orders as priority',
                'remove_attention'  => 'Remove attention required',
            ] as $val => $label) {
                echo '<option value="' . $e($val) . '">' . $e($label) . '</option>';
            }
            echo '</select>';
            echo '<select name="packaging_size_id" id="sf-orders-bulk-packaging"'
               . ' class="form-select form-select-sm d-none" style="width:auto;min-width:10rem">';
            echo '<option value="">Packaging…</option>';
            foreach ($packagingSizes as $p) {
                echo '<option value="' . (int) $p['id'] . '">' . $e((string) $p['name']) . '</option>';
            }
            echo '</select>';
            echo '<select name="shipping_method_id" id="sf-orders-bulk-shipping"'
               . ' class="form-select form-select-sm d-none" style="width:auto;min-width:12rem">';
            echo '<option value="">Shipping method…</option>';
            foreach ($shippingMethods as $m) {
                $label = trim((string) $m['company']);
                $label = ($label !== '' ? $label . ' — ' : '') . (string) $m['method'];
                echo '<option value="' . (int) $m['id'] . '">' . $e($label) . '</option>';
            }
            echo '</select>';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="sf-orders-select-page">Select page</button>';
            echo '<button type="submit" class="btn btn-sm btn-primary" id="sf-orders-bulk-confirm" disabled>Confirm</button>';
            echo '<span class="small text-muted" id="sf-orders-bulk-count">0 selected</span>';
            echo '</div>';
            echo '<div class="d-flex flex-wrap align-items-center gap-2">';
            echo '<select id="sf-orders-saved-filters" class="form-select form-select-sm"'
               . ' style="width:auto;min-width:11rem" data-applied="' . (int) $appliedFilterId . '">';
            echo '<option value="">Saved Filters</option>';
            echo '<option value="__save__">Save Filters…</option>';
            if ($savedFilterList !== []) {
                echo '<option value="__delete__">Delete current saved filter…</option>';
            }
            foreach ($savedFilterList as $sf) {
                $sid = (int) $sf['id'];
                $url = $this->url->to('store/admin/orders', $sf['params'] + ['saved_filter' => $sid]);
                $sel = $appliedFilterId === $sid ? ' selected' : '';
                echo '<option value="' . $sid . '" data-url="' . $e($url) . '"' . $sel . '>'
                   . $e($sf['name']) . '</option>';
            }
            echo '</select>';
            echo '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/orders', $view !== '' ? ['view' => $view] : [])) . '">Reset</a>';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="sf-orders-col-toggle" title="Columns">'
               . '<i class="bi bi-wrench"></i></button>';
            echo '</div>'; // right controls

            $colChooser = [
                'order'       => ['label' => 'Order', 'default' => true, 'locked' => true],
                'channel'     => ['label' => 'Channel', 'default' => true, 'locked' => true],
                'channel_ref' => ['label' => 'Channel order details', 'default' => false],
                'items'       => ['label' => 'Items', 'default' => false],
                'skus'        => ['label' => 'Product SKUs', 'default' => true],
                'tags'        => ['label' => 'Tags', 'default' => false],
                'customer'    => ['label' => 'Customer', 'default' => true],
                'address'     => ['label' => 'Shipping address', 'default' => true],
                'order_date'  => ['label' => 'Order Date', 'default' => true],
                'postcode'    => ['label' => 'Postcode', 'default' => true],
                'email'       => ['label' => 'Email Address', 'default' => true],
                'total'       => ['label' => 'Total', 'default' => true],
                'packaging'   => ['label' => 'Packaging', 'default' => false],
                'packages'    => ['label' => 'Number of Packages', 'default' => false],
                'shipping'    => ['label' => 'Shipping Method', 'default' => false],
                'weight'      => ['label' => 'Order Weight', 'default' => false],
                'pickwave'    => ['label' => 'Pickwave ID', 'default' => false],
                'status'      => ['label' => 'Status', 'default' => true],
                'notes'       => ['label' => 'Notes', 'default' => false],
            ];
            echo '<div id="sf-orders-col-panel" class="sf-col-chooser">';
            echo '<div class="fw-semibold small mb-2">Columns</div>';
            echo '<label class="d-flex align-items-center gap-2 small mb-2">'
               . '<input type="checkbox" class="form-check-input" id="sf-orders-col-select-all"> Select all</label>';
            echo '<div class="sf-col-chooser-list">';
            foreach ($colChooser as $key => $meta) {
                $checked = !empty($meta['default']) ? ' checked' : '';
                $disabled = !empty($meta['locked']) ? ' disabled' : '';
                echo '<label class="d-flex align-items-center gap-2 small mb-1'
                   . (!empty($meta['locked']) ? ' text-muted' : '') . '">'
                   . '<input type="checkbox" class="form-check-input sf-orders-col-check" data-col="'
                   . $e($key) . '"' . $checked . $disabled . '>'
                   . $e((string) $meta['label']) . '</label>';
            }
            echo '</div></div>'; // list + panel
            echo '</div></form>'; // toolbar + bulk form

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
  const KEY = 'sf.orders.cols.v6';
  const defaults = {$defaultsJson};
  const locked = new Set({$lockedJson});
  const bulkForm = document.getElementById('sf-orders-bulk');
  const bulkAction = document.getElementById('sf-orders-bulk-action');
  const confirmBtn = document.getElementById('sf-orders-bulk-confirm');
  const countEl = document.getElementById('sf-orders-bulk-count');
  const packSel = document.getElementById('sf-orders-bulk-packaging');
  const shipSel = document.getElementById('sf-orders-bulk-shipping');
  const selectPage = document.getElementById('sf-orders-select-page');
  const panel = document.getElementById('sf-orders-col-panel');
  const toggle = document.getElementById('sf-orders-col-toggle');
  const checks = () => Array.from(document.querySelectorAll('.sf-orders-col-check'));
  const rowChecks = () => Array.from(document.querySelectorAll('.sf-orders-row'));

  function load() {
    try {
      const raw = localStorage.getItem(KEY);
      if (!raw) return defaults.slice();
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return defaults.slice();
      const set = new Set(parsed.concat(Array.from(locked)));
      return Array.from(set);
    } catch (e) { return defaults.slice(); }
  }
  function apply(visible) {
    const set = new Set(visible.concat(Array.from(locked)));
    checks().forEach(cb => {
      if (locked.has(cb.dataset.col)) {
        cb.checked = true;
        return;
      }
      cb.checked = set.has(cb.dataset.col);
    });
    // Only toggle table cells/headers — never the chooser checkboxes (they also have data-col).
    document.querySelectorAll('.sf-orders-page table [data-col]').forEach(el => {
      const col = el.getAttribute('data-col');
      if (!col || col === 'sel') return;
      if (el.classList.contains('sf-orders-col-check')) return;
      const show = set.has(col);
      el.style.display = show ? 'table-cell' : 'none';
    });
  }
  function save() {
    const visible = checks().filter(c => c.checked).map(c => c.dataset.col);
    localStorage.setItem(KEY, JSON.stringify(visible));
    apply(visible);
  }

  function syncBulk() {
    const n = rowChecks().filter(c => c.checked).length;
    if (countEl) countEl.textContent = n + ' selected';
    const action = bulkAction ? bulkAction.value : '';
    if (confirmBtn) confirmBtn.disabled = !action || (action !== 'export' && n === 0);
    if (packSel) packSel.classList.toggle('d-none', action !== 'alter_packaging');
    if (shipSel) shipSel.classList.toggle('d-none', action !== 'alter_shipping');
  }

  function placePanel() {
    if (!toggle || !panel) return;
    const r = toggle.getBoundingClientRect();
    const spaceBelow = window.innerHeight - r.bottom - 12;
    const spaceAbove = r.top - 12;
    panel.style.position = 'fixed';
    panel.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
    panel.style.left = 'auto';
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
    const all = document.getElementById('sf-orders-col-select-all');
    if (!all) return;
    const toggleable = checks().filter(c => !locked.has(c.dataset.col));
    const on = toggleable.filter(c => c.checked).length;
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
    toggle.addEventListener('click', (e) => {
      e.stopPropagation();
      if (panel.style.display === 'flex') closePanel();
      else openPanel();
    });
    checks().forEach(cb => cb.addEventListener('change', () => { save(); syncSelectAll(); }));
    const selectAll = document.getElementById('sf-orders-col-select-all');
    if (selectAll) {
      selectAll.addEventListener('change', () => {
        checks().forEach(c => {
          if (!locked.has(c.dataset.col)) c.checked = selectAll.checked;
        });
        save();
        syncSelectAll();
      });
    }
    window.addEventListener('resize', () => {
      if (panel.style.display === 'flex') placePanel();
    });
    document.addEventListener('click', e => {
      if (!panel.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
        closePanel();
      }
    });
  }

  rowChecks().forEach(cb => cb.addEventListener('change', syncBulk));
  if (selectPage) {
    selectPage.addEventListener('click', () => {
      const all = rowChecks();
      const allOn = all.length > 0 && all.every(c => c.checked);
      all.forEach(c => { c.checked = !allOn; });
      syncBulk();
    });
  }
  if (bulkAction) bulkAction.addEventListener('change', syncBulk);
  if (bulkForm) {
    bulkForm.addEventListener('submit', function (e) {
      const action = bulkAction ? bulkAction.value : '';
      const n = rowChecks().filter(c => c.checked).length;
      if (!action) { e.preventDefault(); return; }
      if (action !== 'export' && n === 0) {
        e.preventDefault();
        alert('Select at least one order.');
        return;
      }
      if (action === 'delete' || action === 'cancel' || action === 'cancel_keep' || action === 'cancel_channel') {
        if (!confirm('Apply "' + bulkAction.options[bulkAction.selectedIndex].text + '" to ' + n + ' order(s)?')) {
          e.preventDefault();
        }
      }
    });
  }

  const savedSel = document.getElementById('sf-orders-saved-filters');
  if (savedSel) {
    savedSel.addEventListener('change', function () {
      const v = this.value;
      if (v === '__save__') {
        const name = prompt('Name for this saved filter:');
        if (!name) { this.value = this.getAttribute('data-applied') || ''; return; }
        document.getElementById('sf-orders-save-filter-name').value = name;
        document.getElementById('sf-orders-save-filter').submit();
        return;
      }
      if (v === '__delete__') {
        const applied = this.getAttribute('data-applied') || '';
        if (!applied) { alert('No saved filter selected.'); this.value = ''; return; }
        if (!confirm('Delete this saved filter?')) { this.value = applied; return; }
        document.getElementById('sf-orders-delete-filter-id').value = applied;
        document.getElementById('sf-orders-delete-filter').submit();
        return;
      }
      const opt = this.selectedOptions[0];
      const url = opt ? opt.getAttribute('data-url') : '';
      if (url) window.location = url;
    });
  }

  syncBulk();
})();
</script>
HTML;
        });
    }

    /**
     * Map Dataset state (+ view tab) onto OrderRepository filters.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function datasetFiltersToOrderFilters(array $state, string $view): array
    {
        $cols = is_array($state['columns'] ?? null) ? $state['columns'] : [];
        $f = is_array($state['filters'] ?? null) ? $state['filters'] : [];

        $filters = [
            'q'           => trim((string) ($state['q'] ?? '')),
            'channel_id'  => (int) ($f['channel_id'] ?? 0),
            'status'      => trim((string) ($f['status'] ?? '')),
            'f_order'     => trim((string) ($cols['order'] ?? '')),
            'f_sku'       => trim((string) ($cols['skus'] ?? '')),
            'f_customer'  => trim((string) ($cols['customer'] ?? '')),
            'f_address'   => trim((string) ($cols['address'] ?? '')),
            'f_postcode'  => trim((string) ($cols['postcode'] ?? '')),
            'f_email'     => trim((string) ($cols['email'] ?? '')),
            'f_total'     => trim((string) ($cols['total'] ?? '')),
            'f_packages'  => trim((string) ($cols['packages'] ?? '')),
            'f_weight'    => trim((string) ($cols['weight'] ?? '')),
            'f_pickwave'  => trim((string) ($cols['pickwave'] ?? '')),
            'f_notes'     => trim((string) ($cols['notes'] ?? '')),
            'f_date'      => trim((string) ($cols['order_date'] ?? '')),
            'f_packaging' => trim((string) ($f['packaging'] ?? '')),
            'f_shipping'  => trim((string) ($f['shipping'] ?? '')),
            'f_status'    => trim((string) ($f['status'] ?? '')),
            'f_priority'  => '',
            'f_paid'      => '',
            'f_hold'      => '',
            'view'        => $view,
        ];

        if ($filters['f_status'] !== '') {
            $filters['status'] = $filters['f_status'];
        }

        if ($view === 'ready') {
            $filters['status'] = $filters['f_status'] !== '' ? $filters['f_status'] : 'NOT_STARTED';
            $filters['f_status'] = $filters['status'];
            $filters['f_paid'] = '1';
            $filters['f_hold'] = '0';
        } elseif ($view === 'priority') {
            $filters['f_priority'] = '1';
        } elseif ($view === 'today' && $filters['f_date'] === '') {
            $filters['f_date'] = date('Y-m-d');
        } elseif ($view === 'unpaid') {
            $filters['f_paid'] = '0';
        }

        return $filters;
    }

    public function newAction(): void
    {
        $channels = array_values(array_filter(
            $this->channels->all(),
            static fn($c) => $c->enabled
        ));

        if ($this->request->isPost()) {
            try {
                $this->request->checkToken();
                $channelId = (int) $this->request->post('channel_id', 'int', 0);
                $ref = trim((string) $this->request->post('external_order_id', 'string', ''));
                $created = $this->orders->createManual($channelId, $ref);
                $this->notify->success('Order created.');
                redirect_to('store/admin/orders/view', ['id' => $created['id']]);
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
                redirect_to('store/admin/orders/new');
            }
            return;
        }

        echo $this->view->inline(function () use ($channels) {
            $this->view->assign('title', 'New Order');
            $this->view->block('right_sidebar', 'Orders');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="mb-3"><a class="text-decoration-none small" href="'
               . $e($this->url->to('store/admin/orders')) . '">&larr; Orders</a></div>';
            echo '<h1 class="h3 mb-4">New Order</h1>';
            echo '<form method="post" class="card border-0 shadow-sm" style="max-width:32rem">'
               . '<div class="card-body">';
            echo $this->request->csrfField();
            echo '<div class="mb-3"><label class="form-label">Channel</label>'
               . '<select name="channel_id" class="form-select" required>';
            echo '<option value="">Select channel…</option>';
            foreach ($channels as $c) {
                echo '<option value="' . (int) $c->id . '">' . $e($c->label) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="mb-3"><label class="form-label">Order reference (optional)</label>'
               . '<input name="external_order_id" class="form-control" maxlength="64"'
               . ' placeholder="Leave blank to auto-generate"></div>';
            echo '<button type="submit" class="btn btn-success">Create order</button>';
            echo '</div></form>';
        });
    }

    public function bulkAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('store/admin/orders');
            return;
        }

        $action = trim((string) $this->request->post('action', 'string', ''));
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->request->post('ids', 'raw', [])),
            static fn(int $id) => $id > 0
        )));

        $bulk = new OrderBulkService(
            $this->db,
            $this->orders,
            new OrderDespatchService($this->db, $this->channels, $this->factory),
            $this->packaging,
            $this->methods,
            new OrderChannelActionService(
                $this->db,
                $this->channels,
                $this->factory,
                $this->orders,
                new SyncLogRepository($this->db),
                new OrderAuditRepository($this->db),
            ),
        );

        try {
            $result = $bulk->run($action, $ids, [
                'packaging_size_id'  => (int) $this->request->post('packaging_size_id', 'int', 0),
                'shipping_method_id' => (int) $this->request->post('shipping_method_id', 'int', 0),
            ]);
            if (isset($result['export'])) {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="orders-export-' . date('Ymd-His') . '.csv"');
                echo $result['export'];
                exit;
            }
            $this->notify->success($result['message']);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/orders');
    }

    public function saveFilterAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }
        $userId = (int) ($this->auth->id() ?? 0);
        $name = trim((string) $this->request->post('name', 'string', ''));
        $params = (array) $this->request->post('params', 'raw', []);
        try {
            $id = $this->savedFilters->save($userId, $name, $params);
            $filter = $this->savedFilters->findForUser($id, $userId);
            $this->notify->success("Saved filter '{$name}'.");
            redirect_to('store/admin/orders', ($filter['params'] ?? []) + [
                'saved_filter' => $id,
            ]);
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            redirect_to('store/admin/orders', $this->savedFilters->sanitizeParams($params));
        }
    }

    public function deleteFilterAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
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
        redirect_to('store/admin/orders');
    }

    /** @param array<string, mixed> $row */
    private function orderStatusLabel(array $row): string
    {
        $status = strtoupper((string) ($row['status'] ?? ''));
        if ($status === 'CANCELLED') {
            return 'Cancelled';
        }
        if ($status === 'FULFILLED') {
            return 'Complete [Despatched]';
        }
        if ($status === 'IN_PROGRESS') {
            return 'In progress';
        }
        if (empty($row['is_paid'])) {
            return 'Awaiting Payment';
        }
        if (!empty($row['pre_despatched'])) {
            return 'Complete [Pre-Despatched]';
        }
        return 'Complete [Ready To Pick]';
    }

    // =========================================================================
    // View single order
    // =========================================================================

    public function viewAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);

        $data = $this->orders->find($id);
        if ($data === null) {
            throw new NotFoundException('Order not found.');
        }

        $order   = $data['order'];
        $items   = $data['items'];
        $channel = $this->channels->find((int) $order['channel_id']);

        $canShip = $channel !== null
            && $channel->isConnected()
            && !in_array(strtoupper((string) $order['status']), ['FULFILLED', 'CANCELLED', 'CANCELED'], true)
            && trim((string) ($order['tracking_number'] ?? '')) === '';

        $missingLineIds = false;
        foreach ($items as $item) {
            if (trim((string) ($item['external_line_item_id'] ?? '')) === '') {
                $missingLineIds = true;
                break;
            }
        }

        $customers = new CustomerRepository($this->db);
        $customer = null;
        $buyerUser = trim((string) ($order['buyer_username'] ?? ''));
        $buyerEmail = trim((string) ($order['buyer_email'] ?? ''));
        if ($buyerUser !== '') {
            $customer = $customers->findByReference($buyerUser);
        }
        if ($customer === null && $buyerEmail !== '') {
            $customer = $customers->findByEmail($buyerEmail);
        }

        $packagingSizes = $this->packaging->all();
        $shippingMethods = $this->methods->all();
        $warehouses = new WarehouseRepository($this->db);
        $warehouses->ensure('Default');
        $warehouseList = $warehouses->all('', true);

        $auditRows = [];
        $returnRows = [];
        try {
            $auditRows = (new OrderAuditRepository($this->db))->forOrder($id, 80);
        } catch (\Throwable $e) {
            error_log('Order audit load #' . $id . ': ' . $e->getMessage());
        }
        try {
            $returnRows = (new ReturnRepository($this->db))->forOrder($id);
        } catch (\Throwable $e) {
            error_log('Order returns load #' . $id . ': ' . $e->getMessage());
        }

        // Default warehouse from first product if unset.
        $orderWarehouse = trim((string) ($order['warehouse'] ?? ''));
        if ($orderWarehouse === '') {
            foreach ($items as $item) {
                $wh = trim((string) ($item['warehouse'] ?? ''));
                if ($wh !== '') {
                    $orderWarehouse = $wh;
                    break;
                }
            }
            if ($orderWarehouse === '') {
                $orderWarehouse = 'Default';
            }
        }

        echo $this->view->inline(function () use (
            $order,
            $items,
            $channel,
            $canShip,
            $missingLineIds,
            $customer,
            $packagingSizes,
            $shippingMethods,
            $warehouseList,
            $orderWarehouse,
            $auditRows,
            $returnRows
        ) {
            $this->view->assign('title', 'Edit Order ' . $order['external_order_id']);
            $this->view->block('right_sidebar', 'Orders');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            $addr = json_decode((string) ($order['shipping_address'] ?? '{}'), true);
            if (!is_array($addr)) {
                $addr = [];
            }
            $billRaw = $order['billing_address'] ?? null;
            $bill = [];
            if (is_string($billRaw) && $billRaw !== '') {
                $decoded = json_decode($billRaw, true);
                $bill = is_array($decoded) ? $decoded : [];
            } elseif (is_array($billRaw)) {
                $bill = $billRaw;
            }
            if ($bill === [] && $customer !== null) {
                $bill = [
                    'fullName'      => (string) ($customer['name'] ?? ''),
                    'addressLine1'  => (string) ($customer['bill_address1'] ?? ''),
                    'addressLine2'  => (string) ($customer['bill_address2'] ?? ''),
                    'city'          => (string) ($customer['bill_city'] ?? ''),
                    'stateOrProvince' => (string) ($customer['bill_county'] ?? ''),
                    'postalCode'    => (string) ($customer['bill_postcode'] ?? ''),
                    'countryCode'   => (string) ($customer['bill_country'] ?? 'GB'),
                    'primaryPhone'  => (string) ($customer['phone'] ?? ''),
                ];
            }
            if ($bill === []) {
                $bill = $addr;
            }

            $shipBy = trim((string) ($addr['_ship_by'] ?? $addr['shipBy'] ?? ''));
            $showInvoice = $channel !== null
                && ChannelSettings::bool($channel, 'create_invoice_printing', false);
            $currency = (string) (($order['currency'] ?? '') !== '' ? $order['currency'] : 'GBP');
            $status = strtoupper((string) ($order['status'] ?? ''));
            $onHold = !empty($order['on_hold']);
            $isPriority = !empty($order['is_priority']);
            $attention = !empty($order['attention_required']);
            $isPaid = !isset($order['is_paid']) || !empty($order['is_paid']);
            $pickwaveId = (int) ($order['pickwave_id'] ?? 0);
            $tracking = trim((string) ($order['tracking_number'] ?? ''));
            $email = trim((string) ($order['buyer_email'] ?? ''));
            $phone = trim((string) ($addr['primaryPhone'] ?? $addr['phone'] ?? ($customer['phone'] ?? '')));
            $shipName = trim((string) ($order['shipping_name'] ?? ''));
            if ($shipName === '') {
                $shipName = trim((string) ($addr['fullName'] ?? $addr['name'] ?? ''));
            }

            $shipments = new ShipmentRepository($this->db);
            $allShipments = $shipments->forOrder((int) $order['id']);
            $shipment  = $shipments->findLatestForOrder((int) $order['id']);
            $hasLabel = $shipment !== null && trim((string) ($shipment['label_path'] ?? '')) !== '';
            $activeShipments = array_values(array_filter(
                $allShipments,
                static fn(array $s): bool => strtolower((string) ($s['status'] ?? '')) !== 'voided'
            ));

            $itemsSubtotal = 0.0;
            $itemsQty = 0;
            foreach ($items as $item) {
                $q = max(1, (int) ($item['order_qty'] ?? $item['qty'] ?? 1));
                $itemsSubtotal += $q * (float) ($item['price'] ?? 0);
                $itemsQty += $q;
            }
            $fee = (float) ($order['channel_fee'] ?? 0);
            $shipCost = (float) ($order['shipping_cost'] ?? 0);
            $disc = (float) ($order['discount_amount'] ?? 0);
            $stax = (float) ($order['shipping_tax'] ?? 0);

            $fmtDate = static function (string $raw): string {
                if ($raw === '') {
                    return 'Not yet set';
                }
                $ts = strtotime($raw);
                return $ts ? date('d/m/Y @ H:i', $ts) : $raw;
            };

            echo '<style>
.sf-od-panel{border:1px solid #d9dee7;border-radius:.35rem;background:#fff;margin-bottom:1rem;overflow:hidden}
.sf-od-panel-h{background:#1e4d8c;color:#fff;padding:.5rem .9rem;font-weight:600;font-size:.95rem}
.sf-od-panel-b{padding:.9rem 1rem}
.sf-od-meta dt{width:9rem;float:left;clear:left;color:#6c757d;font-weight:500;font-size:.875rem}
.sf-od-meta dd{margin-left:9.5rem;margin-bottom:.35rem;font-size:.875rem}
.sf-od-addr{border:1px solid #e9ecef;border-radius:.3rem;padding:.75rem;background:#f8f9fa;min-height:8rem}
</style>';

            echo '<div class="mb-2"><a class="small text-decoration-none" href="'
               . $e($this->url->to('store/admin/orders')) . '">&lt;&lt; Back to Overview</a></div>';

            // Action bar
            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<h1 class="h3 mb-0">Edit Order</h1>';
            echo '<div class="d-flex flex-wrap gap-2">';
            echo '<form method="post" class="d-inline" action="'
               . $e($this->url->to('store/admin/orders/clone', ['id' => $order['id']])) . '">'
               . $this->request->csrfField()
               . '<button type="submit" class="btn btn-outline-secondary btn-sm">'
               . '<i class="bi bi-copy me-1"></i>Clone Order</button></form>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/orders/invoice', ['id' => $order['id']]))
               . '" target="_blank"><i class="bi bi-receipt me-1"></i>Export Invoice</a>';
            if ($hasLabel) {
                echo '<a class="btn btn-outline-secondary btn-sm" href="'
                   . $e($this->url->to('store/admin/orders/label', ['id' => $order['id']]))
                   . '" target="_blank"><i class="bi bi-printer me-1"></i>Despatch Label</a>';
            }
            if ($email !== '') {
                echo '<a class="btn btn-outline-secondary btn-sm" href="mailto:' . $e($email) . '">'
                   . '<i class="bi bi-envelope me-1"></i>Email Customer</a>';
            }
            echo '<form method="post" class="d-inline" action="'
               . $e($this->url->to('store/admin/orders/delete', ['id' => $order['id']])) . '"'
               . ' onsubmit="return confirm(\'Delete this order permanently?\');">'
               . $this->request->csrfField()
               . '<button type="submit" class="btn btn-outline-danger btn-sm">'
               . '<i class="bi bi-trash me-1"></i>Delete Order</button></form>';
            echo '<button type="submit" form="sf-order-edit" class="btn btn-primary btn-sm">'
               . '<i class="bi bi-check-lg me-1"></i>Save</button>';
            echo '</div></div>';

            echo '<form method="post" id="sf-order-edit" action="'
               . $e($this->url->to('store/admin/orders/update', ['id' => $order['id']])) . '">';
            echo $this->request->csrfField();

            echo '<div class="row g-3">';

            // Order information
            echo '<div class="col-lg-6"><div class="sf-od-panel"><div class="sf-od-panel-h">Order information</div>'
               . '<div class="sf-od-panel-b">';
            echo '<dl class="sf-od-meta mb-3">';
            echo '<dt>Store ID</dt><dd>' . (int) $order['id'] . '</dd>';
            echo '<dt>Channel order</dt><dd><code>' . $e((string) $order['external_order_id']) . '</code></dd>';
            echo '<dt>Channel</dt><dd>'
               . $e((string) ($channel->label ?? $channel->name ?? '—'))
               . ($channel !== null ? ' <span class="badge text-bg-light border">' . $e($channel->type) . '</span>' : '')
               . '</dd>';
            echo '</dl>';
            echo '<div class="mb-2"><label class="form-label small" for="order_status">Order status</label>'
               . '<select name="status" id="order_status" class="form-select form-select-sm">';
            foreach ([
                'NOT_STARTED' => 'Complete [Ready To Pick]',
                'IN_PROGRESS' => 'In progress',
                'PARTIALLY_SHIPPED' => 'Partially shipped',
                'FULFILLED'   => 'Fulfilled / Despatched',
                'CANCELLED'   => 'Cancelled',
            ] as $val => $label) {
                $sel = $status === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            if ($status !== '' && !in_array($status, ['NOT_STARTED', 'IN_PROGRESS', 'PARTIALLY_SHIPPED', 'FULFILLED', 'CANCELLED'], true)) {
                echo '<option value="' . $e($status) . '" selected>' . $e($status) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="d-flex flex-wrap gap-3">';
            foreach ([
                'is_priority'        => ['Priority', $isPriority],
                'attention_required' => ['Attention required', $attention],
                'on_hold'            => ['Hold order', $onHold],
                'is_paid'            => ['Paid', $isPaid],
                'pre_despatched'     => ['Pre-despatched', !empty($order['pre_despatched'])],
            ] as $name => [$label, $checked]) {
                echo '<div class="form-check mb-0">'
                   . '<input class="form-check-input" type="checkbox" name="' . $e($name) . '" value="1" id="flag_'
                   . $e($name) . '"' . ($checked ? ' checked' : '') . '>'
                   . '<label class="form-check-label small" for="flag_' . $e($name) . '">'
                   . $e($label) . '</label></div>';
            }
            echo '</div></div></div></div>';

            // Process details
            echo '<div class="col-lg-6"><div class="sf-od-panel"><div class="sf-od-panel-h">Order process details</div>'
               . '<div class="sf-od-panel-b"><dl class="sf-od-meta mb-0">';
            echo '<dt>Order date</dt><dd>' . $e($fmtDate((string) ($order['created_at'] ?? ''))) . '</dd>';
            echo '<dt>Imported</dt><dd>'
               . $e($fmtDate((string) (($order['synced_at'] ?? '') !== '' ? $order['synced_at'] : ($order['created_at'] ?? ''))))
               . '</dd>';
            echo '<dt>Paid</dt><dd>' . ($isPaid ? $e($fmtDate((string) ($order['created_at'] ?? ''))) : 'Not yet set') . '</dd>';
            echo '<dt>Despatched</dt><dd>' . $e($fmtDate((string) ($order['shipped_at'] ?? ''))) . '</dd>';
            echo '<dt>Pickwave</dt><dd>';
            if ($pickwaveId > 0) {
                echo '<a href="' . $e($this->url->to('store/admin/pickwaves/view', ['id' => $pickwaveId]))
                   . '">#' . $pickwaveId . '</a>';
            } else {
                echo 'Not yet set';
            }
            echo '</dd>';
            if ($shipBy !== '') {
                echo '<dt>Ship by</dt><dd>' . $e($shipBy) . '</dd>';
            }
            echo '<dt>Total</dt><dd class="fw-semibold">£ '
               . number_format((float) $order['total'], 2) . ' ' . $e($currency) . '</dd>';
            echo '</dl></div></div></div>';

            // Customer
            echo '<div class="col-12"><div class="sf-od-panel"><div class="sf-od-panel-h">Customer</div>'
               . '<div class="sf-od-panel-b">';
            echo '<div class="row g-3 mb-3">';
            echo '<div class="col-md-4"><div class="small text-muted">Name</div><div class="fw-semibold">'
               . $e($shipName !== '' ? $shipName : '—') . '</div></div>';
            echo '<div class="col-md-4"><div class="small text-muted">Phone</div><div>'
               . $e($phone !== '' ? $phone : '—') . '</div></div>';
            echo '<div class="col-md-4"><div class="small text-muted">Email</div><div>';
            if ($customer !== null) {
                echo '<a href="' . $e($this->url->to('store/admin/customers/edit', ['id' => $customer['id']]))
                   . '">' . $e((string) ($order['buyer_username'] ?: $customer['reference'])) . '</a>';
                if ($email !== '') {
                    echo '<div class="small"><a href="mailto:' . $e($email) . '">' . $e($email) . '</a></div>';
                }
            } else {
                echo $email !== ''
                    ? '<a href="mailto:' . $e($email) . '">' . $e($email) . '</a>'
                    : $e((string) (($order['buyer_username'] ?? '') !== '' ? $order['buyer_username'] : '—'));
            }
            echo '</div></div></div>';
            echo '<div class="row g-3">';
            echo '<div class="col-md-6"><div class="small text-muted mb-1">Billing address</div>'
               . '<div class="sf-od-addr small">';
            $billName = trim((string) ($bill['fullName'] ?? $bill['name'] ?? $shipName));
            echo '<div class="fw-semibold mb-1">' . $e($billName !== '' ? $billName : '—') . '</div>';
            foreach ($this->formatShipAddress($bill) as $line) {
                echo '<div>' . $e($line) . '</div>';
            }
            echo '</div></div>';
            echo '<div class="col-md-6"><div class="small text-muted mb-1">Shipping address</div>'
               . '<div class="sf-od-addr small">';
            echo '<div class="fw-semibold mb-1">' . $e($shipName !== '' ? $shipName : '—') . '</div>';
            foreach ($this->formatShipAddress($addr) as $line) {
                echo '<div>' . $e($line) . '</div>';
            }
            echo '</div></div></div>';
            echo '</div></div></div>';

            // Shipping & handling
            echo '<div class="col-12"><div class="sf-od-panel"><div class="sf-od-panel-h">Shipping &amp; handling</div>'
               . '<div class="sf-od-panel-b"><div class="row g-3">';
            echo '<div class="col-md-3"><label class="form-label small" for="warehouse">Warehouse</label>'
               . '<select name="warehouse" id="warehouse" class="form-select form-select-sm">';
            foreach ($warehouseList as $w) {
                $sel = strcasecmp($orderWarehouse, (string) $w['name']) === 0 ? ' selected' : '';
                echo '<option value="' . $e((string) $w['name']) . '"' . $sel . '>'
                   . $e((string) $w['name']) . '</option>';
            }
            if ($orderWarehouse !== '' && !in_array($orderWarehouse, array_column($warehouseList, 'name'), true)) {
                echo '<option value="' . $e($orderWarehouse) . '" selected>' . $e($orderWarehouse) . '</option>';
            }
            echo '</select></div>';

            echo '<div class="col-md-3"><label class="form-label small" for="mapped_shipping_method">Shipping method</label>'
               . '<select name="mapped_shipping_method" id="mapped_shipping_method" class="form-select form-select-sm">';
            $mapped = trim((string) ($order['mapped_shipping_method'] ?? ''));
            echo '<option value="">—</option>';
            $mappedSeen = false;
            foreach ($shippingMethods as $m) {
                $label = trim((string) ($m['method'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $sel = strcasecmp($mapped, $label) === 0 ? ' selected' : '';
                if ($sel !== '') {
                    $mappedSeen = true;
                }
                $company = trim((string) ($m['company'] ?? ''));
                $opt = $company !== '' ? $company . ' — ' . $label : $label;
                echo '<option value="' . $e($label) . '"' . $sel . '>' . $e($opt) . '</option>';
            }
            if ($mapped !== '' && !$mappedSeen) {
                echo '<option value="' . $e($mapped) . '" selected>' . $e($mapped) . '</option>';
            }
            echo '</select></div>';

            echo '<div class="col-md-3"><label class="form-label small">Channel shipping method</label>'
               . '<div class="form-control form-control-sm bg-body-tertiary">'
               . $e((string) (($order['shipping_method'] ?? '') !== '' ? $order['shipping_method'] : '—'))
               . '</div></div>';

            echo '<div class="col-md-3"><label class="form-label small" for="packaging_size_id">Package size</label>'
               . '<select name="packaging_size_id" id="packaging_size_id" class="form-select form-select-sm">'
               . '<option value="0">—</option>';
            $currentPack = (int) ($order['packaging_size_id'] ?? 0);
            foreach ($packagingSizes as $p) {
                $sel = $currentPack === (int) $p['id'] ? ' selected' : '';
                echo '<option value="' . (int) $p['id'] . '"' . $sel . '>'
                   . $e((string) $p['name']) . '</option>';
            }
            echo '</select></div>';

            echo '<div class="col-md-3"><label class="form-label small" for="tracking_number_edit">Tracking number</label>'
               . '<input type="text" class="form-control form-control-sm" name="tracking_number"'
               . ' id="tracking_number_edit" maxlength="128" value="' . $e($tracking) . '"></div>';
            echo '<div class="col-md-3"><label class="form-label small" for="carrier_code_edit">Carrier code</label>'
               . '<input type="text" class="form-control form-control-sm" name="carrier_code"'
               . ' id="carrier_code_edit" maxlength="64" value="'
               . $e((string) ($order['carrier_code'] ?? '')) . '"></div>';
            echo '<div class="col-md-2"><label class="form-label small" for="order_weight_kg">Order weight (kg)</label>'
               . '<input type="number" step="0.001" min="0" class="form-control form-control-sm"'
               . ' name="order_weight_kg" id="order_weight_kg" value="'
               . $e(number_format((float) ($order['order_weight_kg'] ?? 0), 3, '.', '')) . '"></div>';
            echo '<div class="col-md-2"><label class="form-label small" for="package_count">No. of parcels</label>'
               . '<input type="number" min="1" class="form-control form-control-sm" name="package_count"'
               . ' id="package_count" value="' . max(1, (int) ($order['package_count'] ?? 1)) . '"></div>';
            echo '<div class="col-md-5"><label class="form-label small" for="special_instructions">Special instructions</label>'
               . '<input type="text" class="form-control form-control-sm" name="special_instructions"'
               . ' id="special_instructions" maxlength="500" value="'
               . $e((string) ($order['special_instructions'] ?? '')) . '"></div>';
            $preferredCourier = trim((string) ($order['preferred_courier'] ?? ''));
            if ($preferredCourier !== '' || $mapped !== '') {
                echo '<div class="col-12 small text-muted">Rule-mapped courier: <strong>'
                   . $e($preferredCourier !== '' ? $preferredCourier : '—')
                   . '</strong>'
                   . ($mapped !== '' ? ' · method <strong>' . $e($mapped) . '</strong>' : '')
                   . '</div>';
            }
            if ($allShipments !== []) {
                echo '<div class="col-12"><div class="table-responsive"><table class="table table-sm mb-0 border">'
                   . '<thead class="table-light"><tr><th>#</th><th>Tracking</th><th>Status</th>'
                   . '<th class="text-end">Cost</th><th>Label</th><th></th></tr></thead><tbody>';
                foreach ($allShipments as $shipRow) {
                    $sid = (int) ($shipRow['id'] ?? 0);
                    $st = strtolower((string) ($shipRow['status'] ?? 'created'));
                    $isVoid = $st === 'voided';
                    echo '<tr' . ($isVoid ? ' class="table-secondary"' : '') . '>';
                    echo '<td>' . (int) ($shipRow['parcel_index'] ?? 1) . '</td>';
                    echo '<td><code class="small">' . $e((string) ($shipRow['tracking_number'] ?: $shipRow['barcode'] ?? '')) . '</code></td>';
                    echo '<td><span class="badge text-bg-secondary">' . $e($st) . '</span></td>';
                    echo '<td class="text-end">'
                       . ((float) ($shipRow['carrier_cost'] ?? 0) > 0
                            ? number_format((float) $shipRow['carrier_cost'], 2) : '—')
                       . '</td>';
                    echo '<td>';
                    if (!$isVoid && trim((string) ($shipRow['label_path'] ?? '')) !== '') {
                        echo '<a class="btn btn-outline-secondary btn-sm py-0" target="_blank" href="'
                           . $e($this->url->to('store/admin/orders/label', [
                               'id' => (int) $order['id'],
                               'shipment_id' => $sid,
                           ])) . '">PDF</a>';
                    } else {
                        echo '—';
                    }
                    echo '</td><td class="text-nowrap">';
                    if (!$isVoid) {
                        echo '<form method="post" class="d-inline" action="'
                           . $e($this->url->to('store/admin/orders/sync-tracking', ['id' => (int) $order['id']])) . '">'
                           . $this->request->csrfField()
                           . '<input type="hidden" name="shipment_id" value="' . $sid . '">'
                           . '<button type="submit" class="btn btn-outline-secondary btn-sm py-0">Sync</button></form> ';
                        echo '<form method="post" class="d-inline" action="'
                           . $e($this->url->to('store/admin/orders/void-label', ['id' => (int) $order['id']])) . '"'
                           . ' onsubmit="return confirm(\'Void this label?\');">'
                           . $this->request->csrfField()
                           . '<input type="hidden" name="shipment_id" value="' . $sid . '">'
                           . '<button type="submit" class="btn btn-outline-danger btn-sm py-0">Void</button></form>';
                    }
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
                if ($activeShipments !== []) {
                    echo '<form method="post" class="mt-2" action="'
                       . $e($this->url->to('store/admin/orders/recreate-label', ['id' => (int) $order['id']])) . '"'
                       . ' onsubmit="return confirm(\'Void all labels and recreate?\');">'
                       . $this->request->csrfField()
                       . '<button type="submit" class="btn btn-outline-primary btn-sm">Void + recreate labels</button></form>';
                }
                echo '</div>';
            }
            echo '</div></div></div></div>';

            // Items
            echo '<div class="col-12"><div class="sf-od-panel"><div class="sf-od-panel-h">Items ordered</div>'
               . '<div class="sf-od-panel-b p-0">';
            echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
            $kitParentSkus = [];
            foreach ($items as $it) {
                $parentKit = trim((string) ($it['kit_sku'] ?? ''));
                if ($parentKit !== '') {
                    $kitParentSkus[$parentKit] = true;
                }
            }

            echo '<thead class="table-light"><tr>'
               . '<th>SKU</th><th>Product</th><th class="text-end">Qty</th>'
               . '<th class="text-end">Shipped</th>'
               . '<th class="text-end">Unit</th><th class="text-end">Line</th>'
               . '<th class="text-end">Stock</th><th class="text-end">Available</th>'
               . '<th>Status</th><th>Warehouse</th>'
               . '</tr></thead><tbody>';
            foreach ($items as $item) {
                $itemId = (int) ($item['id'] ?? 0);
                $qty = max(1, (int) ($item['order_qty'] ?? $item['qty'] ?? 1));
                $qtyShipped = max(0, (int) ($item['qty_shipped'] ?? 0));
                $price = (float) ($item['price'] ?? 0);
                $line = $qty * $price;
                $kitSku = trim((string) ($item['kit_sku'] ?? ''));
                $productId = (int) ($item['product_id'] ?? 0);
                $resolvedVariant = (int) ($item['resolved_variant_id'] ?? $item['variant_id'] ?? 0);
                $onHand = (int) ($item['stock_qty'] ?? 0);
                $reserved = (int) ($item['stock_reserved'] ?? 0);
                $available = $onHand - $reserved;
                $sku = (string) ($item['external_sku'] ?? '');
                $isKitItem = $kitSku === '' && isset($kitParentSkus[$sku]);
                $isAllocated = $kitSku !== '';
                echo '<tr>';
                echo '<td>';
                if ($productId > 0) {
                    echo '<a href="' . $e($this->url->to('store/admin/products/edit', ['id' => $productId]))
                       . '"><code>' . $e($sku) . '</code></a>';
                } else {
                    echo '<code>' . $e($sku) . '</code>';
                }
                if ($isAllocated) {
                    echo '<div class="small text-muted">Kit: <code>' . $e($kitSku) . '</code></div>';
                }
                echo '</td>';
                echo '<td>' . $e((string) ($item['title'] ?? '')) . '</td>';
                echo '<td class="text-end">';
                if ($itemId > 0) {
                    echo '<input type="number" min="1" step="1" class="form-control form-control-sm text-end"'
                       . ' name="item_qty[' . $itemId . ']" value="' . $qty . '"'
                       . ' style="max-width:5.5rem;margin-left:auto" title="Quantity">';
                } else {
                    echo $qty;
                }
                echo '</td>';
                echo '<td class="text-end small">' . $qtyShipped
                   . ($qtyShipped < $qty ? ' <span class="text-muted">/ ' . $qty . '</span>' : '')
                   . '</td>';
                echo '<td class="text-end">' . number_format($price, 2) . '</td>';
                echo '<td class="text-end">' . number_format($line, 2) . '</td>';
                echo '<td class="text-end">';
                if ($resolvedVariant > 0 || $onHand > 0 || $reserved > 0) {
                    echo '<span class="fw-semibold">' . $onHand . '</span>';
                    if ($reserved > 0) {
                        echo '<div class="small text-muted">res ' . $reserved . '</div>';
                    }
                } else {
                    echo '<span class="text-muted" title="No linked inventory row">—</span>';
                }
                echo '</td>';
                echo '<td class="text-end">';
                if ($resolvedVariant > 0 || $onHand > 0 || $reserved > 0) {
                    $cls = $available <= 0 ? 'text-danger' : ($available < $qty ? 'text-warning' : '');
                    echo '<span class="' . $cls . '">' . $available . '</span>';
                } else {
                    echo '<span class="text-muted">—</span>';
                }
                echo '</td>';
                echo '<td class="small">';
                if ($isKitItem) {
                    echo '<span class="badge text-bg-secondary">Kit Item</span>';
                } elseif ($isAllocated) {
                    echo '<span class="badge text-bg-success">Allocated</span>';
                } else {
                    echo '<span class="text-muted">—</span>';
                }
                echo '</td>';
                echo '<td class="small">' . $e((string) (($item['warehouse'] ?? '') !== '' ? $item['warehouse'] : 'Default')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '<div class="px-3 py-2 small text-muted border-top">'
               . count($items) . ' line(s) · ' . $itemsQty . ' unit(s)</div>';
            echo '</div></div></div>';

            // Notes + totals
            echo '<div class="col-lg-7"><div class="sf-od-panel"><div class="sf-od-panel-h">Notes</div>'
               . '<div class="sf-od-panel-b">'
               . '<textarea class="form-control form-control-sm" name="notes" rows="4" maxlength="500">'
               . $e((string) ($order['notes'] ?? '')) . '</textarea>'
               . '</div></div></div>';

            echo '<div class="col-lg-5"><div class="sf-od-panel"><div class="sf-od-panel-h">Order totals</div>'
               . '<div class="sf-od-panel-b"><dl class="sf-od-meta mb-0">';
            echo '<dt>Subtotal</dt><dd>£ ' . number_format($itemsSubtotal, 2) . '</dd>';
            echo '<dt>Shipping</dt><dd>£ ' . number_format($shipCost, 2) . '</dd>';
            if ($disc > 0) {
                echo '<dt>Discount</dt><dd>-£ ' . number_format($disc, 2) . '</dd>';
            }
            if ($stax > 0) {
                echo '<dt>Shipping tax</dt><dd>£ ' . number_format($stax, 2) . '</dd>';
            }
            if ($fee > 0) {
                echo '<dt>Channel fee</dt><dd>£ ' . number_format($fee, 2) . '</dd>';
            }
            echo '<dt>Total</dt><dd class="fw-semibold">£ '
               . number_format((float) $order['total'], 2) . ' ' . $e($currency) . '</dd>';
            echo '<dt>Total to pay</dt><dd>'
               . ($isPaid ? '£ 0.00 <span class="badge text-bg-success">Paid in full</span>'
                   : '£ ' . number_format((float) $order['total'], 2))
               . '</dd>';
            echo '</dl></div></div></div>';

            echo '<div class="col-12 d-flex justify-content-end gap-2 mb-2">';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/orders')) . '">Cancel</a>';
            echo '<button type="submit" class="btn btn-primary">Save</button>';
            echo '</div>';

            echo '</div></form>'; // row + main form

            // Channel cancel + refund cockpit
            if (!in_array($status, ['CANCELLED', 'CANCELED'], true)) {
                echo '<div class="sf-od-panel" id="cancel-refund"><div class="sf-od-panel-h">Cancel / refund cockpit</div>'
                   . '<div class="sf-od-panel-b"><div class="row g-3">';
                echo '<div class="col-md-6"><form method="post" action="'
                   . $e($this->url->to('store/admin/orders/cancel-channel', ['id' => (int) $order['id']])) . '"'
                   . ' onsubmit="return confirm(\'Cancel on channel and locally?\');">'
                   . $this->request->csrfField()
                   . '<p class="small text-muted">Cancels on the sales channel when an API exists, then marks local CANCELLED.</p>'
                   . '<button type="submit" class="btn btn-outline-danger btn-sm">'
                   . '<i class="bi bi-x-circle me-1"></i>Cancel on channel + local</button></form></div>';
                echo '<div class="col-md-6"><form method="post" action="'
                   . $e($this->url->to('store/admin/orders/refund', ['id' => (int) $order['id']])) . '"'
                   . ' onsubmit="return confirm(\'Issue refund on channel?\');">'
                   . $this->request->csrfField()
                   . '<div class="row g-2 align-items-end">'
                   . '<div class="col-5"><label class="form-label small">Amount</label>'
                   . '<input type="number" step="0.01" min="0" name="amount" class="form-control form-control-sm" value="'
                   . $e(number_format((float) $order['total'], 2, '.', '')) . '"></div>'
                   . '<div class="col-4"><label class="form-label small">Return ID (eBay)</label>'
                   . '<input type="text" name="return_id" class="form-control form-control-sm" placeholder="optional"></div>'
                   . '<div class="col-3"><button type="submit" class="btn btn-outline-warning btn-sm w-100">'
                   . 'Issue refund</button></div></div>'
                   . '<p class="small text-muted mb-0 mt-1">Uses eBay Post-Order / Amazon helpers when available; otherwise logs a skip.</p>'
                   . '</form></div>';
                echo '</div></div></div>';
            }

            // Fulfillment (separate form — mark shipped)
            $courierSvc = new CourierShipmentService(
                $this->channels,
                $this->factory,
                $shipments,
                BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store',
                $this->db,
            );
            $couriers = $courierSvc->connectedCouriers();
            $rateQuotes = [];
            try {
                $rateQuotes = $courierSvc->quoteRates($order, $items);
            } catch (\Throwable) {
            }
            $serialRepo = new SerialRepository($this->db);
            $serialRequired = $serialRepo->requiredSerialItems($items);
            $hasCourier = $couriers !== [];
            $defaultCourier = $couriers[0] ?? null;
            if ($preferredCourier !== '') {
                foreach ($couriers as $c) {
                    if (strcasecmp($c->type, $preferredCourier) === 0) {
                        $defaultCourier = $c;
                        break;
                    }
                }
            }
            $defaultCarrier = match ($defaultCourier?->type) {
                'evri'        => 'HERMES',
                'royalmail'   => 'ROYAL_MAIL',
                'parcelforce' => 'PARCELFORCE',
                'dpd'         => 'DPD',
                default       => ((string) ($order['carrier_code'] ?? '') !== ''
                    ? (string) $order['carrier_code']
                    : 'ROYAL_MAIL'),
            };

            echo '<div class="sf-od-panel" id="fulfillment"><div class="sf-od-panel-h">Despatch / fulfillment</div>'
               . '<div class="sf-od-panel-b">';
            if ($tracking !== '' && !$canShip) {
                echo '<dl class="sf-od-meta mb-0">';
                echo '<dt>Carrier</dt><dd>' . $e((string) ($order['carrier_code'] ?? '')) . '</dd>';
                echo '<dt>Tracking</dt><dd><code>' . $e($tracking) . '</code></dd>';
                echo '<dt>Shipped</dt><dd>' . $e($fmtDate((string) ($order['shipped_at'] ?? ''))) . '</dd>';
                if ($hasLabel) {
                    echo '<dt>Label</dt><dd><a class="btn btn-sm btn-outline-secondary" target="_blank" href="'
                       . $e($this->url->to('store/admin/orders/label', ['id' => $order['id']]))
                       . '"><i class="bi bi-printer me-1"></i>Download label</a></dd>';
                }
                echo '</dl>';
            } elseif ($canShip) {
                if ($missingLineIds) {
                    echo '<div class="alert alert-warning mb-0">Line item IDs are missing. Sync this order, then try again.</div>';
                } else {
                    echo '<form method="post" action="'
                       . $e($this->url->to('store/admin/orders/ship', ['id' => $order['id']])) . '">';
                    echo $this->request->csrfField();
                    echo '<div class="table-responsive mb-3"><table class="table table-sm align-middle">'
                       . '<thead><tr><th>SKU</th><th class="text-end">Ordered</th>'
                       . '<th class="text-end">Shipped</th><th class="text-end">Ship now</th></tr></thead><tbody>';
                    foreach ($items as $item) {
                        $itemId = (int) ($item['id'] ?? 0);
                        if ($itemId <= 0) {
                            continue;
                        }
                        $oq = max(0, (int) ($item['order_qty'] ?? $item['qty'] ?? 0));
                        $sq = max(0, (int) ($item['qty_shipped'] ?? 0));
                        $remain = max(0, $oq - $sq);
                        echo '<tr>';
                        echo '<td><code>' . $e((string) ($item['external_sku'] ?? '')) . '</code></td>';
                        echo '<td class="text-end">' . $oq . '</td>';
                        echo '<td class="text-end">' . $sq . '</td>';
                        echo '<td class="text-end"><input type="number" min="0" max="' . $remain . '"'
                           . ' class="form-control form-control-sm text-end" style="max-width:5.5rem;margin-left:auto"'
                           . ' name="ship_qty[' . $itemId . ']" value="' . $remain . '"'
                           . ($remain <= 0 ? ' disabled' : '') . '></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table></div>';
                    if ($serialRequired !== []) {
                        echo '<div class="alert alert-warning py-2 small">Serial / lot capture required before despatch.</div>';
                        echo '<div class="table-responsive mb-3"><table class="table table-sm">'
                           . '<thead><tr><th>SKU</th><th>Serial</th><th>Lot</th></tr></thead><tbody>';
                        foreach ($serialRequired as $need) {
                            for ($i = 0; $i < (int) $need['qty']; $i++) {
                                echo '<tr>';
                                echo '<td><code>' . $e((string) $need['sku']) . '</code>'
                                   . '<input type="hidden" name="serial_item_id[]" value="' . (int) $need['item_id'] . '">'
                                   . '<input type="hidden" name="serial_variant_id[]" value="' . (int) $need['variant_id'] . '">'
                                   . '</td>';
                                echo '<td><input class="form-control form-control-sm" name="serial_value[]" required'
                                   . ' placeholder="Serial #"></td>';
                                echo '<td><input class="form-control form-control-sm" name="serial_lot[]"'
                                   . ' placeholder="Lot (opt)"></td>';
                                echo '</tr>';
                            }
                        }
                        echo '</tbody></table></div>';
                    }
                    if ($rateQuotes !== []) {
                        echo '<div class="mb-3"><div class="small fw-semibold mb-1">Courier rate shop</div>'
                           . '<div class="table-responsive"><table class="table table-sm mb-2">'
                           . '<thead><tr><th></th><th>Courier</th><th>Service</th><th class="text-end">Cost</th>'
                           . '<th>Source</th></tr></thead><tbody>';
                        foreach ($rateQuotes as $ri => $rate) {
                            $checked = $ri === 0 ? ' checked' : '';
                            echo '<tr>';
                            echo '<td><input type="radio" name="rate_pick" value="'
                               . $e((string) $rate['courier'] . '|' . (int) $rate['channel_id']) . '"'
                               . $checked . '></td>';
                            echo '<td>' . $e((string) $rate['courier']) . '</td>';
                            echo '<td class="small">' . $e((string) $rate['service']) . '</td>';
                            echo '<td class="text-end">' . number_format((float) $rate['cost'], 2)
                               . ' ' . $e((string) $rate['currency']) . '</td>';
                            echo '<td class="small">' . $e((string) $rate['source']) . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table></div>'
                           . '<button type="submit" formaction="'
                           . $e($this->url->to('store/admin/orders/prefer-courier', ['id' => (int) $order['id']]))
                           . '" class="btn btn-outline-secondary btn-sm mb-2">Save preferred courier</button></div>';
                    }
                    if ($hasCourier) {
                        echo '<div class="form-check mb-2">'
                           . '<input class="form-check-input" type="checkbox" name="create_courier_label" value="1"'
                           . ' id="create_courier_label" checked>'
                           . '<label class="form-check-label" for="create_courier_label">'
                           . 'Create courier label (auto-fill tracking)</label></div>';
                        echo '<div class="mb-3"><label class="form-label small" for="courier_channel_id">Courier</label>'
                           . '<select name="courier_channel_id" id="courier_channel_id" class="form-select form-select-sm">';
                        foreach ($couriers as $c) {
                            $label = ($c->label !== '' ? $c->label : $c->name) . ' (' . $c->type . ')';
                            $sel = ($defaultCourier !== null && (int) $c->id === (int) $defaultCourier->id)
                                ? ' selected' : '';
                            echo '<option value="' . (int) $c->id . '"' . $sel . '>' . $e($label) . '</option>';
                        }
                        echo '</select></div>';
                    }
                    echo '<div class="row g-2 align-items-end">';
                    echo '<div class="col-md-4"><label class="form-label small">Carrier code</label>'
                       . '<input type="text" name="carrier_code" id="carrier_code" class="form-control"'
                       . ($hasCourier ? '' : ' required') . ' value="' . $e($defaultCarrier) . '"></div>';
                    echo '<div class="col-md-5"><label class="form-label small">Tracking number</label>'
                       . '<input type="text" name="tracking_number" id="tracking_number" class="form-control"'
                       . ($hasCourier ? '' : ' required') . ' maxlength="128" placeholder="'
                       . ($hasCourier ? 'Leave blank to use courier tracking' : 'Tracking / consignment number')
                       . '" value="' . $e($tracking) . '"></div>';
                    echo '<div class="col-md-3"><button type="submit" class="btn btn-primary w-100">'
                       . '<i class="bi bi-truck me-1"></i>Ship selected</button></div>';
                    echo '</div>';
                    if ($hasCourier) {
                        $carrierMap = [];
                        foreach ($couriers as $c) {
                            $carrierMap[(string) $c->id] = match ($c->type) {
                                'evri' => 'HERMES', 'royalmail' => 'ROYAL_MAIL',
                                'parcelforce' => 'PARCELFORCE', 'dpd' => 'DPD', default => 'OTHER',
                            };
                        }
                        echo '<script>(function(){var cb=document.getElementById("create_courier_label");'
                           . 'var tr=document.getElementById("tracking_number");'
                           . 'var ca=document.getElementById("carrier_code");'
                           . 'var sel=document.getElementById("courier_channel_id");'
                           . 'var map=' . json_encode($carrierMap, JSON_UNESCAPED_UNICODE) . ';'
                           . 'function sync(){if(!cb||!tr||!ca)return;if(cb.checked){tr.removeAttribute("required");'
                           . 'ca.removeAttribute("required");if(sel&&map[sel.value])ca.value=map[sel.value];}'
                           . 'else{tr.setAttribute("required","required");ca.setAttribute("required","required");}}'
                           . 'if(cb)cb.addEventListener("change",sync);if(sel)sel.addEventListener("change",sync);'
                           . 'sync();})();</script>';
                    }
                    echo '</form>';
                }
            } else {
                echo '<p class="text-muted mb-0">No fulfillment action available for this order.</p>';
            }
            echo '</div></div>';

            if ($channel !== null && $channel->type === 'amazon' && $channel->isConnected()) {
                $amazonActions = [];
                try {
                    $client = $this->factory->make($channel);
                    if (method_exists($client, 'messagingActions')) {
                        $amazonActions = $client->messagingActions((string) $order['external_order_id']);
                    }
                } catch (\Throwable $ex) {
                    error_log('Amazon messagingActions: ' . $ex->getMessage());
                }
                echo '<div class="sf-od-panel" id="amazon-message"><div class="sf-od-panel-h">Message buyer</div>'
                   . '<div class="sf-od-panel-b">';
                if ($amazonActions === []) {
                    echo '<p class="text-muted mb-0">No message types available for this order right now.</p>';
                } else {
                    echo '<form method="post" action="'
                       . $e($this->url->to('store/admin/orders/message', ['id' => $order['id']])) . '">';
                    echo $this->request->csrfField();
                    echo '<div class="mb-2"><label class="form-label small">Message type</label>'
                       . '<select name="action" class="form-select form-select-sm" required>';
                    foreach ($amazonActions as $a) {
                        echo '<option value="' . $e((string) $a['name']) . '">'
                           . $e((string) $a['label']) . '</option>';
                    }
                    echo '</select></div>';
                    echo '<div class="mb-2"><label class="form-label small">Message</label>'
                       . '<textarea name="body" class="form-control" rows="3" required maxlength="2000"></textarea></div>';
                    echo '<button type="submit" class="btn btn-primary btn-sm">'
                       . '<i class="bi bi-send me-1"></i>Send to buyer</button></form>';
                }
                echo '</div></div>';
            }

            // Returns
            echo '<div class="sf-od-panel" id="returns"><div class="sf-od-panel-h">Returns</div>'
               . '<div class="sf-od-panel-b">';
            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/returns/create', ['order_id' => (int) $order['id']]))
               . '" class="row g-2 align-items-end mb-3">';
            echo $this->request->csrfField();
            echo '<div class="col-md-3"><label class="form-label small mb-1">Reason</label>'
               . '<input type="text" name="reason" class="form-control form-control-sm" maxlength="255"'
               . ' placeholder="e.g. Damaged / Wrong item" required></div>';
            echo '<div class="col-md-2"><label class="form-label small mb-1">Refund</label>'
               . '<input type="number" step="0.01" min="0" name="refund_amount" class="form-control form-control-sm"'
               . ' value="0"></div>';
            echo '<div class="col-md-2"><label class="form-label small mb-1">Status</label>'
               . '<select name="status" class="form-select form-select-sm">'
               . '<option value="open">Open</option>'
               . '<option value="approved">Approved</option>'
               . '<option value="received">Received</option>'
               . '<option value="refunded">Refunded</option>'
               . '<option value="closed">Closed</option>'
               . '</select></div>';
            echo '<div class="col-md-3"><label class="form-label small mb-1">Notes</label>'
               . '<input type="text" name="notes" class="form-control form-control-sm" maxlength="1000"></div>';
            echo '<div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary w-100">'
               . '<i class="bi bi-plus-lg me-1"></i>Log return</button></div>';
            echo '</form>';

            if ($returnRows === []) {
                echo '<p class="text-muted small mb-0">No returns logged for this order. '
                   . '<a href="' . $e($this->url->to('store/admin/returns')) . '">View all returns</a>.</p>';
            } else {
                echo '<div class="table-responsive"><table class="table table-sm align-middle mb-2">';
                echo '<thead><tr><th>#</th><th>Status</th><th>Reason</th><th class="text-end">Refund</th>'
                   . '<th>Created</th><th></th></tr></thead><tbody>';
                foreach ($returnRows as $rr) {
                    $rid = (int) $rr['id'];
                    $badge = match (strtolower((string) $rr['status'])) {
                        'open', 'approved' => 'text-bg-warning',
                        'received' => 'text-bg-info',
                        'refunded', 'closed' => 'text-bg-success',
                        default => 'text-bg-secondary',
                    };
                    echo '<tr>';
                    echo '<td><a href="' . $e($this->url->to('store/admin/returns/view', ['id' => $rid])) . '">'
                       . $rid . '</a></td>';
                    echo '<td><span class="badge ' . $badge . '">' . $e((string) $rr['status']) . '</span></td>';
                    echo '<td class="small">' . $e((string) $rr['reason']) . '</td>';
                    echo '<td class="text-end text-nowrap">' . number_format((float) $rr['refund_amount'], 2)
                       . ' ' . $e((string) $rr['currency']) . '</td>';
                    echo '<td class="small text-nowrap">' . $e((string) $rr['created_at']) . '</td>';
                    echo '<td class="text-end text-nowrap">'
                       . '<a class="btn btn-sm btn-outline-secondary" href="'
                       . $e($this->url->to('store/admin/returns/view', ['id' => $rid])) . '">Open</a> '
                       . '<a class="btn btn-sm btn-outline-primary" target="_blank" href="'
                       . $e($this->url->to('store/admin/orders/credit-note', [
                           'id' => (int) $order['id'],
                           'return_id' => $rid,
                       ])) . '">Credit note</a></td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
                echo '<a class="small" href="' . $e($this->url->to('store/admin/returns')) . '">All returns</a>';
            }
            echo '</div></div>';

            // Audit trail
            echo '<div class="sf-od-panel" id="audit"><div class="sf-od-panel-h">Audit trail</div>'
               . '<div class="sf-od-panel-b">';
            if ($auditRows === []) {
                echo '<p class="text-muted small mb-0">No changes recorded yet. Saves, ship, clone and delete are logged here.</p>';
            } else {
                echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
                echo '<thead><tr><th>When</th><th>User</th><th>Action</th><th>Summary</th></tr></thead><tbody>';
                foreach ($auditRows as $ar) {
                    echo '<tr>';
                    echo '<td class="small text-nowrap">' . $e((string) $ar['created_at']) . '</td>';
                    echo '<td class="small">' . $e((string) ($ar['user_label'] !== '' ? $ar['user_label'] : '—')) . '</td>';
                    echo '<td class="small"><code>' . $e((string) $ar['action']) . '</code></td>';
                    echo '<td class="small">' . $e((string) $ar['summary']);
                    if (!empty($ar['changes']) && is_array($ar['changes'])) {
                        $bits = [];
                        foreach ($ar['changes'] as $field => $chg) {
                            if (!is_array($chg)) {
                                continue;
                            }
                            $bits[] = $field . ': ' . (string) ($chg['from'] ?? '') . ' → ' . (string) ($chg['to'] ?? '');
                        }
                        if ($bits !== []) {
                            echo '<div class="text-muted mt-1" style="font-size:.75rem">'
                               . $e(implode('; ', array_slice($bits, 0, 8))) . '</div>';
                        }
                    }
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div></div>';
        });
    }

    public function updateAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/orders');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $beforeData = $this->orders->find($id);
        if ($beforeData === null) {
            throw new NotFoundException('Order not found.');
        }
        $before = $beforeData['order'];
        $beforeItems = $beforeData['items'];

        try {
            $payload = [
                'status'                 => (string) $this->request->post('status', 'string', 'NOT_STARTED'),
                'notes'                  => (string) $this->request->post('notes', 'string', ''),
                'special_instructions'   => (string) $this->request->post('special_instructions', 'string', ''),
                'packaging_size_id'      => (int) $this->request->post('packaging_size_id', 'int', 0),
                'package_count'          => (int) $this->request->post('package_count', 'int', 1),
                'order_weight_kg'        => (float) $this->request->post('order_weight_kg', 'float', 0),
                'mapped_shipping_method' => (string) $this->request->post('mapped_shipping_method', 'string', ''),
                'warehouse'              => (string) $this->request->post('warehouse', 'string', 'Default'),
                'tracking_number'        => (string) $this->request->post('tracking_number', 'string', ''),
                'carrier_code'           => (string) $this->request->post('carrier_code', 'string', ''),
                'is_paid'                => $this->request->post('is_paid', 'string', '') !== '',
                'on_hold'                => $this->request->post('on_hold', 'string', '') !== '',
                'is_priority'            => $this->request->post('is_priority', 'string', '') !== '',
                'attention_required'     => $this->request->post('attention_required', 'string', '') !== '',
                'pre_despatched'         => $this->request->post('pre_despatched', 'string', '') !== '',
                'item_qty'               => (array) $this->request->post('item_qty', 'raw', []),
            ];
            $this->orders->updateDetails($id, $payload);
            $changes = $this->diffOrderAudit($before, $beforeItems, $payload);
            $this->logOrderAudit(
                $id,
                'updated',
                $changes !== [] ? 'Order fields updated.' : 'Order saved (no field changes).',
                $changes
            );
            $this->notify->success('Order saved.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }

        redirect_to('store/admin/orders/view', ['id' => $id]);
    }

    public function cloneAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }
        try {
            $this->request->checkToken();
            $id = (int) $this->request->get('id', 'int', 0);
            $newId = $this->orders->cloneOrder($id);
            $this->logOrderAudit($id, 'cloned', 'Order cloned to #' . $newId, ['new_order_id' => $newId]);
            $this->logOrderAudit($newId, 'created', 'Cloned from order #' . $id, ['source_order_id' => $id]);
            $this->notify->success('Order cloned.');
            redirect_to('store/admin/orders/view', ['id' => $newId]);
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            redirect_to('store/admin/orders');
        }
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }
        try {
            $this->request->checkToken();
            $id = (int) $this->request->get('id', 'int', 0);
            $data = $this->orders->find($id);
            $ext = $data !== null ? (string) ($data['order']['external_order_id'] ?? '') : '';
            $this->logOrderAudit($id, 'deleted', 'Order deleted' . ($ext !== '' ? ': ' . $ext : ''), [
                'external_order_id' => $ext,
            ]);
            $this->orders->deleteOrder($id);
            $this->notify->success('Order deleted.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            redirect_to('store/admin/orders/view', ['id' => (int) $this->request->get('id', 'int', 0)]);
            return;
        }
        redirect_to('store/admin/orders');
    }

    /** @param array<string, mixed> $addr @return list<string> */
    private function formatShipAddress(array $addr): array
    {
        $lines = [];
        $line1 = trim((string) ($addr['addressLine1'] ?? $addr['address1'] ?? ''));
        $line2 = trim((string) ($addr['addressLine2'] ?? $addr['address2'] ?? ''));
        $city = trim((string) ($addr['city'] ?? ''));
        $county = trim((string) ($addr['stateOrProvince'] ?? $addr['county'] ?? $addr['state'] ?? ''));
        $postcode = trim((string) ($addr['postalCode'] ?? $addr['postcode'] ?? $addr['zip'] ?? ''));
        $country = trim((string) ($addr['countryCode'] ?? $addr['country'] ?? ''));

        if ($line1 !== '') {
            $lines[] = $line1;
        }
        if ($line2 !== '') {
            $lines[] = $line2;
        }
        $cityLine = trim($city . ($county !== '' && strcasecmp($city, $county) !== 0 ? ', ' . $county : ''));
        if ($cityLine !== '') {
            $lines[] = $cityLine;
        }
        if ($postcode !== '') {
            $lines[] = strtoupper($postcode);
        }
        if ($country !== '') {
            $lines[] = strtoupper($country);
        }
        return $lines;
    }

    // =========================================================================
    // Mark shipped on eBay
    // =========================================================================

    public function shipAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/orders');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $data = $this->orders->find($id);
        if ($data === null) {
            throw new NotFoundException('Order not found.');
        }

        $order   = $data['order'];
        $items   = $data['items'];
        $channel = $this->channels->find((int) $order['channel_id']);

        if ($channel === null || !$channel->isConnected()) {
            $this->notify->error('Channel not connected.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        $carrier   = strtoupper(trim((string) $this->request->post('carrier_code', 'string', '')));
        $tracking  = trim((string) $this->request->post('tracking_number', 'string', ''));
        $createCourier = (string) $this->request->post('create_courier_label', 'string', '') === '1'
            || (string) $this->request->post('create_evri_label', 'string', '') === '1';
        $courierChannelId = (int) $this->request->post('courier_channel_id', 'int', 0);

        $ratePick = trim((string) $this->request->post('rate_pick', 'string', ''));
        if ($ratePick !== '' && str_contains($ratePick, '|')) {
            [$prefType, $prefCh] = array_pad(explode('|', $ratePick, 2), 2, '');
            if ($prefType !== '') {
                $this->db->update('store_orders', [
                    'preferred_courier' => mb_substr($prefType, 0, 32),
                ], ['id' => $id]);
                $order['preferred_courier'] = $prefType;
            }
            if ($courierChannelId <= 0 && (int) $prefCh > 0) {
                $courierChannelId = (int) $prefCh;
            }
        }

        foreach ($items as $item) {
            if (trim((string) ($item['external_line_item_id'] ?? '')) === '') {
                $this->notify->error('Missing channel line item IDs. Sync orders again, then retry.');
                redirect_to('store/admin/orders/view', ['id' => $id]);
                return;
            }
        }

        try {
            $labelNote = '';
            if ($createCourier) {
                $courierSvc = new CourierShipmentService(
                    $this->channels,
                    $this->factory,
                    new ShipmentRepository($this->db),
                    BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store',
                    $this->db,
                );
                $courierChannel = $courierChannelId > 0
                    ? $this->channels->find($courierChannelId)
                    : $courierSvc->findDefaultCourierChannel(
                        trim((string) ($order['preferred_courier'] ?? '')) !== ''
                            ? [(string) $order['preferred_courier']]
                            : null
                    );
                $created  = $courierSvc->createCourierShipment($order, $items, $courierChannel);
                $tracking = $created['tracking_number'];
                $carrier  = $created['carrier_code'];
                $labelNote = $created['label_path'] !== ''
                    ? ' Courier label ready to download.'
                    : ' Courier tracking created (label download pending).';
            }

            if ($carrier === '' || $tracking === '') {
                $this->notify->error('Carrier code and tracking number are required (or enable Create courier label).');
                redirect_to('store/admin/orders/view', ['id' => $id]);
                return;
            }

            $shipQtyRaw = $this->request->post('ship_qty', 'raw', []);
            $shipQtys = null;
            if (is_array($shipQtyRaw) && $shipQtyRaw !== []) {
                $shipQtys = [];
                foreach ($shipQtyRaw as $itemId => $rawQty) {
                    $shipQtys[(int) $itemId] = max(0, (int) $rawQty);
                }
            }

            $serials = [];
            $serialItemIds = (array) $this->request->post('serial_item_id', 'raw', []);
            $serialValues = (array) $this->request->post('serial_value', 'raw', []);
            $serialLots = (array) $this->request->post('serial_lot', 'raw', []);
            $serialVids = (array) $this->request->post('serial_variant_id', 'raw', []);
            foreach ($serialItemIds as $idx => $itemId) {
                $serials[] = [
                    'order_item_id' => (int) $itemId,
                    'variant_id'    => (int) ($serialVids[$idx] ?? 0),
                    'serial'        => trim((string) ($serialValues[$idx] ?? '')),
                    'lot'           => trim((string) ($serialLots[$idx] ?? '')),
                ];
            }

            $despatch = new OrderDespatchService($this->db, $this->channels, $this->factory);
            $result   = $despatch->despatchOrder(
                $order,
                $items,
                $tracking,
                $carrier,
                false,
                $shipQtys,
                $serials !== [] ? $serials : null
            );

            $statusNote = ' Status: ' . (string) ($result['status'] ?? '');
            if (!empty($result['notified'])) {
                $this->notify->success('Order shipped on the channel.' . $labelNote . $statusNote);
            } else {
                $msg = 'Order updated locally.' . $labelNote . $statusNote;
                if (!empty($result['skipped_reason'])) {
                    $msg .= ' ' . $result['skipped_reason'];
                }
                $this->notify->success($msg);
            }
            $this->logOrderAudit($id, 'shipped', 'Order shipped' . $labelNote, [
                'carrier_code'    => $carrier,
                'tracking_number' => $tracking,
                'notified'        => !empty($result['notified']),
                'status'          => $result['status'] ?? '',
            ]);
        } catch (\Throwable $e) {
            $this->notify->error('Ship failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/orders/view', ['id' => $id]);
    }

    /** @param array<string, mixed> $changes */
    private function logOrderAudit(int $orderId, string $action, string $summary, array $changes = []): void
    {
        try {
            (new OrderAuditRepository($this->db))->log(
                $orderId,
                $action,
                $summary,
                $changes,
                $this->auth->id(),
                $this->currentOrderAuditUserLabel(),
            );
        } catch (\Throwable $e) {
            error_log('Order audit #' . $orderId . ': ' . $e->getMessage());
        }
    }

    private function currentOrderAuditUserLabel(): string
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

    /**
     * @param array<string, mixed> $before
     * @param array<int, array<string, mixed>> $beforeItems
     * @param array<string, mixed> $payload
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diffOrderAudit(array $before, array $beforeItems, array $payload): array
    {
        $keys = [
            'status', 'notes', 'special_instructions', 'packaging_size_id', 'package_count',
            'order_weight_kg', 'mapped_shipping_method', 'warehouse', 'tracking_number',
            'carrier_code', 'is_paid', 'on_hold', 'is_priority', 'attention_required', 'pre_despatched',
        ];
        $out = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $from = $before[$key] ?? null;
            $to = $payload[$key];
            if (in_array($key, ['is_paid', 'on_hold', 'is_priority', 'attention_required', 'pre_despatched'], true)) {
                $from = !empty($from) ? 1 : 0;
                $to = !empty($to) ? 1 : 0;
            }
            if ($key === 'status') {
                $from = strtoupper((string) $from);
                $to = strtoupper((string) $to);
            }
            if ($key === 'packaging_size_id') {
                $from = (int) ($from ?? 0);
                $to = (int) $to;
            }
            if ((string) $from === (string) $to) {
                continue;
            }
            $out[$key] = ['from' => $from, 'to' => $to];
        }

        if (isset($payload['item_qty']) && is_array($payload['item_qty'])) {
            $qtyMap = [];
            foreach ($beforeItems as $item) {
                $qtyMap[(int) $item['id']] = (int) ($item['qty'] ?? 1);
            }
            foreach ($payload['item_qty'] as $itemId => $rawQty) {
                $itemId = (int) $itemId;
                $newQty = max(1, (int) $rawQty);
                $oldQty = $qtyMap[$itemId] ?? null;
                if ($oldQty === null || $oldQty === $newQty) {
                    continue;
                }
                $out['item_qty.' . $itemId] = ['from' => $oldQty, 'to' => $newQty];
            }
        }

        return $out;
    }

    // =========================================================================
    // Amazon Messaging API — send buyer message
    // =========================================================================

    public function messageAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/orders');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $data = $this->orders->find($id);
        if ($data === null) {
            throw new NotFoundException('Order not found.');
        }

        $order = $data['order'];
        $channel = $this->channels->find((int) $order['channel_id']);
        $action = trim((string) $this->request->post('action', 'string', ''));
        $body = trim((string) $this->request->post('body', 'string', ''));

        if ($channel === null || $channel->type !== 'amazon' || !$channel->isConnected()) {
            $this->notify->error('Amazon messaging requires a connected Amazon channel.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }
        if ($action === '' || $body === '') {
            $this->notify->error('Message type and body are required.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        try {
            $client = $this->factory->make($channel);
            if (!method_exists($client, 'sendMessage')) {
                throw new \RuntimeException('Amazon client cannot send messages.');
            }
            $client->sendMessage(
                (string) $order['external_order_id'],
                'AMAZON_ORDER',
                $body,
                $action,
            );
            $this->notify->success('Message sent to the Amazon buyer.');
        } catch (\Throwable $e) {
            $this->notify->error('Send failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/orders/view', ['id' => $id]);
    }

    // =========================================================================
    // Evri label PDF
    // =========================================================================

    public function syncTrackingAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        $shipRepo = new ShipmentRepository($this->db);
        $shipmentId = (int) $this->request->post('shipment_id', 'int', 0);
        $shipment = $shipmentId > 0
            ? $shipRepo->find($shipmentId)
            : $shipRepo->findLatestForOrder($id);
        if ($shipment === null || (int) ($shipment['order_id'] ?? 0) !== $id) {
            $this->notify->error('No courier shipment for this order.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        try {
            $courier = new CourierShipmentService(
                $this->channels,
                $this->factory,
                $shipRepo,
                BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store',
                $this->db,
            );
            $result = $courier->pollTracking($shipment);
            try {
                (new SyncLogRepository($this->db))->log(
                    (int) ($shipment['courier_channel_id'] ?? 0),
                    'tracking_poll',
                    'ok',
                    'Order #' . $id . ' shipment → ' . ($result['status'] ?? ''),
                    ['order_id' => $id, 'shipment_id' => (int) $shipment['id'], 'status' => $result['status'] ?? ''],
                    $this->auth->id(),
                    '',
                );
            } catch (\Throwable) {
            }
            $this->notify->success('Tracking updated: ' . (string) ($result['status'] ?? 'created'));
        } catch (\Throwable $e) {
            $this->notify->error('Tracking sync failed: ' . $e->getMessage());
        }
        redirect_to('store/admin/orders/view', ['id' => $id]);
    }

    public function voidLabelAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        $shipRepo = new ShipmentRepository($this->db);
        $shipmentId = (int) $this->request->post('shipment_id', 'int', 0);
        $shipment = $shipmentId > 0
            ? $shipRepo->find($shipmentId)
            : $shipRepo->findLatestForOrder($id);
        if ($shipment === null || (int) ($shipment['order_id'] ?? 0) !== $id) {
            $this->notify->error('Shipment not found.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        try {
            $courier = new CourierShipmentService(
                $this->channels,
                $this->factory,
                $shipRepo,
                BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store',
                $this->db,
            );
            $result = $courier->voidShipment($shipment);
            $this->notify->success(
                'Label voided' . (!empty($result['remote']) ? ' (courier notified).' : ' locally.')
            );
            $this->logOrderAudit($id, 'void_label', 'Voided shipment #' . (int) $shipment['id'], [
                'shipment_id' => (int) $shipment['id'],
                'remote'      => !empty($result['remote']),
            ]);
        } catch (\Throwable $e) {
            $this->notify->error('Void failed: ' . $e->getMessage());
        }
        redirect_to('store/admin/orders/view', ['id' => $id]);
    }

    public function recreateLabelAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        $data = $this->orders->find($id);
        if ($data === null) {
            throw new NotFoundException('Order not found.');
        }

        try {
            $shipRepo = new ShipmentRepository($this->db);
            $courier = new CourierShipmentService(
                $this->channels,
                $this->factory,
                $shipRepo,
                BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store',
                $this->db,
            );
            $created = $courier->recreateLabels($data['order'], $data['items']);
            $this->notify->success(
                'Labels recreated (' . (int) ($created['parcel_count'] ?? 1) . ' parcel(s)).'
            );
            $this->logOrderAudit($id, 'recreate_label', 'Recreated courier labels', [
                'parcel_count' => (int) ($created['parcel_count'] ?? 1),
                'tracking'     => (string) ($created['tracking_number'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->notify->error('Recreate failed: ' . $e->getMessage());
        }
        redirect_to('store/admin/orders/view', ['id' => $id]);
    }

    public function labelAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $data = $this->orders->find($id);
        if ($data === null) {
            throw new NotFoundException('Order not found.');
        }

        $shipRepo = new ShipmentRepository($this->db);
        $shipmentId = (int) $this->request->get('shipment_id', 'int', 0);
        $shipment = $shipmentId > 0
            ? $shipRepo->find($shipmentId)
            : $shipRepo->findLatestForOrder($id);
        if ($shipment === null || (int) ($shipment['order_id'] ?? 0) !== $id) {
            $this->notify->error('No courier shipment for this order.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        try {
            $courier = new CourierShipmentService(
                $this->channels,
                $this->factory,
                $shipRepo,
                BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store',
                $this->db,
            );
            $rel = $courier->ensureLabelFile($shipment);
        } catch (\Throwable $e) {
            $this->notify->error('Label unavailable: ' . $e->getMessage());
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        $full = BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store'
            . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);

        if (!is_file($full)) {
            $this->notify->error('Label file not found.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        header('Content-Type: application/pdf');
        header(
            'Content-Disposition: inline; filename="evri-'
            . preg_replace('/\W+/', '', (string) ($shipment['barcode'] ?? (string) $id))
            . '.pdf"'
        );
        header('Content-Length: ' . (string) filesize($full));
        readfile($full);
        exit;
    }

    // =========================================================================
    // Invoice print (when Create Invoice Printing is on)
    // =========================================================================

    public function cancelChannelAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }
        try {
            $this->request->checkToken();
            $id = (int) $this->request->get('id', 'int', 0);
            $svc = new OrderChannelActionService(
                $this->db,
                $this->channels,
                $this->factory,
                $this->orders,
                new SyncLogRepository($this->db),
                new OrderAuditRepository($this->db),
            );
            $r = $svc->cancelOnChannelAndLocal(
                $id,
                $this->auth->id(),
                $this->currentOrderAuditUserLabel()
            );
            $msg = 'Order cancelled locally.';
            if (($r['channel'] ?? '') === 'ok') {
                $msg = 'Cancelled on channel and locally.';
            } elseif (!empty($r['skipped'])) {
                $msg .= ' ' . $r['skipped'];
            }
            $this->notify->success($msg);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/orders/view', ['id' => (int) $this->request->get('id', 'int', 0)]);
    }

    public function refundAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }
        try {
            $this->request->checkToken();
            $id = (int) $this->request->get('id', 'int', 0);
            $svc = new OrderChannelActionService(
                $this->db,
                $this->channels,
                $this->factory,
                $this->orders,
                new SyncLogRepository($this->db),
                new OrderAuditRepository($this->db),
            );
            $r = $svc->issueRefund(
                $id,
                (float) $this->request->post('amount', 'float', 0),
                (string) $this->request->post('currency', 'string', 'GBP'),
                (string) $this->request->post('comments', 'string', ''),
                trim((string) $this->request->post('return_id', 'string', '')) ?: null,
                $this->auth->id(),
                $this->currentOrderAuditUserLabel()
            );
            if (($r['status'] ?? '') === 'ok') {
                $this->notify->success('Refund issued on channel.');
            } else {
                $this->notify->warning((string) ($r['skipped'] ?? 'Refund skipped.'));
            }
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/orders/view', ['id' => (int) $this->request->get('id', 'int', 0)]);
    }

    public function preferCourierAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }
        try {
            $this->request->checkToken();
            $id = (int) $this->request->get('id', 'int', 0);
            if ($this->orders->find($id) === null) {
                throw new NotFoundException('Order not found.');
            }
            $ratePick = trim((string) $this->request->post('rate_pick', 'string', ''));
            $pref = '';
            $channelId = 0;
            if ($ratePick !== '' && str_contains($ratePick, '|')) {
                [$pref, $channelId] = array_pad(explode('|', $ratePick, 2), 2, '');
                $channelId = (int) $channelId;
            }
            $this->db->update('store_orders', [
                'preferred_courier' => mb_substr(trim($pref), 0, 32),
            ], ['id' => $id]);
            $this->logOrderAudit($id, 'prefer_courier', 'Preferred courier set to ' . $pref, [
                'preferred_courier' => $pref,
                'courier_channel_id' => $channelId,
            ]);
            $this->notify->success('Preferred courier saved.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/orders/view', ['id' => (int) $this->request->get('id', 'int', 0)]);
    }

    public function creditNoteAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $returnId = (int) $this->request->get('return_id', 'int', 0);
        $data = $this->orders->find($id);
        if ($data === null) {
            throw new NotFoundException('Order not found.');
        }
        $order = $data['order'];
        $items = $data['items'];
        $ret = null;
        if ($returnId > 0) {
            try {
                $ret = (new ReturnRepository($this->db))->find($returnId);
            } catch (\Throwable) {
            }
        }
        $customers = new CustomerRepository($this->db);
        $customer = null;
        $email = trim((string) ($order['buyer_email'] ?? ''));
        if ($email !== '') {
            $customer = $customers->findByEmail($email);
        }
        $addr = json_decode((string) ($order['shipping_address'] ?? ''), true) ?: [];
        $amount = $ret !== null ? (float) ($ret['refund_amount'] ?? 0) : (float) ($order['total'] ?? 0);
        $currency = (string) (($ret['currency'] ?? null) ?: ($order['currency'] ?? 'GBP'));

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Credit note '
           . htmlspecialchars((string) $order['external_order_id']) . '</title>';
        echo '<style>
body{font-family:system-ui,sans-serif;margin:2rem;color:#111}
h1{font-size:1.4rem;margin:0 0 .5rem}
table{width:100%;border-collapse:collapse;margin-top:1rem}
th,td{border-bottom:1px solid #ddd;padding:.5rem;text-align:left}
.meta{color:#555;font-size:.9rem}.tot{font-weight:700;font-size:1.1rem;margin-top:1rem}
@media print{button{display:none}}
</style></head><body>';
        echo '<button onclick="window.print()">Print</button>';
        echo '<h1>Credit note</h1>';
        echo '<div class="meta">Order <strong>'
           . htmlspecialchars((string) $order['external_order_id']) . '</strong>';
        if ($returnId > 0) {
            echo ' · Return #' . $returnId;
        }
        echo ' · ' . htmlspecialchars((string) ($order['created_at'] ?? '')) . '</div>';
        if ($customer !== null && trim((string) ($customer['vat_number'] ?? '')) !== '') {
            echo '<div class="meta">Customer VAT: '
               . htmlspecialchars((string) $customer['vat_number']) . '</div>';
        }
        echo '<p><strong>Bill to</strong><br>'
           . htmlspecialchars((string) ($order['shipping_name'] ?? '')) . '<br>'
           . htmlspecialchars((string) ($addr['addressLine1'] ?? '')) . '</p>';
        if ($ret !== null) {
            echo '<p class="meta">Reason: ' . htmlspecialchars((string) ($ret['reason'] ?? '')) . '</p>';
        }
        echo '<table><thead><tr><th>SKU</th><th>Item</th><th>Qty</th><th>Price</th></tr></thead><tbody>';
        foreach ($items as $item) {
            echo '<tr><td>' . htmlspecialchars((string) ($item['external_sku'] ?? '')) . '</td>'
               . '<td>' . htmlspecialchars((string) ($item['title'] ?? '')) . '</td>'
               . '<td>' . (int) ($item['qty'] ?? 1) . '</td>'
               . '<td>' . number_format((float) ($item['price'] ?? 0), 2) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<div class="tot">Credit '
           . number_format($amount, 2) . ' ' . htmlspecialchars($currency) . '</div>';
        echo '</body></html>';
        exit;
    }

    public function invoiceAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $data = $this->orders->find($id);
        if ($data === null) {
            throw new NotFoundException('Order not found.');
        }

        $order   = $data['order'];
        $items   = $data['items'];
        $channel = $this->channels->find((int) $order['channel_id']);

        if ($channel === null || !ChannelSettings::bool($channel, 'create_invoice_printing', false)) {
            $this->notify->error('Invoice printing is disabled for this integration.');
            redirect_to('store/admin/orders/view', ['id' => $id]);
            return;
        }

        $addr = json_decode((string) ($order['shipping_address'] ?? ''), true) ?: [];
        $customers = new CustomerRepository($this->db);
        $customer = null;
        $email = trim((string) ($order['buyer_email'] ?? ''));
        $buyer = trim((string) ($order['buyer_username'] ?? ''));
        if ($buyer !== '') {
            $customer = $customers->findByReference($buyer);
        }
        if ($customer === null && $email !== '') {
            $customer = $customers->findByEmail($email);
        }

        $channelVat = (float) ($channel->settings['vat_rate'] ?? $channel->settings['default_vat_rate'] ?? 20);
        $netTotal = 0.0;
        $vatTotal = 0.0;
        $lineRows = [];
        foreach ($items as $item) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $unit = (float) ($item['price'] ?? 0);
            $taxClass = trim((string) ($item['tax_class'] ?? ''));
            $includesVat = !empty($item['price_includes_vat']);
            if ($taxClass === '' && !empty($item['product_id'])) {
                $prow = $this->db->query(
                    'SELECT `tax_class`, `price_includes_vat` FROM `#__store_products` WHERE `id` = ? LIMIT 1',
                    [(int) $item['product_id']]
                )->row;
                if ($prow !== []) {
                    $taxClass = (string) ($prow['tax_class'] ?? '20');
                    $includesVat = !empty($prow['price_includes_vat']);
                }
            }
            $rate = is_numeric($taxClass) ? (float) $taxClass : $channelVat;
            $lineGross = $unit * $qty;
            if ($includesVat && $rate > 0) {
                $lineNet = round($lineGross / (1 + ($rate / 100)), 2);
                $lineVat = round($lineGross - $lineNet, 2);
            } else {
                $lineNet = round($lineGross, 2);
                $lineVat = round($lineNet * ($rate / 100), 2);
            }
            $netTotal += $lineNet;
            $vatTotal += $lineVat;
            $lineRows[] = [
                'sku'   => (string) ($item['external_sku'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'qty'   => $qty,
                'net'   => $lineNet,
                'vat'   => $lineVat,
                'rate'  => $rate,
                'gross' => $lineNet + $lineVat,
            ];
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice '
           . htmlspecialchars((string) $order['external_order_id']) . '</title>';
        echo '<style>
body{font-family:system-ui,sans-serif;margin:2rem;color:#111}
h1{font-size:1.4rem;margin:0 0 .5rem}
table{width:100%;border-collapse:collapse;margin-top:1.5rem}
th,td{border-bottom:1px solid #ddd;padding:.5rem;text-align:left}
th{font-size:.85rem;text-transform:uppercase;color:#555}
.meta{color:#555;font-size:.9rem}
.tot{font-weight:700;font-size:1.1rem;margin-top:1rem}
@media print{button{display:none}}
</style></head><body>';
        echo '<button onclick="window.print()">Print</button>';
        echo '<h1>Tax invoice</h1>';
        echo '<div class="meta">Order <strong>'
           . htmlspecialchars((string) $order['external_order_id']) . '</strong> · '
           . htmlspecialchars((string) ($channel->label ?? '')) . ' · '
           . htmlspecialchars((string) $order['created_at']) . '</div>';
        if ($customer !== null && trim((string) ($customer['vat_number'] ?? '')) !== '') {
            echo '<div class="meta">Customer VAT number: <strong>'
               . htmlspecialchars((string) $customer['vat_number']) . '</strong></div>';
        }
        echo '<p><strong>Ship to</strong><br>'
           . htmlspecialchars((string) $order['shipping_name']) . '<br>'
           . htmlspecialchars((string) ($addr['addressLine1'] ?? '')) . '<br>'
           . htmlspecialchars(trim(($addr['city'] ?? '') . ' ' . ($addr['postalCode'] ?? ''))) . '<br>'
           . htmlspecialchars((string) ($addr['countryCode'] ?? '')) . '</p>';
        echo '<table><thead><tr><th>SKU</th><th>Item</th><th>Qty</th><th>Net</th><th>VAT</th><th>Gross</th></tr></thead><tbody>';
        foreach ($lineRows as $line) {
            echo '<tr><td>' . htmlspecialchars($line['sku']) . '</td>'
               . '<td>' . htmlspecialchars($line['title'])
               . ' <span class="meta">(' . rtrim(rtrim(number_format((float) $line['rate'], 2, '.', ''), '0'), '.') . '%)</span></td>'
               . '<td>' . (int) $line['qty'] . '</td>'
               . '<td>' . number_format((float) $line['net'], 2) . '</td>'
               . '<td>' . number_format((float) $line['vat'], 2) . '</td>'
               . '<td>' . number_format((float) $line['gross'], 2) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<div class="meta">Net ' . number_format($netTotal, 2)
           . ' · VAT ' . number_format($vatTotal, 2) . '</div>';
        echo '<div class="tot">Total '
           . number_format($netTotal + $vatTotal, 2) . ' '
           . htmlspecialchars((string) $order['currency']) . '</div>';
        if ((float) ($order['channel_fee'] ?? 0) > 0) {
            echo '<div class="meta">Channel fee: '
               . number_format((float) $order['channel_fee'], 2) . '</div>';
        }
        echo '</body></html>';
        exit;
    }

    // =========================================================================
    // Sync from within the orders page
    // =========================================================================

    public function syncAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload and try again.');
            redirect_to('store/admin/orders');
            return;
        }

        $channelId = (int) $this->request->post('channel_id', 'int', 0);
        $days      = (int) $this->request->post('days', 'int', 30);
        if (!in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }

        $vault = CredentialsVault::forTenant(
            (string) $this->config->get('store.credentials_key', '')
        );
        $sync = new OrderSyncService($this->db, $this->channels, $this->factory);

        if ($channelId > 0) {
            $one = $this->channels->find($channelId);
            $channels = ($one !== null && $one->enabled && $one->isConnected()) ? [$one] : [];
        } else {
            $channels = array_values(array_filter(
                $this->channels->enabled(),
                static fn($c) => $c->isConnected()
            ));
        }

        $created = 0;
        $updated = 0;
        $errors  = [];
        $ran     = 0;

        foreach ($channels as $channel) {
            if (!$channel->isConnected()) {
                continue;
            }
            $ran++;
            try {
                $r = $sync->syncChannel($channel, $days);
                $created += $r['created'];
                $updated += $r['updated'];
                if (!empty($r['truncated'])) {
                    $errors[] = "{$channel->name}: more orders remain — pull again.";
                }
                $this->channels->update((int) $channel->id, [
                    'last_error' => '',
                ], $vault);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $errors[] = "{$channel->name}: {$msg}";
                $this->channels->update((int) $channel->id, [
                    'last_error' => $msg,
                ], $vault);
            }
        }

        if ($ran === 0) {
            $this->notify->warning('No connected channels to pull from. Connect eBay under Channels first.');
        } elseif ($errors !== []) {
            $this->notify->warning(
                "Pulled {$created} new, {$updated} updated. "
                . implode(' ', $errors)
            );
        } else {
            $this->notify->success("Pulled {$created} new order(s), {$updated} updated.");
        }

        $redirect = ['days' => $days];
        if ($channelId > 0) {
            $redirect['channel_id'] = $channelId;
        }
        redirect_to('store/admin/orders', $redirect);
    }

    /**
     * Order line items cell.
     * $showSku true  → Product SKUs column: short SKU x qty
     * $showSku false → Items column: truncated title × qty
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function renderOrderItemsCell(array $items, bool $showSku = true): string
    {
        $productUrl = fn(int $id): string => $this->url->to('store/admin/products/edit', ['id' => $id]);
        return $showSku
            ? store_render_short_skus($items, $productUrl)
            : store_render_item_titles($items, $productUrl);
    }

    private function extractOrderPostcode(mixed $shippingAddress): string
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

    private function truncateOrderText(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, max(1, $max - 1)) . '…';
    }

    /** @param array<string, mixed> $row */
    private function orderStatusTone(array $row): string
    {
        $status = strtoupper((string) ($row['status'] ?? ''));
        if ($status === 'CANCELLED') {
            return 'bad';
        }
        if ($status === 'FULFILLED' || $status === 'IN_PROGRESS' || !empty($row['pre_despatched'])) {
            return 'off';
        }
        if (empty($row['is_paid']) || !empty($row['on_hold'])) {
            return 'wait';
        }
        return 'ok';
    }

    private function formatOrderWhen(string $created): string
    {
        if ($created === '') {
            return '—';
        }
        $ts = strtotime($created);
        if ($ts === false) {
            return $created;
        }
        $day = date('Y-m-d', $ts);
        if ($day === date('Y-m-d')) {
            return 'Today';
        }
        if ($day === date('Y-m-d', strtotime('-1 day') ?: time())) {
            return 'Yesterday';
        }
        return date('j M', $ts);
    }

    private function renderChannelMark(string $type, string $label): string
    {
        $type = strtolower($type);
        $cls = 'sf-chan-other';
        $letter = $label !== '' ? mb_strtolower(mb_substr($label, 0, 1)) : 'c';
        if (str_contains($type, 'amazon')) {
            $cls = 'sf-chan-amazon';
            $letter = 'a';
        } elseif (str_contains($type, 'ebay')) {
            $cls = 'sf-chan-ebay';
            $letter = 'e';
        } elseif (str_contains($type, 'shopify')) {
            $cls = 'sf-chan-shopify';
            $letter = 's';
        }
        return '<span class="sf-chan"><span class="sf-chan-mark ' . $cls . '">'
            . $this->view->e($letter) . '</span>'
            . $this->view->e($label !== '' ? $label : 'Channel') . '</span>';
    }

    /** @param array<string, mixed> $row */
    private function renderOrderAddressCell(mixed $shippingAddress, array $row): string
    {
        $addr = $this->decodeOrderAddress($shippingAddress);
        $country = strtoupper(trim((string) ($addr['countryCode'] ?? $addr['country'] ?? $addr['country_code'] ?? 'GB')));
        $flagCls = match ($country) {
            'GB', 'UK', 'GBR' => 'sf-flag-gb',
            'US', 'USA' => 'sf-flag-us',
            'IE', 'IRL' => 'sf-flag-ie',
            default => 'sf-flag-other',
        };
        $flag = '<span class="sf-flag ' . $flagCls . '" title="' . $this->view->e($country !== '' ? $country : 'GB') . '"></span>';

        $parts = [];
        foreach ([
            $row['shipping_name'] ?? ($addr['fullName'] ?? $addr['name'] ?? ''),
            $addr['primaryPhone'] ?? $addr['phone'] ?? '',
            $addr['addressLine1'] ?? $addr['address1'] ?? '',
            $addr['addressLine2'] ?? $addr['address2'] ?? '',
            $addr['city'] ?? '',
            $addr['postalCode'] ?? $addr['postal_code'] ?? $addr['zip'] ?? '',
        ] as $part) {
            $part = trim((string) $part);
            if ($part !== '' && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        $line = $parts !== []
            ? mb_strtoupper(implode(', ', $parts))
            : (empty($row['is_paid']) ? 'UNPAID ORDER — N/A' : 'N/A');

        return '<div class="sf-addr">' . $flag
            . '<span>' . $this->view->e($this->truncateOrderText($line, 72)) . '</span></div>';
    }

    /** @return array<string, mixed> */
    private function decodeOrderAddress(mixed $shippingAddress): array
    {
        if (is_string($shippingAddress)) {
            $decoded = json_decode($shippingAddress, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($shippingAddress) ? $shippingAddress : [];
    }
}