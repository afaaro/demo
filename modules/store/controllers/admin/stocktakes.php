<?php
declare(strict_types=1);

use Module\Store\Services\StocktakeService;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreAdminStocktakes extends Controller
{
    private StocktakeService $stocktakes;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->stocktakes = new StocktakeService($this->db);
    }

    public function indexAction(): void
    {
        $rows = $this->stocktakes->list('', 100);
        echo $this->view->inline(function () use ($rows) {
            $this->view->assign('title', 'Stocktakes');
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-clipboard2-check me-1"></i>Cycle counts</h1>'
               . '<p class="text-muted small mb-0">Stocktake sessions → adjust on finalize.</p></div>';
            echo '<a class="btn btn-success btn-sm" href="'
               . $e($this->url->to('store/admin/stocktakes/new')) . '">'
               . '<i class="bi bi-plus-lg me-1"></i>New stocktake</a></div>';

            if ($rows === []) {
                echo '<div class="alert alert-secondary">No stocktakes yet.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle bg-white border">';
            echo '<thead class="table-light"><tr><th>#</th><th>Name</th><th>Warehouse</th>'
               . '<th>Status</th><th>Created</th><th></th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $st = strtolower((string) ($row['status'] ?? ''));
                $badge = $st === 'open' ? 'text-bg-warning' : 'text-bg-success';
                echo '<tr>';
                echo '<td>' . $id . '</td>';
                echo '<td>' . $e((string) $row['name']) . '</td>';
                echo '<td class="small">' . $e((string) ($row['warehouse'] !== '' ? $row['warehouse'] : '—')) . '</td>';
                echo '<td><span class="badge ' . $badge . '">' . $e((string) $row['status']) . '</span></td>';
                echo '<td class="small text-nowrap">' . $e((string) $row['created_at']) . '</td>';
                echo '<td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($this->url->to('store/admin/stocktakes/view', ['id' => $id])) . '">Open</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    public function newAction(): void
    {
        if ($this->request->isPost()) {
            try {
                $this->request->checkToken();
                $id = $this->stocktakes->create(
                    (string) $this->request->post('name', 'string', ''),
                    (string) $this->request->post('warehouse', 'string', ''),
                    $this->auth->id(),
                );
                $seed = !empty($this->request->post('seed', 'string', ''));
                if ($seed) {
                    $n = $this->stocktakes->seedFromInventory($id);
                    $this->notify->success('Stocktake created with ' . $n . ' line(s).');
                } else {
                    $this->notify->success('Stocktake created.');
                }
                redirect_to('store/admin/stocktakes/view', ['id' => $id]);
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () {
            $this->view->assign('title', 'New stocktake');
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string $s): string => $this->view->e($s);
            echo '<h1 class="h3 mb-3">New stocktake</h1>';
            echo '<form method="post" class="card border-0 shadow-sm" style="max-width:32rem">';
            echo $this->request->csrfField();
            echo '<div class="card-body">';
            echo '<div class="mb-3"><label class="form-label">Name</label>'
               . '<input class="form-control" name="name" required placeholder="Cycle count ' . $e(date('Y-m-d')) . '"></div>';
            echo '<div class="mb-3"><label class="form-label">Warehouse (optional filter)</label>'
               . '<input class="form-control" name="warehouse" placeholder="Default"></div>';
            echo '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="seed" value="1" id="seed" checked>'
               . '<label class="form-check-label" for="seed">Seed lines from inventory</label></div>';
            echo '<button class="btn btn-primary" type="submit">Create</button> ';
            echo '<a class="btn btn-outline-secondary" href="' . $e($this->url->to('store/admin/stocktakes')) . '">Cancel</a>';
            echo '</div></form>';
        });
    }

    public function viewAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $data = $this->stocktakes->find($id);
        if ($data === null) {
            throw new NotFoundException('Stocktake not found.');
        }
        $session = $data['session'];
        $lines = $data['lines'];
        $open = strtolower((string) ($session['status'] ?? '')) === 'open';

        echo $this->view->inline(function () use ($session, $lines, $open, $id) {
            $this->view->assign('title', 'Stocktake #' . $id);
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1">' . $e((string) $session['name']) . '</h1>'
               . '<p class="text-muted small mb-0">#' . $id . ' · ' . $e((string) $session['status'])
               . ($session['warehouse'] !== '' ? ' · ' . $e((string) $session['warehouse']) : '')
               . '</p></div>';
            echo '<div class="d-flex gap-2">';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/stocktakes')) . '">All</a>';
            if ($open) {
                echo '<form method="post" action="'
                   . $e($this->url->to('store/admin/stocktakes/seed', ['id' => $id])) . '">'
                   . $this->request->csrfField()
                   . '<button class="btn btn-outline-primary btn-sm" type="submit">Seed inventory</button></form>';
                echo '<form method="post" action="'
                   . $e($this->url->to('store/admin/stocktakes/finalize', ['id' => $id])) . '"'
                   . ' onsubmit="return confirm(\'Finalize and adjust stock for counted lines?\');">'
                   . $this->request->csrfField()
                   . '<button class="btn btn-success btn-sm" type="submit">Finalize</button></form>';
            }
            echo '</div></div>';

            if ($lines === []) {
                echo '<div class="alert alert-secondary">No lines. Seed from inventory to begin.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm align-middle bg-white border">';
            echo '<thead class="table-light"><tr><th>SKU</th><th>Title</th>'
               . '<th class="text-end">System</th><th class="text-end">Counted</th><th>Notes</th><th></th></tr></thead><tbody>';
            foreach ($lines as $line) {
                $lid = (int) $line['id'];
                echo '<tr>';
                echo '<td><code>' . $e((string) $line['sku']) . '</code></td>';
                echo '<td class="small">' . $e((string) $line['title']) . '</td>';
                echo '<td class="text-end">' . (int) $line['system_qty'] . '</td>';
                if ($open) {
                    echo '<td colspan="3"><form method="post" class="d-flex gap-2 align-items-center" action="'
                       . $e($this->url->to('store/admin/stocktakes/count', ['id' => $id])) . '">'
                       . $this->request->csrfField()
                       . '<input type="hidden" name="line_id" value="' . $lid . '">'
                       . '<input type="number" class="form-control form-control-sm text-end" name="counted_qty" min="0" style="width:6rem" value="'
                       . ($line['counted_qty'] === null || $line['counted_qty'] === '' ? '' : (int) $line['counted_qty'])
                       . '" required>'
                       . '<input type="text" class="form-control form-control-sm" name="notes" placeholder="Notes" value="'
                       . $e((string) $line['notes']) . '" style="max-width:12rem">'
                       . '<button class="btn btn-sm btn-outline-primary" type="submit">Save</button></form></td>';
                } else {
                    echo '<td class="text-end">' . ($line['counted_qty'] === null ? '—' : (int) $line['counted_qty']) . '</td>';
                    echo '<td class="small">' . $e((string) $line['notes']) . '</td><td></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    public function seedAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        if (!$this->request->isPost()) {
            redirect_to('store/admin/stocktakes/view', ['id' => $id]);
            return;
        }
        try {
            $this->request->checkToken();
            $n = $this->stocktakes->seedFromInventory($id);
            $this->notify->success('Added ' . $n . ' line(s).');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/stocktakes/view', ['id' => $id]);
    }

    public function countAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        if (!$this->request->isPost()) {
            redirect_to('store/admin/stocktakes/view', ['id' => $id]);
            return;
        }
        try {
            $this->request->checkToken();
            $this->stocktakes->setCountedQty(
                (int) $this->request->post('line_id', 'int', 0),
                (int) $this->request->post('counted_qty', 'int', 0),
                (string) $this->request->post('notes', 'string', ''),
            );
            $this->notify->success('Count saved.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/stocktakes/view', ['id' => $id]);
    }

    public function finalizeAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        if (!$this->request->isPost()) {
            redirect_to('store/admin/stocktakes/view', ['id' => $id]);
            return;
        }
        try {
            $this->request->checkToken();
            $result = $this->stocktakes->finalize($id, $this->auth->id());
            $this->notify->success(
                'Finalized: ' . $result['adjusted'] . ' adjusted, ' . $result['skipped'] . ' skipped.'
            );
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/stocktakes/view', ['id' => $id]);
    }
}
