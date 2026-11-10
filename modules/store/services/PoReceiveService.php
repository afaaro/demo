<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

/**
 * Scan-to-receive goods-in for purchase orders (mirrors pickwave scan confirm).
 */
final class PoReceiveService
{
    use TenantScoped;

    public function __construct(
        private Database $db,
        private PurchaseOrderRepository $pos,
    ) {}

    /**
     * Confirmed scan qty by normalized SKU key.
     *
     * @return array<string, int>
     */
    public function scanTotals(int $poId): array
    {
        if ($poId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT `sku_key`, SUM(`qty`) AS qty
               FROM `#__store_po_scans`
              WHERE `po_id` = ? AND `tenant_id` = ?
              GROUP BY `sku_key`",
            [$poId, $this->tenantId()]
        )->rows;
        $out = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row['sku_key'] ?? '')));
            if ($key === '') {
                continue;
            }
            $out[$key] = (int) ($row['qty'] ?? 0);
        }
        return $out;
    }

    public function recordScan(int $poId, string $sku, int $qty, ?int $userId = null): void
    {
        $po = $this->pos->find($poId);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        if ((string) ($po['status'] ?? '') !== PurchaseOrderRepository::STATUS_CONFIRMED) {
            throw new RuntimeException('Only confirmed purchase orders accept scans.');
        }

        $sku = trim($sku);
        $qty = max(1, $qty);
        if ($sku === '') {
            throw new RuntimeException('SKU is required.');
        }
        $key = strtolower($sku);

        $needed = 0;
        foreach ($this->pos->lines($poId) as $line) {
            $lineSku = trim((string) ($line['variant_sku'] ?? $line['sku'] ?? ''));
            if (strtolower($lineSku) !== $key) {
                continue;
            }
            $needed += PurchaseOrderRepository::lineOutstanding($line);
        }
        if ($needed <= 0) {
            throw new RuntimeException('SKU not open on this purchase order.');
        }

        $already = $this->scanTotals($poId)[$key] ?? 0;
        if ($already + $qty > $needed) {
            throw new RuntimeException('Scan would exceed open qty (' . $needed . ').');
        }

        $payload = [
            'po_id'      => $poId,
            'sku_key'    => $key,
            'sku'        => mb_substr($sku, 0, 128),
            'qty'        => $qty,
            'user_id'    => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if (TenantContext::get()->has()) {
            $payload['tenant_id'] = $this->tenantId();
        }
        $this->db->insert('store_po_scans', $payload);
    }

    /**
     * @return array{complete: bool, needed: int, scanned: int, missing: list<array{sku: string, needed: int, scanned: int}>}
     */
    public function scanProgress(int $poId): array
    {
        $po = $this->pos->find($poId);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        $totals = $this->scanTotals($poId);
        /** @var array<string, array{sku: string, needed: int, scanned: int}> $bySku */
        $bySku = [];
        foreach ($this->pos->lines($poId) as $line) {
            $sku = trim((string) ($line['variant_sku'] ?? $line['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $key = strtolower($sku);
            $open = PurchaseOrderRepository::lineOutstanding($line);
            if ($open <= 0) {
                continue;
            }
            if (!isset($bySku[$key])) {
                $bySku[$key] = ['sku' => $sku, 'needed' => 0, 'scanned' => (int) ($totals[$key] ?? 0)];
            }
            $bySku[$key]['needed'] += $open;
        }

        $needed = 0;
        $scanned = 0;
        $missing = [];
        foreach ($bySku as $row) {
            $need = (int) $row['needed'];
            $got = min((int) $row['scanned'], $need);
            $needed += $need;
            $scanned += $got;
            if ($got < $need) {
                $missing[] = ['sku' => $row['sku'], 'needed' => $need, 'scanned' => $got];
            }
        }

        return [
            'complete' => $missing === [],
            'needed'   => $needed,
            'scanned'  => $scanned,
            'missing'  => $missing,
        ];
    }

    /**
     * Compare ASN expected qty vs current scan receive totals.
     *
     * @return array{asn_id:int|null, matches: list<array{sku:string, expected:int, scanned:int, delta:int}>, complete:bool}
     */
    public function compareAsn(int $poId): array
    {
        $asns = new AsnRepository($this->db);
        return $asns->compareToScans($poId, $this->scanTotals($poId));
    }

    /**
     * Book in scanned quantities against matching open lines (FIFO by line id).
     *
     * @return list<array{line_id:int, variant_id:int, qty:int}>
     */
    public function applyReceivedFromScans(int $poId): array
    {
        $po = $this->pos->find($poId);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        if ((string) ($po['status'] ?? '') !== PurchaseOrderRepository::STATUS_CONFIRMED) {
            throw new RuntimeException('Only confirmed purchase orders can be booked in.');
        }

        $totals = $this->scanTotals($poId);
        if ($totals === []) {
            throw new RuntimeException('No scans to apply.');
        }

        $asnCompare = $this->compareAsn($poId);
        if ($asnCompare['asn_id'] !== null && !$asnCompare['complete']) {
            // Soft warning path: still allow receive, but mark ASN incomplete deltas in sync notes via exception message optional.
            // MVP: proceed; UI surfaces compareAsn separately.
        }

        $qtyByLine = [];
        foreach ($this->pos->lines($poId) as $line) {
            $sku = trim((string) ($line['variant_sku'] ?? $line['sku'] ?? ''));
            $key = strtolower($sku);
            $open = PurchaseOrderRepository::lineOutstanding($line);
            $avail = (int) ($totals[$key] ?? 0);
            if ($sku === '' || $open <= 0 || $avail <= 0) {
                continue;
            }
            $take = min($open, $avail);
            $qtyByLine[(int) $line['id']] = $take;
            $totals[$key] = $avail - $take;
        }

        if ($qtyByLine === []) {
            throw new RuntimeException('Scans do not match any open lines.');
        }

        $out = $this->pos->bookInLines($poId, $qtyByLine);

        // Clear applied scans for this PO so partial receive can restart cleanly.
        if (TenantContext::get()->has()) {
            $this->db->query(
                "DELETE FROM `#__store_po_scans` WHERE `po_id` = ? AND `tenant_id` = ?",
                [$poId, $this->tenantId()]
            );
        } else {
            $this->db->query("DELETE FROM `#__store_po_scans` WHERE `po_id` = ?", [$poId]);
        }

        return $out;
    }
}
