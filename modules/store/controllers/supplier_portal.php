<?php
declare(strict_types=1);

use Module\Store\Services\AsnRepository;
use Module\Store\Services\PurchaseOrderRepository;
use Module\Store\Services\SupplierPortalTokenRepository;
use Module\Store\Services\TenantContext;
use System\Engine\Controller;

/**
 * Public-ish supplier portal gated by token query param.
 */
class StoreSupplierPortal extends Controller
{
    private SupplierPortalTokenRepository $tokens;
    private PurchaseOrderRepository $pos;
    private AsnRepository $asns;

    protected function boot(): void
    {
        $this->tokens = new SupplierPortalTokenRepository($this->db);
        $this->pos = new PurchaseOrderRepository($this->db);
        $this->asns = new AsnRepository($this->db);
    }

    public function indexAction(): void
    {
        $token = trim((string) $this->request->get('token', 'string', ''));
        $gate = $this->tokens->findValid($token);
        if ($gate === null) {
            http_response_code(403);
            echo '<!DOCTYPE html><html><body><h1>Invalid or expired portal link</h1></body></html>';
            exit;
        }

        $tenantRow = $this->db->query(
            'SELECT `id`, `slug`, `name`, `status` FROM `#__store_tenants` WHERE `id` = ? LIMIT 1',
            [(int) $gate['tenant_id']]
        )->row;
        if ($tenantRow === []) {
            http_response_code(403);
            echo '<!DOCTYPE html><html><body><h1>Tenant unavailable</h1></body></html>';
            exit;
        }
        TenantContext::get()->set(
            (int) $tenantRow['id'],
            (string) $tenantRow['slug'],
            (string) $tenantRow['name'],
            (string) $tenantRow['status'],
            'token',
            ['supplier_portal'],
            (int) $gate['id']
        );
        $supplierId = (int) $gate['supplier_id'];
        $openPos = $this->openPosForSupplier($supplierId);

        if ($this->request->isPost()) {
            $action = (string) $this->request->post('action', 'string', '');
            try {
                if ($action === 'confirm_po') {
                    $poId = (int) $this->request->post('po_id', 'int', 0);
                    $po = $this->pos->find($poId);
                    if ($po === null || (int) ($po['supplier_id'] ?? 0) !== $supplierId) {
                        throw new \RuntimeException('PO not found.');
                    }
                    $this->pos->confirm($poId);
                    $msg = 'Purchase order confirmed.';
                } elseif ($action === 'submit_asn') {
                    $poId = (int) $this->request->post('po_id', 'int', 0);
                    $po = $this->pos->find($poId);
                    if ($po === null || (int) ($po['supplier_id'] ?? 0) !== $supplierId) {
                        throw new \RuntimeException('PO not found.');
                    }
                    $lines = [];
                    foreach ($this->pos->lines($poId) as $line) {
                        $sku = trim((string) ($line['variant_sku'] ?? ''));
                        $qty = PurchaseOrderRepository::lineOutstanding($line);
                        if ($sku !== '' && $qty > 0) {
                            $lines[] = ['sku' => $sku, 'qty_expected' => $qty];
                        }
                    }
                    $this->asns->create(
                        $poId,
                        (string) $this->request->post('expected_date', 'string', ''),
                        (int) $this->request->post('pallet_count', 'int', 0),
                        $lines,
                        'Submitted via supplier portal',
                        'submitted'
                    );
                    $msg = 'ASN submitted.';
                } else {
                    $msg = '';
                }
            } catch (\Throwable $e) {
                $msg = 'Error: ' . $e->getMessage();
            }
            $openPos = $this->openPosForSupplier($supplierId);
        } else {
            $msg = '';
        }

        header('Content-Type: text/html; charset=utf-8');
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Supplier portal</title>'
           . '<style>body{font-family:system-ui,sans-serif;margin:1.5rem;color:#111;max-width:48rem}'
           . 'table{width:100%;border-collapse:collapse;margin:1rem 0}th,td{border-bottom:1px solid #ddd;padding:.5rem;text-align:left}'
           . '.btn{display:inline-block;padding:.4rem .75rem;border:1px solid #333;background:#111;color:#fff;text-decoration:none;border-radius:4px;cursor:pointer}'
           . '.ok{background:#ecfdf5;border:1px solid #6ee7b7;padding:.5rem .75rem;margin-bottom:1rem}</style></head><body>';
        echo '<h1>Supplier portal</h1>';
        echo '<p>Hello <strong>' . $e((string) $gate['supplier_name']) . '</strong>.</p>';
        if ($msg !== '') {
            echo '<div class="ok">' . $e($msg) . '</div>';
        }
        if ($openPos === []) {
            echo '<p>No open purchase orders.</p></body></html>';
            exit;
        }
        echo '<table><thead><tr><th>PO</th><th>Status</th><th>Expected</th><th></th></tr></thead><tbody>';
        foreach ($openPos as $po) {
            $poId = (int) $po['id'];
            echo '<tr>';
            echo '<td>' . $e((string) ($po['reference'] ?? '#' . $poId)) . '</td>';
            echo '<td>' . $e((string) ($po['status'] ?? '')) . '</td>';
            echo '<td>' . $e((string) ($po['expected_at'] ?? $po['expected_date'] ?? '—')) . '</td>';
            echo '<td>';
            $status = (string) ($po['status'] ?? '');
            if ($status === PurchaseOrderRepository::STATUS_DRAFT) {
                echo '<form method="post" style="display:inline">'
                   . '<input type="hidden" name="action" value="confirm_po">'
                   . '<input type="hidden" name="po_id" value="' . $poId . '">'
                   . '<button class="btn" type="submit">Confirm</button></form> ';
            }
            echo '<form method="post" style="display:inline;margin-left:.35rem">'
               . '<input type="hidden" name="action" value="submit_asn">'
               . '<input type="hidden" name="po_id" value="' . $poId . '">'
               . '<input type="date" name="expected_date" required> '
               . '<input type="number" name="pallet_count" min="0" value="1" style="width:4rem"> pallets '
               . '<button class="btn" type="submit">Submit ASN</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    /** @return list<array<string, mixed>> */
    private function openPosForSupplier(int $supplierId): array
    {
        if (!TenantContext::get()->has() || $supplierId <= 0) {
            return [];
        }
        return $this->db->query(
            "SELECT * FROM `#__store_purchase_orders`
              WHERE `tenant_id` = ? AND `supplier_id` = ?
                AND `status` NOT IN ('cancelled','received','closed')
              ORDER BY `id` DESC
              LIMIT 100",
            [TenantContext::get()->id(), $supplierId]
        )->rows;
    }
}
