<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

final class DashboardService
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return array{
     *     products_total: int,
     *     products_active: int,
     *     variants_total: int,
     *     categories_total: int,
     *     stock_units: int,
     *     stock_value: float,
     *     low_stock_count: int,
     *     out_of_stock_count: int,
     *     orders_open: int,
     *     orders_today: int,
     *     orders_on_hold: int,
     *     orders_ready: int,
     *     pickwaves_open: int,
     *     messages_unread: int,
     *     messages_overdue: int,
     *     cases_open: int,
     *     cases_overdue: int,
     *     ship_by_overdue: int,
     *     channels_connected: int
     * }
     */
    public function stats(): array
    {
        if (!TenantContext::get()->has()) {
            return $this->emptyStats();
        }

        $tid = $this->tenantId();

        $productsTotal  = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_products` WHERE `tenant_id` = ?",
            [$tid]
        )->value;

        $productsActive = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_products` WHERE `active` = 1 AND `tenant_id` = ?",
            [$tid]
        )->value;

        $variantsTotal = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_product_variants` WHERE `tenant_id` = ?",
            [$tid]
        )->value;

        $categoriesTotal = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_categories` WHERE `tenant_id` = ?",
            [$tid]
        )->value;

        $row = $this->db->query(
            "SELECT COALESCE(SUM(i.`quantity`), 0) AS units,
                    COALESCE(SUM(i.`quantity` * v.`cost`), 0) AS value
               FROM `#__store_inventory` i
               JOIN `#__store_product_variants` v
                 ON v.`id` = i.`variant_id` AND v.`tenant_id` = i.`tenant_id`
               JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
              WHERE p.`active` = 1 AND v.`active` = 1 AND p.`tenant_id` = ?",
            [$tid]
        )->row;

        $stockUnits = (int) ($row['units'] ?? 0);
        $stockValue = (float) ($row['value'] ?? 0);

        $lowStockCount = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_inventory` i
               JOIN `#__store_product_variants` v
                 ON v.`id` = i.`variant_id` AND v.`tenant_id` = i.`tenant_id`
               JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
              WHERE (i.`quantity` - i.`reserved`) > 0
                AND (i.`quantity` - i.`reserved`) <= i.`reorder_at`
                AND p.`active` = 1 AND v.`active` = 1 AND p.`tenant_id` = ?",
            [$tid]
        )->value;

        $outOfStockCount = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_inventory` i
               JOIN `#__store_product_variants` v
                 ON v.`id` = i.`variant_id` AND v.`tenant_id` = i.`tenant_id`
               JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
              WHERE (i.`quantity` - i.`reserved`) <= 0
                AND p.`active` = 1 AND v.`active` = 1 AND p.`tenant_id` = ?",
            [$tid]
        )->value;

        $ordersOpen = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_orders`
              WHERE `tenant_id` = ?
                AND UPPER(`status`) NOT IN ('FULFILLED', 'CANCELLED', 'CANCELED')",
            [$tid]
        )->value;

        $ordersToday = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_orders`
              WHERE DATE(`created_at`) = CURDATE() AND `tenant_id` = ?",
            [$tid]
        )->value;

        $ordersOnHold = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_orders`
              WHERE `tenant_id` = ?
                AND COALESCE(`on_hold`, 0) = 1
                AND UPPER(`status`) NOT IN ('FULFILLED', 'CANCELLED', 'CANCELED')",
            [$tid]
        )->value;

        $ordersReady = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_orders`
              WHERE `tenant_id` = ?
                AND UPPER(`status`) = 'NOT_STARTED'
                AND COALESCE(`on_hold`, 0) = 0
                AND COALESCE(`is_paid`, 1) = 1",
            [$tid]
        )->value;

        $pickwavesOpen = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_pickwaves`
              WHERE `tenant_id` = ?
                AND LOWER(`status`) IN ('open', 'picking', 'in_progress')",
            [$tid]
        )->value;

        $messagesUnread = (int) $this->db->query(
            "SELECT (
                SELECT COUNT(*) FROM `#__store_messages`
                 WHERE COALESCE(`is_read`, 0) = 0 AND `tenant_id` = ?
             ) + (
                SELECT COUNT(*) FROM `#__store_conversations`
                 WHERE COALESCE(`unread_count`, 0) > 0 AND `tenant_id` = ?
             )",
            [$tid, $tid]
        )->value;

        $messagesOverdue = $this->overdueConversationCount();

        $casesOpen = 0;
        try {
            $casesOpen = (int) $this->db->query(
                "SELECT COUNT(*) FROM `#__store_support_cases`
                  WHERE `tenant_id` = ?
                    AND UPPER(`status`) NOT IN (
                    'CLOSED', 'CLOSED_WITH_ESCALATION', 'CS_CLOSED',
                    'CANCELLED', 'CANCELED', 'RESOLVED'
                  )",
                [$tid]
            )->value;
        } catch (\Throwable) {
            $casesOpen = 0;
        }

        $channelsConnected = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_channels` WHERE `status` = 'connected' AND `tenant_id` = ?",
            [$tid]
        )->value;

        $casesOverdue = $this->overdueCaseCount();
        $shipByOverdue = $this->overdueShipByCount();

        return [
            'products_total'     => $productsTotal,
            'products_active'    => $productsActive,
            'variants_total'     => $variantsTotal,
            'categories_total'   => $categoriesTotal,
            'stock_units'        => $stockUnits,
            'stock_value'        => $stockValue,
            'low_stock_count'    => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'orders_open'        => $ordersOpen,
            'orders_today'       => $ordersToday,
            'orders_on_hold'     => $ordersOnHold,
            'orders_ready'       => $ordersReady,
            'pickwaves_open'     => $pickwavesOpen,
            'messages_unread'    => $messagesUnread,
            'messages_overdue'   => $messagesOverdue,
            'cases_open'         => $casesOpen,
            'cases_overdue'      => $casesOverdue,
            'ship_by_overdue'    => $shipByOverdue,
            'channels_connected' => $channelsConnected,
        ];
    }

    public function overdueShipByCount(): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        try {
            return (int) $this->db->query(
                "SELECT COUNT(*) FROM `#__store_orders` o
                  WHERE o.`tenant_id` = ?
                    AND UPPER(o.`status`) NOT IN ('FULFILLED', 'CANCELLED', 'CANCELED')
                    AND JSON_UNQUOTE(JSON_EXTRACT(o.`shipping_address`, '\$._ship_by')) IS NOT NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(o.`shipping_address`, '\$._ship_by')) <> ''
                    AND JSON_UNQUOTE(JSON_EXTRACT(o.`shipping_address`, '\$._ship_by')) < CURDATE()",
                [$this->tenantId()]
            )->value;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function overdueCaseCount(): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        try {
            return (int) $this->db->query(
                "SELECT COUNT(*) FROM `#__store_support_cases`
                  WHERE `tenant_id` = ?
                    AND `seller_response_due` IS NOT NULL
                    AND `seller_response_due` < NOW()
                    AND UPPER(`status`) NOT IN (
                        'CLOSED','CS_CLOSED','OTHER','CLOSED_WITH_ESCALATION',
                        'APPROVED','COMPLETED','REFUNDED','DENIED','CANCELLED','CANCELED',
                        'POSTED','REIMBURSED','RECEIVED','RESOLVED'
                    )",
                [$this->tenantId()]
            )->value;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function overdueConversationCount(): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        try {
            return (int) $this->db->query(
                "SELECT COUNT(*) FROM `#__store_conversations`
                  WHERE `tenant_id` = ?
                    AND `due_at` IS NOT NULL
                    AND `due_at` < NOW()
                    AND UPPER(`status`) NOT IN ('ARCHIVE','ARCHIVED','DELETE','DELETED','CLOSED')",
                [$this->tenantId()]
            )->value;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Compact supplier OTIF summary for dashboard card.
     *
     * @return array{suppliers:int, otif_pct:float, late:int}
     */
    public function supplierOtifSummary(int $days = 180): array
    {
        $rows = (new PurchaseOrderRepository($this->db))->supplierOtif($days);
        if ($rows === []) {
            return ['suppliers' => 0, 'otif_pct' => 0.0, 'late' => 0];
        }
        $onTime = 0;
        $late = 0;
        foreach ($rows as $row) {
            $onTime += (int) ($row['on_time'] ?? 0);
            $late += (int) ($row['late'] ?? 0);
        }
        $scored = $onTime + $late;
        return [
            'suppliers' => count($rows),
            'otif_pct'  => $scored > 0 ? round(($onTime / $scored) * 100, 1) : 0.0,
            'late'      => $late,
        ];
    }

    /**
     * Contribution margin by channel: revenue − cost − channel fees − shipping cost.
     *
     * @return list<array{
     *   channel_id:int, channel_label:string, revenue:float, cost:float,
     *   fees:float, shipping:float, margin:float, orders:int
     * }>
     */
    public function marginByChannel(int $days = 30): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $days = max(1, min(365, $days));
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT o.`channel_id`,
                    COALESCE(NULLIF(c.`label`, ''), c.`name`, 'Channel') AS channel_label,
                    COUNT(DISTINCT o.`id`) AS orders,
                    COALESCE(SUM(i.`qty` * i.`price`), 0) AS revenue,
                    COALESCE(SUM(
                      i.`qty` * COALESCE(
                        NULLIF(v.`cost`, 0),
                        NULLIF(p.`cost`, 0),
                        0
                      )
                    ), 0) AS cost,
                    COALESCE((
                      SELECT SUM(o2.`channel_fee`)
                        FROM `#__store_orders` o2
                       WHERE o2.`tenant_id` = o.`tenant_id`
                         AND o2.`channel_id` <=> o.`channel_id`
                         AND UPPER(o2.`status`) IN ('FULFILLED', 'SHIPPED', 'COMPLETED', 'DELIVERED')
                         AND o2.`created_at` >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    ), 0) AS fees,
                    COALESCE((
                      SELECT SUM(o3.`shipping_cost`)
                        FROM `#__store_orders` o3
                       WHERE o3.`tenant_id` = o.`tenant_id`
                         AND o3.`channel_id` <=> o.`channel_id`
                         AND UPPER(o3.`status`) IN ('FULFILLED', 'SHIPPED', 'COMPLETED', 'DELIVERED')
                         AND o3.`created_at` >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    ), 0)
                    + COALESCE((
                      SELECT SUM(s.`carrier_cost`)
                        FROM `#__store_shipments` s
                        INNER JOIN `#__store_orders` o4
                          ON o4.`id` = s.`order_id` AND o4.`tenant_id` = s.`tenant_id`
                       WHERE s.`tenant_id` = o.`tenant_id`
                         AND o4.`channel_id` <=> o.`channel_id`
                         AND UPPER(o4.`status`) IN ('FULFILLED', 'SHIPPED', 'COMPLETED', 'DELIVERED')
                         AND o4.`created_at` >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    ), 0) AS shipping
               FROM `#__store_orders` o
               JOIN `#__store_order_items` i
                 ON i.`order_id` = o.`id` AND i.`tenant_id` = o.`tenant_id`
               LEFT JOIN `#__store_product_variants` v
                 ON v.`id` = i.`variant_id` AND v.`tenant_id` = o.`tenant_id`
               LEFT JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = o.`tenant_id`
               LEFT JOIN `#__store_channels` c
                 ON c.`id` = o.`channel_id` AND c.`tenant_id` = o.`tenant_id`
              WHERE o.`tenant_id` = ?
                AND UPPER(o.`status`) IN ('FULFILLED', 'SHIPPED', 'COMPLETED', 'DELIVERED')
                AND o.`created_at` >= DATE_SUB(NOW(), INTERVAL ? DAY)
              GROUP BY o.`channel_id`, channel_label
              ORDER BY revenue DESC",
            [$days, $days, $days, $tid, $days]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $revenue = round((float) ($row['revenue'] ?? 0), 2);
            $cost = round((float) ($row['cost'] ?? 0), 2);
            $fees = round((float) ($row['fees'] ?? 0), 2);
            $shipping = round((float) ($row['shipping'] ?? 0), 2);
            $out[] = [
                'channel_id'    => (int) ($row['channel_id'] ?? 0),
                'channel_label' => (string) ($row['channel_label'] ?? ''),
                'revenue'       => $revenue,
                'cost'          => $cost,
                'fees'          => $fees,
                'shipping'      => $shipping,
                'margin'        => round($revenue - $cost - $fees - $shipping, 2),
                'orders'        => (int) ($row['orders'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * SKU × channel contribution margin (fees shared by order revenue weight).
     *
     * @return list<array{
     *   sku:string, channel_id:int, channel_label:string, qty:int,
     *   revenue:float, cost:float, fees:float, margin:float
     * }>
     */
    public function skuMarginByChannel(int $days = 30, int $limit = 100): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $days = max(1, min(365, $days));
        $limit = max(1, min(500, $limit));
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT COALESCE(NULLIF(i.`external_sku`, ''), v.`sku`, CONCAT('vid:', i.`variant_id`)) AS sku,
                    o.`channel_id`,
                    COALESCE(NULLIF(c.`label`, ''), c.`name`, 'Channel') AS channel_label,
                    SUM(i.`qty`) AS qty,
                    COALESCE(SUM(i.`qty` * i.`price`), 0) AS revenue,
                    COALESCE(SUM(i.`qty` * COALESCE(NULLIF(v.`cost`, 0), NULLIF(p.`cost`, 0), 0)), 0) AS cost,
                    COALESCE(SUM(
                      CASE WHEN o.`total` > 0
                        THEN (i.`qty` * i.`price` / o.`total`) * COALESCE(o.`channel_fee`, 0)
                        ELSE 0 END
                    ), 0) AS fees,
                    COALESCE(SUM(
                      CASE WHEN o.`total` > 0
                        THEN (i.`qty` * i.`price` / o.`total`) * COALESCE((
                          SELECT SUM(s.`carrier_cost`)
                            FROM `#__store_shipments` s
                           WHERE s.`order_id` = o.`id` AND s.`tenant_id` = o.`tenant_id`
                        ), 0)
                        ELSE 0 END
                    ), 0) AS carrier
               FROM `#__store_order_items` i
               JOIN `#__store_orders` o
                 ON o.`id` = i.`order_id` AND o.`tenant_id` = i.`tenant_id`
               LEFT JOIN `#__store_product_variants` v
                 ON v.`id` = i.`variant_id` AND v.`tenant_id` = i.`tenant_id`
               LEFT JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = i.`tenant_id`
               LEFT JOIN `#__store_channels` c
                 ON c.`id` = o.`channel_id` AND c.`tenant_id` = o.`tenant_id`
              WHERE i.`tenant_id` = ?
                AND UPPER(o.`status`) IN ('FULFILLED', 'SHIPPED', 'COMPLETED', 'DELIVERED')
                AND o.`created_at` >= DATE_SUB(NOW(), INTERVAL ? DAY)
              GROUP BY sku, o.`channel_id`, channel_label
              ORDER BY revenue DESC
              LIMIT ?",
            [$tid, $days, $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $revenue = round((float) ($row['revenue'] ?? 0), 2);
            $cost = round((float) ($row['cost'] ?? 0), 2);
            $fees = round((float) ($row['fees'] ?? 0) + (float) ($row['carrier'] ?? 0), 2);
            $out[] = [
                'sku'           => (string) ($row['sku'] ?? ''),
                'channel_id'    => (int) ($row['channel_id'] ?? 0),
                'channel_label' => (string) ($row['channel_label'] ?? ''),
                'qty'           => (int) ($row['qty'] ?? 0),
                'revenue'       => $revenue,
                'cost'          => $cost,
                'fees'          => $fees,
                'margin'        => round($revenue - $cost - $fees, 2),
            ];
        }
        return $out;
    }

    /**
     * Active variants with qty>0 and no sales in the last N days.
     *
     * @return list<array{
     *   variant_id:int, product_id:int, sku:string, product_name:string,
     *   quantity:int, reserved:int, cost:float, days_since_sale:?int
     * }>
     */
    public function deadStock(int $days = 90, int $limit = 100): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $days = max(1, min(730, $days));
        $limit = max(1, min(500, $limit));
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT v.`id` AS variant_id, p.`id` AS product_id, v.`sku`, p.`name` AS product_name,
                    COALESCE(inv.`quantity`, 0) AS quantity,
                    COALESCE(inv.`reserved`, 0) AS reserved,
                    COALESCE(v.`cost`, 0) AS cost,
                    (
                      SELECT MAX(o.`created_at`)
                        FROM `#__store_order_items` oi
                        JOIN `#__store_orders` o ON o.`id` = oi.`order_id`
                       WHERE oi.`variant_id` = v.`id`
                         AND o.`tenant_id` = v.`tenant_id`
                         AND UPPER(o.`status`) NOT IN ('CANCELLED', 'CANCELED')
                    ) AS last_sale_at
               FROM `#__store_product_variants` v
               JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
               JOIN `#__store_inventory` inv
                 ON inv.`variant_id` = v.`id` AND inv.`tenant_id` = v.`tenant_id`
              WHERE v.`tenant_id` = ?
                AND v.`active` = 1 AND p.`active` = 1
                AND COALESCE(inv.`quantity`, 0) > 0
                AND (
                  NOT EXISTS (
                    SELECT 1 FROM `#__store_order_items` oi2
                      JOIN `#__store_orders` o2 ON o2.`id` = oi2.`order_id`
                     WHERE oi2.`variant_id` = v.`id`
                       AND o2.`tenant_id` = v.`tenant_id`
                       AND UPPER(o2.`status`) NOT IN ('CANCELLED', 'CANCELED')
                       AND o2.`created_at` >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  )
                )
              ORDER BY inv.`quantity` DESC, v.`sku` ASC
              LIMIT ?",
            [$tid, $days, $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $last = (string) ($row['last_sale_at'] ?? '');
            $daysSince = null;
            if ($last !== '') {
                $ts = strtotime($last);
                if ($ts !== false) {
                    $daysSince = (int) floor((time() - $ts) / 86400);
                }
            }
            $out[] = [
                'variant_id'      => (int) ($row['variant_id'] ?? 0),
                'product_id'      => (int) ($row['product_id'] ?? 0),
                'sku'             => (string) ($row['sku'] ?? ''),
                'product_name'    => (string) ($row['product_name'] ?? ''),
                'quantity'        => (int) ($row['quantity'] ?? 0),
                'reserved'        => (int) ($row['reserved'] ?? 0),
                'cost'            => (float) ($row['cost'] ?? 0),
                'days_since_sale' => $daysSince,
            ];
        }
        return $out;
    }

    /**
     * @return list<array{
     *   id:int, external_order_id:string, status:string, buyer:string,
     *   total:float, currency:string, created_at:string, channel_label:string,
     *   on_hold:bool
     * }>
     */
    public function recentOrders(int $limit = 10): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT o.`id`, o.`external_order_id`, o.`status`, o.`buyer_username`,
                    o.`shipping_name`, o.`total`, o.`currency`, o.`created_at`,
                    COALESCE(o.`on_hold`, 0) AS on_hold,
                    c.`label` AS channel_label, c.`name` AS channel_name
               FROM `#__store_orders` o
               LEFT JOIN `#__store_channels` c
                 ON c.`id` = o.`channel_id` AND c.`tenant_id` = o.`tenant_id`
              WHERE o.`tenant_id` = ?
              ORDER BY o.`created_at` DESC, o.`id` DESC
              LIMIT ?",
            [$tid, $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $buyer = trim((string) ($row['buyer_username'] ?? ''));
            if ($buyer === '') {
                $buyer = trim((string) ($row['shipping_name'] ?? ''));
            }
            $out[] = [
                'id'                => (int) $row['id'],
                'external_order_id' => (string) ($row['external_order_id'] ?? ''),
                'status'            => (string) ($row['status'] ?? ''),
                'buyer'             => $buyer,
                'total'             => (float) ($row['total'] ?? 0),
                'currency'          => (string) (($row['currency'] ?? '') !== '' ? $row['currency'] : 'GBP'),
                'created_at'        => (string) ($row['created_at'] ?? ''),
                'channel_label'     => (string) ($row['channel_label'] ?? $row['channel_name'] ?? ''),
                'on_hold'           => (int) ($row['on_hold'] ?? 0) === 1,
            ];
        }
        return $out;
    }

    /**
     * @return list<array{
     *   id:int, status:string, picker_name:string, order_count:int, created_at:string
     * }>
     */
    public function openPickwaves(int $limit = 8): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $limit = max(1, min(30, $limit));
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT w.`id`, w.`status`, w.`picker_name`, w.`created_at`,
                    COUNT(po.`order_id`) AS order_count
               FROM `#__store_pickwaves` w
               LEFT JOIN `#__store_pickwave_orders` po
                 ON po.`pickwave_id` = w.`id` AND po.`tenant_id` = w.`tenant_id`
              WHERE w.`tenant_id` = ?
                AND LOWER(w.`status`) IN ('open', 'picking', 'in_progress')
              GROUP BY w.`id`, w.`status`, w.`picker_name`, w.`created_at`
              ORDER BY w.`created_at` DESC, w.`id` DESC
              LIMIT ?",
            [$tid, $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'          => (int) $row['id'],
                'status'      => (string) ($row['status'] ?? ''),
                'picker_name' => (string) ($row['picker_name'] ?? ''),
                'order_count' => (int) ($row['order_count'] ?? 0),
                'created_at'  => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * The N products most recently updated.
     *
     * @return array<int, array{product: Product, updated_at: string}>
     */
    public function recentProducts(int $limit = 8): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__store_products`
              WHERE `tenant_id` = ?
              ORDER BY `updated_at` DESC, `id` DESC
              LIMIT ?",
            [$this->tenantId(), $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $p = new Product();
            $p->id          = (int)    $row['id'];
            $p->sku         = (string) $row['sku'];
            $p->name        = (string) $row['name'];
            $p->image       = (string) ($row['image'] ?? '');
            $p->price       = (float)  ($row['price'] ?? 0);
            $p->active      = (bool)   ($row['active'] ?? true);
            $p->updatedAt   = $row['updated_at'] ?? null;

            $out[] = [
                'product'    => $p,
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Recent stock movements with variant and product context.
     *
     * @return array<int, array{
     *     delta: int, reason: string, note: string,
     *     user_id: ?int, created_at: string,
     *     variant_sku: string, product_name: string, product_id: int
     * }>
     */
    public function recentMovements(int $limit = 10): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT m.`delta`, m.`reason`, m.`note`, m.`user_id`, m.`created_at`,
                    v.`sku` AS variant_sku, v.`id` AS variant_id,
                    p.`name` AS product_name, p.`id` AS product_id
               FROM `#__store_stock_movements` m
               JOIN `#__store_product_variants` v
                 ON v.`id` = m.`variant_id` AND v.`tenant_id` = m.`tenant_id`
               JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
              WHERE m.`tenant_id` = ?
              ORDER BY m.`created_at` DESC, m.`id` DESC
              LIMIT ?",
            [$tid, $limit]
        )->rows;

        return array_map(fn($r) => [
            'delta'        => (int)    $r['delta'],
            'reason'       => (string) $r['reason'],
            'note'         => (string) $r['note'],
            'user_id'      => isset($r['user_id']) ? (int) $r['user_id'] : null,
            'created_at'   => (string) $r['created_at'],
            'variant_sku'  => (string) $r['variant_sku'],
            'variant_id'   => (int)    $r['variant_id'],
            'product_name' => (string) $r['product_name'],
            'product_id'   => (int)    $r['product_id'],
        ], $rows);
    }

    /**
     * Top N low-stock variants to draw attention to.
     *
     * @return array<int, array{
     *     product: Product, variant: Variant,
     *     available: int, reorder_at: int
     * }>
     */
    public function lowStockTop(int $limit = 8): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT p.`id` AS product_id, p.`sku` AS product_sku, p.`name` AS product_name,
                    p.`image` AS product_image,
                    v.`id` AS variant_id, v.`sku` AS variant_sku, v.`name` AS variant_name,
                    COALESCE(i.`quantity`, 0) - COALESCE(i.`reserved`, 0) AS available,
                    COALESCE(i.`reorder_at`, 0) AS reorder_at
               FROM `#__store_product_variants` v
               JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
               JOIN `#__store_inventory` i
                 ON i.`variant_id` = v.`id` AND i.`tenant_id` = v.`tenant_id`
              WHERE p.`tenant_id` = ?
                AND (i.`quantity` - i.`reserved`) <= i.`reorder_at`
                AND p.`active` = 1 AND v.`active` = 1
              ORDER BY (i.`quantity` - i.`reserved`) ASC, p.`name` ASC
              LIMIT ?",
            [$tid, $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $p = new Product();
            $p->id    = (int)    $row['product_id'];
            $p->sku   = (string) $row['product_sku'];
            $p->name  = (string) $row['product_name'];
            $p->image = (string) ($row['product_image'] ?? '');

            $v = new Variant();
            $v->id   = (int)    $row['variant_id'];
            $v->sku  = (string) $row['variant_sku'];
            $v->name = (string) $row['variant_name'];

            $out[] = [
                'product'    => $p,
                'variant'    => $v,
                'available'  => (int) $row['available'],
                'reorder_at' => (int) $row['reorder_at'],
            ];
        }
        return $out;
    }

    /**
     * Pick/pack performance from scans + completed pickwaves.
     *
     * @return array{
     *   summary: array{
     *     units_scanned: int, pick_units: int, pack_units: int,
     *     waves_completed: int, avg_despatch_hours: float|null,
     *     scans_per_hour: float|null
     *   },
     *   by_user: list<array{
     *     user_id: int, pick_units: int, pack_units: int, total_units: int,
     *     units_per_hour: float|null, first_scan: ?string, last_scan: ?string
     *   }>
     * }
     */
    public function pickPackPerformance(int $days = 30): array
    {
        $empty = [
            'summary' => [
                'units_scanned' => 0,
                'pick_units' => 0,
                'pack_units' => 0,
                'waves_completed' => 0,
                'avg_despatch_hours' => null,
                'scans_per_hour' => null,
            ],
            'by_user' => [],
        ];
        if (!TenantContext::get()->has()) {
            return $empty;
        }
        $days = max(1, min(365, $days));
        $tid = $this->tenantId();

        $totals = $this->db->query(
            "SELECT COALESCE(SUM(s.`qty`), 0) AS units,
                    COALESCE(SUM(CASE WHEN COALESCE(NULLIF(s.`stage`, ''), 'pick') = 'pick' THEN s.`qty` ELSE 0 END), 0) AS pick_units,
                    COALESCE(SUM(CASE WHEN COALESCE(NULLIF(s.`stage`, ''), 'pick') = 'pack' THEN s.`qty` ELSE 0 END), 0) AS pack_units,
                    MIN(s.`created_at`) AS first_scan,
                    MAX(s.`created_at`) AS last_scan
               FROM `#__store_pickwave_scans` s
              WHERE s.`tenant_id` = ?
                AND s.`created_at` >= (NOW() - INTERVAL {$days} DAY)",
            [$tid]
        )->row;

        $wavesCompleted = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_pickwaves`
              WHERE `tenant_id` = ?
                AND LOWER(`status`) = 'completed'
                AND COALESCE(`completed_at`, `created_at`) >= (NOW() - INTERVAL {$days} DAY)",
            [$tid]
        )->value;

        $avgHours = $this->db->query(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, `created_at`, `completed_at`)) / 60.0 AS hrs
               FROM `#__store_pickwaves`
              WHERE `tenant_id` = ?
                AND LOWER(`status`) = 'completed'
                AND `completed_at` IS NOT NULL
                AND `completed_at` >= (NOW() - INTERVAL {$days} DAY)",
            [$tid]
        )->row;

        $units = (int) ($totals['units'] ?? 0);
        $first = (string) ($totals['first_scan'] ?? '');
        $last = (string) ($totals['last_scan'] ?? '');
        $scansPerHour = null;
        if ($units > 0 && $first !== '' && $last !== '') {
            $hours = max(1 / 60, (strtotime($last) - strtotime($first)) / 3600);
            $scansPerHour = round($units / $hours, 1);
        }

        $byUserRows = $this->db->query(
            "SELECT COALESCE(s.`user_id`, 0) AS user_id,
                    COALESCE(SUM(CASE WHEN COALESCE(NULLIF(s.`stage`, ''), 'pick') = 'pick' THEN s.`qty` ELSE 0 END), 0) AS pick_units,
                    COALESCE(SUM(CASE WHEN COALESCE(NULLIF(s.`stage`, ''), 'pick') = 'pack' THEN s.`qty` ELSE 0 END), 0) AS pack_units,
                    COALESCE(SUM(s.`qty`), 0) AS total_units,
                    MIN(s.`created_at`) AS first_scan,
                    MAX(s.`created_at`) AS last_scan
               FROM `#__store_pickwave_scans` s
              WHERE s.`tenant_id` = ?
                AND s.`created_at` >= (NOW() - INTERVAL {$days} DAY)
              GROUP BY COALESCE(s.`user_id`, 0)
              ORDER BY total_units DESC
              LIMIT 50",
            [$tid]
        )->rows;

        $byUser = [];
        foreach ($byUserRows as $row) {
            $total = (int) ($row['total_units'] ?? 0);
            $uf = (string) ($row['first_scan'] ?? '');
            $ul = (string) ($row['last_scan'] ?? '');
            $uph = null;
            if ($total > 0 && $uf !== '' && $ul !== '') {
                $hours = max(1 / 60, (strtotime($ul) - strtotime($uf)) / 3600);
                $uph = round($total / $hours, 1);
            }
            $byUser[] = [
                'user_id'        => (int) ($row['user_id'] ?? 0),
                'pick_units'     => (int) ($row['pick_units'] ?? 0),
                'pack_units'     => (int) ($row['pack_units'] ?? 0),
                'total_units'    => $total,
                'units_per_hour' => $uph,
                'first_scan'     => $uf !== '' ? $uf : null,
                'last_scan'      => $ul !== '' ? $ul : null,
            ];
        }

        return [
            'summary' => [
                'units_scanned'      => $units,
                'pick_units'         => (int) ($totals['pick_units'] ?? 0),
                'pack_units'         => (int) ($totals['pack_units'] ?? 0),
                'waves_completed'    => $wavesCompleted,
                'avg_despatch_hours' => isset($avgHours['hrs']) && $avgHours['hrs'] !== null
                    ? round((float) $avgHours['hrs'], 2)
                    : null,
                'scans_per_hour'     => $scansPerHour,
            ],
            'by_user' => $byUser,
        ];
    }

    /**
     * @return array{
     *     products_total: int,
     *     products_active: int,
     *     variants_total: int,
     *     categories_total: int,
     *     stock_units: int,
     *     stock_value: float,
     *     low_stock_count: int,
     *     out_of_stock_count: int,
     *     orders_open: int,
     *     orders_today: int,
     *     orders_on_hold: int,
     *     orders_ready: int,
     *     pickwaves_open: int,
     *     messages_unread: int,
     *     messages_overdue: int,
     *     cases_open: int,
     *     cases_overdue: int,
     *     ship_by_overdue: int,
     *     channels_connected: int
     * }
     */
    private function emptyStats(): array
    {
        return [
            'products_total'     => 0,
            'products_active'    => 0,
            'variants_total'     => 0,
            'categories_total'   => 0,
            'stock_units'        => 0,
            'stock_value'        => 0.0,
            'low_stock_count'    => 0,
            'out_of_stock_count' => 0,
            'orders_open'        => 0,
            'orders_today'       => 0,
            'orders_on_hold'     => 0,
            'orders_ready'       => 0,
            'pickwaves_open'     => 0,
            'messages_unread'    => 0,
            'messages_overdue'   => 0,
            'cases_open'         => 0,
            'cases_overdue'      => 0,
            'ship_by_overdue'    => 0,
            'channels_connected' => 0,
        ];
    }
}
