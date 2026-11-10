<?php
declare(strict_types=1);

use Module\Store\Services\OrderRepository;
use Module\Store\Services\SyncLogRepository;
use Module\Store\Services\VariantRepository;
use System\Engine\Controller;

class StoreAdminUnmapped extends Controller
{
    private OrderRepository $orders;
    private VariantRepository $variants;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->orders = new OrderRepository($this->db);
        $this->variants = new VariantRepository($this->db);
    }

    public function indexAction(): void
    {
        $items = $this->orders->unmappedItems(250);

        echo $this->view->inline(function () use ($items) {
            $this->view->assign('title', 'Unmapped SKUs');
            $this->view->block('right_sidebar', 'Orders');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-link-45deg me-1"></i>Unmapped SKU queue</h1>'
               . '<p class="text-muted small mb-0">'
               . count($items) . ' open order line(s) without a variant mapping.</p></div>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/orders')) . '">Orders</a>';
            echo '</div>';

            if ($items === []) {
                echo '<div class="alert alert-success">No unmapped SKUs on open orders.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle bg-white border">';
            echo '<thead class="table-light"><tr>'
               . '<th>Order</th><th>Channel</th><th>SKU</th><th>Title</th><th class="text-end">Qty</th>'
               . '<th>Map to variant</th><th></th></tr></thead><tbody>';

            foreach ($items as $row) {
                $itemId = (int) ($row['item_id'] ?? 0);
                $sku = (string) ($row['external_sku'] ?? '');
                echo '<tr>';
                echo '<td><a href="' . $e($this->url->to('store/admin/orders/view', ['id' => (int) $row['order_id']])) . '">'
                   . $e((string) (($row['external_order_id'] ?? '') !== '' ? $row['external_order_id'] : '#' . $row['order_id']))
                   . '</a></td>';
                echo '<td class="small">' . $e((string) ($row['channel_label'] ?? '—')) . '</td>';
                echo '<td><code>' . $e($sku !== '' ? $sku : '—') . '</code></td>';
                echo '<td class="small">' . $e((string) ($row['title'] ?? '')) . '</td>';
                echo '<td class="text-end">' . (int) ($row['qty'] ?? 0) . '</td>';
                echo '<td colspan="2">';
                echo '<form method="post" action="'
                   . $e($this->url->to('store/admin/orders/unmapped/map'))
                   . '" class="d-flex flex-wrap gap-2 align-items-center">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="item_id" value="' . $itemId . '">';
                echo '<input type="number" class="form-control form-control-sm" name="variant_id" min="1"'
                   . ' placeholder="Variant ID" required style="width:8rem">';
                if ($sku !== '') {
                    echo '<input type="text" class="form-control form-control-sm" name="sku_hint" value="'
                       . $e($sku) . '" readonly style="width:9rem" title="Order SKU">';
                }
                echo '<label class="form-check small m-0 text-nowrap">'
                   . '<input type="checkbox" class="form-check-input me-1" name="link_listing" value="1"> Link listing</label>';
                echo '<button type="submit" class="btn btn-sm btn-primary">Map</button>';
                echo '</form></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '<p class="small text-muted mt-2">Enter the catalog variant ID. Optionally create a pending listing link for that channel.</p>';
        });
    }

    public function mapAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/orders/unmapped');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/orders/unmapped');
            return;
        }

        $itemId = (int) $this->request->post('item_id', 'int', 0);
        $variantId = (int) $this->request->post('variant_id', 'int', 0);
        $linkListing = (int) $this->request->post('link_listing', 'int', 0) === 1;
        $skuHint = trim((string) $this->request->post('sku_hint', 'string', ''));

        try {
            if ($variantId <= 0 && $skuHint !== '') {
                $v = $this->variants->findBySku($skuHint);
                if ($v !== null && $v->id) {
                    $variantId = (int) $v->id;
                }
            }
            $this->orders->mapItemVariant($itemId, $variantId, $linkListing);
            try {
                (new SyncLogRepository($this->db))->log(
                    0,
                    'order_item_map',
                    'ok',
                    'Mapped order item #' . $itemId . ' → variant #' . $variantId,
                    ['item_id' => $itemId, 'variant_id' => $variantId, 'link_listing' => $linkListing],
                    $this->auth->id(),
                    null,
                );
            } catch (\Throwable) {
            }
            $this->notify->success('SKU mapped to variant #' . $variantId . '.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }

        redirect_to('store/admin/orders/unmapped');
    }
}
