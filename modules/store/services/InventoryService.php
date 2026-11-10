<?php
declare(strict_types=1);

namespace Module\Store\Services;

use Module\Store\Services\Product;
use System\Library\Database;

final class InventoryService
{
    use TenantScoped;

    /** @var array<int, array{base_id:int, pack_qty:array<int,int>}|null> */
    private array $packFamilyCache = [];

    public function __construct(
        private Database $db,
        private ?ChannelStockPublisher $stockPublisher = null,
    ) {}

    /**
     * @return array{
     *   variant_id:int, quantity:int, reserved:int, available:int,
     *   reorder_at:int, out_of_stock_at:?string, supplier_inventory:?int
     * }|null
     */
    public function get(int $variantId): ?array
    {
        if (!TenantContext::get()->has() || !$this->variantBelongsToTenant($variantId)) {
            return null;
        }

        $row = $this->db->query(
            "SELECT inv.`variant_id`, inv.`quantity`, inv.`reserved`, inv.`reorder_at`,
                    inv.`out_of_stock_at`, inv.`supplier_inventory`
               FROM `#__store_inventory` inv
              WHERE inv.`variant_id` = ?
                AND inv.`tenant_id` = ?
              LIMIT 1",
            [$variantId, $this->tenantId()]
        )->row;

        if ($row === []) {
            return null;
        }

        $supInv = $row['supplier_inventory'] ?? null;

        return [
            'variant_id'          => (int) $row['variant_id'],
            'quantity'            => (int) $row['quantity'],
            'reserved'            => (int) $row['reserved'],
            'available'           => (int) $row['quantity'] - (int) $row['reserved'],
            'reorder_at'          => (int) $row['reorder_at'],
            'out_of_stock_at'     => isset($row['out_of_stock_at']) && $row['out_of_stock_at'] !== null && $row['out_of_stock_at'] !== ''
                ? (string) $row['out_of_stock_at']
                : null,
            'supplier_inventory'  => $supInv === null || $supInv === '' ? null : (int) $supInv,
        ];
    }

    public function adjust(int $variantId, int $delta, string $reason = 'manual', string $note = '', ?int $userId = null): int
    {
        if (!$this->variantBelongsToTenant($variantId)) {
            return (int) ($this->get($variantId)['quantity'] ?? 0);
        }

        $targetId = $variantId;
        $actualDelta = $delta;
        if ($reason !== 'ebay_import') {
            $family = $this->packFamilyFor($variantId);
            if ($family !== null) {
                $routed = PackInventory::toBaseDelta($family, $variantId, $delta);
                $targetId = $routed['variant_id'];
                $actualDelta = $routed['delta'];
                if ($targetId !== $variantId && $note === '') {
                    $note = 'Pack ×' . (int) ($family['pack_qty'][$variantId] ?? 1);
                }
            }
        }

        $tenantId = $this->tenantId();
        $quantity = $this->db->transaction(function (Database $db) use ($targetId, $actualDelta, $reason, $note, $userId, $tenantId) {
            $db->query(
                "INSERT IGNORE INTO `#__store_inventory` (`variant_id`, `tenant_id`, `quantity`, `reserved`, `reorder_at`)
                VALUES (?, ?, 0, 0, 0)",
                [$targetId, $tenantId]
            );

            $db->query(
                "UPDATE `#__store_inventory` SET `quantity` = `quantity` + ?
                 WHERE `variant_id` = ? AND `tenant_id` = ?",
                [$actualDelta, $targetId, $tenantId]
            );

            // Stamp / clear out-of-stock date from available qty.
            $db->query(
                "UPDATE `#__store_inventory`
                    SET `out_of_stock_at` = CASE
                          WHEN (`quantity` - `reserved`) <= 0
                          THEN COALESCE(`out_of_stock_at`, NOW())
                          ELSE NULL
                        END
                  WHERE `variant_id` = ? AND `tenant_id` = ?",
                [$targetId, $tenantId]
            );

            $db->insert('store_stock_movements', $this->withTenant([
                'variant_id' => $targetId,
                'delta'      => $actualDelta,
                'reason'     => $reason,
                'note'       => $note,
                'user_id'    => $userId,
            ]));

            return (int) $db->query(
                "SELECT `quantity` FROM `#__store_inventory` WHERE `variant_id` = ? AND `tenant_id` = ?",
                [$targetId, $tenantId]
            )->value;
        });

        $this->publishSellableFamily($targetId);

        return $quantity;
    }

    /** Set on-hand quantity (absolute), then publish the pack family. */
    public function setQuantity(int $variantId, int $quantity, string $reason = 'manual'): int
    {
        $quantity = max(0, $quantity);
        $current = (int) ($this->get($variantId)['quantity'] ?? 0);
        if ($current === $quantity) {
            return $current;
        }

        return $this->adjust($variantId, $quantity - $current, $reason, 'Set stock');
    }

    /** Channel / UI qty: pack SKUs show floor(1× stock / pack size). */
    public function sellableAvailable(int $variantId): int
    {
        $family = $this->packFamilyFor($variantId);
        if ($family === null) {
            return max(0, (int) ($this->get($variantId)['available'] ?? 0));
        }
        $baseAvail = (int) ($this->get((int) $family['base_id'])['available'] ?? 0);
        return PackInventory::sellable($family, $variantId, $baseAvail);
    }

