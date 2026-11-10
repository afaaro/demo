<?php
declare(strict_types=1);

use Module\Store\Services\InventoryService;
use Module\Store\Services\SyncLogRepository;
use System\Engine\Controller;

class StoreAdminReservedIntegrity extends Controller
{
    private InventoryService $inventory;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->inventory = new InventoryService($this->db);
    }

    public function indexAction(): void
    {
        $rows = $this->inventory->reservedIntegrityMismatches(250);

        echo $this->view->inline(function () use ($rows) {
            $this->view->assign('title', 'Reserved stock integrity');
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-shield-exclamation me-1"></i>Reserved stock integrity</h1>'
               . '<p class="text-muted small mb-0">'
               . count($rows) . ' mismatch(es) between inventory.reserved and open order demand.</p></div>';
            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/inventory/reserved-integrity/rebuild')) . '">'
               . $this->request->csrfField()
               . '<button type="submit" class="btn btn-primary btn-sm"'
               . ' onclick="return confirm(\'Rebuild reserved qty from open orders?\');">'
               . 'Rebuild reserved</button></form>';
            echo '</div>';

            if ($rows === []) {
                echo '<div class="alert alert-success">Reserved stock matches open order demand.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle bg-white border">';
            echo '<thead class="table-light"><tr>'
               . '<th>SKU</th><th>Product</th><th class="text-end">On hand</th>'
               . '<th class="text-end">Reserved</th><th class="text-end">Expected</th>'
               . '<th class="text-end">Delta</th><th></th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $deltaClass = ((int) $row['delta']) === 0 ? '' : 'text-danger fw-semibold';
                echo '<tr>';
                echo '<td><code>' . $e($row['sku']) . '</code></td>';
                echo '<td><a href="' . $e($this->url->to('store/admin/products/edit', ['id' => (int) $row['product_id']])) . '">'
                   . $e($row['product_name']) . '</a></td>';
                echo '<td class="text-end">' . (int) $row['quantity'] . '</td>';
                echo '<td class="text-end">' . (int) $row['reserved'] . '</td>';
                echo '<td class="text-end">' . (int) $row['expected'] . '</td>';
                echo '<td class="text-end ' . $deltaClass . '">' . (int) $row['delta'] . '</td>';
                echo '<td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($this->url->to('store/admin/inventory/adjust', ['id' => (int) $row['variant_id']]))
                   . '">Adjust</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    public function rebuildAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/inventory/reserved-integrity');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/inventory/reserved-integrity');
            return;
        }

        try {
            $this->inventory->rebuildReservedFromOpenOrders();
            try {
                (new SyncLogRepository($this->db))->log(
                    0,
                    'reserved_rebuild',
                    'ok',
                    'Rebuilt reserved stock from open orders',
                    [],
                    $this->auth->id(),
                    null,
                );
            } catch (\Throwable) {
            }
            $this->notify->success('Reserved quantities rebuilt from open orders.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/inventory/reserved-integrity');
    }
}
