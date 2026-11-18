<?php
declare(strict_types=1);

use Module\Store\Services\TenantContext;
use System\Engine\Controller;
use System\Library\Paginator;

class StoreAdminWarehouse extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
    }

    public function deliveriesAction(): void
    {
        $page = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;
        $q = trim((string) $this->request->get('q', 'string', ''));
        $result = $this->listShipments($q, $perPage, $offset);

        echo $this->view->inline(function () use ($result, $page, $perPage, $q) {
            $this->view->assign('title', 'Deliveries');
            $this->view->block('right_sidebar', 'Warehouse');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="sf-page">';
            echo '<div class="sf-head"><div><h1>Deliveries</h1>'
               . '<p>Outbound shipments and labels from despatched orders.</p></div></div>';

            echo '<form method="get" class="sf-card mb-3"><div class="sf-filters">'
               . '<div><label class="form-label small mb-1">Search</label>'
               . '<input type="search" name="q" class="form-control form-control-sm" value="' . $e($q) . '"'
               . ' placeholder="Tracking, order id, courier…"></div>'
               . '<div><button class="sf-btn is-primary" type="submit">Search</button></div>'
               . '</div></form>';

            echo '<div class="sf-card table-responsive"><table class="table table-sm align-middle mb-0">';
            echo '<thead><tr><th>Shipment</th><th>Order</th><th>Courier</th><th>Tracking</th>'
               . '<th>Status</th><th>Created</th></tr></thead><tbody>';
            if ($result['rows'] === []) {
                echo '<tr><td colspan="6" class="text-muted text-center py-4">No shipments found.</td></tr>';
            }
            foreach ($result['rows'] as $r) {
                $oid = (int) ($r['order_id'] ?? 0);
                echo '<tr>';
                echo '<td>#' . (int) ($r['id'] ?? 0) . '</td>';
                echo '<td>' . ($oid > 0
                    ? '<a href="' . $e($this->url->to('store/admin/orders/view', ['id' => $oid])) . '">' . $oid . '</a>'
                    : '—') . '</td>';
                echo '<td class="small">' . $e((string) ($r['carrier_code'] ?? '')) . '</td>';
                echo '<td class="small"><code>' . $e((string) ($r['tracking_number'] ?? '')) . '</code></td>';
                echo '<td class="small">' . $e((string) ($r['status'] ?? '')) . '</td>';
                echo '<td class="small text-nowrap">' . $e((string) ($r['created_at'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';

            echo Paginator::make((int) $result['total'], $page, $perPage)->render(
                fn(int $p) => $this->url->to('store/admin/warehouse/deliveries', array_filter([
                    'page' => $p,
                    'q' => $q !== '' ? $q : null,
                ])),
                ['theme' => 'bootstrap']
            );
            echo '</div>';
        });
    }

    public function manifestsAction(): void
    {
        $result = $this->listShipments('', 100, 0, true);

        echo $this->view->inline(function () use ($result) {
            $this->view->assign('title', 'Manifests');
            $this->view->block('right_sidebar', 'Warehouse');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="sf-page">';
            echo '<div class="sf-head"><div><h1>Manifests</h1>'
               . '<p>Today\'s outbound shipments ready for end-of-day courier close.</p></div>'
               . '<div class="sf-actions">'
               . '<a class="sf-btn is-primary" href="' . $e($this->url->to('store/admin/warehouse/courier-export')) . '">'
               . 'Courier File Export</a></div></div>';

            echo '<div class="sf-card table-responsive"><table class="table table-sm align-middle mb-0">';
            echo '<thead><tr><th>Shipment</th><th>Order</th><th>Courier</th><th>Tracking</th>'
               . '<th>Status</th><th>Created</th></tr></thead><tbody>';
            if ($result['rows'] === []) {
                echo '<tr><td colspan="6" class="text-muted text-center py-4">No shipments created today.</td></tr>';
            }
            foreach ($result['rows'] as $r) {
                $oid = (int) ($r['order_id'] ?? 0);
                echo '<tr>';
                echo '<td>#' . (int) ($r['id'] ?? 0) . '</td>';
                echo '<td>' . ($oid > 0
                    ? '<a href="' . $e($this->url->to('store/admin/orders/view', ['id' => $oid])) . '">' . $oid . '</a>'
                    : '—') . '</td>';
                echo '<td class="small">' . $e((string) ($r['carrier_code'] ?? '')) . '</td>';
                echo '<td class="small"><code>' . $e((string) ($r['tracking_number'] ?? '')) . '</code></td>';
                echo '<td class="small">' . $e((string) ($r['status'] ?? '')) . '</td>';
                echo '<td class="small text-nowrap">' . $e((string) ($r['created_at'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';
        });
    }

    public function courierExportAction(): void
    {
        if ($this->request->isPost()) {
            $this->request->checkToken();
            $result = $this->listShipments('', 500, 0, true);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="courier-export-' . date('Ymd-His') . '.csv"');
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new \RuntimeException('Unable to open output stream.');
            }
            fputcsv($out, ['Shipment ID', 'Order ID', 'Courier', 'Tracking', 'Status', 'Created']);
            foreach ($result['rows'] as $r) {
                fputcsv($out, [
                    (int) ($r['id'] ?? 0),
                    (int) ($r['order_id'] ?? 0),
                    (string) ($r['carrier_code'] ?? ''),
                    (string) ($r['tracking_number'] ?? ''),
                    (string) ($r['status'] ?? ''),
                    (string) ($r['created_at'] ?? ''),
                ]);
            }
            fclose($out);
            exit;
        }

        echo $this->view->inline(function () {
            $this->view->assign('title', 'Courier File Export');
            $this->view->block('right_sidebar', 'Warehouse');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="sf-page">';
            echo '<div class="sf-head"><div><h1>Courier File Export</h1>'
               . '<p>Download today\'s shipment tracking rows as CSV for courier upload.</p></div></div>';
            echo '<form method="post" class="sf-card p-3" style="max-width:28rem">';
            echo $this->request->csrfField();
            echo '<p class="small text-muted mb-3">Exports shipments created today (tenant-scoped).</p>';
            echo '<button type="submit" class="sf-btn is-primary">Download CSV</button> ';
            echo '<a class="sf-btn" href="' . $e($this->url->to('store/admin/warehouse/manifests')) . '">Back to Manifests</a>';
            echo '</form></div>';
        });
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function listShipments(string $q, int $limit, int $offset, bool $todayOnly = false): array
    {
        if (!TenantContext::get()->has()) {
            return ['rows' => [], 'total' => 0];
        }
        $tid = (int) TenantContext::get()->id();
        $clauses = ['s.`tenant_id` = ?'];
        $params = [$tid];
        if ($todayOnly) {
            $clauses[] = 'DATE(s.`created_at`) = CURDATE()';
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $clauses[] = '(s.`tracking_number` LIKE ? OR CAST(s.`order_id` AS CHAR) LIKE ? OR s.`status` LIKE ?'
                . ' OR COALESCE(s.`carrier_code`, \'\') LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $where = implode(' AND ', $clauses);
        $total = (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM `#__store_shipments` s WHERE {$where}",
            $params
        )->row['c'];

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $rows = $this->db->query(
            "SELECT s.* FROM `#__store_shipments` s
              WHERE {$where}
              ORDER BY s.`id` DESC
              LIMIT {$limit} OFFSET {$offset}",
            $params
        )->rows;

        return ['rows' => $rows, 'total' => $total];
    }
}
