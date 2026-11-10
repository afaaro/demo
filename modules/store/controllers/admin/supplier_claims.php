<?php
declare(strict_types=1);

use Module\Store\Services\SupplierClaimRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreAdminSupplierClaims extends Controller
{
    private SupplierClaimRepository $claims;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->claims = new SupplierClaimRepository($this->db);
    }

    public function indexAction(): void
    {
        $status = trim((string) $this->request->get('status', 'string', ''));
        $rows = $this->claims->list($status, 200);

        echo $this->view->inline(function () use ($rows, $status) {
            $this->view->assign('title', 'Supplier claims');
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1">Supplier claims</h1>'
               . '<p class="text-muted small mb-0">Shortfalls / write-offs raised against suppliers.</p></div>';
            echo '<div class="btn-group btn-group-sm">';
            foreach (['' => 'All', 'open' => 'Open', 'chasing' => 'Chasing', 'resolved' => 'Resolved'] as $k => $lab) {
                $cls = $status === $k ? 'btn-primary' : 'btn-outline-secondary';
                $url = $this->url->to('store/admin/supplier-claims', $k !== '' ? ['status' => $k] : []);
                echo '<a class="btn ' . $cls . '" href="' . $e($url) . '">' . $e($lab) . '</a>';
            }
            echo '</div></div>';

            if ($rows === []) {
                echo '<div class="alert alert-secondary">No claims yet. Write off shortfalls on a PO to raise one.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle bg-white border">';
            echo '<thead class="table-light"><tr><th>#</th><th>PO</th><th>Supplier</th><th class="text-end">Qty</th>'
               . '<th>Reason</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $st = (string) $row['status'];
                $badge = match ($st) {
                    'resolved' => 'text-bg-success',
                    'chasing'  => 'text-bg-warning',
                    default    => 'text-bg-secondary',
                };
                echo '<tr>';
                echo '<td>' . $id . '</td>';
                echo '<td>';
                if ((int) $row['po_id'] > 0) {
                    echo '<a href="' . $e($this->url->to('store/admin/purchase-orders/view', ['id' => (int) $row['po_id']]))
                       . '">' . $e((string) ($row['po_reference'] !== '' ? $row['po_reference'] : '#' . $row['po_id'])) . '</a>';
                } else {
                    echo '—';
                }
                echo '</td>';
                echo '<td>' . $e((string) ($row['supplier_name'] !== '' ? $row['supplier_name'] : '—')) . '</td>';
                echo '<td class="text-end">' . (int) $row['qty'] . '</td>';
                echo '<td class="small">' . $e((string) $row['reason']) . '</td>';
                echo '<td><span class="badge ' . $badge . '">' . $e($st) . '</span></td>';
                echo '<td class="small text-nowrap">' . $e((string) $row['created_at']) . '</td>';
                echo '<td class="text-end">';
                if ($st !== 'resolved') {
                    echo '<form method="post" class="d-inline" action="'
                       . $e($this->url->to('store/admin/supplier-claims/status', ['id' => $id])) . '">'
                       . $this->request->csrfField();
                    if ($st === 'open') {
                        echo '<input type="hidden" name="status" value="chasing">'
                           . '<button class="btn btn-sm btn-outline-warning">Chase</button></form> ';
                    }
                    echo '<form method="post" class="d-inline" action="'
                       . $e($this->url->to('store/admin/supplier-claims/status', ['id' => $id])) . '">'
                       . $this->request->csrfField()
                       . '<input type="hidden" name="status" value="resolved">'
                       . '<button class="btn btn-sm btn-outline-success">Resolve</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    public function statusAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/supplier-claims');
            return;
        }
        try {
            $this->request->checkToken();
            $id = (int) $this->request->get('id', 'int', 0);
            if ($this->claims->find($id) === null) {
                throw new NotFoundException('Claim not found.');
            }
            $this->claims->updateStatus(
                $id,
                (string) $this->request->post('status', 'string', 'open'),
                (string) $this->request->post('notes', 'string', '')
            );
            $this->notify->success('Claim updated.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/supplier-claims');
    }
}
