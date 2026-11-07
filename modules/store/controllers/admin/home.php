<?php
declare(strict_types=1);

use Module\Store\Services\DashboardService;
use Module\Store\Services\TenantContext;
use System\Engine\Controller;

class StoreAdminHome extends Controller
{
    private DashboardService $dashboard;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->dashboard = new DashboardService($this->db);
    }

    public function indexAction(): void
    {
        $stats     = $this->dashboard->stats();
        $orders    = $this->dashboard->recentOrders(10);
        $waves     = $this->dashboard->openPickwaves(8);
        $recent    = $this->dashboard->recentProducts(6);
        $movements = $this->dashboard->recentMovements(8);
        $lowStock  = $this->dashboard->lowStockTop(6);

        echo $this->view->inline(function () use ($stats, $orders, $waves, $recent, $movements, $lowStock) {
            $this->view->assign('title', 'Store');
            $this->view->block('right_sidebar', 'Dashboard');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo $this->dashStyles();
            echo '<div class="sf-dash">';
            echo '<div class="sf-dash-head">';
            $tenantName = TenantContext::get()->name();
            echo '<div><h1>Store</h1>'
               . '<p>Operations overview — orders, picking, stock'
               . ($tenantName !== '' ? ' for <strong>' . $e($tenantName) . '</strong>' : '')
               . '.</p></div>';
            echo '<div class="sf-dash-actions">';
            echo '<a href="' . $e($this->url->to('store/admin/orders')) . '" class="sf-dash-btn is-primary">'
               . '<i class="bi bi-receipt"></i>Orders</a>';
            echo '<a href="' . $e($this->url->to('store/admin/pickwaves')) . '" class="sf-dash-btn">'
               . '<i class="bi bi-clipboard-check"></i>Picking</a>';
            echo '<a href="' . $e($this->url->to('store/admin/products/add')) . '" class="sf-dash-btn">'
               . '<i class="bi bi-plus-lg"></i>Add product</a>';
            echo '</div></div>';

            // Ops cards
            $opsCards = [
                [
                    'label' => 'Open orders',
                    'value' => number_format($stats['orders_open']),
                    'sub'   => $stats['orders_today'] . ' today · ' . $stats['orders_on_hold'] . ' on hold',
                    'icon'  => 'receipt',
                    'url'   => $this->url->to('store/admin/orders'),
                    'tone'  => $stats['orders_on_hold'] > 0 ? 'warning' : 'primary',
                ],
                [
                    'label' => 'Ready to pick',
                    'value' => number_format($stats['orders_ready']),
                    'sub'   => $stats['pickwaves_open'] . ' open pickwave'
                        . ($stats['pickwaves_open'] === 1 ? '' : 's'),
                    'icon'  => 'clipboard-check',
                    'url'   => $this->url->to('store/admin/orders', ['f_status' => 'NOT_STARTED']),
                    'tone'  => 'info',
                ],
                [
                    'label' => 'Unread messages',
                    'value' => number_format($stats['messages_unread']),
                    'sub'   => $stats['cases_open'] . ' open support case'
                        . ($stats['cases_open'] === 1 ? '' : 's'),
                    'icon'  => 'chat',
                    'url'   => $this->url->to('store/admin/messages/conversations'),
                    'tone'  => $stats['messages_unread'] > 0 ? 'danger' : 'secondary',
                ],
                [
                    'label' => 'Stock attention',
                    'value' => number_format($stats['low_stock_count'] + $stats['out_of_stock_count']),
                    'sub'   => $stats['low_stock_count'] . ' low · ' . $stats['out_of_stock_count'] . ' out',
                    'icon'  => 'exclamation-triangle',
                    'url'   => $this->url->to('store/admin/inventory/low'),
                    'tone'  => $stats['out_of_stock_count'] > 0 ? 'danger' : 'warning',
                ],
            ];

            echo '<div class="sf-dash-stats">';
            foreach ($opsCards as $c) {
                echo $this->statCard($c);
            }
            echo '</div>';

            // Catalog cards
            $catalogCards = [
                [
                    'label' => 'Products',
                    'value' => number_format($stats['products_total']),
                    'sub'   => $stats['products_active'] . ' active · ' . $stats['variants_total'] . ' variants',
                    'icon'  => 'box',
                    'url'   => $this->url->to('store/admin/products'),
                    'tone'  => 'primary',
                ],
                [
                    'label' => 'Stock units',
                    'value' => number_format($stats['stock_units']),
                    'sub'   => 'worth ' . number_format($stats['stock_value'], 2),
                    'icon'  => 'currency-pound',
                    'url'   => $this->url->to('store/admin/inventory'),
                    'tone'  => 'success',
                ],
                [
                    'label' => 'Channels',
                    'value' => number_format($stats['channels_connected']),
                    'sub'   => 'connected integrations',
                    'icon'  => 'plug',
                    'url'   => $this->url->to('store/admin/channels'),
                    'tone'  => 'secondary',
                ],
                [
                    'label' => 'Categories',
                    'value' => number_format($stats['categories_total']),
                    'sub'   => 'product taxonomy',
                    'icon'  => 'diagram-3',
                    'url'   => $this->url->to('store/admin/categories'),
                    'tone'  => 'info',
                ],
            ];

            echo '<div class="sf-dash-stats">';
            foreach ($catalogCards as $c) {
                echo $this->statCard($c);
            }
            echo '</div>';

            echo '<div class="sf-dash-grid">';

            echo '<section class="sf-dash-card is-wide">';
            echo '<div class="sf-dash-card-h"><h2>Recent orders</h2>'
               . '<a href="' . $e($this->url->to('store/admin/orders')) . '">View all</a></div>';
            if ($orders === []) {
                echo '<p class="sf-dash-empty">No orders yet. Connect a channel and sync.</p>';
            } else {
                echo '<div class="table-responsive"><table class="sf-dash-table">';
                echo '<thead><tr>'
                   . '<th>Order</th><th>Channel</th><th>Buyer</th><th>Status</th>'
                   . '<th class="is-num">Total</th><th>When</th>'
                   . '</tr></thead><tbody>';
                foreach ($orders as $o) {
                    $label = $o['external_order_id'] !== '' ? $o['external_order_id'] : '#' . $o['id'];
                    echo '<tr>';
                    echo '<td><a href="'
                       . $e($this->url->to('store/admin/orders/view', ['id' => $o['id']]))
                       . '">' . $e($label) . '</a></td>';
                    echo '<td>' . $e($o['channel_label']) . '</td>';
                    echo '<td>' . $e($o['buyer'] !== '' ? $o['buyer'] : '—') . '</td>';
                    echo '<td><span class="sf-dash-pill">' . $e($o['status']) . '</span>';
                    if ($o['on_hold']) {
                        echo ' <span class="sf-dash-pill is-warn">Hold</span>';
                    }
                    echo '</td>';
                    echo '<td class="is-num">'
                       . $e(number_format($o['total'], 2) . ' ' . $o['currency']) . '</td>';
                    echo '<td class="is-muted">' . $e($this->formatWhen($o['created_at'])) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</section>';

            echo '<section class="sf-dash-card">';
            echo '<div class="sf-dash-card-h"><h2>Open pickwaves</h2>'
               . '<a href="' . $e($this->url->to('store/admin/pickwaves')) . '">View all</a></div>';
            if ($waves === []) {
                echo '<p class="sf-dash-empty">No open pickwaves.</p>';
            } else {
                echo '<ul class="sf-dash-list">';
                foreach ($waves as $w) {
                    echo '<li class="sf-dash-row has-status">';
                    echo '<div class="sf-dash-copy">';
                    echo '<a href="'
                       . $e($this->url->to('store/admin/pickwaves/view', ['id' => $w['id']]))
                       . '">Wave #' . (int) $w['id'] . '</a>';
                    echo '<span class="sf-dash-sub">'
                       . $e($w['picker_name'] !== '' ? $w['picker_name'] : 'Unassigned')
                       . ' · ' . (int) $w['order_count'] . ' order'
                       . ((int) $w['order_count'] === 1 ? '' : 's')
                       . '</span></div>';
                    echo '<span class="sf-dash-meta">'
                       . $e($this->formatWhen($w['created_at']))
                       . '</span>';
                    echo '<span class="sf-dash-pill">' . $e($w['status']) . '</span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '</section>';

            echo '<section class="sf-dash-card">';
            echo '<div class="sf-dash-card-h"><h2>Low stock</h2>'
               . '<a href="' . $e($this->url->to('store/admin/inventory/low')) . '">View all</a></div>';
            if ($lowStock === []) {
                echo '<p class="sf-dash-empty">Nothing below reorder thresholds.</p>';
            } else {
                echo '<ul class="sf-dash-list">';
                foreach ($lowStock as $r) {
                    $p = $r['product'];
                    $v = $r['variant'];
                    $title = $p->name . ($v->name !== '' ? ' — ' . $v->name : '');
                    $tone = $r['available'] <= 0 ? 'is-out' : 'is-low';
                    echo '<li class="sf-dash-row">';
                    echo '<div class="sf-dash-copy">';
                    echo '<a href="' . $e($this->url->to('store/admin/inventory/adjust', ['id' => $v->id])) . '"'
                       . ' title="' . $e($title) . '">' . $e($title) . '</a>';
                    echo '<span class="sf-dash-sub">' . $e($v->sku) . '</span></div>';
                    echo '<span class="sf-dash-qty ' . $tone . '" title="On hand / reorder at">'
                       . (int) $r['available'] . '<small>/' . (int) $r['reorder_at'] . '</small></span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '</section>';

            echo '<section class="sf-dash-card">';
            echo '<div class="sf-dash-card-h"><h2>Recently updated</h2>'
               . '<a href="' . $e($this->url->to('store/admin/products')) . '">View all</a></div>';
            if ($recent === []) {
                echo '<p class="sf-dash-empty">No products yet. '
                   . '<a href="' . $e($this->url->to('store/admin/products/add')) . '">Add your first product</a>.</p>';
            } else {
                echo '<ul class="sf-dash-list">';
                foreach ($recent as $r) {
                    $p = $r['product'];
                    echo '<li class="sf-dash-row has-thumb">';
                    if ($p->image !== '') {
                        echo '<img class="sf-dash-thumb" src="'
                           . $e($this->url->asset('storage/store/' . $p->image))
                           . '" alt="">';
                    } else {
                        echo '<span class="sf-dash-thumb is-empty"><i class="bi bi-image"></i></span>';
                    }
                    echo '<div class="sf-dash-copy">';
                    echo '<a href="' . $e($this->url->to('store/admin/products/edit', ['id' => $p->id])) . '"'
                       . ' title="' . $e($p->name) . '">' . $e($p->name) . '</a>';
                    echo '<span class="sf-dash-sub">' . $e($p->sku) . '</span></div>';
                    echo '<span class="sf-dash-meta">' . $e($this->formatWhen($r['updated_at'])) . '</span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '</section>';

            echo '</div>';

            echo '<section class="sf-dash-card">';
            echo '<div class="sf-dash-card-h"><h2>Recent stock movements</h2></div>';
            if ($movements === []) {
                echo '<p class="sf-dash-empty">No movements yet.</p>';
            } else {
                echo '<div class="table-responsive"><table class="sf-dash-table">';
                echo '<thead><tr>'
                   . '<th class="c-when">When</th><th>Product</th><th>Variant</th>'
                   . '<th class="is-num">Delta</th><th>Reason</th><th>User</th>'
                   . '</tr></thead><tbody>';
                foreach ($movements as $m) {
                    $deltaClass = $m['delta'] > 0 ? 'is-plus' : 'is-minus';
                    echo '<tr>';
                    echo '<td class="is-muted">' . $e($this->formatWhen($m['created_at'])) . '</td>';
                    echo '<td><span class="sf-dash-clip" title="' . $e($m['product_name']) . '">'
                       . $e($m['product_name']) . '</span></td>';
                    echo '<td class="is-sku">' . $e($m['variant_sku']) . '</td>';
                    echo '<td class="is-num ' . $deltaClass . '">'
                       . ($m['delta'] > 0 ? '+' : '') . (int) $m['delta']
                       . '</td>';
                    echo '<td>' . $e($m['reason'] !== '' ? $m['reason'] : '—') . '</td>';
                    echo '<td class="is-muted">'
                       . ($m['user_id'] !== null ? '#' . $m['user_id'] : '—')
                       . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</section>';
            echo '</div>';
        });
    }

    private function formatWhen(?string $at): string
    {
        if ($at === null || trim($at) === '') {
            return '—';
        }
        $ts = strtotime($at);
        return $ts === false ? $at : date('d M H:i', $ts);
    }

    /** @param array{label:string,value:string,sub:string,icon:string,url:string,tone:string} $c */
    private function statCard(array $c): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        return '<a href="' . $e($c['url']) . '" class="sf-dash-stat is-' . $e($c['tone']) . '">'
            . '<div class="sf-dash-stat-top">'
            . '<span>' . $e($c['label']) . '</span>'
            . '<i class="bi bi-' . $e($c['icon']) . '"></i>'
            . '</div>'
            . '<strong>' . $e($c['value']) . '</strong>'
            . '<em>' . $e($c['sub']) . '</em>'
            . '</a>';
    }

    private function dashStyles(): string
    {
        return <<<'CSS'
<style>
.sf-dash{color:#1f2937}
.sf-dash-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.1rem;flex-wrap:wrap}
.sf-dash-head h1{margin:0;font-size:1.35rem;font-weight:650;letter-spacing:-.02em}
.sf-dash-head p{margin:.2rem 0 0;color:#6b7280;font-size:.84rem}
.sf-dash-actions{display:flex;flex-wrap:wrap;gap:.45rem}
.sf-dash-btn{display:inline-flex;align-items:center;gap:.4rem;height:2.15rem;padding:0 .85rem;border:1px solid #d1d5db;border-radius:.5rem;background:#fff;color:#374151;font-size:.84rem;font-weight:600;text-decoration:none}
.sf-dash-btn:hover{background:#f3f4f6;color:#111827}
.sf-dash-btn.is-primary{background:#2563eb;border-color:#2563eb;color:#fff}
.sf-dash-btn.is-primary:hover{background:#1d4ed8;color:#fff}
.sf-dash-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;margin-bottom:.75rem}
.sf-dash-stat{display:block;background:#fff;border:1px solid #e5e7eb;border-radius:.7rem;padding:.85rem 1rem;text-decoration:none;color:#1f2937;box-shadow:0 1px 2px rgba(16,24,40,.04);border-left:4px solid #94a3b8}
.sf-dash-stat.is-primary{border-left-color:#2563eb}
.sf-dash-stat.is-info{border-left-color:#0ea5e9}
.sf-dash-stat.is-success{border-left-color:#16a34a}
.sf-dash-stat.is-warning{border-left-color:#f59e0b}
.sf-dash-stat.is-danger{border-left-color:#dc2626}
.sf-dash-stat.is-secondary{border-left-color:#64748b}
.sf-dash-stat-top{display:flex;justify-content:space-between;align-items:center;gap:.5rem;color:#6b7280;font-size:.75rem;font-weight:600}
.sf-dash-stat strong{display:block;margin:.25rem 0 .15rem;font-size:1.45rem;letter-spacing:-.03em;line-height:1.1}
.sf-dash-stat em{display:block;font-style:normal;color:#6b7280;font-size:.75rem}
.sf-dash-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,.8fr);gap:.75rem;margin:1rem 0}
.sf-dash-card{background:#fff;border:1px solid #e5e7eb;border-radius:.7rem;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.04);min-width:0}
.sf-dash-card.is-wide{grid-column:1}
.sf-dash-card-h{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.7rem 1rem;border-bottom:1px solid #eef0f3}
.sf-dash-card-h h2{margin:0;font-size:.92rem;font-weight:650}
.sf-dash-card-h a{font-size:.78rem;font-weight:600;color:#2563eb;text-decoration:none;white-space:nowrap}
.sf-dash-card-h a:hover{text-decoration:underline}
.sf-dash-empty{margin:0;padding:1rem;color:#6b7280;font-size:.84rem}
.sf-dash-list{list-style:none;margin:0;padding:.15rem .85rem .35rem}
.sf-dash-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:.65rem .85rem;padding:.55rem .15rem;border-bottom:1px solid #f1f5f9;min-height:3.1rem}
.sf-dash-row.has-thumb{grid-template-columns:2.15rem minmax(0,1fr) 6.4rem}
.sf-dash-row.has-status{grid-template-columns:minmax(0,1fr) 6.4rem auto}
.sf-dash-list .sf-dash-row:last-child{border-bottom:0}
.sf-dash-copy{min-width:0}
.sf-dash-copy a{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#111827;font-weight:650;font-size:.84rem;text-decoration:none;line-height:1.3}
.sf-dash-copy a:hover{color:#2563eb}
.sf-dash-sub{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:.1rem;color:#6b7280;font-size:.72rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.sf-dash-thumb{width:2.15rem;height:2.15rem;border-radius:.4rem;object-fit:cover;background:#f3f4f6;display:inline-flex;align-items:center;justify-content:center;color:#9ca3af}
.sf-dash-thumb.is-empty{border:1px solid #e5e7eb}
.sf-dash-meta{font-variant-numeric:tabular-nums;white-space:nowrap;color:#6b7280;font-size:.75rem;text-align:right}
.sf-dash-qty{display:inline-flex;align-items:baseline;justify-content:flex-end;gap:.15rem;min-width:4.4rem;padding:.2rem .5rem;border-radius:.4rem;font-variant-numeric:tabular-nums;font-weight:700;font-size:.84rem;white-space:nowrap}
.sf-dash-qty small{font-weight:600;font-size:.7rem;opacity:.75}
.sf-dash-qty.is-out{background:#fef2f2;color:#b91c1c}
.sf-dash-qty.is-low{background:#fff7ed;color:#c2410c}
.sf-dash-pill{display:inline-flex;align-items:center;padding:.15rem .45rem;border-radius:999px;background:#f3f4f6;color:#374151;font-size:.7rem;font-weight:700;white-space:nowrap}
.sf-dash-pill.is-warn{background:#fef3c7;color:#92400e}
.sf-dash-table{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
.sf-dash-table th,.sf-dash-table td{padding:.6rem .8rem;font-size:.8rem;vertical-align:middle}
.sf-dash-table thead th{background:#4b5563;color:#fff;font-weight:500;font-size:.72rem;letter-spacing:.02em;text-align:left;border:0;white-space:nowrap}
.sf-dash-table tbody td{border-top:1px solid #f1f5f9}
.sf-dash-table tbody tr:hover{background:#f8fafc}
.sf-dash-table .is-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.sf-dash-table .is-muted{color:#6b7280;white-space:nowrap;font-variant-numeric:tabular-nums}
.sf-dash-table .is-sku{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.74rem;color:#4b5563}
.sf-dash-table .is-plus{color:#15803d;font-weight:700}
.sf-dash-table .is-minus{color:#b91c1c;font-weight:700}
.sf-dash-table .c-when{width:7.5rem}
.sf-dash-clip{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media (max-width:1100px){
  .sf-dash-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
  .sf-dash-grid{grid-template-columns:1fr}
  .sf-dash-card.is-wide{grid-column:auto}
}
</style>
CSS;
    }
}
