<?php
declare(strict_types=1);

use Module\Store\Services\ChannelClientFactory;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\Channels\Marketplace\Ebay\EbayChannelClient;
use Module\Store\Services\CredentialsVault;
use Module\Store\Services\InventoryService;
use Module\Store\Services\OrderRepository;
use Module\Store\Services\ReturnRepository;
use Module\Store\Services\ReturnSyncService;
use Module\Store\Services\SyncLogRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreAdminReturns extends Controller
{
    private ReturnRepository  $returns;
    private OrderRepository   $orders;
    private ChannelRepository $channels;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->returns  = $this->load->service('ReturnRepository');
        $this->orders   = $this->load->service('OrderRepository');
        $this->channels = $this->load->service('ChannelRepository');
    }

    public function indexAction(): void
    {
        $channels = $this->channels->all();

        $channelOptions = ['0' => 'All channels'];
        foreach ($channels as $c) {
            $channelOptions[(string) (int) $c->id] = $c->label !== '' ? $c->label : $c->name;
        }

        $statusOptions = ['' => 'All statuses'];
        foreach (['open', 'approved', 'received', 'refunded', 'closed', 'cancelled'] as $st) {
            $statusOptions[$st] = ucfirst($st);
        }

        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->searchPlaceholder('Order id, buyer, reason…')
            ->sortable(['id', 'created_at', 'status', 'refund_amount'])
            ->defaultSort('id', 'desc')
            ->filter('status', 'select', $statusOptions)
            ->filter('channel_id', 'select', $channelOptions)
            ->columns([
                'id'           => '#',
                'order'        => 'Order',
                'channel'      => 'Channel',
                'buyer'        => 'Buyer',
                'status'       => 'Status',
                'reason'       => 'Reason',
                'refund'       => 'Refund',
                'created_at'   => 'Created',
                'actions'      => '',
            ])
            ->query(function ($db, array $state): array {
                $status = (string) ($state['filters']['status'] ?? '');
                $channelId = (int) ($state['filters']['channel_id'] ?? 0);
                $rows = $this->returns->list(
                    $status,
                    $channelId,
                    $state['q'],
                    $state['per_page'],
                    $state['offset']
                );
                $total = $this->returns->count($status, $channelId, $state['q']);

                return [
                    'rows'  => array_map(static fn(array $row): array => ['_row' => $row], $rows),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $dataset->columns([
            'id' => fn(array $row): string => (string) (int) $row['_row']['id'],
            'order' => function (array $row) use ($e): string {
                $r = $row['_row'];
                $orderLabel = (string) ($r['external_order_id'] !== ''
                    ? $r['external_order_id']
                    : ($r['order_external_id'] !== '' ? $r['order_external_id'] : '—'));
                $oid = $r['order_id'];
                if ($oid !== null && (int) $oid > 0) {
                    return '<a href="'
                        . $e($this->url->to('store/admin/orders/view', ['id' => (int) $oid]))
                        . '">' . $e($orderLabel) . '</a>';
                }
                return $e($orderLabel);
            },
            'channel' => function (array $row) use ($e): string {
                return '<span class="small">' . $e((string) ($row['_row']['channel_label'] !== ''
                    ? $row['_row']['channel_label'] : '—')) . '</span>';
            },
            'buyer' => function (array $row) use ($e): string {
                return '<span class="small">' . $e((string) ($row['_row']['buyer_username'] !== ''
                    ? $row['_row']['buyer_username'] : '—')) . '</span>';
            },
            'status' => function (array $row) use ($e): string {
                $st = (string) $row['_row']['status'];
                $badge = match (strtolower($st)) {
                    'open', 'approved' => 'text-bg-warning',
                    'received' => 'text-bg-info',
                    'refunded', 'closed' => 'text-bg-success',
                    'cancelled' => 'text-bg-secondary',
                    default => 'text-bg-light',
                };
                return '<span class="badge ' . $badge . '">' . $e($st) . '</span>';
            },
            'reason' => function (array $row) use ($e): string {
                return '<span class="small">' . $e((string) $row['_row']['reason']) . '</span>';
            },
            'refund' => function (array $row) use ($e): string {
                $r = $row['_row'];
                return '<span class="text-end text-nowrap d-block">'
                    . number_format((float) $r['refund_amount'], 2)
                    . ' ' . $e((string) $r['currency']) . '</span>';
            },
            'created_at' => function (array $row) use ($e): string {
                return '<span class="small text-nowrap">' . $e((string) $row['_row']['created_at']) . '</span>';
            },
            'actions' => function (array $row) use ($e): string {
                return '<span class="text-end d-block"><a class="btn btn-sm btn-outline-secondary" href="'
                    . $e($this->url->to('store/admin/returns/view', ['id' => (int) $row['_row']['id']]))
                    . '">View</a></span>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset) {
            $this->view->assign('title', 'Returns');
            $this->view->block('right_sidebar', 'Returns');

            $ebayChannels = array_values(array_filter(
                $this->channels->all(),
                static fn($c) => $c->type === 'ebay' && $c->enabled && $c->isConnected()
            ));

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-arrow-return-left me-1"></i>Returns</h1>'
               . '<p class="text-muted small mb-0">RMA / refunds logged against orders.</p></div>';
            echo '<div class="d-flex flex-wrap gap-2">';
            if ($ebayChannels !== []) {
                echo '<form method="post" class="d-inline-flex gap-1 align-items-center" action="'
                   . $this->view->e($this->url->to('store/admin/returns/sync')) . '">'
                   . $this->request->csrfField()
                   . '<select name="channel_id" class="form-select form-select-sm" style="width:auto">';
                foreach ($ebayChannels as $c) {
                    echo '<option value="' . (int) $c->id . '">'
                       . $this->view->e($c->label !== '' ? $c->label : $c->name) . '</option>';
                }
                echo '</select>'
                   . '<button type="submit" class="btn btn-outline-secondary btn-sm">'
                   . '<i class="bi bi-arrow-repeat me-1"></i>Sync eBay returns</button></form>';
            }
            echo '<a class="btn btn-outline-primary btn-sm" href="'
               . $this->view->e($this->url->to('store/admin/returns/inbound'))
               . '"><i class="bi bi-box-arrow-in-down me-1"></i>Inbound receive</a>';
            echo '</div></div>';

            echo $dataset->render();
        });
    }

    public function syncAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/returns');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/returns');
            return;
        }

        $channelId = (int) $this->request->post('channel_id', 'int', 0);
        $channel = $this->channels->find($channelId);
        if ($channel === null || $channel->type !== 'ebay' || !$channel->isConnected()) {
            $this->notify->error('Select a connected eBay channel.');
            redirect_to('store/admin/returns');
            return;
        }

        $vault = CredentialsVault::forTenant(
            (string) $this->config->get('store.credentials_key', '')
        );
        $factory = new ChannelClientFactory($vault, $this->channels);
        $sync = new ReturnSyncService($this->db, $factory, $this->returns);
        $log = new SyncLogRepository($this->db);

        try {
            $stats = $sync->syncChannel($channel, 90);
            $log->log(
                (int) $channel->id,
                'returns_sync',
                'ok',
                sprintf(
                    'Returns sync: %d created, %d updated, %d skipped, %d errors',
                    $stats['created'],
                    $stats['updated'],
                    $stats['skipped'],
                    $stats['errors']
                ),
                $stats,
                $this->auth->id(),
                '',
            );
            $this->notify->success(
                'Synced returns: +' . $stats['created'] . ' / ~' . $stats['updated']
                . ($stats['errors'] > 0 ? ' (' . $stats['errors'] . ' errors)' : '')
            );
        } catch (\Throwable $e) {
            $log->log(
                (int) $channel->id,
                'returns_sync',
                'error',
                $e->getMessage(),
                [],
                $this->auth->id(),
                '',
            );
            $this->notify->error('Returns sync failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/returns');
    }

    public function inboundAction(): void
    {
        $q = trim((string) $this->request->get('q', 'string', ''));
        $rows = $this->returns->inboundQueue(100, 0, $q);
        $total = $this->returns->countInbound($q);

        echo $this->view->inline(function () use ($rows, $total, $q) {
            $this->view->assign('title', 'Returns inbound');
            $this->view->block('right_sidebar', 'Returns');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-box-arrow-in-down me-1"></i>Returns inbound</h1>'
               . '<p class="text-muted small mb-0">'
               . (int) $total . ' open/approved return(s) awaiting receive.</p></div>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/returns')) . '">All returns</a>';
            echo '</div>';

            echo '<form method="get" class="mb-3 d-flex gap-2">';
            echo '<input type="hidden" name="route" value="store/admin/returns/inbound">';
            echo '<input type="search" class="form-control form-control-sm" name="q" value="'
               . $e($q) . '" placeholder="Order / buyer…" style="max-width:16rem">';
            echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>';
            echo '</form>';

            if ($rows === []) {
                echo '<div class="alert alert-success">Nothing waiting to receive.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle bg-white border">';
            echo '<thead class="table-light"><tr>'
               . '<th>#</th><th>Order</th><th>Channel</th><th>Buyer</th><th>Status</th>'
               . '<th>Reason</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr>';
                echo '<td>' . (int) $r['id'] . '</td>';
                echo '<td>';
                if (!empty($r['order_id'])) {
                    echo '<a href="' . $e($this->url->to('store/admin/orders/view', ['id' => (int) $r['order_id']])) . '">'
                       . $e((string) ($r['external_order_id'] !== '' ? $r['external_order_id'] : '#' . $r['order_id']))
                       . '</a>';
                } else {
                    echo $e((string) ($r['external_order_id'] !== '' ? $r['external_order_id'] : '—'));
                }
                echo '</td>';
                echo '<td class="small">' . $e((string) ($r['channel_label'] !== '' ? $r['channel_label'] : '—')) . '</td>';
                echo '<td class="small">' . $e((string) ($r['buyer_username'] !== '' ? $r['buyer_username'] : '—')) . '</td>';
                echo '<td><span class="badge text-bg-warning">' . $e((string) $r['status']) . '</span></td>';
                echo '<td class="small">' . $e((string) $r['reason']) . '</td>';
                echo '<td class="text-end text-nowrap">';
                echo '<form method="post" class="d-inline" action="'
                   . $e($this->url->to('store/admin/returns/receive', ['id' => (int) $r['id']])) . '">'
                   . $this->request->csrfField()
                   . '<button type="submit" class="btn btn-sm btn-success"'
                   . ' onclick="return confirm(\'Mark return #' . (int) $r['id'] . ' as received and restock?\');">'
                   . 'Receive</button></form> ';
                echo '<a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($this->url->to('store/admin/returns/view', ['id' => (int) $r['id']])) . '">View</a>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    public function receiveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/returns/inbound');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/returns/inbound');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $row = $this->returns->find($id);
        if ($row === null) {
            throw new NotFoundException('Return not found.');
        }

        try {
            $this->returns->updateStatus($id, 'received');
            if (empty($row['restocked_at'])) {
                $this->restockReturnItems($row);
            }
            $this->notify->success('Return #' . $id . ' marked received.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/returns/inbound');
    }

    public function viewAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $row = $this->returns->find($id);
        if ($row === null) {
            throw new NotFoundException('Return not found.');
        }

        echo $this->view->inline(function () use ($row) {
            $this->view->assign('title', 'Return #' . $row['id']);
            $this->view->block('right_sidebar', 'Returns');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<h1 class="h3 mb-0">Return #' . (int) $row['id'] . '</h1>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/returns')) . '">Back to returns</a>';
            echo '</div>';

            echo '<div class="row g-3">';
            echo '<div class="col-lg-7">';
            echo '<div class="border rounded bg-white p-3 mb-3">';
            echo '<dl class="row mb-0 small">';
            echo '<dt class="col-sm-3">Status</dt><dd class="col-sm-9"><span class="badge text-bg-secondary">'
               . $e((string) $row['status']) . '</span></dd>';
            echo '<dt class="col-sm-3">Order</dt><dd class="col-sm-9">';
            if (!empty($row['order_id'])) {
                echo '<a href="' . $e($this->url->to('store/admin/orders/view', ['id' => (int) $row['order_id']])) . '">'
                   . $e((string) ($row['external_order_id'] !== '' ? $row['external_order_id'] : '#' . $row['order_id']))
                   . '</a>';
            } else {
                echo $e((string) ($row['external_order_id'] !== '' ? $row['external_order_id'] : '—'));
            }
            echo '</dd>';
            echo '<dt class="col-sm-3">Channel</dt><dd class="col-sm-9">'
               . $e((string) ($row['channel_label'] !== '' ? $row['channel_label'] : '—')) . '</dd>';
            echo '<dt class="col-sm-3">Buyer</dt><dd class="col-sm-9">'
               . $e((string) ($row['buyer_username'] !== '' ? $row['buyer_username'] : '—')) . '</dd>';
            echo '<dt class="col-sm-3">Reason</dt><dd class="col-sm-9">' . $e((string) $row['reason']) . '</dd>';
            echo '<dt class="col-sm-3">Refund</dt><dd class="col-sm-9">'
               . number_format((float) $row['refund_amount'], 2) . ' ' . $e((string) $row['currency']) . '</dd>';
            echo '<dt class="col-sm-3">Notes</dt><dd class="col-sm-9">'
               . ($row['notes'] !== '' ? $e((string) $row['notes']) : '<span class="text-muted">—</span>') . '</dd>';
            echo '<dt class="col-sm-3">Created</dt><dd class="col-sm-9">' . $e((string) $row['created_at']) . '</dd>';
            echo '<dt class="col-sm-3">Updated</dt><dd class="col-sm-9">' . $e((string) $row['updated_at']) . '</dd>';
            echo '<dt class="col-sm-3">Restocked</dt><dd class="col-sm-9">'
               . ($row['restocked_at'] !== null
                    ? $e((string) $row['restocked_at'])
                    : '<span class="text-muted">Not yet</span>') . '</dd>';
            echo '</dl></div></div>';

            echo '<div class="col-lg-5">';
            echo '<div class="border rounded bg-white p-3">';
            echo '<h2 class="h6 mb-3">Update status</h2>';
            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/returns/update-status', ['id' => (int) $row['id']])) . '">';
            echo $this->request->csrfField();
            echo '<div class="mb-2"><label class="form-label small">Status</label>'
               . '<select name="status" class="form-select form-select-sm" required>';
            foreach (['open', 'approved', 'received', 'refunded', 'closed', 'cancelled'] as $st) {
                echo '<option value="' . $e($st) . '"'
                   . ($row['status'] === $st ? ' selected' : '') . '>' . $e(ucfirst($st)) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="mb-3"><label class="form-label small">Notes (optional)</label>'
               . '<textarea name="notes" class="form-control form-control-sm" rows="3" maxlength="1000">'
               . $e((string) $row['notes']) . '</textarea></div>';
            echo '<button type="submit" class="btn btn-primary btn-sm">Save</button>';
            echo '</form></div></div>';
            echo '</div>';
        });
    }

    public function createAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/returns');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/returns');
            return;
        }

        $orderId = (int) $this->request->get('order_id', 'int', 0);
        if ($orderId <= 0) {
            $orderId = (int) $this->request->post('order_id', 'int', 0);
        }

        $data = $this->orders->find($orderId);
        if ($data === null) {
            throw new NotFoundException('Order not found.');
        }
        $order = $data['order'];

        try {
            $rid = $this->returns->create([
                'order_id'          => $orderId,
                'channel_id'        => (int) ($order['channel_id'] ?? 0) ?: null,
                'external_order_id' => (string) ($order['external_order_id'] ?? ''),
                'status'            => (string) $this->request->post('status', 'string', 'open'),
                'reason'            => (string) $this->request->post('reason', 'string', ''),
                'notes'             => (string) $this->request->post('notes', 'string', ''),
                'refund_amount'     => (float) $this->request->post('refund_amount', 'float', 0),
                'currency'          => (string) (($order['currency'] ?? '') !== '' ? $order['currency'] : 'GBP'),
                'buyer_username'    => (string) ($order['buyer_username'] ?? ''),
                'items'             => [],
            ]);

            try {
                $this->load->service('OrderAuditRepository')->log(
                    $orderId,
                    'return_logged',
                    'Return #' . $rid . ' logged.',
                    [
                        'return_id' => $rid,
                        'reason'    => (string) $this->request->post('reason', 'string', ''),
                        'status'    => (string) $this->request->post('status', 'string', 'open'),
                    ],
                    $this->auth->id(),
                    $this->currentUserLabel(),
                );
            } catch (\Throwable $e) {
                error_log('Order audit on return: ' . $e->getMessage());
            }

            $this->notify->success('Return #' . $rid . ' logged.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect_to('store/admin/orders/view', ['id' => $orderId]);
    }

    public function updateStatusAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/returns');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/returns');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $row = $this->returns->find($id);
        if ($row === null) {
            throw new NotFoundException('Return not found.');
        }

        $status = (string) $this->request->post('status', 'string', 'open');
        $notes = (string) $this->request->post('notes', 'string', '');

        try {
            $this->returns->updateStatus($id, $status, $notes);

            if (strtolower($status) === 'received' && empty($row['restocked_at'])) {
                $this->restockReturnItems($row);
            }

            if (strtolower($status) === 'refunded') {
                $this->tryChannelRefund($row);
            }

            if (!empty($row['order_id'])) {
                try {
                    $this->load->service('OrderAuditRepository')->log(
                        (int) $row['order_id'],
                        'return_status',
                        'Return #' . $id . ' → ' . $status,
                        [
                            'return_id' => $id,
                            'from'      => $row['status'],
                            'to'        => $status,
                        ],
                        $this->auth->id(),
                        $this->currentUserLabel(),
                    );
                } catch (\Throwable $e) {
                    error_log('Order audit on return status: ' . $e->getMessage());
                }
            }
            $this->notify->success('Return updated.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect_to('store/admin/returns/view', ['id' => $id]);
    }

    /**
     * Restock variant qty from items_json once when status becomes received.
     *
     * @param array<string, mixed> $row
     */
    private function restockReturnItems(array $row): void
    {
        $items = is_array($row['items'] ?? null) ? $row['items'] : [];
        $inventory = new InventoryService($this->db);
        $restocked = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $variantId = (int) ($item['variant_id'] ?? $item['variantId'] ?? 0);
            $qty = (int) ($item['qty'] ?? $item['quantity'] ?? $item['qty_returned'] ?? 0);
            if ($variantId <= 0 || $qty <= 0) {
                continue;
            }
            $inventory->adjust(
                $variantId,
                $qty,
                'return_restock',
                'Return #' . (int) $row['id'],
                $this->auth->id(),
            );
            $restocked++;
        }

        if ($restocked > 0) {
            $this->returns->markRestocked((int) $row['id']);
            $this->notify->success('Restocked ' . $restocked . ' line(s) into inventory.');
            return;
        }

        // No restockable lines — stamp anyway so status=received does not retry forever.
        $this->returns->markRestocked((int) $row['id']);
    }

    /**
     * Best-effort channel refund; always keeps the local refund_amount.
     *
     * @param array<string, mixed> $row
     */
    private function tryChannelRefund(array $row): void
    {
        $channelId = (int) ($row['channel_id'] ?? 0);
        $externalId = trim((string) ($row['external_id'] ?? ''));
        $amount = (float) ($row['refund_amount'] ?? 0);
        $currency = (string) (($row['currency'] ?? '') !== '' ? $row['currency'] : 'GBP');
        $log = new SyncLogRepository($this->db);

        if ($channelId <= 0) {
            error_log('Return #' . (int) $row['id'] . ' refunded locally (no channel); amount=' . $amount);
            return;
        }

        try {
            $channel = $this->channels->find($channelId);
            if ($channel === null || !$channel->isConnected()) {
                $log->log($channelId, 'return_refund', 'skipped', 'Channel not connected for return refund', [
                    'return_id' => (int) $row['id'],
                    'amount'    => $amount,
                ], $this->auth->id(), $this->currentUserLabel());
                return;
            }

            $vault = CredentialsVault::forTenant(
                (string) $this->config->get('store.credentials_key', '')
            );
            $factory = new ChannelClientFactory($vault, $this->channels);
            $client = $factory->make($channel);

            if ($channel->type === 'ebay' && $client instanceof EbayChannelClient && $externalId !== '') {
                $client->issueReturnRefund(
                    $externalId,
                    $amount > 0 ? $amount : null,
                    $currency,
                    'Return #' . (int) $row['id'] . ' refunded in Store admin.',
                );
                $log->log($channelId, 'return_refund', 'ok', 'eBay return refund issued for #' . (int) $row['id'], [
                    'return_id'   => (int) $row['id'],
                    'external_id' => $externalId,
                    'amount'      => $amount,
                    'currency'    => $currency,
                ], $this->auth->id(), $this->currentUserLabel());
                return;
            }

            $log->log($channelId, 'return_refund', 'skipped', 'Channel refund not available; local refund amount kept', [
                'return_id'    => (int) $row['id'],
                'channel_type' => $channel->type,
                'amount'       => $amount,
                'currency'     => $currency,
                'external_id'  => $externalId,
            ], $this->auth->id(), $this->currentUserLabel());
        } catch (\Throwable $e) {
            $log->log($channelId, 'return_refund', 'error', $e->getMessage(), [
                'return_id'   => (int) $row['id'],
                'external_id' => $externalId,
                'amount'      => $amount,
            ], $this->auth->id(), $this->currentUserLabel());
            error_log('Return channel refund #' . (int) $row['id'] . ': ' . $e->getMessage());
        }
    }

    private function currentUserLabel(): string
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
}
