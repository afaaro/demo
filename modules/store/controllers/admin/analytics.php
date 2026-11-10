<?php
declare(strict_types=1);

use Module\Store\Services\DashboardService;
use Module\Store\Services\PurchaseOrderRepository;
use System\Engine\Controller;

class StoreAdminAnalytics extends Controller
{
    private DashboardService $dashboard;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->dashboard = new DashboardService($this->db);
    }

    public function supplierOtifAction(): void
    {
        $days = max(30, min(730, (int) $this->request->get('days', 'int', 180)));
        $rows = (new PurchaseOrderRepository($this->db))->supplierOtif($days);

        echo $this->view->inline(function () use ($rows, $days) {
            $this->view->assign('title', 'Supplier OTIF');
            $this->view->block('right_sidebar', 'Dashboard');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-truck me-1"></i>Supplier OTIF</h1>'
               . '<p class="text-muted small mb-0">On-time in-full scorecard from expected vs received dates.</p></div>';
            echo '<div class="d-flex gap-2">';
            echo '<form method="get" class="d-flex gap-2 align-items-end">';
            echo '<input type="hidden" name="route" value="store/admin/analytics/supplier-otif">';
            echo '<div><label class="form-label small mb-0">Days</label>'
               . '<input type="number" class="form-control form-control-sm" name="days" min="30" max="730" value="'
               . (int) $days . '" style="width:5.5rem"></div>';
            echo '<button type="submit" class="btn btn-sm btn-outline-primary">Apply</button></form>';
            echo '<a class="btn btn-sm btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/purchase-orders/calendar')) . '">Inbound calendar</a>';
            echo '</div></div>';

            if ($rows === []) {
                echo '<div class="alert alert-secondary">No purchase orders in range.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm align-middle bg-white border">';
            echo '<thead class="table-light"><tr><th>Supplier</th><th class="text-end">POs</th>'
               . '<th class="text-end">Received</th><th class="text-end">On time</th>'
               . '<th class="text-end">Late</th><th class="text-end">OTIF %</th>'
               . '<th class="text-end">Avg late days</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $cls = $row['otif_pct'] >= 90 ? 'text-success' : ($row['otif_pct'] >= 70 ? 'text-warning' : 'text-danger');
                echo '<tr>';
                echo '<td>' . $e($row['supplier_name']) . '</td>';
                echo '<td class="text-end">' . (int) $row['pos'] . '</td>';
                echo '<td class="text-end">' . (int) $row['received'] . '</td>';
                echo '<td class="text-end">' . (int) $row['on_time'] . '</td>';
                echo '<td class="text-end">' . (int) $row['late'] . '</td>';
                echo '<td class="text-end fw-semibold ' . $cls . '">' . $e(number_format($row['otif_pct'], 1)) . '%</td>';
                echo '<td class="text-end">'
                   . ($row['avg_delay_days'] !== null ? $e(number_format($row['avg_delay_days'], 1)) : '—')
                   . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    public function pickPackAction(): void
    {
        $days = max(1, min(365, (int) $this->request->get('days', 'int', 30)));
        $data = $this->dashboard->pickPackPerformance($days);
        $summary = $data['summary'];
        $byUser = $data['by_user'];

        echo $this->view->inline(function () use ($summary, $byUser, $days) {
            $this->view->assign('title', 'Pick / pack performance');
            $this->view->block('right_sidebar', 'Dashboard');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-stopwatch me-1"></i>Pick / pack hub</h1>'
               . '<p class="text-muted small mb-0">Units scanned, waves completed, avg time to despatch.</p></div>';
            echo '<form method="get" class="d-flex gap-2 align-items-end">';
            echo '<input type="hidden" name="route" value="store/admin/analytics/pick-pack">';
            echo '<div><label class="form-label small mb-0">Days</label>'
               . '<input type="number" class="form-control form-control-sm" name="days" min="1" max="365" value="'
               . (int) $days . '" style="width:5.5rem"></div>';
            echo '<button type="submit" class="btn btn-sm btn-outline-primary">Apply</button></form></div>';

            echo '<div class="row g-3 mb-3">';
            foreach ([
                ['Units scanned', (string) (int) $summary['units_scanned']],
                ['Pick units', (string) (int) $summary['pick_units']],
                ['Pack units', (string) (int) $summary['pack_units']],
                ['Waves done', (string) (int) $summary['waves_completed']],
                ['Scans / hr', $summary['scans_per_hour'] !== null ? (string) $summary['scans_per_hour'] : '—'],
                ['Avg despatch (h)', $summary['avg_despatch_hours'] !== null
                    ? number_format((float) $summary['avg_despatch_hours'], 2) : '—'],
            ] as [$label, $val]) {
                echo '<div class="col-6 col-md-4 col-xl-2"><div class="border rounded bg-white p-3 h-100">'
                   . '<div class="text-muted small">' . $e($label) . '</div>'
                   . '<div class="fs-4 fw-semibold">' . $e($val) . '</div></div></div>';
            }
            echo '</div>';

            if ($byUser === []) {
                echo '<div class="alert alert-secondary">No scans in this period.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm align-middle bg-white border">';
            echo '<thead class="table-light"><tr><th>User</th><th class="text-end">Pick</th>'
               . '<th class="text-end">Pack</th><th class="text-end">Total</th>'
               . '<th class="text-end">Units / hr</th></tr></thead><tbody>';
            foreach ($byUser as $row) {
                $uid = (int) $row['user_id'];
                echo '<tr>';
                echo '<td>' . ($uid > 0 ? 'User #' . $uid : 'Unassigned') . '</td>';
                echo '<td class="text-end">' . (int) $row['pick_units'] . '</td>';
                echo '<td class="text-end">' . (int) $row['pack_units'] . '</td>';
                echo '<td class="text-end fw-semibold">' . (int) $row['total_units'] . '</td>';
                echo '<td class="text-end">'
                   . ($row['units_per_hour'] !== null ? $e(number_format((float) $row['units_per_hour'], 1)) : '—')
                   . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    public function skuMarginAction(): void
    {
        $days = max(1, min(365, (int) $this->request->get('days', 'int', 30)));
        $deadDays = max(1, min(730, (int) $this->request->get('dead_days', 'int', 90)));
        $margins = $this->dashboard->skuMarginByChannel($days, 150);
        $dead = $this->dashboard->deadStock($deadDays, 100);

        echo $this->view->inline(function () use ($margins, $dead, $days, $deadDays) {
            $this->view->assign('title', 'SKU margin / dead stock');
            $this->view->block('right_sidebar', 'Dashboard');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-graph-up me-1"></i>SKU × channel margin</h1>'
               . '<p class="text-muted small mb-0">Contribution after cost and fee share · dead stock with qty &gt; 0.</p></div>';
            echo '<form method="get" class="d-flex flex-wrap gap-2 align-items-end">';
            echo '<input type="hidden" name="route" value="store/admin/analytics/sku-margin">';
            echo '<div><label class="form-label small mb-0">Margin days</label>'
               . '<input type="number" class="form-control form-control-sm" name="days" min="1" max="365" value="'
               . (int) $days . '" style="width:5.5rem"></div>';
            echo '<div><label class="form-label small mb-0">Dead stock days</label>'
               . '<input type="number" class="form-control form-control-sm" name="dead_days" min="1" max="730" value="'
               . (int) $deadDays . '" style="width:5.5rem"></div>';
            echo '<button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>';
            echo '</form></div>';

            echo '<div class="row g-3">';
            echo '<div class="col-lg-7">';
            echo '<div class="card border-0 shadow-sm"><div class="card-body">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">SKU margin by channel (' . (int) $days . 'd)</h2>';
            if ($margins === []) {
                echo '<p class="text-muted small mb-0">No fulfilled sales in range.</p>';
            } else {
                echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
                echo '<thead><tr><th>SKU</th><th>Channel</th><th class="text-end">Qty</th>'
                   . '<th class="text-end">Revenue</th><th class="text-end">Cost</th>'
                   . '<th class="text-end">Fees</th><th class="text-end">Margin</th></tr></thead><tbody>';
                foreach ($margins as $m) {
                    $cls = $m['margin'] >= 0 ? 'text-success' : 'text-danger';
                    echo '<tr>';
                    echo '<td><code>' . $e($m['sku']) . '</code></td>';
                    echo '<td class="small">' . $e($m['channel_label'] !== '' ? $m['channel_label'] : '#' . $m['channel_id']) . '</td>';
                    echo '<td class="text-end">' . (int) $m['qty'] . '</td>';
                    echo '<td class="text-end">' . $e(number_format($m['revenue'], 2)) . '</td>';
                    echo '<td class="text-end">' . $e(number_format($m['cost'], 2)) . '</td>';
                    echo '<td class="text-end">' . $e(number_format($m['fees'], 2)) . '</td>';
                    echo '<td class="text-end fw-semibold ' . $cls . '">' . $e(number_format($m['margin'], 2)) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div></div></div>';

            echo '<div class="col-lg-5">';
            echo '<div class="card border-0 shadow-sm"><div class="card-body">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Dead stock (no sales ' . (int) $deadDays . 'd)</h2>';
            if ($dead === []) {
                echo '<p class="text-muted small mb-0">No dead stock candidates.</p>';
            } else {
                echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
                echo '<thead><tr><th>SKU</th><th>Product</th><th class="text-end">Qty</th>'
                   . '<th class="text-end">Days</th></tr></thead><tbody>';
                foreach ($dead as $d) {
                    echo '<tr>';
                    echo '<td><code>' . $e($d['sku']) . '</code></td>';
                    echo '<td class="small"><a href="'
                       . $e($this->url->to('store/admin/products/edit', ['id' => (int) $d['product_id']])) . '">'
                       . $e($d['product_name']) . '</a></td>';
                    echo '<td class="text-end">' . (int) $d['quantity'] . '</td>';
                    echo '<td class="text-end">'
                       . ($d['days_since_sale'] !== null ? (int) $d['days_since_sale'] : '∞')
                       . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div></div></div>';
            echo '</div>';
        });
    }
}
