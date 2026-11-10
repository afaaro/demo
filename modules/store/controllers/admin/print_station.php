<?php
declare(strict_types=1);

use Module\Store\Services\TenantContext;
use System\Engine\Controller;

class StoreAdminPrintStation extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
    }

    public function indexAction(): void
    {
        $q = trim((string) $this->request->get('q', 'string', ''));
        $variants = $this->searchVariants($q, 80);

        echo $this->view->inline(function () use ($variants, $q) {
            $this->view->assign('title', 'Print station');
            $this->view->block('right_sidebar', 'Inventory');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="mb-3"><h1 class="h3 mb-1"><i class="bi bi-printer me-1"></i>Barcode / bin label station</h1>'
               . '<p class="text-muted small mb-0">Select variants or bins, then print a label sheet.</p></div>';

            echo '<form method="get" class="row g-2 mb-3" action="'
               . $e($this->url->to('store/admin/print-station')) . '">';
            echo '<div class="col-md-6"><input class="form-control" name="q" value="' . $e($q)
               . '" placeholder="Search SKU, EAN, name, pick bin…"></div>';
            echo '<div class="col-auto"><button class="btn btn-outline-secondary">Search</button></div>';
            echo '</form>';

            echo '<form method="post" action="' . $e($this->url->to('store/admin/print-station/print'))
               . '" target="_blank">';
            echo $this->request->csrfField();
            echo '<div class="table-responsive mb-3"><table class="table table-sm table-hover align-middle bg-white border">';
            echo '<thead class="table-light"><tr><th style="width:2rem"></th><th>SKU</th><th>Name</th>'
               . '<th>EAN</th><th>Pick bin</th></tr></thead><tbody>';
            if ($variants === []) {
                echo '<tr><td colspan="5" class="text-muted">No variants found.</td></tr>';
            }
            foreach ($variants as $v) {
                $vid = (int) $v['id'];
                echo '<tr>';
                echo '<td><input type="checkbox" class="form-check-input" name="variant_ids[]" value="' . $vid . '"></td>';
                echo '<td><code>' . $e((string) $v['sku']) . '</code></td>';
                echo '<td class="small">' . $e((string) $v['name']) . '</td>';
                echo '<td class="small">' . $e((string) ($v['barcode'] !== '' ? $v['barcode'] : '—')) . '</td>';
                echo '<td><code>' . $e((string) ($v['pick_bin'] !== '' ? $v['pick_bin'] : '—')) . '</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '<button type="submit" class="btn btn-primary"><i class="bi bi-printer me-1"></i>Print selected labels</button>';
            echo '</form>';
        });
    }

    public function printAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/print-station');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\Throwable) {
            $this->notify->error('Session expired.');
            redirect_to('store/admin/print-station');
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->request->post('variant_ids', 'raw', [])),
            static fn(int $id): bool => $id > 0
        )));
        $labels = $this->labelsForVariants($ids);

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Label sheet</title>';
        echo '<style>
@page{margin:8mm}
body{font-family:system-ui,sans-serif;margin:0;color:#111}
.sheet{display:grid;grid-template-columns:repeat(3,1fr);gap:6mm;padding:6mm}
.label{border:1px dashed #999;border-radius:4px;padding:4mm;min-height:32mm;break-inside:avoid}
.sku{font-weight:700;font-size:14px;margin:0 0 2mm}
.name{font-size:11px;margin:0 0 3mm;line-height:1.25}
.meta{font-size:11px;color:#333}
.barcode{font-family:ui-monospace,monospace;font-size:13px;letter-spacing:.04em;margin-top:3mm}
.bin{display:inline-block;margin-top:2mm;padding:1mm 2mm;border:1px solid #222;font-weight:700;font-size:12px}
@media print{button{display:none}.label{border-style:solid}}
</style></head><body>';
        echo '<button onclick="window.print()" style="margin:8px">Print</button>';
        echo '<div class="sheet">';
        foreach ($labels as $lab) {
            echo '<div class="label">';
            echo '<div class="sku">' . htmlspecialchars((string) $lab['sku']) . '</div>';
            echo '<div class="name">' . htmlspecialchars((string) $lab['name']) . '</div>';
            if ($lab['barcode'] !== '') {
                echo '<div class="barcode">' . htmlspecialchars((string) $lab['barcode']) . '</div>';
                echo '<div class="meta">EAN / barcode</div>';
            }
            if ($lab['pick_bin'] !== '') {
                echo '<div class="bin">' . htmlspecialchars((string) $lab['pick_bin']) . '</div>';
            }
            echo '</div>';
        }
        if ($labels === []) {
            echo '<p>No labels selected.</p>';
        }
        echo '</div></body></html>';
        exit;
    }

    /**
     * @return list<array{id:int, sku:string, name:string, barcode:string, pick_bin:string}>
     */
    private function searchVariants(string $q, int $limit): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = TenantContext::get()->id();
        $limit = max(1, min(200, $limit));
        $params = [$tid];
        $where = 'v.`tenant_id` = ? AND v.`active` = 1';
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where .= ' AND (v.`sku` LIKE ? OR v.`name` LIKE ? OR p.`name` LIKE ? OR p.`barcode` LIKE ? OR v.`pick_bin` LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $params[] = $limit;
        $rows = $this->db->query(
            "SELECT v.`id`, v.`sku`, COALESCE(NULLIF(v.`name`,''), p.`name`) AS name,
                    COALESCE(p.`barcode`,'') AS barcode,
                    COALESCE(v.`pick_bin`,'') AS pick_bin
               FROM `#__store_product_variants` v
               JOIN `#__store_products` p ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
              WHERE {$where}
              ORDER BY v.`sku` ASC
              LIMIT ?",
            $params
        )->rows;
        return array_map(static fn(array $r): array => [
            'id'       => (int) $r['id'],
            'sku'      => (string) $r['sku'],
            'name'     => (string) ($r['name'] ?? ''),
            'barcode'  => (string) ($r['barcode'] ?? ''),
            'pick_bin' => (string) ($r['pick_bin'] ?? ''),
        ], $rows);
    }

    /**
     * @param list<int> $ids
     * @return list<array{sku:string, name:string, barcode:string, pick_bin:string}>
     */
    private function labelsForVariants(array $ids): array
    {
        if ($ids === [] || !TenantContext::get()->has()) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [TenantContext::get()->id()]);
        $rows = $this->db->query(
            "SELECT v.`sku`, COALESCE(NULLIF(v.`name`,''), p.`name`) AS name,
                    COALESCE(p.`barcode`,'') AS barcode,
                    COALESCE(v.`pick_bin`,'') AS pick_bin
               FROM `#__store_product_variants` v
               JOIN `#__store_products` p ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
              WHERE v.`id` IN ({$placeholders}) AND v.`tenant_id` = ?
              ORDER BY v.`sku` ASC",
            $params
        )->rows;
        return array_map(static fn(array $r): array => [
            'sku'      => (string) $r['sku'],
            'name'     => (string) ($r['name'] ?? ''),
            'barcode'  => (string) ($r['barcode'] ?? ''),
            'pick_bin' => (string) ($r['pick_bin'] ?? ''),
        ], $rows);
    }
}
