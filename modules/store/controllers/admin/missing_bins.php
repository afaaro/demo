<?php
declare(strict_types=1);

use Module\Store\Services\InventoryService;
use System\Engine\Controller;

class StoreAdminMissingBins extends Controller
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
        $rows = $this->inventory->missingPickBins(250);
        $history = $this->inventory->binMoveHistory(40);

        echo $this->view->inline(function () use ($rows, $history) {
            $this->view->assign('title', 'Missing pick bins');
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-geo me-1"></i>Missing pick bins</h1>'
               . '<p class="text-muted small mb-0">'
               . count($rows) . ' active variant(s) with stock or open demand and no pick bin.</p></div>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/inventory')) . '">Inventory</a>';
            echo '</div>';

            echo '<div class="card border-0 shadow-sm mb-4"><div class="card-body">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Relocate bin</h2>';
            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/inventory/missing-bins/relocate'))
               . '" class="row g-2 align-items-end">';
            echo $this->request->csrfField();
            echo '<div class="col-md-2"><label class="form-label small">Variant ID</label>'
               . '<input type="number" min="1" name="variant_id" class="form-control form-control-sm" required></div>';
            echo '<div class="col-md-2"><label class="form-label small">From bin</label>'
               . '<input type="text" name="from_bin" class="form-control form-control-sm" maxlength="64"'
               . ' placeholder="auto"></div>';
            echo '<div class="col-md-2"><label class="form-label small">To bin</label>'
               . '<input type="text" name="to_bin" class="form-control form-control-sm" maxlength="64" required></div>';
            echo '<div class="col-md-4"><label class="form-label small">Note</label>'
               . '<input type="text" name="note" class="form-control form-control-sm" maxlength="255"></div>';
            echo '<div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary w-100">Relocate</button></div>';
            echo '</form></div></div>';

            if ($rows === []) {
                echo '<div class="alert alert-success">All stocked / demanded variants have a pick bin.</div>';
            } else {
                echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle bg-white border">';
                echo '<thead class="table-light"><tr>'
                   . '<th>SKU</th><th>Product</th><th class="text-end">Qty</th><th class="text-end">Demand</th>'
                   . '<th>Pick bin</th><th></th></tr></thead><tbody>';
                foreach ($rows as $row) {
                    $vid = (int) $row['variant_id'];
                    echo '<tr>';
                    echo '<td><code>' . $e($row['sku']) . '</code></td>';
                    echo '<td><a href="' . $e($this->url->to('store/admin/products/edit', ['id' => (int) $row['product_id']])) . '">'
                       . $e($row['product_name']) . '</a></td>';
                    echo '<td class="text-end">' . (int) $row['quantity'] . '</td>';
                    echo '<td class="text-end">' . (int) $row['open_demand'] . '</td>';
                    echo '<td colspan="2">';
                    echo '<form method="post" action="'
                       . $e($this->url->to('store/admin/inventory/missing-bins/save'))
                       . '" class="d-flex gap-2 align-items-center">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="variant_id" value="' . $vid . '">';
                    echo '<input type="text" class="form-control form-control-sm" name="pick_bin" maxlength="64"'
                       . ' placeholder="e.g. A1-02" required style="width:9rem">';
                    echo '<button type="submit" class="btn btn-sm btn-primary">Save</button>';
                    echo '<a class="btn btn-sm btn-outline-secondary" href="'
                       . $e($this->url->to('store/admin/inventory/adjust', ['id' => $vid])) . '">Stock</a>';
                    echo '</form></td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }

            echo '<h2 class="h6 text-uppercase text-muted mt-4 mb-2">Recent bin moves</h2>';
            if ($history === []) {
                echo '<p class="text-muted small">No relocates yet.</p>';
            } else {
                echo '<div class="table-responsive"><table class="table table-sm bg-white border">';
                echo '<thead class="table-light"><tr><th>When</th><th>SKU</th><th>From</th><th>To</th><th>Note</th></tr></thead><tbody>';
                foreach ($history as $h) {
                    echo '<tr>';
                    echo '<td class="small text-nowrap">' . $e((string) ($h['created_at'] ?? '')) . '</td>';
                    echo '<td><code>' . $e((string) ($h['sku'] ?? '')) . '</code></td>';
                    echo '<td>' . $e((string) ($h['from_bin'] ?? '') ?: '—') . '</td>';
                    echo '<td>' . $e((string) ($h['to_bin'] ?? '')) . '</td>';
                    echo '<td class="small">' . $e((string) ($h['note'] ?? '')) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
        });
    }

    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/inventory/missing-bins');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/inventory/missing-bins');
            return;
        }

        $variantId = (int) $this->request->post('variant_id', 'int', 0);
        $pickBin = trim((string) $this->request->post('pick_bin', 'string', ''));
        try {
            $this->inventory->setPickBin($variantId, $pickBin);
            $this->notify->success('Pick bin saved.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/inventory/missing-bins');
    }

    public function relocateAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/inventory/missing-bins');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/inventory/missing-bins');
            return;
        }

        $variantId = (int) $this->request->post('variant_id', 'int', 0);
        $from = trim((string) $this->request->post('from_bin', 'string', ''));
        $to = trim((string) $this->request->post('to_bin', 'string', ''));
        $note = trim((string) $this->request->post('note', 'string', ''));
        try {
            $this->inventory->relocateBin($variantId, $from, $to, $this->auth->id(), $note);
            $this->notify->success('Bin relocated.');
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/inventory/missing-bins');
    }
}
