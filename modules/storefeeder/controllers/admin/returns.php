<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\ChannelRepository;
use Module\StoreFeeder\Services\OrderRepository;
use Module\StoreFeeder\Services\ReturnRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreFeederAdminReturns extends Controller
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
                        . $e($this->url->to('storefeeder/admin/orders/view', ['id' => (int) $oid]))
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
                    . $e($this->url->to('storefeeder/admin/returns/view', ['id' => (int) $row['_row']['id']]))
                    . '">View</a></span>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset) {
            $this->view->assign('title', 'Returns');
            $this->view->block('right_sidebar', 'Returns');

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-arrow-return-left me-1"></i>Returns</h1>'
               . '<p class="text-muted small mb-0">RMA / refunds logged against orders.</p></div>';
            echo '</div>';

            echo $dataset->render();
        });
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
               . $e($this->url->to('storefeeder/admin/returns')) . '">Back to returns</a>';
            echo '</div>';

            echo '<div class="row g-3">';
            echo '<div class="col-lg-7">';
            echo '<div class="border rounded bg-white p-3 mb-3">';
            echo '<dl class="row mb-0 small">';
            echo '<dt class="col-sm-3">Status</dt><dd class="col-sm-9"><span class="badge text-bg-secondary">'
               . $e((string) $row['status']) . '</span></dd>';
            echo '<dt class="col-sm-3">Order</dt><dd class="col-sm-9">';
            if (!empty($row['order_id'])) {
                echo '<a href="' . $e($this->url->to('storefeeder/admin/orders/view', ['id' => (int) $row['order_id']])) . '">'
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
            echo '</dl></div></div>';

            echo '<div class="col-lg-5">';
            echo '<div class="border rounded bg-white p-3">';
            echo '<h2 class="h6 mb-3">Update status</h2>';
            echo '<form method="post" action="'
               . $e($this->url->to('storefeeder/admin/returns/update-status', ['id' => (int) $row['id']])) . '">';
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
            redirect_to('storefeeder/admin/returns');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/returns');
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

        redirect_to('storefeeder/admin/orders/view', ['id' => $orderId]);
    }

    public function updateStatusAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/returns');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/returns');
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

        redirect_to('storefeeder/admin/returns/view', ['id' => $id]);
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
