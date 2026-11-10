<?php
declare(strict_types=1);

use Module\Store\Services\PurchaseOrderRepository;
use System\Engine\Controller;

/**
 * Month calendar of purchase orders by expected_date.
 */
class StoreAdminInboundCalendar extends Controller
{
    private PurchaseOrderRepository $orders;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->orders = new PurchaseOrderRepository($this->db);
    }

    public function indexAction(): void
    {
        $ym = trim((string) $this->request->get('month', 'string', ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $ym = date('Y-m');
        }
        $start = $ym . '-01';
        $ts = strtotime($start);
        if ($ts === false) {
            $ym = date('Y-m');
            $start = $ym . '-01';
            $ts = strtotime($start) ?: time();
        }
        $end = date('Y-m-t', $ts);
        $prev = date('Y-m', strtotime('-1 month', $ts) ?: $ts);
        $next = date('Y-m', strtotime('+1 month', $ts) ?: $ts);

        $pos = $this->orders->search([
            'due_from' => $start,
            'due_to'   => $end,
        ], 500, 0);

        $byDay = [];
        foreach ($pos as $po) {
            $day = substr((string) ($po['expected_date'] ?? ''), 0, 10);
            if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }
            $byDay[$day][] = $po;
        }

        $firstDow = (int) date('N', $ts); // 1=Mon
        $daysInMonth = (int) date('t', $ts);

        echo $this->view->inline(function () use (
            $ym, $prev, $next, $byDay, $firstDow, $daysInMonth, $pos
        ) {
            $this->view->assign('title', 'Inbound calendar');
            $this->view->block('right_sidebar', 'Deliveries');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-calendar3 me-1"></i>Inbound calendar</h1>'
               . '<p class="text-muted small mb-0">' . count($pos)
               . ' PO(s) with expected date in ' . $e($ym) . '</p></div>';
            echo '<div class="d-flex gap-2">';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/purchase-orders/calendar', ['month' => $prev]))
               . '">&larr; Prev</a>';
            echo '<span class="btn btn-light btn-sm disabled">' . $e(date('F Y', strtotime($ym . '-01') ?: time())) . '</span>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/purchase-orders/calendar', ['month' => $next]))
               . '">Next &rarr;</a>';
            echo '<a class="btn btn-outline-primary btn-sm" href="'
               . $e($this->url->to('store/admin/purchase-orders')) . '">All deliveries</a>';
            echo '</div></div>';

            echo '<style>
.sf-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.35rem}
.sf-cal-h{font-size:.72rem;font-weight:700;color:#6b7280;text-align:center;padding:.25rem}
.sf-cal-day{min-height:6.5rem;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:.35rem;font-size:.75rem}
.sf-cal-day.is-empty{background:#f9fafb;border-style:dashed}
.sf-cal-num{font-weight:700;color:#374151;margin-bottom:.25rem}
.sf-cal-po{display:block;padding:.15rem .3rem;margin:.1rem 0;border-radius:.3rem;background:#eff6ff;color:#1d4ed8;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sf-cal-po:hover{background:#dbeafe}
.sf-cal-po.is-late{background:#fef2f2;color:#b91c1c}
</style>';

            echo '<div class="sf-cal mb-2">';
            foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d) {
                echo '<div class="sf-cal-h">' . $d . '</div>';
            }
            for ($i = 1; $i < $firstDow; $i++) {
                echo '<div class="sf-cal-day is-empty"></div>';
            }
            $today = date('Y-m-d');
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = sprintf('%s-%02d', $ym, $day);
                $list = $byDay[$date] ?? [];
                echo '<div class="sf-cal-day">';
                echo '<div class="sf-cal-num">' . $day
                   . ($date === $today ? ' · today' : '') . '</div>';
                foreach ($list as $po) {
                    $st = strtolower((string) ($po['status'] ?? ''));
                    $late = $date < $today && !in_array($st, ['received', 'cancelled', 'canceled'], true);
                    $label = 'PO #' . (int) $po['id'];
                    if (!empty($po['supplier_name'])) {
                        $label .= ' · ' . (string) $po['supplier_name'];
                    } elseif (!empty($po['reference'])) {
                        $label .= ' · ' . (string) $po['reference'];
                    }
                    echo '<a class="sf-cal-po' . ($late ? ' is-late' : '') . '" href="'
                       . $e($this->url->to('store/admin/purchase-orders/view', ['id' => (int) $po['id']]))
                       . '" title="' . $e($label) . '">' . $e($label) . '</a>';
                }
                echo '</div>';
            }
            $cellsUsed = ($firstDow - 1) + $daysInMonth;
            $pad = (7 - ($cellsUsed % 7)) % 7;
            for ($i = 0; $i < $pad; $i++) {
                echo '<div class="sf-cal-day is-empty"></div>';
            }
            echo '</div>';
        });
    }
}
