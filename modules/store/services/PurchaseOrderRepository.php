<?php
declare(strict_types=1);

namespace Module\Store\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class PurchaseOrderRepository
{
    use TenantScoped;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_RECEIVED  = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(private Database $db) {}

    public static function statusLabel(string $status): string
    {
        return match (strtolower($status)) {
            self::STATUS_DRAFT     => 'Draft',
            self::STATUS_CONFIRMED => 'Sent To Supplier',
            self::STATUS_RECEIVED  => 'Booked In',
            self::STATUS_CANCELLED => 'Cancelled',
            default                => ucfirst($status),
        };
    }

    /**
     * @param array{
     *   id?: string, supplier?: string, warehouse?: string, status?: string,
     *   raised_from?: string, raised_to?: string,
     *   due_from?: string, due_to?: string, lines_q?: string
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $lineScope = 'l.`po_id` = po.`id` AND l.`tenant_id` = po.`tenant_id`';
        $sql = "SELECT po.*,
                       (SELECT COUNT(*) FROM `#__store_purchase_order_lines` l WHERE {$lineScope}) AS line_count,
                       (SELECT COALESCE(SUM(l.`qty_ordered`), 0)
                          FROM `#__store_purchase_order_lines` l
                         WHERE {$lineScope}) AS units_ordered,
                       (SELECT COALESCE(SUM(l.`qty_received`), 0)
                          FROM `#__store_purchase_order_lines` l
                         WHERE {$lineScope}) AS units_received,
                       (SELECT COALESCE(SUM(GREATEST(l.`qty_ordered` - l.`qty_received` - l.`qty_written_off`, 0)), 0)
                          FROM `#__store_purchase_order_lines` l
                         WHERE {$lineScope}) AS open_qty,
                       (SELECT COALESCE(SUM(l.`qty_ordered` * l.`unit_cost`), 0)
                          FROM `#__store_purchase_order_lines` l
                         WHERE {$lineScope}) AS total_value
                  FROM `#__store_purchase_orders` po";
        $clauses = [$this->tenantClause('po')];
        $params = [$this->tenantId()];

        $id = trim((string) ($filters['id'] ?? ''));
        if ($id !== '') {
            $clauses[] = 'CAST(po.`id` AS CHAR) LIKE ?';
            $params[] = '%' . $id . '%';
        }
        $supplier = trim((string) ($filters['supplier'] ?? ''));
        if ($supplier !== '') {
            $clauses[] = 'po.`supplier_name` = ?';
            $params[] = $supplier;
        }
        $warehouse = trim((string) ($filters['warehouse'] ?? ''));
        if ($warehouse !== '') {
            $clauses[] = 'po.`warehouse` = ?';
            $params[] = $warehouse;
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $clauses[] = 'po.`status` = ?';
            $params[] = $status;
        }
        $raisedFrom = trim((string) ($filters['raised_from'] ?? ''));
        if ($raisedFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raisedFrom)) {
            $clauses[] = 'DATE(po.`created_at`) >= ?';
            $params[] = $raisedFrom;
        }
        $raisedTo = trim((string) ($filters['raised_to'] ?? ''));
        if ($raisedTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raisedTo)) {
            $clauses[] = 'DATE(po.`created_at`) <= ?';
            $params[] = $raisedTo;
        }
        $dueFrom = trim((string) ($filters['due_from'] ?? ''));
        if ($dueFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueFrom)) {
            $clauses[] = 'po.`expected_date` >= ?';
            $params[] = $dueFrom;
        }
        $dueTo = trim((string) ($filters['due_to'] ?? ''));
        if ($dueTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueTo)) {
            $clauses[] = 'po.`expected_date` <= ?';
            $params[] = $dueTo;
        }
        $linesQ = trim((string) ($filters['lines_q'] ?? ''));
        if ($linesQ !== '' && ctype_digit($linesQ)) {
            $clauses[] = "(SELECT COUNT(*) FROM `#__store_purchase_order_lines` l WHERE {$lineScope}) = ?";
            $params[] = (int) $linesQ;
        }

        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }
        $sql .= ' ORDER BY po.`id` DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        return $this->db->query($sql, $params)->rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(?string $status = null, int $limit = 100, int $offset = 0): array
    {
        return $this->search(
            $status !== null && $status !== '' ? ['status' => $status] : [],
            $limit,
            $offset
        );
    }

    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $this->ensureHeaderColumns();
        $row = $this->db->query(
            "SELECT * FROM `#__store_purchase_orders` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
            [$id, $this->tenantId()]
        )->row;
        return $row === [] ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(int $poId): array
    {
        if ($poId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        return $this->db->query(
            "SELECT l.*,
                    v.`sku` AS variant_sku,
                    v.`name` AS variant_name,
                    v.`product_id`,
                    p.`name` AS product_name,
                    p.`barcode` AS product_ean,
                    p.`supplier_sku` AS product_supplier_sku
               FROM `#__store_purchase_order_lines` l
               LEFT JOIN `#__store_product_variants` v
                 ON v.`id` = l.`variant_id` AND v.`tenant_id` = l.`tenant_id`
               LEFT JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = l.`tenant_id`
              WHERE l.`po_id` = ? AND l.`tenant_id` = ?
              ORDER BY l.`id` ASC",
            [$poId, $tid]
        )->rows;
    }

    /**
     * @return array{
     *   subtotal: float, shipping: float, vat: float, total: float, vat_rate: float
     * }
     */
    public function moneyTotals(array $po, array $lines): array
    {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) ($line['qty_ordered'] ?? 0) * (float) ($line['unit_cost'] ?? 0);
        }
        $shipping = (float) ($po['shipping_cost'] ?? 0);
        if (array_key_exists('include_delivery_cost', $po) && !(int) $po['include_delivery_cost']) {
            $shipping = 0.0;
        }
        $vatRate = (float) ($po['vat_rate'] ?? 20);
        $vat = round(($subtotal + $shipping) * ($vatRate / 100), 2);
        $total = round($subtotal + $shipping + $vat, 2);
        return [
            'subtotal' => round($subtotal, 2),
            'shipping' => round($shipping, 2),
            'vat'      => $vat,
            'total'    => $total,
            'vat_rate' => $vatRate,
        ];
    }

    public static function lineOutstanding(array $line): int
    {
        return max(
            0,
            (int) ($line['qty_ordered'] ?? 0)
            - (int) ($line['qty_received'] ?? 0)
            - (int) ($line['qty_written_off'] ?? 0)
        );
    }

    public static function lineStatus(array $line): string
    {
        $open = self::lineOutstanding($line);
        if ($open <= 0) {
            return 'Complete';
        }
        if ((int) ($line['qty_received'] ?? 0) > 0) {
            return 'Part Received';
        }
        return 'Awaiting Product';
    }

    /**
     * @param array<string, mixed> $extra Extra header / address snapshot fields
     */
    public function create(
        string $supplierName,
        string $reference = '',
        string $notes = '',
        ?string $expectedDate = null,
        string $warehouse = 'Default',
        string $raisedBy = '',
        float $shippingCost = 0.0,
        float $vatRate = 20.0,
        array $extra = [],
    ): int {
        $this->ensureHeaderColumns();
        $supplierName = mb_substr(trim($supplierName), 0, 128);
        $warehouse = mb_substr(trim($warehouse), 0, 128) ?: 'Default';
        $reference = mb_substr(trim($reference), 0, 64);

        $row = $this->withTenant([
            'supplier_name' => $supplierName,
            'warehouse'     => $warehouse,
            'reference'     => $reference,
            'status'        => self::STATUS_DRAFT,
            'notes'         => trim($notes),
            'expected_date' => $this->normalizeDate($expectedDate),
            'shipping_cost' => max(0, $shippingCost),
            'vat_rate'      => max(0, $vatRate),
            'raised_by'     => mb_substr(trim($raisedBy), 0, 128),
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $row = array_merge($row, $this->normalizeExtra($extra));

        $id = (int) $this->db->insert('store_purchase_orders', $row);
        if ($reference === '' && $id > 0) {
            $auto = (string) (800000000 + $id);
            $this->db->update(
                'store_purchase_orders',
                ['reference' => mb_substr($auto, 0, 64)],
                $this->withTenant(['id' => $id])
            );
        }
        return $id;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function updateHeader(
        int $id,
        string $supplierName,
        string $reference,
        string $notes,
        ?string $expectedDate = null,
        string $warehouse = 'Default',
        ?float $shippingCost = null,
        ?float $vatRate = null,
        array $extra = [],
    ): void {
        $this->ensureHeaderColumns();
        $po = $this->requireEditable($id);
        $supplierName = mb_substr(trim($supplierName), 0, 128);
        $warehouse = mb_substr(trim($warehouse), 0, 128) ?: 'Default';

        $data = [
            'supplier_name' => $supplierName,
            'warehouse'     => $warehouse,
            'reference'     => mb_substr(trim($reference), 0, 64),
            'notes'         => trim($notes),
            'expected_date' => $this->normalizeDate($expectedDate),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        if ($shippingCost !== null) {
            $data['shipping_cost'] = max(0, round($shippingCost, 4));
        }
        if ($vatRate !== null) {
            $data['vat_rate'] = max(0, min(100, round($vatRate, 2)));
        }
        $data = array_merge($data, $this->normalizeExtra($extra));

        $this->db->update('store_purchase_orders', $data, $this->withTenant(['id' => (int) $po['id']]));
    }

    /** Allow expected date changes on draft or confirmed POs. */
    public function setExpectedDate(int $id, ?string $expectedDate): void
    {
        $po = $this->find($id);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        $status = (string) $po['status'];
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_CONFIRMED], true)) {
            throw new RuntimeException('Expected date can only be set on draft or confirmed POs.');
        }
        $this->db->update('store_purchase_orders', [
            'expected_date' => $this->normalizeDate($expectedDate),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], $this->withTenant(['id' => $id]));
    }

    /** Shipping / VAT editable on draft or confirmed (sent) POs. */
    public function updateShippingVat(int $id, float $shippingCost, float $vatRate): void
    {
        $po = $this->find($id);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        $status = (string) $po['status'];
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_CONFIRMED], true)) {
            throw new RuntimeException('Shipping and VAT can only be edited on draft or confirmed POs.');
        }
        $this->db->update('store_purchase_orders', [
            'shipping_cost' => max(0, round($shippingCost, 4)),
            'vat_rate'      => max(0, min(100, round($vatRate, 2))),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], $this->withTenant(['id' => $id]));
    }

    public function addLine(int $poId, int $variantId, int $qty, float $unitCost = 0.0, string $sku = ''): void
    {
        $this->requireEditable($poId);
        if ($variantId <= 0) {
            throw new InvalidArgumentException('Variant is required.');
        }
        if ($qty <= 0) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        $tid = $this->tenantId();
        $existing = $this->db->query(
            "SELECT `id`, `qty_ordered` FROM `#__store_purchase_order_lines`
              WHERE `po_id` = ? AND `variant_id` = ? AND `tenant_id` = ? LIMIT 1",
            [$poId, $variantId, $tid]
        )->row;

        if ($existing !== []) {
            $this->db->query(
                "UPDATE `#__store_purchase_order_lines`
                    SET `qty_ordered` = `qty_ordered` + ?,
                        `unit_cost` = ?,
                        `sku` = CASE WHEN ? <> '' THEN ? ELSE `sku` END
                  WHERE `id` = ?",
                [$qty, $unitCost, $sku, mb_substr($sku, 0, 64), (int) $existing['id']]
            );
            return;
        }

        $this->db->insert('store_purchase_order_lines', $this->withTenant([
            'po_id'        => $poId,
            'variant_id'   => $variantId,
            'sku'          => mb_substr($sku, 0, 64),
            'qty_ordered'  => $qty,
            'qty_received' => 0,
            'unit_cost'    => $unitCost,
        ]));
    }

    public function updateLineQty(int $lineId, int $qtyOrdered): void
    {
        $line = $this->findLine($lineId);
        if ($line === null) {
            throw new RuntimeException('Line not found.');
        }
        $this->requireEditable((int) $line['po_id']);
        if ($qtyOrdered <= 0) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }
        if ($qtyOrdered < (int) $line['qty_received']) {
            throw new InvalidArgumentException('Ordered qty cannot be less than received.');
        }
        $this->db->update('store_purchase_order_lines', [
            'qty_ordered' => $qtyOrdered,
        ], $this->withTenant(['id' => $lineId]));
    }

    public function deleteLine(int $lineId): void
    {
        $line = $this->findLine($lineId);
        if ($line === null) {
            throw new RuntimeException('Line not found.');
        }
        $this->requireEditable((int) $line['po_id']);
        if ((int) $line['qty_received'] > 0) {
            throw new RuntimeException('Cannot remove a line that has received stock.');
        }
        $this->db->query(
            "DELETE FROM `#__store_purchase_order_lines` WHERE `id` = ?",
            [$lineId]
        );
    }

    public function confirm(int $id): void
    {
        $po = $this->find($id);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        if ((string) $po['status'] !== self::STATUS_DRAFT) {
            throw new RuntimeException('Only draft purchase orders can be confirmed.');
        }
        if (trim((string) ($po['supplier_name'] ?? '')) === '') {
            throw new RuntimeException('Select a supplier before confirming.');
        }
        $lines = $this->lines($id);
        if ($lines === []) {
            throw new RuntimeException('Add at least one line before confirming.');
        }

        $payload = [
            'status'       => self::STATUS_CONFIRMED,
            'confirmed_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        if (trim((string) ($po['date_sent'] ?? '')) === '') {
            $this->ensureHeaderColumns();
            $payload['date_sent'] = date('Y-m-d');
        }
        $this->db->update('store_purchase_orders', $payload, $this->withTenant(['id' => $id]));
    }

    public function cancel(int $id): void
    {
        $po = $this->find($id);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        $status = (string) $po['status'];
        if ($status === self::STATUS_RECEIVED) {
            throw new RuntimeException('Received purchase orders cannot be cancelled.');
        }
        if ($status === self::STATUS_CANCELLED) {
            return;
        }

        $received = (int) $this->db->query(
            "SELECT COALESCE(SUM(`qty_received`), 0) FROM `#__store_purchase_order_lines`
              WHERE `po_id` = ? AND `tenant_id` = ?",
            [$id, $this->tenantId()]
        )->value;
        if ($received > 0) {
            throw new RuntimeException('Cannot cancel a PO with received stock.');
        }

        $this->db->update('store_purchase_orders', [
            'status'     => self::STATUS_CANCELLED,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $this->withTenant(['id' => $id]));
    }

    /**
     * Receive qty against a line; returns delta actually received.
     */
    public function receiveLine(int $lineId, int $qty): int
    {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Receive quantity must be at least 1.');
        }

        $line = $this->findLine($lineId);
        if ($line === null) {
            throw new RuntimeException('Line not found.');
        }

        $po = $this->find((int) $line['po_id']);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        if ((string) $po['status'] !== self::STATUS_CONFIRMED) {
            throw new RuntimeException('Only confirmed purchase orders can be received.');
        }

        $open = self::lineOutstanding($line);
        $take = min($qty, $open);
        if ($take <= 0) {
            throw new RuntimeException('Nothing left to receive on this line.');
        }

        $this->db->query(
            "UPDATE `#__store_purchase_order_lines`
                SET `qty_received` = `qty_received` + ?
              WHERE `id` = ?",
            [$take, $lineId]
        );

        $this->refreshReceivedStatus((int) $po['id']);

        return $take;
    }

    /**
     * Book in selected line quantities.
     *
     * @param array<int, int> $qtyByLineId line_id => qty
     * @param array<int, int> $writeOffByLineId line_id => absolute qty written off
     * @return list<array{line_id:int, variant_id:int, qty:int}>
     */
    public function bookInLines(int $poId, array $qtyByLineId, array $writeOffByLineId = []): array
    {
        $po = $this->find($poId);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        if ((string) $po['status'] !== self::STATUS_CONFIRMED) {
            throw new RuntimeException('Only deliveries sent to supplier can be booked in.');
        }

        $wroteOff = false;
        foreach ($writeOffByLineId as $lineId => $qty) {
            $lineId = (int) $lineId;
            if ($lineId <= 0) {
                continue;
            }
            if ($this->setLineWrittenOff($lineId, $poId, (int) $qty)) {
                $wroteOff = true;
            }
        }

        $out = [];
        foreach ($qtyByLineId as $lineId => $qty) {
            $lineId = (int) $lineId;
            $qty = (int) $qty;
            if ($lineId <= 0 || $qty <= 0) {
                continue;
            }
            $line = $this->findLine($lineId);
            if ($line === null || (int) $line['po_id'] !== $poId) {
                continue;
            }
            $taken = $this->receiveLine($lineId, $qty);
            $out[] = [
                'line_id'    => $lineId,
                'variant_id' => (int) $line['variant_id'],
                'qty'        => $taken,
            ];
        }

        if ($out === [] && !$wroteOff) {
            throw new RuntimeException('Nothing selected to book in or write off.');
        }

        if ($out === [] && $wroteOff) {
            $this->refreshReceivedStatus($poId);
        }

        return $out;
    }

    /**
     * Set absolute written-off qty for a line. Returns true if the value changed.
     */
    public function setLineWrittenOff(int $lineId, int $poId, int $qty): bool
    {
        $line = $this->findLine($lineId);
        if ($line === null || (int) $line['po_id'] !== $poId) {
            return false;
        }

        $ordered = (int) ($line['qty_ordered'] ?? 0);
        $received = (int) ($line['qty_received'] ?? 0);
        $max = max(0, $ordered - $received);
        $qty = max(0, min($qty, $max));
        $current = (int) ($line['qty_written_off'] ?? 0);
        if ($qty === $current) {
            return false;
        }

        $this->db->query(
            "UPDATE `#__store_purchase_order_lines`
                SET `qty_written_off` = ?
              WHERE `id` = ?",
            [$qty, $lineId]
        );

        return true;
    }

    /**
     * Receive all remaining open qty on a confirmed PO.
     *
     * @return list<array{line_id:int, variant_id:int, qty:int}>
     */
    public function receiveAllOpen(int $poId): array
    {
        $qtyByLine = [];
        foreach ($this->lines($poId) as $line) {
            $open = self::lineOutstanding($line);
            if ($open > 0) {
                $qtyByLine[(int) $line['id']] = $open;
            }
        }
        return $this->bookInLines($poId, $qtyByLine);
    }

    /**
     * Move outstanding qty onto a new confirmed PO (split).
     */
    public function splitByRemaining(int $poId): int
    {
        $po = $this->find($poId);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        if ((string) $po['status'] !== self::STATUS_CONFIRMED) {
            throw new RuntimeException('Only sent-to-supplier deliveries can be split.');
        }

        $openLines = [];
        foreach ($this->lines($poId) as $line) {
            $open = self::lineOutstanding($line);
            if ($open > 0) {
                $openLines[] = [$line, $open];
            }
        }
        if ($openLines === []) {
            throw new RuntimeException('No outstanding items to split.');
        }

        $newId = $this->create(
            (string) $po['supplier_name'],
            'Split from #' . $poId,
            'Split by remaining items from PO #' . $poId,
            isset($po['expected_date']) ? (string) $po['expected_date'] : null,
            (string) ($po['warehouse'] ?? 'Default'),
            (string) ($po['raised_by'] ?? ''),
            (float) ($po['shipping_cost'] ?? 0),
            (float) ($po['vat_rate'] ?? 20),
        );

        foreach ($openLines as [$line, $open]) {
            $this->addLine(
                $newId,
                (int) $line['variant_id'],
                $open,
                (float) $line['unit_cost'],
                (string) ($line['sku'] ?? '')
            );
            // Write off remaining on original so it can close.
            $this->db->query(
                "UPDATE `#__store_purchase_order_lines`
                    SET `qty_written_off` = `qty_written_off` + ?
                  WHERE `id` = ?",
                [$open, (int) $line['id']]
            );
        }

        $this->refreshReceivedStatus($poId);
        $this->confirm($newId);

        return $newId;
    }

    /**
     * Open PO qty (confirmed, not fully received) keyed by variant_id.
     *
     * @param list<int> $variantIds
     * @return array<int, int>
     */
    public function openQtyByVariant(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter(
            array_map('intval', $variantIds),
            static fn(int $id) => $id > 0
        )));
        if ($variantIds === [] || !TenantContext::get()->has()) {
            return [];
        }

        $tid = $this->tenantId();
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $rows = $this->db->query(
            "SELECT l.`variant_id`,
                    COALESCE(SUM(GREATEST(l.`qty_ordered` - l.`qty_received` - l.`qty_written_off`, 0)), 0) AS open_qty
               FROM `#__store_purchase_order_lines` l
               INNER JOIN `#__store_purchase_orders` po
                 ON po.`id` = l.`po_id` AND po.`tenant_id` = l.`tenant_id`
              WHERE l.`variant_id` IN ({$placeholders})
                AND po.`status` = ?
                AND po.`tenant_id` = ?
                AND (l.`qty_ordered` - l.`qty_received` - l.`qty_written_off`) > 0
              GROUP BY l.`variant_id`",
            [...$variantIds, self::STATUS_CONFIRMED, $tid]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['variant_id']] = (int) $row['open_qty'];
        }
        return $out;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, int>
     */
    public function openQtyByProduct(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id) => $id > 0
        )));
        if ($productIds === [] || !TenantContext::get()->has()) {
            return [];
        }

        $tid = $this->tenantId();
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = $this->db->query(
            "SELECT v.`product_id`,
                    COALESCE(SUM(GREATEST(l.`qty_ordered` - l.`qty_received` - l.`qty_written_off`, 0)), 0) AS open_qty
               FROM `#__store_purchase_order_lines` l
               INNER JOIN `#__store_purchase_orders` po
                 ON po.`id` = l.`po_id` AND po.`tenant_id` = l.`tenant_id`
               INNER JOIN `#__store_product_variants` v
                 ON v.`id` = l.`variant_id` AND v.`tenant_id` = l.`tenant_id`
              WHERE v.`product_id` IN ({$placeholders})
                AND po.`status` = ?
                AND po.`tenant_id` = ?
                AND (l.`qty_ordered` - l.`qty_received` - l.`qty_written_off`) > 0
              GROUP BY v.`product_id`",
            [...$productIds, self::STATUS_CONFIRMED, $tid]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['product_id']] = (int) $row['open_qty'];
        }
        return $out;
    }

    public function delete(int $id): void
    {
        $po = $this->find($id);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        if ((string) $po['status'] !== self::STATUS_DRAFT
            && (string) $po['status'] !== self::STATUS_CANCELLED
        ) {
            throw new RuntimeException('Only draft or cancelled purchase orders can be deleted.');
        }
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('Tenant context is required.');
        }
        $this->db->query(
            "DELETE FROM `#__store_purchase_orders` WHERE `id` = ? AND `tenant_id` = ?",
            [$id, $this->tenantId()]
        );
    }

    private function findLine(int $lineId): ?array
    {
        if ($lineId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_purchase_order_lines` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
            [$lineId, $this->tenantId()]
        )->row;
        return $row === [] ? null : $row;
    }

    /** @return array<string, mixed> */
    private function requireEditable(int $id): array
    {
        $po = $this->find($id);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }
        if ((string) $po['status'] !== self::STATUS_DRAFT) {
            throw new RuntimeException('Only draft purchase orders can be edited.');
        }
        return $po;
    }

    private function refreshReceivedStatus(int $poId): void
    {
        if (!TenantContext::get()->has()) {
            return;
        }
        $open = (int) $this->db->query(
            "SELECT COALESCE(SUM(GREATEST(`qty_ordered` - `qty_received` - `qty_written_off`, 0)), 0)
               FROM `#__store_purchase_order_lines`
              WHERE `po_id` = ? AND `tenant_id` = ?",
            [$poId, $this->tenantId()]
        )->value;

        if ($open <= 0) {
            $this->db->update('store_purchase_orders', [
                'status'      => self::STATUS_RECEIVED,
                'received_at' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ], $this->withTenant(['id' => $poId]));
        } else {
            $this->db->update('store_purchase_orders', [
                'updated_at' => date('Y-m-d H:i:s'),
            ], $this->withTenant(['id' => $poId]));
        }
    }

    private function normalizeDate(?string $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Expected date must be YYYY-MM-DD.');
        }
        return $date;
    }

    /**
     * Split a free-text address into Store-style fields.
     *
     * @return array{
     *   address1:string,address2:string,address3:string,
     *   city:string,county:string,country:string,postcode:string
     * }
     */
    public static function parseAddress(string $address): array
    {
        $raw = trim($address);
        $out = [
            'address1' => '',
            'address2' => '',
            'address3' => '',
            'city'     => '',
            'county'   => '',
            'country'  => 'UNITED KINGDOM',
            'postcode' => '',
        ];
        if ($raw === '') {
            return $out;
        }

        $parts = preg_split('/\r\n|\n|\r|,/', $raw) ?: [];
        $lines = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $lines[] = $part;
            }
        }

        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}$/i', $line)) {
                $out['postcode'] = strtoupper(preg_replace('/\s+/', ' ', $line) ?? $line);
                continue;
            }
            $upper = strtoupper($line);
            if (in_array($upper, ['UNITED KINGDOM', 'UK', 'GREAT BRITAIN', 'GB', 'ENGLAND'], true)) {
                $out['country'] = 'UNITED KINGDOM';
                continue;
            }
            $kept[] = $line;
        }

        $n = count($kept);
        if ($n <= 2) {
            $out['address1'] = $kept[0] ?? '';
            $out['address2'] = $kept[1] ?? '';
        } elseif ($n === 3) {
            $out['address1'] = $kept[0];
            $out['address2'] = $kept[1];
            $out['city']     = $kept[2];
        } elseif ($n === 4) {
            $out['address1'] = $kept[0];
            $out['address2'] = $kept[1];
            $out['city']     = $kept[2];
            $out['county']   = $kept[3];
        } else {
            $out['address1'] = $kept[0];
            $out['address2'] = $kept[1];
            $out['address3'] = $kept[2];
            $out['city']     = $kept[3];
            $out['county']   = $kept[4];
        }

        return $out;
    }

    /**
     * Variants available to add onto a PO.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogVariants(string $search = '', string $supplier = '', int $limit = 80): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $clauses = ['p.`tenant_id` = ?', 'v.`tenant_id` = ?', 'COALESCE(p.`active`, 1) = 1', 'COALESCE(v.`active`, 1) = 1'];
        $params = [$tid, $tid];

        $search = trim($search);
        if ($search !== '') {
            $clauses[] = '(v.`sku` LIKE ? OR p.`sku` LIKE ? OR p.`name` LIKE ? OR v.`name` LIKE ?'
                . ' OR p.`supplier_sku` LIKE ? OR p.`barcode` LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $supplier = trim($supplier);
        if ($supplier !== '') {
            $clauses[] = 'p.`supplier_name` = ?';
            $params[] = $supplier;
        }

        $limit = max(1, min(200, $limit));
        $sql = "SELECT v.`id` AS variant_id,
                       v.`sku`,
                       v.`name` AS variant_name,
                       v.`cost`,
                       p.`id` AS product_id,
                       p.`name` AS product_name,
                       p.`sku` AS product_sku,
                       p.`supplier_name`,
                       p.`supplier_sku`,
                       p.`barcode`,
                       COALESCE(i.`quantity` - i.`reserved`, 0) AS stock
                  FROM `#__store_product_variants` v
                  INNER JOIN `#__store_products` p
                    ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
                  LEFT JOIN `#__store_inventory` i
                    ON i.`variant_id` = v.`id` AND i.`tenant_id` = v.`tenant_id`
                 WHERE " . implode(' AND ', $clauses) . "
                 ORDER BY p.`name` ASC, v.`sku` ASC
                 LIMIT {$limit}";

        return $this->db->query($sql, $params)->rows;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function normalizeExtra(array $extra): array
    {
        $out = [];
        foreach (self::extraStringFields() as $field => $max) {
            if (!array_key_exists($field, $extra)) {
                continue;
            }
            $out[$field] = mb_substr(trim((string) $extra[$field]), 0, $max);
        }
        foreach (['date_sent', 'paid_on'] as $field) {
            if (!array_key_exists($field, $extra)) {
                continue;
            }
            $out[$field] = $this->normalizeDate((string) $extra[$field]);
        }
        if (array_key_exists('include_delivery_cost', $extra)) {
            $out['include_delivery_cost'] = !empty($extra['include_delivery_cost']) ? 1 : 0;
        }
        if (array_key_exists('retained_delivery', $extra)) {
            $out['retained_delivery'] = !empty($extra['retained_delivery']) ? 1 : 0;
        }
        if (array_key_exists('estimated_pallet_count', $extra)) {
            $out['estimated_pallet_count'] = max(0, (int) $extra['estimated_pallet_count']);
        }
        if (array_key_exists('sup_min_order_value', $extra)) {
            $out['sup_min_order_value'] = max(0, round((float) $extra['sup_min_order_value'], 2));
        }
        return $out;
    }

    /** @return array<string, int> */
    private static function extraStringFields(): array
    {
        return [
            'payment_reference'    => 128,
            'payment_confirmed_by' => 128,
            'created_via'          => 64,
            'po_change_to_status'  => 64,
            'supplier_code'        => 64,
            'supplier_reference'   => 64,
            'del_company'          => 128,
            'del_address1'         => 255,
            'del_address2'         => 255,
            'del_address3'         => 255,
            'del_city'             => 128,
            'del_county'           => 128,
            'del_country'          => 128,
            'del_postcode'         => 32,
            'sup_company'          => 128,
            'sup_address1'         => 255,
            'sup_address2'         => 255,
            'sup_city'             => 128,
            'sup_county'           => 128,
            'sup_country'          => 128,
            'sup_postcode'         => 32,
            'sup_phone'            => 64,
            'sup_email'            => 255,
            'sup_lead_time'        => 32,
        ];
    }

    private function ensureHeaderColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $table = $this->db->getPrefix() . 'store_purchase_orders';
        $exists = (int) $this->db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = 'created_via'",
            [$table]
        )->value;
        if ($exists > 0) {
            return;
        }

        $columns = [
            'date_sent'              => 'date NULL DEFAULT NULL',
            'paid_on'                => 'date NULL DEFAULT NULL',
            'payment_reference'      => "varchar(128) NOT NULL DEFAULT ''",
            'payment_confirmed_by'   => "varchar(128) NOT NULL DEFAULT ''",
            'created_via'            => "varchar(64) NOT NULL DEFAULT 'Manual'",
            'include_delivery_cost'  => 'tinyint(1) NOT NULL DEFAULT 0',
            'retained_delivery'      => 'tinyint(1) NOT NULL DEFAULT 0',
            'estimated_pallet_count' => 'int UNSIGNED NOT NULL DEFAULT 0',
            'po_change_to_status'    => "varchar(64) NOT NULL DEFAULT ''",
            'supplier_code'          => "varchar(64) NOT NULL DEFAULT ''",
            'supplier_reference'     => "varchar(64) NOT NULL DEFAULT ''",
            'del_company'            => "varchar(128) NOT NULL DEFAULT ''",
            'del_address1'           => "varchar(255) NOT NULL DEFAULT ''",
            'del_address2'           => "varchar(255) NOT NULL DEFAULT ''",
            'del_address3'           => "varchar(255) NOT NULL DEFAULT ''",
            'del_city'               => "varchar(128) NOT NULL DEFAULT ''",
            'del_county'             => "varchar(128) NOT NULL DEFAULT ''",
            'del_country'            => "varchar(128) NOT NULL DEFAULT 'UNITED KINGDOM'",
            'del_postcode'           => "varchar(32) NOT NULL DEFAULT ''",
            'sup_company'            => "varchar(128) NOT NULL DEFAULT ''",
            'sup_address1'           => "varchar(255) NOT NULL DEFAULT ''",
            'sup_address2'           => "varchar(255) NOT NULL DEFAULT ''",
            'sup_city'               => "varchar(128) NOT NULL DEFAULT ''",
            'sup_county'             => "varchar(128) NOT NULL DEFAULT ''",
            'sup_country'            => "varchar(128) NOT NULL DEFAULT 'UNITED KINGDOM'",
            'sup_postcode'           => "varchar(32) NOT NULL DEFAULT ''",
            'sup_phone'              => "varchar(64) NOT NULL DEFAULT ''",
            'sup_email'              => "varchar(255) NOT NULL DEFAULT ''",
            'sup_lead_time'          => "varchar(32) NOT NULL DEFAULT ''",
            'sup_min_order_value'    => 'decimal(12,2) NOT NULL DEFAULT 0.00',
        ];
        foreach ($columns as $name => $ddl) {
            $has = (int) $this->db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?",
                [$table, $name]
            )->value;
            if ($has === 0) {
                $this->db->query("ALTER TABLE `#__store_purchase_orders` ADD COLUMN `{$name}` {$ddl}");
            }
        }
    }
}