    /**
     * Batch stock summary for product manager rows.
     *
     * @param list<int> $productIds
     * @return array<int, array{stock:int, reserved:int, out_of_stock_at:?string, supplier_inventory:?int}>
     */
    public function productStockSummaries(array $productIds): array
    {
        if ($productIds === [] || !TenantContext::get()->has()) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $params = array_merge($productIds, [$this->tenantId()]);
        $rows = $this->db->query(
            "SELECT v.`product_id`,
                    COALESCE(SUM(i.`quantity` - i.`reserved`), 0) AS stock,
                    COALESCE(SUM(i.`reserved`), 0) AS reserved,
                    MAX(CASE
                          WHEN (i.`quantity` - i.`reserved`) <= 0 THEN i.`out_of_stock_at`
                          ELSE NULL
                        END) AS out_of_stock_at,
                    SUM(i.`supplier_inventory`) AS supplier_inventory,
                    SUM(CASE WHEN i.`supplier_inventory` IS NOT NULL THEN 1 ELSE 0 END) AS supplier_inventory_set
               FROM `#__store_product_variants` v
               INNER JOIN `#__store_products` p ON p.`id` = v.`product_id`
               LEFT JOIN `#__store_inventory` i ON i.`variant_id` = v.`id` AND i.`tenant_id` = p.`tenant_id`
              WHERE v.`product_id` IN ({$placeholders})
                AND p.`tenant_id` = ?
              GROUP BY v.`product_id`",
            $params
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            $stock = (int) $row['stock'];
            $oos = (string) ($row['out_of_stock_at'] ?? '');
            $supSet = (int) ($row['supplier_inventory_set'] ?? 0);
            $out[$pid] = [
                'stock'              => $stock,
                'reserved'           => (int) $row['reserved'],
                'out_of_stock_at'    => ($stock <= 0 && $oos !== '') ? $oos : null,
                'supplier_inventory' => $supSet > 0 ? (int) $row['supplier_inventory'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $variantIds
     * @return array<int, array{available:int, reserved:int, out_of_stock_at:?string, supplier_inventory:?int}>
     */
    public function variantStockSummaries(array $variantIds): array
    {
        if ($variantIds === [] || !TenantContext::get()->has()) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $params = array_merge($variantIds, [$this->tenantId()]);
        $rows = $this->db->query(
            "SELECT i.`variant_id`, i.`quantity`, i.`reserved`, i.`out_of_stock_at`, i.`supplier_inventory`
               FROM `#__store_inventory` i
               INNER JOIN `#__store_product_variants` v ON v.`id` = i.`variant_id`
               INNER JOIN `#__store_products` p ON p.`id` = v.`product_id`
              WHERE i.`variant_id` IN ({$placeholders})
                AND p.`tenant_id` = ?",
            $params
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $vid = (int) $row['variant_id'];
            $available = (int) $row['quantity'] - (int) $row['reserved'];
            $oos = (string) ($row['out_of_stock_at'] ?? '');
            $supInv = $row['supplier_inventory'] ?? null;
            $out[$vid] = [
                'available'          => $available,
                'reserved'           => (int) $row['reserved'],
                'out_of_stock_at'    => ($available <= 0 && $oos !== '') ? $oos : null,
                'supplier_inventory' => $supInv === null || $supInv === '' ? null : (int) $supInv,
            ];
        }
        return $this->overlayPackSellable($out, $variantIds);
    }

    public function setReorder(int $variantId, int $reorderAt): void
    {
        if (!$this->variantBelongsToTenant($variantId)) {
            return;
        }
        $tenantId = $this->tenantId();
        $this->db->query(
            "INSERT IGNORE INTO `#__store_inventory` (`variant_id`, `tenant_id`, `quantity`, `reserved`, `reorder_at`)
            VALUES (?, ?, 0, 0, ?)",
            [$variantId, $tenantId, $reorderAt]
        );
        $this->db->query(
            "UPDATE `#__store_inventory` SET `reorder_at` = ? WHERE `variant_id` = ? AND `tenant_id` = ?",
            [$reorderAt, $variantId, $tenantId]
        );
    }

    /**
     * Set supplier stock level (null clears / shows as — on the products grid).
     */
    public function setSupplierInventory(int $variantId, ?int $qty): void
    {
        if (!$this->variantBelongsToTenant($variantId)) {
            return;
        }
        $tenantId = $this->tenantId();
        $this->db->query(
            "INSERT IGNORE INTO `#__store_inventory` (`variant_id`, `tenant_id`, `quantity`, `reserved`, `reorder_at`)
            VALUES (?, ?, 0, 0, 0)",
            [$variantId, $tenantId]
        );
        $this->db->query(
            "UPDATE `#__store_inventory` SET `supplier_inventory` = ? WHERE `variant_id` = ? AND `tenant_id` = ?",
            [$qty, $variantId, $tenantId]
        );
    }

    /**
     * Recompute reserved qty from open (unfulfilled) order lines.
     * Uses remaining qty (qty - qty_shipped) when the column exists.
     * Safe to call after order sync / despatch / cancel.
     */
    public function rebuildReservedFromOpenOrders(): void
    {
        if (!TenantContext::get()->has()) {
            return;
        }

        $tenantId = $this->tenantId();
        $needExpr = 'COALESCE(SUM(GREATEST(CAST(i.`qty` AS SIGNED) - CAST(COALESCE(i.`qty_shipped`, 0) AS SIGNED), 0)), 0)';

        // Ensure inventory rows exist for any variant on open orders.
        $this->db->query(
            "INSERT IGNORE INTO `#__store_inventory` (`variant_id`, `tenant_id`, `quantity`, `reserved`, `reorder_at`)
             SELECT DISTINCT i.`variant_id`, ?, 0, 0, 0
               FROM `#__store_order_items` i
               INNER JOIN `#__store_orders` o ON o.`id` = i.`order_id`
              WHERE i.`variant_id` IS NOT NULL
                AND o.`tenant_id` = ?
                AND UPPER(o.`status`) NOT IN ('FULFILLED', 'CANCELLED', 'CANCELED')",
            [$tenantId, $tenantId]
        );

        $this->db->query(
            "UPDATE `#__store_inventory` inv
                LEFT JOIN (
                    SELECT i.`variant_id`, {$needExpr} AS need
                      FROM `#__store_order_items` i
                      INNER JOIN `#__store_orders` o ON o.`id` = i.`order_id`
                     WHERE i.`variant_id` IS NOT NULL
                       AND o.`tenant_id` = ?
                       AND UPPER(o.`status`) NOT IN ('FULFILLED', 'CANCELLED', 'CANCELED')
                     GROUP BY i.`variant_id`
                ) x ON x.`variant_id` = inv.`variant_id`
                   SET inv.`reserved` = COALESCE(x.`need`, 0),
                       inv.`out_of_stock_at` = CASE
                         WHEN (inv.`quantity` - COALESCE(x.`need`, 0)) <= 0
                         THEN COALESCE(inv.`out_of_stock_at`, NOW())
                         ELSE NULL
                       END
                 WHERE inv.`tenant_id` = ?",
            [$tenantId, $tenantId]
        );
    }

    /**
     * Compare inventory.reserved vs sum of open order line qtys.
     *
     * @return list<array{
     *   variant_id:int, sku:string, product_name:string, product_id:int,
     *   reserved:int, expected:int, delta:int, quantity:int
     * }>
     */
    public function reservedIntegrityMismatches(int $limit = 200): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT inv.`variant_id`,
                    COALESCE(inv.`reserved`, 0) AS reserved,
                    COALESCE(inv.`quantity`, 0) AS quantity,
                    COALESCE(x.`need`, 0) AS expected,
                    v.`sku`,
                    p.`name` AS product_name,
                    p.`id` AS product_id
               FROM `#__store_inventory` inv
               JOIN `#__store_product_variants` v
                 ON v.`id` = inv.`variant_id` AND v.`tenant_id` = inv.`tenant_id`
               JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = inv.`tenant_id`
               LEFT JOIN (
                    SELECT i.`variant_id`,
                           COALESCE(SUM(GREATEST(CAST(i.`qty` AS SIGNED) - CAST(COALESCE(i.`qty_shipped`, 0) AS SIGNED), 0)), 0) AS need
                      FROM `#__store_order_items` i
                      INNER JOIN `#__store_orders` o ON o.`id` = i.`order_id`
                     WHERE i.`variant_id` IS NOT NULL
                       AND i.`variant_id` > 0
                       AND o.`tenant_id` = ?
                       AND UPPER(o.`status`) NOT IN ('FULFILLED', 'CANCELLED', 'CANCELED')
                     GROUP BY i.`variant_id`
               ) x ON x.`variant_id` = inv.`variant_id`
              WHERE inv.`tenant_id` = ?
                AND COALESCE(inv.`reserved`, 0) <> COALESCE(x.`need`, 0)
              ORDER BY ABS(COALESCE(inv.`reserved`, 0) - COALESCE(x.`need`, 0)) DESC
              LIMIT ?",
            [$tid, $tid, $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $reserved = (int) ($row['reserved'] ?? 0);
            $expected = (int) ($row['expected'] ?? 0);
            $out[] = [
                'variant_id'   => (int) ($row['variant_id'] ?? 0),
                'sku'          => (string) ($row['sku'] ?? ''),
                'product_name' => (string) ($row['product_name'] ?? ''),
                'product_id'   => (int) ($row['product_id'] ?? 0),
                'reserved'     => $reserved,
                'expected'     => $expected,
                'delta'        => $reserved - $expected,
                'quantity'     => (int) ($row['quantity'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Active variants with empty pick_bin that have stock or open order demand.
     *
     * @return list<array{
     *   variant_id:int, product_id:int, sku:string, product_name:string,
     *   pick_bin:string, quantity:int, reserved:int, open_demand:int
     * }>
     */
    public function missingPickBins(int $limit = 200): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT v.`id` AS variant_id, p.`id` AS product_id, v.`sku`, p.`name` AS product_name,
                    COALESCE(v.`pick_bin`, '') AS pick_bin,
                    COALESCE(inv.`quantity`, 0) AS quantity,
                    COALESCE(inv.`reserved`, 0) AS reserved,
                    COALESCE(d.`open_demand`, 0) AS open_demand
               FROM `#__store_product_variants` v
               JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
               LEFT JOIN `#__store_inventory` inv
                 ON inv.`variant_id` = v.`id` AND inv.`tenant_id` = v.`tenant_id`
               LEFT JOIN (
                    SELECT i.`variant_id`, SUM(i.`qty`) AS open_demand
                      FROM `#__store_order_items` i
                      JOIN `#__store_orders` o ON o.`id` = i.`order_id`
                     WHERE o.`tenant_id` = ?
                       AND i.`variant_id` IS NOT NULL AND i.`variant_id` > 0
                       AND UPPER(o.`status`) NOT IN ('FULFILLED', 'CANCELLED', 'CANCELED')
                     GROUP BY i.`variant_id`
               ) d ON d.`variant_id` = v.`id`
              WHERE v.`tenant_id` = ?
                AND v.`active` = 1 AND p.`active` = 1
                AND TRIM(COALESCE(v.`pick_bin`, '')) = ''
                AND (
                  COALESCE(inv.`quantity`, 0) > 0
                  OR COALESCE(d.`open_demand`, 0) > 0
                )
              ORDER BY COALESCE(d.`open_demand`, 0) DESC, COALESCE(inv.`quantity`, 0) DESC, v.`sku` ASC
              LIMIT ?",
            [$tid, $tid, $limit]
        )->rows;

        return array_map(static fn(array $r): array => [
            'variant_id'   => (int) ($r['variant_id'] ?? 0),
            'product_id'   => (int) ($r['product_id'] ?? 0),
            'sku'          => (string) ($r['sku'] ?? ''),
            'product_name' => (string) ($r['product_name'] ?? ''),
            'pick_bin'     => (string) ($r['pick_bin'] ?? ''),
            'quantity'     => (int) ($r['quantity'] ?? 0),
            'reserved'     => (int) ($r['reserved'] ?? 0),
            'open_demand'  => (int) ($r['open_demand'] ?? 0),
        ], $rows);
    }

    public function setPickBin(int $variantId, string $pickBin): void
    {
        if ($variantId <= 0 || !TenantContext::get()->has()) {
            throw new \InvalidArgumentException('Invalid variant.');
        }
        if (!$this->variantBelongsToTenant($variantId)) {
            throw new \RuntimeException('Variant not found.');
        }
        $this->db->query(
            "UPDATE `#__store_product_variants`
                SET `pick_bin` = ?
              WHERE `id` = ? AND `tenant_id` = ?",
            [mb_substr(trim($pickBin), 0, 64), $variantId, $this->tenantId()]
        );
    }

    /**
     * After an order is fulfilled: deduct remaining unshipped qty from stock, then refresh reserved.
     * Prefer consumeShippedQuantities for partial despatch.
     */
    public function consumeFulfilledOrder(int $orderId): void
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return;
        }

        $order = $this->db->first('store_orders', $this->withTenant(['id' => $orderId]));
        if ($order === []) {
            return;
        }

        $rows = $this->db->query(
            "SELECT `variant_id`,
                    GREATEST(CAST(`qty` AS SIGNED) - CAST(COALESCE(`qty_shipped`, 0) AS SIGNED), 0) AS remain,
                    `qty`, COALESCE(`qty_shipped`, 0) AS qty_shipped
               FROM `#__store_order_items`
              WHERE `order_id` = ?
                AND `variant_id` IS NOT NULL",
            [$orderId]
        )->rows;

        $lines = [];
        foreach ($rows as $row) {
            $vid = (int) ($row['variant_id'] ?? 0);
            // If qty_shipped already covers qty, nothing left; else consume remain
            // (legacy full-fulfil path may have qty_shipped=0 → consume full qty).
            $qtyShipped = (int) ($row['qty_shipped'] ?? 0);
            $qty = max(0, (int) ($row['qty'] ?? 0));
            $consume = $qtyShipped > 0
                ? max(0, (int) ($row['remain'] ?? 0))
                : $qty;
            if ($vid <= 0 || $consume <= 0) {
                continue;
            }
            $lines[] = ['variant_id' => $vid, 'qty' => $consume];
        }

        $this->consumeShippedQuantities(
            $orderId,
            $lines,
            (string) ($order['warehouse'] ?? '')
        );
    }

    /**
     * Deduct specifically shipped quantities (partial or full).
     *
     * @param list<array{variant_id:int, qty:int}> $lines
     */
    public function consumeShippedQuantities(int $orderId, array $lines, string $warehouse = ''): void
    {
        if ($orderId <= 0 || !TenantContext::get()->has() || $lines === []) {
            return;
        }

        $warehouseId = $this->resolveWarehouseId($warehouse);
        foreach ($lines as $line) {
            $vid = (int) ($line['variant_id'] ?? 0);
            $qty = max(0, (int) ($line['qty'] ?? 0));
            if ($vid <= 0 || $qty <= 0) {
                continue;
            }
            $this->adjust($vid, -$qty, 'order_despatch', 'Order #' . $orderId);
            if ($warehouseId > 0) {
                $this->adjustAtWarehouse($vid, $warehouseId, -$qty, 'order_despatch', 'Order #' . $orderId);
            }
        }

        $this->rebuildReservedFromOpenOrders();
    }

    /**
     * Location-level stock for a variant at a warehouse.
     *
     * @return array{variant_id:int, warehouse_id:int, quantity:int, reserved:int, available:int}|null
     */
    public function getAtWarehouse(int $variantId, int $warehouseId): ?array
    {
        if (!TenantContext::get()->has() || $variantId <= 0 || $warehouseId <= 0
            || !$this->variantBelongsToTenant($variantId)
        ) {
            return null;
        }
        $row = $this->db->query(
            "SELECT `variant_id`, `warehouse_id`, `quantity`, `reserved`
               FROM `#__store_inventory_locations`
              WHERE `variant_id` = ? AND `warehouse_id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$variantId, $warehouseId, $this->tenantId()]
        )->row;
        if ($row === [] || $row === null) {
            return [
                'variant_id'   => $variantId,
                'warehouse_id' => $warehouseId,
                'quantity'     => 0,
                'reserved'     => 0,
                'available'    => 0,
            ];
        }
        $qty = (int) $row['quantity'];
        $res = (int) $row['reserved'];
        return [
            'variant_id'   => (int) $row['variant_id'],
            'warehouse_id' => (int) $row['warehouse_id'],
            'quantity'     => $qty,
            'reserved'     => $res,
            'available'    => $qty - $res,
        ];
    }

    /**
     * Adjust location qty and keep aggregate `#__store_inventory` in sync when delta applied only to location.
     * When called from consumeShippedQuantities, aggregate is already adjusted separately.
     */
    public function adjustAtWarehouse(
        int $variantId,
        int $warehouseId,
        int $delta,
        string $reason = 'manual',
        string $note = '',
        ?int $userId = null,
        bool $syncAggregate = false,
    ): int {
        if (!$this->variantBelongsToTenant($variantId) || $warehouseId <= 0) {
            return 0;
        }
        $tenantId = $this->tenantId();
        $quantity = $this->db->transaction(function (Database $db) use (
            $variantId, $warehouseId, $delta, $reason, $note, $userId, $tenantId
        ) {
            $db->query(
                "INSERT IGNORE INTO `#__store_inventory_locations`
                    (`tenant_id`, `variant_id`, `warehouse_id`, `quantity`, `reserved`)
                 VALUES (?, ?, ?, 0, 0)",
                [$tenantId, $variantId, $warehouseId]
            );
            $db->query(
                "UPDATE `#__store_inventory_locations`
                    SET `quantity` = `quantity` + ?
                  WHERE `variant_id` = ? AND `warehouse_id` = ? AND `tenant_id` = ?",
                [$delta, $variantId, $warehouseId, $tenantId]
            );
            $db->insert('store_stock_movements', $this->withTenant([
                'variant_id' => $variantId,
                'delta'      => $delta,
                'reason'     => $reason,
                'note'       => mb_substr(trim($note . ' wh#' . $warehouseId), 0, 255),
                'user_id'    => $userId,
            ]));
            return (int) $db->query(
                "SELECT `quantity` FROM `#__store_inventory_locations`
                  WHERE `variant_id` = ? AND `warehouse_id` = ? AND `tenant_id` = ?",
                [$variantId, $warehouseId, $tenantId]
            )->value;
        });

        if ($syncAggregate) {
            $this->adjust($variantId, $delta, $reason, $note !== '' ? $note : 'Warehouse adjust', $userId);
        }

        return $quantity;
    }

    /**
     * Transfer stock between warehouses (location + aggregate-neutral).
     *
     * @return array{from_qty:int, to_qty:int}
     */
    public function transferBetweenWarehouses(
        int $variantId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $qty,
        ?int $userId = null,
        string $note = '',
    ): array {
        $qty = max(0, $qty);
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Transfer qty must be positive.');
        }
        if ($fromWarehouseId <= 0 || $toWarehouseId <= 0 || $fromWarehouseId === $toWarehouseId) {
            throw new \InvalidArgumentException('Distinct from/to warehouses required.');
        }
        if (!$this->variantBelongsToTenant($variantId)) {
            throw new \RuntimeException('Variant not found.');
        }

        $from = $this->getAtWarehouse($variantId, $fromWarehouseId);
        $available = (int) ($from['available'] ?? 0);
        // Allow transfer even if location row empty but aggregate has stock (bootstrap).
        if ($available < $qty) {
            $agg = (int) ($this->get($variantId)['available'] ?? 0);
            if ($available <= 0 && $agg >= $qty) {
                // Seed location from aggregate for the from warehouse.
                $this->adjustAtWarehouse(
                    $variantId,
                    $fromWarehouseId,
                    $qty,
                    'transfer_seed',
                    'Seed location for transfer',
                    $userId,
                    false
                );
            } elseif ($available < $qty) {
                throw new \RuntimeException('Insufficient warehouse stock to transfer (' . $available . ' available).');
            }
        }

        $fromQty = $this->adjustAtWarehouse(
            $variantId,
            $fromWarehouseId,
            -$qty,
            'transfer_out',
            $note !== '' ? $note : 'Transfer to wh#' . $toWarehouseId,
            $userId,
            false
        );
        $toQty = $this->adjustAtWarehouse(
            $variantId,
            $toWarehouseId,
            $qty,
            'transfer_in',
            $note !== '' ? $note : 'Transfer from wh#' . $fromWarehouseId,
            $userId,
            false
        );

        return ['from_qty' => $fromQty, 'to_qty' => $toQty];
    }

    /**
     * Relocate pick bin and audit the move.
     */
    public function relocateBin(int $variantId, string $fromBin, string $toBin, ?int $userId = null, string $note = ''): void
    {
        if ($variantId <= 0 || !TenantContext::get()->has()) {
            throw new \InvalidArgumentException('Invalid variant.');
        }
        if (!$this->variantBelongsToTenant($variantId)) {
            throw new \RuntimeException('Variant not found.');
        }
        $toBin = mb_substr(trim($toBin), 0, 64);
        if ($toBin === '') {
            throw new \InvalidArgumentException('Destination bin is required.');
        }
        $fromBin = mb_substr(trim($fromBin), 0, 64);
        if ($fromBin === '') {
            $row = $this->db->query(
                "SELECT `pick_bin` FROM `#__store_product_variants`
                  WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
                [$variantId, $this->tenantId()]
            )->row;
            $fromBin = trim((string) ($row['pick_bin'] ?? ''));
        }

        $this->setPickBin($variantId, $toBin);

        $this->db->insert('store_bin_moves', $this->withTenant([
            'variant_id' => $variantId,
            'from_bin'   => $fromBin,
            'to_bin'     => $toBin,
            'user_id'    => $userId,
            'note'       => mb_substr(trim($note), 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ]));

        $this->db->insert('store_stock_movements', $this->withTenant([
            'variant_id' => $variantId,
            'delta'      => 0,
            'reason'     => 'bin_relocate',
            'note'       => mb_substr(($fromBin !== '' ? $fromBin . ' → ' : '→ ') . $toBin
                . ($note !== '' ? ' · ' . $note : ''), 0, 255),
            'user_id'    => $userId,
        ]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function binMoveHistory(int $limit = 50, ?int $variantId = null): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $params = [$this->tenantId()];
        $sql = "SELECT m.*, v.`sku`, p.`name` AS product_name, p.`id` AS product_id
                  FROM `#__store_bin_moves` m
                  INNER JOIN `#__store_product_variants` v
                    ON v.`id` = m.`variant_id` AND v.`tenant_id` = m.`tenant_id`
                  INNER JOIN `#__store_products` p
                    ON p.`id` = v.`product_id` AND p.`tenant_id` = m.`tenant_id`
                 WHERE m.`tenant_id` = ?";
        if ($variantId !== null && $variantId > 0) {
            $sql .= ' AND m.`variant_id` = ?';
            $params[] = $variantId;
        }
        $sql .= ' ORDER BY m.`id` DESC LIMIT ' . $limit;
        return $this->db->query($sql, $params)->rows;
    }

    /**
     * Location qty rows for a variant.
     *
     * @return list<array{warehouse_id:int, warehouse:string, quantity:int, reserved:int, available:int}>
     */
    public function locationsForVariant(int $variantId): array
    {
        if ($variantId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT loc.`warehouse_id`, w.`name` AS warehouse,
                    loc.`quantity`, loc.`reserved`
               FROM `#__store_inventory_locations` loc
               INNER JOIN `#__store_warehouses` w
                 ON w.`id` = loc.`warehouse_id` AND w.`tenant_id` = loc.`tenant_id`
              WHERE loc.`variant_id` = ? AND loc.`tenant_id` = ?
              ORDER BY w.`name` ASC",
            [$variantId, $this->tenantId()]
        )->rows;
        return array_map(static function (array $r): array {
            $qty = (int) ($r['quantity'] ?? 0);
            $res = (int) ($r['reserved'] ?? 0);
            return [
                'warehouse_id' => (int) ($r['warehouse_id'] ?? 0),
                'warehouse'    => (string) ($r['warehouse'] ?? ''),
                'quantity'     => $qty,
                'reserved'     => $res,
                'available'    => $qty - $res,
            ];
        }, $rows);
    }

    private function resolveWarehouseId(string $warehouse): int
    {
        $warehouse = trim($warehouse);
        if ($warehouse === '' || !TenantContext::get()->has()) {
            return 0;
        }
        try {
            $name = (new WarehouseRepository($this->db))->ensure($warehouse);
            $row = $this->db->query(
                "SELECT `id` FROM `#__store_warehouses`
                  WHERE `name` = ? AND `tenant_id` = ? LIMIT 1",
                [$name, $this->tenantId()]
            )->row;
            return (int) ($row['id'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<int, array{
     *   product:Product, variant:Variant, available:int, reorder_at:int,
     *   supplier:string, warehouse:string, on_po:int, suggested_qty:int
     * }>
     */
    public function lowStock(int $limit = 50): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }

        $tenantId = $this->tenantId();
        $rows = $this->db->query(
            "SELECT p.id AS product_id, p.sku AS product_sku, p.name AS product_name,
                    p.price AS product_price, p.active AS product_active,
                    p.supplier_name, p.warehouse,
                    v.id AS variant_id, v.product_id, v.sku AS variant_sku,
                    v.name AS variant_name, v.price AS variant_price, v.cost AS variant_cost,
                    v.active AS variant_active,
                    i.quantity, i.reserved, i.reorder_at,
                    COALESCE((
                        SELECT SUM(GREATEST(l.`qty_ordered` - l.`qty_received` - l.`qty_written_off`, 0))
                          FROM `#__store_purchase_order_lines` l
                          INNER JOIN `#__store_purchase_orders` po ON po.`id` = l.`po_id`
                         WHERE l.`variant_id` = v.`id`
                           AND po.`status` = 'confirmed'
                    ), 0) AS on_po
            FROM `#__store_product_variants` v
            JOIN `#__store_products` p   ON p.id = v.product_id
            JOIN `#__store_inventory` i  ON i.variant_id = v.id
            WHERE p.`tenant_id` = ?
                AND i.quantity - i.reserved <= i.reorder_at
                AND v.active = 1
                AND p.active = 1
            ORDER BY (i.quantity - i.reserved) ASC
            LIMIT ?",
            [$tenantId, $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $p = new Product();
            $p->id    = (int) $row['product_id'];
            $p->sku   = (string) $row['product_sku'];
            $p->name  = (string) $row['product_name'];
            $p->price = (float) $row['product_price'];
            $p->active = (bool) $row['product_active'];
            $p->supplierName = (string) ($row['supplier_name'] ?? '');
            $p->warehouse = (string) (($row['warehouse'] ?? '') !== '' ? $row['warehouse'] : 'Default');

            $v = new Variant();
            $v->id        = (int) $row['variant_id'];
            $v->productId = (int) $row['product_id'];
            $v->sku       = (string) $row['variant_sku'];
            $v->name      = (string) $row['variant_name'];
            $v->price     = (float) $row['variant_price'];
            $v->cost      = (float) ($row['variant_cost'] ?? 0);
            $v->active    = (bool) $row['variant_active'];

            $vid = (int) $row['variant_id'];
            $family = $this->packFamilyFor($vid);
            if ($family !== null && $vid !== (int) $family['base_id']
                && (int) ($family['pack_qty'][$vid] ?? 1) > 1
            ) {
                continue;
            }

            $available = (int) $row['quantity'] - (int) $row['reserved'];
            $reorderAt = (int) $row['reorder_at'];
            $onPo = (int) ($row['on_po'] ?? 0);
            // Cover up to reorder level, accounting for stock already on PO.
            $suggested = max(0, $reorderAt - $available - $onPo);
            if ($suggested <= 0 && $available <= $reorderAt) {
                $suggested = max(1, $reorderAt);
            }

            $out[] = [
                'product'       => $p,
                'variant'       => $v,
                'available'     => $available,
                'reorder_at'    => $reorderAt,
                'supplier'      => $p->supplierName !== '' ? $p->supplierName : 'Unassigned',
                'warehouse'     => $p->warehouse,
                'on_po'         => $onPo,
                'suggested_qty' => $suggested,
            ];
        }
        return $out;
    }

    public function movements(int $variantId, int $limit = 50): array
    {
        if (!$this->variantBelongsToTenant($variantId)) {
            return [];
        }

        $rows = $this->db->query(
            "SELECT `id`, `delta`, `reason`, `note`, `user_id`, `created_at`
            FROM `#__store_stock_movements`
            WHERE `variant_id` = ?
              AND `tenant_id` = ?
            ORDER BY `created_at` DESC
            LIMIT ?",
            [$variantId, $this->tenantId(), $limit]
        )->rows;

        return array_map(fn($r) => [
            'id'         => (int)    $r['id'],
            'delta'      => (int)    $r['delta'],
            'reason'     => (string) $r['reason'],
            'note'       => (string) $r['note'],
            'user_id'    => isset($r['user_id']) ? (int) $r['user_id'] : null,
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * Paginated stock grid for Inventory landing.
     *
     * @param array{q?:string, stock?:string, warehouse?:string, active?:string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginateStock(array $filters, int $limit = 50, int $offset = 0): array
    {
        if (!TenantContext::get()->has()) {
            return ['rows' => [], 'total' => 0];
        }

        $clauses = [$this->tenantClause('p')];
        $params  = [$this->tenantId()];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = '(p.`name` LIKE ? OR p.`sku` LIKE ? OR v.`sku` LIKE ? OR v.`name` LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $warehouse = trim((string) ($filters['warehouse'] ?? ''));
        if ($warehouse !== '') {
            if (strcasecmp($warehouse, 'Default') === 0) {
                $clauses[] = "(p.`warehouse` = '' OR p.`warehouse` = 'Default')";
            } else {
                $clauses[] = 'p.`warehouse` = ?';
                $params[]  = $warehouse;
            }
        }

        $active = trim((string) ($filters['active'] ?? '1'));
        if ($active === '1') {
            $clauses[] = 'p.`active` = 1 AND v.`active` = 1';
        } elseif ($active === '0') {
            $clauses[] = '(p.`active` = 0 OR v.`active` = 0)';
        }

        $availableExpr = '(COALESCE(i.`quantity`, 0) - COALESCE(i.`reserved`, 0))';
        $stock = trim((string) ($filters['stock'] ?? ''));
        if ($stock === 'out') {
            $clauses[] = "{$availableExpr} <= 0";
        } elseif ($stock === 'low') {
            $clauses[] = "{$availableExpr} > 0 AND {$availableExpr} <= COALESCE(i.`reorder_at`, 0)";
        } elseif ($stock === 'in') {
            $clauses[] = "{$availableExpr} > COALESCE(i.`reorder_at`, 0)";
        } elseif ($stock === 'zero_reorder') {
            $clauses[] = 'COALESCE(i.`reorder_at`, 0) = 0';
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);

        $total = (int) $this->db->query(
            "SELECT COUNT(*)
               FROM `#__store_product_variants` v
               INNER JOIN `#__store_products` p ON p.`id` = v.`product_id`
               LEFT JOIN `#__store_inventory` i ON i.`variant_id` = v.`id`
              {$where}",
            $params
        )->value;

        $rows = $this->db->query(
            "SELECT p.`id` AS product_id, p.`name` AS product_name, p.`sku` AS product_sku,
                    p.`warehouse`, p.`supplier_name`, p.`active` AS product_active,
                    v.`id` AS variant_id, v.`sku` AS variant_sku, v.`name` AS variant_name,
                    v.`active` AS variant_active, v.`cost`,
                    COALESCE(i.`quantity`, 0) AS quantity,
                    COALESCE(i.`reserved`, 0) AS reserved,
                    COALESCE(i.`reorder_at`, 0) AS reorder_at,
                    i.`supplier_inventory`, i.`out_of_stock_at`, i.`updated_at`
               FROM `#__store_product_variants` v
               INNER JOIN `#__store_products` p ON p.`id` = v.`product_id`
               LEFT JOIN `#__store_inventory` i ON i.`variant_id` = v.`id`
              {$where}
              ORDER BY {$availableExpr} ASC, p.`name` ASC, v.`sku` ASC
              LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->rows;

        $mapped = [];
        foreach ($rows as $row) {
            $qty = (int) $row['quantity'];
            $reserved = (int) $row['reserved'];
            $available = $qty - $reserved;
            $reorder = (int) $row['reorder_at'];
            $warehouseName = trim((string) ($row['warehouse'] ?? ''));
            if ($warehouseName === '') {
                $warehouseName = 'Default';
            }

            $mapped[] = [
                'product_id'         => (int) $row['product_id'],
                'product_name'       => (string) $row['product_name'],
                'product_sku'        => (string) $row['product_sku'],
                'variant_id'         => (int) $row['variant_id'],
                'variant_sku'        => (string) $row['variant_sku'],
                'variant_name'       => (string) ($row['variant_name'] ?? ''),
                'warehouse'          => $warehouseName,
                'supplier'           => (string) ($row['supplier_name'] ?? ''),
                'quantity'           => $qty,
                'reserved'           => $reserved,
                'available'          => $available,
                'reorder_at'         => $reorder,
                'supplier_inventory' => $row['supplier_inventory'] === null || $row['supplier_inventory'] === ''
                    ? null
                    : (int) $row['supplier_inventory'],
                'out_of_stock_at'    => $row['out_of_stock_at'] ?? null,
                'updated_at'         => $row['updated_at'] ?? null,
                'active'             => (bool) $row['product_active'] && (bool) $row['variant_active'],
                'is_low'             => $available > 0 && $available <= $reorder,
                'is_out'             => $available <= 0,
                'line_value'         => round($available * (float) ($row['cost'] ?? 0), 2),
            ];
        }

        $mapped = $this->overlayPackSellableRows($mapped);
        foreach ($mapped as &$row) {
            $available = (int) $row['available'];
            $reorder = (int) $row['reorder_at'];
            $row['is_low'] = $available > 0 && $available <= $reorder;
            $row['is_out'] = $available <= 0;
            $row['line_value'] = round($available * (float) ($row['cost'] ?? 0), 2);
        }
        unset($row);

        return ['rows' => $mapped, 'total' => $total];
    }

    /** @return list<string> */
    public function warehousesInUse(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }

        $rows = $this->db->query(
            "SELECT DISTINCT CASE
                      WHEN TRIM(COALESCE(`warehouse`, '')) = '' THEN 'Default'
                      ELSE `warehouse`
                    END AS wh
               FROM `#__store_products`
              WHERE `tenant_id` = ?
              ORDER BY wh ASC",
            [$this->tenantId()]
        )->rows;

        return array_values(array_map(
            static fn(array $r): string => (string) $r['wh'],
            $rows
        ));
    }

    private function variantBelongsToTenant(int $variantId): bool
    {
        if ($variantId <= 0 || !TenantContext::get()->has()) {
            return false;
        }

        return (int) $this->db->query(
            "SELECT 1
               FROM `#__store_product_variants` v
               INNER JOIN `#__store_products` p ON p.`id` = v.`product_id`
              WHERE v.`id` = ? AND p.`tenant_id` = ?
              LIMIT 1",
            [$variantId, $this->tenantId()]
        )->value === 1;
    }

    /**
     * @return array{base_id:int, pack_qty:array<int,int>}|null
     */
    private function packFamilyFor(int $variantId): ?array
    {
        if ($variantId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        if (array_key_exists($variantId, $this->packFamilyCache)) {
            return $this->packFamilyCache[$variantId];
        }

        $row = $this->db->query(
            "SELECT `product_id` FROM `#__store_product_variants`
              WHERE `id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$variantId, $this->tenantId()]
        )->row;
        if ($row === []) {
            $this->packFamilyCache[$variantId] = null;
            return null;
        }

        $productId = (int) $row['product_id'];
        $siblings = $this->packSiblingRows([$productId]);
        $rows = $siblings[$productId] ?? [];
        foreach ($rows as $sib) {
            $this->packFamilyCache[(int) $sib['id']] = null;
        }
        foreach (PackInventory::families($rows) as $family) {
            foreach (array_keys($family['pack_qty']) as $id) {
                $this->packFamilyCache[(int) $id] = $family;
            }
        }
        if (!array_key_exists($variantId, $this->packFamilyCache)) {
            $this->packFamilyCache[$variantId] = null;
        }

        return $this->packFamilyCache[$variantId];
    }

    /**
     * @param list<int> $productIds
     * @return array<int, list<array{id:int, sku:string, name:string, available:int}>>
     */
    private function packSiblingRows(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($productIds === [] || !TenantContext::get()->has()) {
            return [];
        }

        PackInventory::ensureColumn($this->db);

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $params = array_merge($productIds, [$this->tenantId()]);
        $rows = $this->db->query(
            "SELECT v.`id`, v.`product_id`, v.`sku`, v.`name`,
                    COALESCE(v.`pack_qty`, 0) AS pack_qty,
                    COALESCE(i.`quantity` - i.`reserved`, 0) AS available,
                    MAX(CASE
                          WHEN LOWER(a.`name`) = 'size' OR LOWER(a.`code`) = 'size'
                          THEN COALESCE(NULLIF(av.`label`, ''), av.`value`)
                          ELSE NULL
                        END) AS size_label,
                    MAX(CASE
                          WHEN LOWER(a.`name`) IN (
                                'pack', 'pack size', 'pack qty', 'pack quantity',
                                'packsize', 'units per pack', 'inner qty', 'inner quantity'
                              )
                            OR LOWER(a.`code`) IN (
                                'pack', 'pack_size', 'pack_qty', 'packsize', 'inner_qty'
                              )
                          THEN COALESCE(NULLIF(av.`label`, ''), av.`value`)
                          ELSE NULL
                        END) AS pack_label,
                    MAX(CASE
                          WHEN LOWER(a.`name`) IN (
                                'metres', 'metre', 'meters', 'meter', 'length', 'lenght'
                              )
                            OR LOWER(a.`code`) IN (
                                'metres', 'metre', 'length', 'lenght'
                              )
                          THEN COALESCE(NULLIF(av.`label`, ''), av.`value`)
                          ELSE NULL
                        END) AS metres_label,
                    GROUP_CONCAT(
                      CONCAT(
                        LOWER(a.`name`),
                        '=',
                        COALESCE(NULLIF(av.`label`, ''), av.`value`)
                      )
                      SEPARATOR '\n'
                    ) AS attr_blob
               FROM `#__store_product_variants` v
               LEFT JOIN `#__store_inventory` i
                 ON i.`variant_id` = v.`id` AND i.`tenant_id` = v.`tenant_id`
               LEFT JOIN `#__store_variant_attribute_values` vav
                 ON vav.`variant_id` = v.`id` AND vav.`tenant_id` = v.`tenant_id`
               LEFT JOIN `#__store_attributes` a
                 ON a.`id` = vav.`attribute_id` AND a.`tenant_id` = v.`tenant_id`
               LEFT JOIN `#__store_attribute_values` av
                 ON av.`id` = vav.`attribute_value_id` AND av.`tenant_id` = v.`tenant_id`
              WHERE v.`product_id` IN ({$placeholders})
                AND v.`tenant_id` = ?
              GROUP BY v.`id`, v.`product_id`, v.`sku`, v.`name`, v.`pack_qty`,
                       i.`quantity`, i.`reserved`",
            $params
        )->rows;

        $byProduct = [];
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            $byProduct[$pid][] = [
                'id'         => (int) $row['id'],
                'sku'        => (string) $row['sku'],
                'name'       => (string) ($row['name'] ?? ''),
                'pack_qty'   => (int) ($row['pack_qty'] ?? 0),
                'available'  => (int) $row['available'],
                'size_label'   => (string) ($row['size_label'] ?? ''),
                'pack_label'   => (string) ($row['pack_label'] ?? ''),
                'metres_label' => (string) ($row['metres_label'] ?? ''),
                'attr_blob'    => (string) ($row['attr_blob'] ?? ''),
            ];
        }

        return $byProduct;
    }

    /**
     * @param array<int, array{available:int, reserved:int, out_of_stock_at:?string, supplier_inventory:?int}> $out
     * @param list<int> $variantIds
     * @return array<int, array{available:int, reserved:int, out_of_stock_at:?string, supplier_inventory:?int}>
     */
    private function overlayPackSellable(array $out, array $variantIds): array
    {
        if ($out === [] || $variantIds === [] || !TenantContext::get()->has()) {
            return $out;
        }

        $wanted = [];
        foreach ($variantIds as $id) {
            $wanted[(int) $id] = true;
        }
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $params = array_merge(array_values($variantIds), [$this->tenantId()]);
        $pidRows = $this->db->query(
            "SELECT `id`, `product_id` FROM `#__store_product_variants`
              WHERE `id` IN ({$placeholders}) AND `tenant_id` = ?",
            $params
        )->rows;
        $productIds = [];
        foreach ($pidRows as $row) {
            $productIds[] = (int) $row['product_id'];
        }

        $siblings = $this->packSiblingRows($productIds);
        $baseAvail = [];
        foreach ($siblings as $rows) {
            foreach (PackInventory::families($rows) as $family) {
                $baseId = (int) $family['base_id'];
                if (!isset($baseAvail[$baseId])) {
                    $stock = $this->get($baseId);
                    $baseAvail[$baseId] = (int) ($stock['available'] ?? 0);
                }
                foreach (array_keys($family['pack_qty']) as $vid) {
                    $vid = (int) $vid;
                    if (!isset($wanted[$vid])) {
                        continue;
                    }
                    $sellable = PackInventory::sellable($family, $vid, $baseAvail[$baseId]);
                    $out[$vid] = array_merge(
                        $out[$vid] ?? [
                            'available' => 0,
                            'reserved' => 0,
                            'out_of_stock_at' => null,
                            'supplier_inventory' => null,
                        ],
                        ['available' => $sellable]
                    );
                    if ($sellable > 0) {
                        $out[$vid]['out_of_stock_at'] = null;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function overlayPackSellableRows(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $byProduct = [];
        foreach ($rows as $i => $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            if ($pid > 0) {
                $byProduct[$pid][] = $i;
            }
        }
        $siblings = $this->packSiblingRows(array_keys($byProduct));
        $byVariantIndex = [];
        foreach ($byProduct as $indexes) {
            foreach ($indexes as $i) {
                $vid = (int) ($rows[$i]['variant_id'] ?? 0);
                if ($vid > 0) {
                    $byVariantIndex[$vid][] = $i;
                }
            }
        }
        foreach ($siblings as $sibRows) {
            foreach (PackInventory::families($sibRows) as $family) {
                $baseAvail = (int) ($this->get((int) $family['base_id'])['available'] ?? 0);
                foreach (array_keys($family['pack_qty']) as $vid) {
                    $vid = (int) $vid;
                    foreach ($byVariantIndex[$vid] ?? [] as $i) {
                        $rows[$i]['available'] = PackInventory::sellable($family, $vid, $baseAvail);
                    }
                }
            }
        }
        return $rows;
    }

    private function publishSellableFamily(int $variantId): void
    {
        if ($this->stockPublisher === null) {
            return;
        }
        $family = $this->packFamilyFor($variantId);
        $ids = [$variantId];
        if ($family !== null) {
            $ids = array_map('intval', array_keys($family['pack_qty']));
        }
        foreach (array_unique($ids) as $id) {
            if ($id <= 0) {
                continue;
            }
            $this->stockPublisher->publish($id, $this->sellableAvailable($id));
        }
    }
}