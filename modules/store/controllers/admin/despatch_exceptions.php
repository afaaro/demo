<?php
declare(strict_types=1);

use Module\Store\Services\OrderRepository;
use Module\Store\Services\SyncLogRepository;
use System\Engine\Controller;
use System\Library\Dataset;

/**
 * Orders needing despatch attention: attention_required flag or recent
 * sync_log errors for despatch / label / ship actions.
 */
class StoreAdminDespatchExceptions extends Controller
{
    private OrderRepository $orders;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->orders = new OrderRepository($this->db);
    }

    public function indexAction(): void
    {
        $attentionOrders = $this->orders->paginate(100, 0, ['f_attention' => '1']);
        $syncErrors = (new SyncLogRepository($this->db))->recentErrors(
            40,
            ['despatch', 'label', 'ship', 'shipping', 'fulfillment', 'return_refund'],
            168
        );

        $byId = [];
        foreach ($attentionOrders as $row) {
            $oid = (int) ($row['id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $byId[$oid] = [
                'order'   => $row,
                'sources' => ['attention_required'],
                'errors'  => [],
            ];
        }

        foreach ($syncErrors as $err) {
            $details = is_array($err['details'] ?? null) ? $err['details'] : [];
            $oid = (int) ($details['order_id'] ?? $details['id'] ?? 0);
            if ($oid <= 0) {
                // Keep orphan sync errors visible as a synthetic row.
                $key = 'log:' . (int) $err['id'];
                $byId[$key] = [
                    'order'   => null,
                    'sources' => ['sync_log'],
                    'errors'  => [$err],
                    'log_only'=> true,
                ];
                continue;
            }
            if (!isset($byId[$oid])) {
                $data = $this->orders->find($oid);
                $orderRow = $data['order'] ?? null;
                if ($orderRow === null) {
                    continue;
                }
                // find() has no channel join — decorate for the table.
                if (empty($orderRow['channel_label']) && !empty($orderRow['channel_id'])) {
                    try {
                        $ch = $this->db->query(
                            "SELECT COALESCE(NULLIF(`label`, ''), `name`, '') AS label
                               FROM `#__store_channels` WHERE `id` = ? LIMIT 1",
                            [(int) $orderRow['channel_id']]
                        )->row;
                        $orderRow['channel_label'] = (string) ($ch['label'] ?? '');
                    } catch (\Throwable) {
                        $orderRow['channel_label'] = '';
                    }
                }
                $byId[$oid] = [
                    'order'   => $orderRow,
                    'sources' => [],
                    'errors'  => [],
                ];
            }
            $byId[$oid]['sources'][] = 'sync_log';
            $byId[$oid]['errors'][] = $err;
        }

        $rows = array_values($byId);

        $dataset = (new Dataset($this->registry))
            ->perPage(50)
            ->searchPlaceholder('Order id…')
            ->sortable(['id'])
            ->defaultSort('id', 'desc')
            ->columns([
                'order'   => 'Order',
                'channel' => 'Channel',
                'status'  => 'Status',
                'reason'  => 'Why',
                'when'    => 'Updated',
                'actions' => '',
            ])
            ->query(function ($db, array $state) use ($rows): array {
                $q = trim((string) ($state['q'] ?? ''));
                $filtered = $rows;
                if ($q !== '') {
                    $filtered = array_values(array_filter($rows, static function (array $r) use ($q): bool {
                        $o = $r['order'] ?? null;
                        if (!is_array($o)) {
                            foreach ($r['errors'] ?? [] as $err) {
                                if (stripos((string) ($err['summary'] ?? ''), $q) !== false) {
                                    return true;
                                }
                            }
                            return false;
                        }
                        $hay = (string) ($o['external_order_id'] ?? '') . ' ' . (string) ($o['id'] ?? '');
                        return stripos($hay, $q) !== false;
                    }));
                }
                $total = count($filtered);
                $slice = array_slice($filtered, (int) $state['offset'], (int) $state['per_page']);
                return [
                    'rows'  => array_map(static fn(array $r): array => ['_row' => $r], $slice),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $dataset->columns([
            'order' => function (array $row) use ($e): string {
                $r = $row['_row'];
                $o = $r['order'] ?? null;
                if (!is_array($o)) {
                    return '<span class="text-muted small">Sync log only</span>';
                }
                $label = (string) (($o['external_order_id'] ?? '') !== ''
                    ? $o['external_order_id']
                    : '#' . $o['id']);
                return '<a href="' . $e($this->url->to('store/admin/orders/view', ['id' => (int) $o['id']])) . '">'
                    . $e($label) . '</a>';
            },
            'channel' => function (array $row) use ($e): string {
                $o = $row['_row']['order'] ?? null;
                if (!is_array($o)) {
                    return '—';
                }
                return $e((string) ($o['channel_label'] ?? $o['channel_name'] ?? '—'));
            },
            'status' => function (array $row) use ($e): string {
                $o = $row['_row']['order'] ?? null;
                if (!is_array($o)) {
                    return '<span class="badge text-bg-danger">sync error</span>';
                }
                return '<span class="badge text-bg-secondary">' . $e((string) ($o['status'] ?? '')) . '</span>';
            },
            'reason' => function (array $row) use ($e): string {
                $r = $row['_row'];
                $bits = [];
                if (in_array('attention_required', $r['sources'] ?? [], true)) {
                    $bits[] = '<span class="badge text-bg-warning">Attention required</span>';
                }
                foreach ($r['errors'] ?? [] as $err) {
                    $bits[] = '<span class="small d-block mt-1"><code>'
                        . $e((string) ($err['action'] ?? '')) . '</code> '
                        . $e((string) ($err['summary'] ?? '')) . '</span>';
                }
                return $bits !== [] ? implode(' ', $bits) : '—';
            },
            'when' => function (array $row) use ($e): string {
                $o = $row['_row']['order'] ?? null;
                if (is_array($o)) {
                    return '<span class="small text-nowrap">'
                        . $e((string) ($o['updated_at'] ?? $o['created_at'] ?? '')) . '</span>';
                }
                $err = $row['_row']['errors'][0] ?? null;
                return is_array($err)
                    ? '<span class="small text-nowrap">' . $e((string) ($err['created_at'] ?? '')) . '</span>'
                    : '—';
            },
            'actions' => function (array $row) use ($e): string {
                $o = $row['_row']['order'] ?? null;
                if (!is_array($o)) {
                    return '';
                }
                return '<a class="btn btn-sm btn-outline-secondary" href="'
                    . $e($this->url->to('store/admin/orders/view', ['id' => (int) $o['id']]))
                    . '">Open</a>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $attentionOrders, $syncErrors) {
            $this->view->assign('title', 'Despatch exceptions');
            $this->view->block('right_sidebar', 'Orders');

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-exclamation-octagon me-1"></i>Despatch exceptions</h1>'
               . '<p class="text-muted small mb-0">'
               . count($attentionOrders) . ' attention-required · '
               . count($syncErrors) . ' recent despatch/label errors</p></div>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $this->view->e($this->url->to('store/admin/orders')) . '">All orders</a>';
            echo '</div>';

            echo $dataset->render();
        });
    }
}
