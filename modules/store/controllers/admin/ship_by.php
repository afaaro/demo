<?php
declare(strict_types=1);

use Module\Store\Services\DashboardService;
use Module\Store\Services\OrderRepository;
use System\Engine\Controller;
use System\Library\Dataset;

class StoreAdminShipBy extends Controller
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
        $bucket = strtolower(trim((string) $this->request->get('bucket', 'string', 'overdue')));
        if (!in_array($bucket, ['overdue', 'today', 'upcoming', 'all'], true)) {
            $bucket = 'overdue';
        }

        $dash = new DashboardService($this->db);
        $overdueCount = $dash->overdueShipByCount();
        $today = $this->orders->shipByQueue('today', 500);
        $upcoming = $this->orders->shipByQueue('upcoming', 500);
        $rows = $this->orders->shipByQueue($bucket, 500);

        $dataset = (new Dataset($this->registry))
            ->perPage(50)
            ->searchPlaceholder('Order id…')
            ->preserve(['bucket' => $bucket])
            ->sortable(['id'])
            ->defaultSort('id', 'asc')
            ->columns([
                'order'    => 'Order',
                'channel'  => 'Channel',
                'ship_by'  => 'Ship by',
                'bucket'   => 'Bucket',
                'status'   => 'Status',
                'buyer'    => 'Buyer',
                'method'   => 'Shipping',
                'actions'  => '',
            ])
            ->query(function ($db, array $state) use ($rows): array {
                $q = trim((string) ($state['q'] ?? ''));
                $filtered = $rows;
                if ($q !== '') {
                    $filtered = array_values(array_filter($rows, static function (array $r) use ($q): bool {
                        $hay = (string) ($r['external_order_id'] ?? '') . ' ' . (string) ($r['id'] ?? '');
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
        $todayDate = date('Y-m-d');

        $dataset->columns([
            'order' => function (array $row) use ($e): string {
                $r = $row['_row'];
                $label = (string) (($r['external_order_id'] ?? '') !== '' ? $r['external_order_id'] : '#' . $r['id']);
                return '<a href="' . $e($this->url->to('store/admin/orders/view', ['id' => (int) $r['id']])) . '">'
                    . $e($label) . '</a>';
            },
            'channel' => fn(array $row): string => $e((string) ($row['_row']['channel_label'] ?? $row['_row']['channel_name'] ?? '—')),
            'ship_by' => function (array $row) use ($e): string {
                return '<span class="small text-nowrap">' . $e((string) ($row['_row']['ship_by'] ?? '')) . '</span>';
            },
            'bucket' => function (array $row) use ($todayDate): string {
                $shipBy = (string) ($row['_row']['ship_by'] ?? '');
                if ($shipBy < $todayDate) {
                    return '<span class="badge text-bg-danger">Overdue</span>';
                }
                if ($shipBy === $todayDate) {
                    return '<span class="badge text-bg-warning">Due today</span>';
                }
                return '<span class="badge text-bg-info">Upcoming</span>';
            },
            'status' => fn(array $row): string => '<span class="badge text-bg-secondary">'
                . $e((string) ($row['_row']['status'] ?? '')) . '</span>',
            'buyer' => function (array $row) use ($e): string {
                $r = $row['_row'];
                $buyer = trim((string) ($r['buyer_username'] ?? ''));
                if ($buyer === '') {
                    $buyer = trim((string) ($r['shipping_name'] ?? ''));
                }
                return $e($buyer !== '' ? $buyer : '—');
            },
            'method' => fn(array $row): string => $e((string) (($row['_row']['shipping_method'] ?? '') !== ''
                ? $row['_row']['shipping_method'] : '—')),
            'actions' => function (array $row) use ($e): string {
                return '<a class="btn btn-sm btn-outline-secondary" href="'
                    . $e($this->url->to('store/admin/orders/view', ['id' => (int) $row['_row']['id']]))
                    . '">Open</a>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $bucket, $overdueCount, $today, $upcoming, $e) {
            $this->view->assign('title', 'Ship-by queue');
            $this->view->block('right_sidebar', 'Orders');

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-calendar-x me-1"></i>Ship-by / late despatch</h1>'
               . '<p class="text-muted small mb-0">'
               . (int) $overdueCount . ' overdue · '
               . count($today) . ' due today · '
               . count($upcoming) . ' upcoming</p></div>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/orders')) . '">All orders</a>';
            echo '</div>';

            echo '<ul class="nav nav-pills gap-1 mb-3">';
            foreach ([
                'overdue'  => 'Overdue',
                'today'    => 'Due today',
                'upcoming' => 'Upcoming',
                'all'      => 'All with ship-by',
            ] as $key => $label) {
                $active = $bucket === $key ? ' active' : '';
                echo '<li class="nav-item"><a class="nav-link' . $active . '" href="'
                   . $e($this->url->to('store/admin/orders/ship-by', ['bucket' => $key]))
                   . '">' . $e($label) . '</a></li>';
            }
            echo '</ul>';

            echo $dataset->render();
        });
    }
}
