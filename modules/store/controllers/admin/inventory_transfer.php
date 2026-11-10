<?php
declare(strict_types=1);

use Module\Store\Services\InventoryService;
use Module\Store\Services\VariantRepository;
use Module\Store\Services\WarehouseRepository;
use System\Engine\Controller;

class StoreAdminInventoryTransfer extends Controller
{
    private InventoryService $inventory;
    private WarehouseRepository $warehouses;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->inventory = new InventoryService($this->db);
        $this->warehouses = new WarehouseRepository($this->db);
    }

    public function indexAction(): void
    {
        $warehouses = $this->warehouses->all('', true);
        $variantId = (int) $this->request->get('variant_id', 'int', 0);
        $locations = $variantId > 0 ? $this->inventory->locationsForVariant($variantId) : [];
        $stock = $variantId > 0 ? $this->inventory->get($variantId) : null;
        $sku = '';
        if ($variantId > 0) {
            $v = (new VariantRepository($this->db))->find($variantId);
            $sku = $v !== null ? (string) ($v->sku ?? '') : '';
        }

        echo $this->view->inline(function () use ($warehouses, $variantId, $locations, $stock, $sku) {
            $this->view->assign('title', 'Inventory transfer');
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-arrow-left-right me-1"></i>Warehouse transfer</h1>'
               . '<p class="text-muted small mb-0">Move stock between warehouses (location ledger + aggregate stays net-neutral).</p></div>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/inventory')) . '">Inventory</a></div>';

            echo '<form method="get" class="row g-2 align-items-end mb-3">';
            echo '<input type="hidden" name="route" value="store/admin/inventory/transfer">';
            echo '<div class="col-auto"><label class="form-label small mb-0">Variant ID</label>'
               . '<input type="number" class="form-control form-control-sm" name="variant_id" min="1" value="'
               . ($variantId > 0 ? $variantId : '') . '" style="width:8rem"></div>';
            echo '<div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-primary">Load</button></div>';
            if ($sku !== '') {
                echo '<div class="col-auto small text-muted pt-3">SKU <code>' . $e($sku) . '</code>'
                   . ($stock ? ' · aggregate avail ' . (int) $stock['available'] : '')
                   . '</div>';
            }
            echo '</form>';

            if ($variantId > 0) {
                if ($locations !== []) {
                    echo '<div class="table-responsive mb-3"><table class="table table-sm bg-white border">'
                       . '<thead class="table-light"><tr><th>Warehouse</th><th class="text-end">Qty</th>'
                       . '<th class="text-end">Reserved</th><th class="text-end">Available</th></tr></thead><tbody>';
                    foreach ($locations as $loc) {
                        echo '<tr><td>' . $e($loc['warehouse']) . '</td>'
                           . '<td class="text-end">' . (int) $loc['quantity'] . '</td>'
                           . '<td class="text-end">' . (int) $loc['reserved'] . '</td>'
                           . '<td class="text-end">' . (int) $loc['available'] . '</td></tr>';
                    }
                    echo '</tbody></table></div>';
                } else {
                    echo '<div class="alert alert-secondary">No location rows yet — first transfer seeds from aggregate if needed.</div>';
                }

                echo '<form method="post" action="'
                   . $e($this->url->to('store/admin/inventory/transfer/save'))
                   . '" class="card border-0 shadow-sm"><div class="card-body row g-3">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="variant_id" value="' . $variantId . '">';
                echo '<div class="col-md-4"><label class="form-label">From warehouse</label>'
                   . '<select name="from_warehouse_id" class="form-select" required>';
                echo '<option value="">—</option>';
                foreach ($warehouses as $w) {
                    echo '<option value="' . (int) $w['id'] . '">' . $e($w['name']) . '</option>';
                }
                echo '</select></div>';
                echo '<div class="col-md-4"><label class="form-label">To warehouse</label>'
                   . '<select name="to_warehouse_id" class="form-select" required>';
                echo '<option value="">—</option>';
                foreach ($warehouses as $w) {
                    echo '<option value="' . (int) $w['id'] . '">' . $e($w['name']) . '</option>';
                }
                echo '</select></div>';
                echo '<div class="col-md-2"><label class="form-label">Qty</label>'
                   . '<input type="number" min="1" name="qty" class="form-control" required value="1"></div>';
                echo '<div class="col-md-8"><label class="form-label">Note</label>'
                   . '<input type="text" name="note" class="form-control" maxlength="255"></div>';
                echo '<div class="col-md-4 d-flex align-items-end">'
                   . '<button type="submit" class="btn btn-primary w-100">Transfer</button></div>';
                echo '</div></form>';
            }
        });
    }

    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/inventory/transfer');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/inventory/transfer');
            return;
        }

        $variantId = (int) $this->request->post('variant_id', 'int', 0);
        $fromId = (int) $this->request->post('from_warehouse_id', 'int', 0);
        $toId = (int) $this->request->post('to_warehouse_id', 'int', 0);
        $qty = (int) $this->request->post('qty', 'int', 0);
        $note = trim((string) $this->request->post('note', 'string', ''));

        try {
            $result = $this->inventory->transferBetweenWarehouses(
                $variantId,
                $fromId,
                $toId,
                $qty,
                $this->auth->id(),
                $note
            );
            $this->notify->success(
                'Transferred ' . $qty . ' unit(s). From qty now '
                . (int) $result['from_qty'] . ', to qty ' . (int) $result['to_qty'] . '.'
            );
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/inventory/transfer', ['variant_id' => $variantId]);
    }
}
