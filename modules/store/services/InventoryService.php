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
     * Safe to call after order sync / despatch / cancel.
     */
    public function rebuildReservedFromOpenOrders(): void
    {
        if (!TenantContext::get()->has()) {
            return;
        }

        $tenantId = $this->tenantId();

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
                    SELECT i.`variant_id`, COALESCE(SUM(i.`qty`), 0) AS need
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
     * After an order is fulfilled: deduct sold qty from stock, then refresh reserved.
     */
    public function consumeFulfilledOrder(int $orderId): void
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return;
        }

        if ($this->db->first('store_orders', $this->withTenant(['id' => $orderId])) === []) {
            return;
        }

        $rows = $this->db->query(
            "SELECT `variant_id`, `qty`
               FROM `#__store_order_items`
              WHERE `order_id` = ?
                AND `variant_id` IS NOT NULL",
            [$orderId]
        )->rows;

        foreach ($rows as $row) {
            $vid = (int) ($row['variant_id'] ?? 0);
            $qty = max(0, (int) ($row['qty'] ?? 0));
            if ($vid <= 0 || $qty <= 0) {
                continue;
            }
            $this->adjust($vid, -$qty, 'order_despatch', 'Order #' . $orderId);
        }

        $this->rebuildReservedFromOpenOrders();
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