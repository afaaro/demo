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
     *     cases_open: int,
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
            'cases_open'         => $casesOpen,
            'channels_connected' => $channelsConnected,
        ];
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
     *     cases_open: int,
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
            'cases_open'         => 0,
            'channels_connected' => 0,
        ];
    }
}
