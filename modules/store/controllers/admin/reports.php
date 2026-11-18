<?php
declare(strict_types=1);

use System\Engine\Controller;

class StoreAdminReports extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
    }

    public function indexAction(): void
    {
        $links = [
            ['SKU margin', 'store/admin/analytics/sku-margin', 'Channel / SKU margin over time.'],
            ['Supplier OTIF', 'store/admin/analytics/supplier-otif', 'On-time in-full purchasing scorecard.'],
            ['Pick / pack hub', 'store/admin/analytics/pick-pack', 'Picker and packer productivity.'],
            ['Inventory stock', 'store/admin/inventory', 'Live stock grid and low-stock filters.'],
            ['Missing pick bins', 'store/admin/inventory/missing-bins', 'Variants without a pickbin.'],
            ['Stocktakes', 'store/admin/stocktakes', 'Count sheets and approvals.'],
            ['Outbound deliveries', 'store/admin/warehouse/deliveries', 'Shipment and tracking history.'],
            ['Courier export', 'store/admin/warehouse/courier-export', 'Daily courier CSV close-out.'],
            ['Purchase orders', 'store/admin/purchase-orders', 'Open and booked-in purchase orders.'],
            ['Listing health', 'store/admin/listing-health', 'Channel listing errors and qty mismatch.'],
        ];

        echo $this->view->inline(function () use ($links) {
            $this->view->assign('title', 'Reports');
            $this->view->block('right_sidebar', 'Reports');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="sf-page">';
            echo '<div class="sf-head"><div><h1>Reports</h1>'
               . '<p>Warehouse, purchasing, and channel reports in one place.</p></div></div>';
            echo '<div class="row g-3">';
            foreach ($links as [$label, $route, $blurb]) {
                echo '<div class="col-md-6 col-xl-4"><a class="sf-stat" href="'
                   . $e($this->url->to($route)) . '"><span>' . $e($label) . '</span>'
                   . '<strong style="font-size:1rem;font-weight:600">' . $e($blurb) . '</strong></a></div>';
            }
            echo '</div></div>';
        });
    }
}
