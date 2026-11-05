<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use System\Library\Database;

final class OrderRepository
{
    use TenantScoped;

    private static bool $orderEditColumnsEnsured = false;

    public function __construct(private Database $db) {}

    public function find(int $id): ?array
    {
        $where = TenantContext::get()->has()
            ? $this->withTenant(['id' => $id])
            : ['id' => $id];
        $order = $this->db->first('sf_orders', $where);
        if ($order === []) {
            return null;
        }

        $items = $this->db->query(
            "SELECT i.`id`, i.`order_id`, i.`variant_id`, i.`external_line_item_id`,
                    i.`external_sku`, i.`kit_sku`, i.`title`,
                    i.`qty` AS order_qty, i.`qty`, i.`price`,
                    COALESCE(v.`id`, vsku.`id`, vl.`id`, vparent.`id`, 0) AS resolved_variant_id,
                    COALESCE(v.`product_id`, vsku.`product_id`, vl.`product_id`, pparent.`id`, 0) AS product_id,
                    COALESCE(v.`sku`, vsku.`sku`, vl.`sku`, vparent.`sku`, '') AS variant_sku,
                    COALESCE(p.`name`, psku.`name`, pl.`name`, pparent.`name`, '') AS product_name,
                    COALESCE(p.`brand`, psku.`brand`, pl.`brand`, pparent.`brand`, '') AS brand,
                    COALESCE(NULLIF(p.`warehouse`, ''), NULLIF(psku.`warehouse`, ''),
                             NULLIF(pl.`warehouse`, ''), NULLIF(pparent.`warehouse`, ''), 'Default') AS warehouse,
                    COALESCE(inv.`quantity`, invsku.`quantity`, invl.`quantity`, invparent.`quantity`, 0) AS stock_qty,
                    COALESCE(inv.`reserved`, invsku.`reserved`, invl.`reserved`, invparent.`reserved`, 0) AS stock_reserved
               FROM `#__sf_order_items` i
               LEFT JOIN `#__sf_product_variants` v
                      ON v.`id` = i.`variant_id`
               LEFT JOIN `#__sf_products` p
                      ON p.`id` = v.`product_id`
               LEFT JOIN `#__sf_inventory` inv
                      ON inv.`variant_id` = v.`id`
               LEFT JOIN `#__sf_product_variants` vsku
                      ON (i.`variant_id` IS NULL OR i.`variant_id` = 0)
                     AND i.`external_sku` <> ''
                     AND vsku.`sku` = i.`external_sku`
               LEFT JOIN `#__sf_products` psku
                      ON psku.`id` = vsku.`product_id`
               LEFT JOIN `#__sf_inventory` invsku
                      ON invsku.`variant_id` = vsku.`id`
               LEFT JOIN `#__sf_listings` l
                      ON l.`id` = (
                            SELECT l2.`id` FROM `#__sf_listings` l2
                             WHERE (i.`variant_id` IS NULL OR i.`variant_id` = 0)
                               AND i.`external_sku` <> ''
                               AND l2.`external_sku` = i.`external_sku`
                             ORDER BY l2.`id` DESC
                             LIMIT 1
                      )
               LEFT JOIN `#__sf_product_variants` vl
                      ON vl.`id` = l.`variant_id`
               LEFT JOIN `#__sf_products` pl
                      ON pl.`id` = vl.`product_id`
               LEFT JOIN `#__sf_inventory` invl
                      ON invl.`variant_id` = vl.`id`
               LEFT JOIN `#__sf_products` pparent
                      ON (i.`variant_id` IS NULL OR i.`variant_id` = 0)
                     AND i.`external_sku` <> ''
                     AND pparent.`sku` = i.`external_sku`
               LEFT JOIN `#__sf_product_variants` vparent
                      ON vparent.`id` = (
                            SELECT MIN(v2.`id`) FROM `#__sf_product_variants` v2
                             WHERE v2.`product_id` = pparent.`id`
                      )
               LEFT JOIN `#__sf_inventory` invparent
                      ON invparent.`variant_id` = vparent.`id`
              WHERE i.`order_id` = ?
              ORDER BY i.`id`",
            [$id]
        )->rows;

        $pickwaveId = (int) $this->db->query(
            'SELECT `pickwave_id` FROM `#__sf_pickwave_orders` WHERE `order_id` = ? LIMIT 1',
            [$id]
        )->value;
        $order['pickwave_id'] = $pickwaveId;

        $packagingName = '';
        $packagingId = (int) ($order['packaging_size_id'] ?? 0);
        if ($packagingId > 0) {
            $packagingName = (string) $this->db->query(
                'SELECT `name` FROM `#__sf_packaging_sizes` WHERE `id` = ? LIMIT 1',
                [$packagingId]
            )->value;
        }
        $order['packaging_name'] = $packagingName;

        return [
            'order' => $order,
            'items' => $items,
        ];
    }

    /**
     * Update editable warehouse/ops fields on a single order.
     *
     * @param array<string, mixed> $data
     */
    public function updateDetails(int $id, array $data): void
    {
        if ($this->find($id) === null) {
            throw new \RuntimeException('Order not found.');
        }

        $this->ensureOrderEditColumns();

        $payload = [];
        if (array_key_exists('notes', $data)) {
            $payload['notes'] = mb_substr(trim((string) $data['notes']), 0, 500);
        }
        if (array_key_exists('special_instructions', $data)) {
            $payload['special_instructions'] = mb_substr(trim((string) $data['special_instructions']), 0, 500);
        }
        if (array_key_exists('packaging_size_id', $data)) {
            $pid = (int) $data['packaging_size_id'];
            $payload['packaging_size_id'] = $pid > 0 ? $pid : null;
        }
        if (array_key_exists('package_count', $data)) {
            $payload['package_count'] = max(1, (int) $data['package_count']);
        }
        if (array_key_exists('order_weight_kg', $data)) {
            $payload['order_weight_kg'] = max(0, round((float) $data['order_weight_kg'], 3));
        }
        if (array_key_exists('shipping_method', $data)) {
            $payload['shipping_method'] = mb_substr(trim((string) $data['shipping_method']), 0, 128);
        }
        if (array_key_exists('mapped_shipping_method', $data)) {
            $payload['mapped_shipping_method'] = mb_substr(trim((string) $data['mapped_shipping_method']), 0, 128);
        }
        if (array_key_exists('warehouse', $data)) {
            $wh = mb_substr(trim((string) $data['warehouse']), 0, 128);
            $payload['warehouse'] = $wh !== '' ? $wh : 'Default';
        }
        if (array_key_exists('status', $data)) {
            $status = strtoupper(trim((string) $data['status']));
            $allowed = ['NOT_STARTED', 'IN_PROGRESS', 'FULFILLED', 'CANCELLED'];
            if (!in_array($status, $allowed, true)) {
                throw new \RuntimeException('Invalid order status.');
            }
            $payload['status'] = $status;
        }
        if (array_key_exists('tracking_number', $data)) {
            $payload['tracking_number'] = mb_substr(trim((string) $data['tracking_number']), 0, 128);
        }
        if (array_key_exists('carrier_code', $data)) {
            $payload['carrier_code'] = mb_substr(strtoupper(trim((string) $data['carrier_code'])), 0, 64);
        }
        if (array_key_exists('billing_address', $data)) {
            $bill = $data['billing_address'];
            if (is_array($bill)) {
                $payload['billing_address'] = json_encode($bill, JSON_UNESCAPED_UNICODE) ?: '{}';
            } elseif (is_string($bill)) {
                $payload['billing_address'] = $bill !== '' ? $bill : null;
            }
        }
        foreach (['on_hold', 'is_priority', 'attention_required', 'is_paid', 'pre_despatched'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $payload[$flag] = !empty($data[$flag]) ? 1 : 0;
            }
        }

        if ($payload !== []) {
            $this->db->update('sf_orders', $payload, ['id' => $id]);
        }

        if (array_key_exists('item_qty', $data) && is_array($data['item_qty'])) {
            foreach ($data['item_qty'] as $itemId => $rawQty) {
                $itemId = (int) $itemId;
                $qty = max(1, (int) $rawQty);
                if ($itemId <= 0) {
                    continue;
                }
                $this->db->query(
                    'UPDATE `#__sf_order_items` SET `qty` = ? WHERE `id` = ? AND `order_id` = ?',
                    [$qty, $itemId, $id]
                );
            }
        }
    }

    /**
     * Ensure order-edit columns exist (self-heal when migrations were stamped without applying).
     */
    private function ensureOrderEditColumns(): void
    {
        if (self::$orderEditColumnsEnsured) {
            return;
        }
        self::$orderEditColumnsEnsured = true;

        $table = str_replace('#__', $this->db->getPrefix(), '#__sf_orders');
        $columns = [
            'warehouse'              => "varchar(128) NOT NULL DEFAULT 'Default'",
            'mapped_shipping_method' => "varchar(128) NOT NULL DEFAULT ''",
            'special_instructions'   => "varchar(500) NOT NULL DEFAULT ''",
            'billing_address'        => 'json NULL',
            'packaging_size_id'      => 'int unsigned NULL',
            'package_count'          => 'int unsigned NOT NULL DEFAULT 1',
            'order_weight_kg'        => 'decimal(10,3) NOT NULL DEFAULT 0.000',
            'on_hold'                => 'tinyint(1) NOT NULL DEFAULT 0',
            'is_priority'            => 'tinyint(1) NOT NULL DEFAULT 0',
            'attention_required'     => 'tinyint(1) NOT NULL DEFAULT 0',
            'is_paid'                => 'tinyint(1) NOT NULL DEFAULT 1',
            'notes'                  => "varchar(500) NOT NULL DEFAULT ''",
            'pre_despatched'         => 'tinyint(1) NOT NULL DEFAULT 0',
            'shipped_at'             => 'datetime NULL',
        ];

        foreach ($columns as $name => $definition) {
            $exists = (int) $this->db->query(
                'SELECT COUNT(*) FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE()'
                . ' AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $name]
            )->value;
            if ($exists > 0) {
                continue;
            }
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
        }
    }

    /** Clone an order and its lines. Returns new order id. */
    public function cloneOrder(int $id): int
    {
        $this->ensureOrderEditColumns();
        $data = $this->find($id);
        if ($data === null) {
            throw new \RuntimeException('Order not found.');
        }
        $src = $data['order'];
        $cloneRef = 'CLONE-' . (string) $src['external_order_id'] . '-' . substr((string) microtime(true), -4);
        $tenantId = (int) ($src['tenant_id'] ?? (TenantContext::get()->id() ?? 1));
        $newId = (int) $this->db->insert('sf_orders', [
            'tenant_id'              => $tenantId,
            'channel_id'             => (int) $src['channel_id'],
            'external_order_id'      => mb_substr($cloneRef, 0, 64),
            'status'                 => 'NOT_STARTED',
            'buyer_username'         => (string) ($src['buyer_username'] ?? ''),
            'buyer_email'            => (string) ($src['buyer_email'] ?? ''),
            'total'                  => (float) ($src['total'] ?? 0),
            'currency'               => (string) ($src['currency'] ?? 'GBP'),
            'shipping_cost'          => (float) ($src['shipping_cost'] ?? 0),
            'discount_amount'        => (float) ($src['discount_amount'] ?? 0),
            'channel_fee'            => (float) ($src['channel_fee'] ?? 0),
            'shipping_tax'           => (float) ($src['shipping_tax'] ?? 0),
            'shipping_name'          => (string) ($src['shipping_name'] ?? ''),
            'shipping_address'       => is_string($src['shipping_address'] ?? null)
                ? (string) $src['shipping_address']
                : (json_encode($src['shipping_address'] ?? [], JSON_UNESCAPED_UNICODE) ?: '{}'),
            'billing_address'        => $src['billing_address'] ?? null,
            'shipping_method'        => (string) ($src['shipping_method'] ?? ''),
            'mapped_shipping_method' => (string) ($src['mapped_shipping_method'] ?? ''),
            'warehouse'              => (string) (($src['warehouse'] ?? '') !== '' ? $src['warehouse'] : 'Default'),
            'special_instructions'   => (string) ($src['special_instructions'] ?? ''),
            'packaging_size_id'      => $src['packaging_size_id'] ?? null,
            'package_count'          => (int) ($src['package_count'] ?? 1),
            'order_weight_kg'        => (float) ($src['order_weight_kg'] ?? 0),
            'on_hold'                => 0,
            'is_priority'            => (int) ($src['is_priority'] ?? 0),
            'attention_required'     => 0,
            'is_paid'                => (int) ($src['is_paid'] ?? 1),
            'notes'                  => 'Cloned from #' . (string) $src['external_order_id'],
            'created_at'             => date('Y-m-d H:i:s'),
        ]);
        foreach ($data['items'] as $item) {
            $this->db->insert('sf_order_items', [
                'tenant_id'             => $tenantId,
                'order_id'              => $newId,
                'variant_id'            => $item['variant_id'] ?? null,
                'external_sku'          => (string) ($item['external_sku'] ?? ''),
                'kit_sku'               => (string) ($item['kit_sku'] ?? ''),
                'title'                 => (string) ($item['title'] ?? ''),
                'qty'                   => (int) ($item['qty'] ?? 1),
                'price'                 => (float) ($item['price'] ?? 0),
                'external_line_item_id' => '',
            ]);
        }
        return $newId;
    }

    public function deleteOrder(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        if (TenantContext::get()->has()) {
            $this->db->query(
                'DELETE FROM `#__sf_orders` WHERE `id` = ? AND `tenant_id` = ?',
                [$id, $this->tenantId()]
            );
            return;
        }
        $this->db->query('DELETE FROM `#__sf_orders` WHERE `id` = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function paginate(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        [$where, $params] = $this->buildFilter($filters);

        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT o.*,
                       c.`name` AS channel_name,
                       c.`label` AS channel_label,
                       c.`type` AS channel_type,
                       pk.`name` AS packaging_name,
                       (SELECT pwo.`pickwave_id`
                          FROM `#__sf_pickwave_orders` pwo
                         WHERE pwo.`order_id` = o.`id`
                         LIMIT 1) AS pickwave_id
                  FROM `#__sf_orders` o
                  JOIN `#__sf_channels` c ON c.`id` = o.`channel_id`
                  LEFT JOIN `#__sf_packaging_sizes` pk ON pk.`id` = o.`packaging_size_id`
                {$where}
                 ORDER BY o.`created_at` DESC, o.`id` DESC
                 LIMIT ? OFFSET ?";

        return $this->db->query($sql, $params)->rows;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilter($filters);

        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_orders` o
               JOIN `#__sf_channels` c ON c.`id` = o.`channel_id`
               LEFT JOIN `#__sf_packaging_sizes` pk ON pk.`id` = o.`packaging_size_id`
             {$where}",
            $params
        )->value;
    }

    /**
     * Line items for a set of order IDs, keyed by order_id.
     *
     * @param  array<int, int|string> $orderIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function itemsByOrderIds(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($id) => (int) $id, $orderIds),
            static fn(int $id) => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->db->query(
            "SELECT i.`order_id`, i.`external_sku`, i.`title`, i.`qty`, i.`variant_id`,
                    COALESCE(p.`brand`, '') AS brand,
                    COALESCE(v.`product_id`, 0) AS product_id
               FROM `#__sf_order_items` i
               LEFT JOIN `#__sf_product_variants` v ON v.`id` = i.`variant_id`
               LEFT JOIN `#__sf_products` p ON p.`id` = v.`product_id`
              WHERE i.`order_id` IN ({$placeholders})
              ORDER BY i.`id`",
            $ids
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $oid = (int) $row['order_id'];
            $out[$oid][] = [
                'external_sku' => (string) ($row['external_sku'] ?? ''),
                'title'        => (string) ($row['title'] ?? ''),
                'qty'          => (int) ($row['qty'] ?? 1),
                'brand'        => (string) ($row['brand'] ?? ''),
                'variant_id'   => (int) ($row['variant_id'] ?? 0),
                'product_id'   => (int) ($row['product_id'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $ids  empty = all matching recent export window
     * @return list<array<string, mixed>>
     */
    public function exportRows(array $ids = []): array
    {
        $params = [];
        $where = '';
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where = "WHERE o.`id` IN ({$placeholders})";
            $params = $ids;
        }

        $rows = $this->db->query(
            "SELECT o.*,
                    c.`label` AS channel_label,
                    pk.`name` AS packaging_name,
                    (SELECT pwo.`pickwave_id`
                       FROM `#__sf_pickwave_orders` pwo
                      WHERE pwo.`order_id` = o.`id`
                      LIMIT 1) AS pickwave_id
               FROM `#__sf_orders` o
               JOIN `#__sf_channels` c ON c.`id` = o.`channel_id`
               LEFT JOIN `#__sf_packaging_sizes` pk ON pk.`id` = o.`packaging_size_id`
             {$where}
              ORDER BY o.`created_at` DESC, o.`id` DESC
              LIMIT 5000",
            $params
        )->rows;

        $out = [];
        foreach ($rows as $r) {
            $addr = json_decode((string) ($r['shipping_address'] ?? '{}'), true) ?: [];
            $postcode = trim((string) ($addr['postalCode'] ?? $addr['postcode'] ?? $addr['zip'] ?? ''));
            $out[] = [
                'id'                => (int) $r['id'],
                'external_order_id' => (string) $r['external_order_id'],
                'channel_label'     => (string) ($r['channel_label'] ?? ''),
                'status'            => (string) ($r['status'] ?? ''),
                'shipping_name'     => (string) ($r['shipping_name'] ?? ''),
                'buyer_email'       => (string) ($r['buyer_email'] ?? ''),
                'postcode'          => $postcode,
                'total'             => (float) ($r['total'] ?? 0),
                'currency'          => (string) ($r['currency'] ?? 'GBP'),
                'shipping_method'   => (string) ($r['shipping_method'] ?? ''),
                'packaging_name'    => (string) ($r['packaging_name'] ?? ''),
                'package_count'     => (int) ($r['package_count'] ?? 1),
                'order_weight_kg'   => (float) ($r['order_weight_kg'] ?? 0),
                'pickwave_id'       => (int) ($r['pickwave_id'] ?? 0),
                'notes'             => (string) ($r['notes'] ?? ''),
                'created_at'        => (string) ($r['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Create a blank manual order on the given channel.
     *
     * @return array{id:int}
     */
    public function createManual(int $channelId, string $externalId = ''): array
    {
        if ($channelId <= 0) {
            throw new \InvalidArgumentException('Channel is required.');
        }
        $ref = $externalId !== '' ? $externalId : ('MAN-' . date('YmdHis') . '-' . random_int(100, 999));
        $parcel = $this->db->query(
            "SELECT `id` FROM `#__sf_packaging_sizes` WHERE `name` = 'Parcel' LIMIT 1"
        )->row;
        $payload = [
            'channel_id'        => $channelId,
            'external_order_id' => mb_substr($ref, 0, 64),
            'status'            => 'NOT_STARTED',
            'buyer_username'    => '',
            'buyer_email'       => '',
            'total'             => 0,
            'currency'          => 'GBP',
            'shipping_name'     => '',
            'shipping_address'  => '{}',
            'shipping_method'   => '',
            'packaging_size_id' => $parcel !== [] ? (int) $parcel['id'] : null,
            'package_count'     => 1,
            'order_weight_kg'   => 0,
            'is_paid'           => 0,
            'notes'             => '',
            'created_at'        => date('Y-m-d H:i:s'),
        ];
        if (TenantContext::get()->has()) {
            $payload = $this->withTenant($payload);
        }
        $id = (int) $this->db->insert('sf_orders', $payload);
        return ['id' => $id];
    }

    /**
     * Orders that include any variant of the given product.
     *
     * @return array<int, array{
     *   id:int, external_order_id:string, status:string, buyer_username:string,
     *   shipping_name:string, total:float, currency:string, created_at:string,
     *   channel_label:string, channel_name:string, line_qty:int, line_skus:string
     * }>
     */
    public function forProduct(int $productId, int $limit = 50): array
    {
        if ($productId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $rows = $this->db->query(
            "SELECT o.`id`, o.`external_order_id`, o.`status`, o.`buyer_username`,
                    o.`shipping_name`, o.`total`, o.`currency`, o.`created_at`,
                    c.`name` AS channel_name, c.`label` AS channel_label,
                    COALESCE(SUM(i.`qty`), 0) AS line_qty,
                    GROUP_CONCAT(DISTINCT NULLIF(i.`external_sku`, '') ORDER BY i.`id` SEPARATOR ', ') AS line_skus
               FROM `#__sf_orders` o
               JOIN `#__sf_channels` c ON c.`id` = o.`channel_id`
               JOIN `#__sf_order_items` i ON i.`order_id` = o.`id`
               JOIN `#__sf_product_variants` v ON v.`id` = i.`variant_id`
              WHERE v.`product_id` = ?
              GROUP BY o.`id`, o.`external_order_id`, o.`status`, o.`buyer_username`,
                       o.`shipping_name`, o.`total`, o.`currency`, o.`created_at`,
                       c.`name`, c.`label`
              ORDER BY o.`created_at` DESC, o.`id` DESC
              LIMIT ?",
            [$productId, $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'                => (int) $row['id'],
                'external_order_id' => (string) ($row['external_order_id'] ?? ''),
                'status'            => (string) ($row['status'] ?? ''),
                'buyer_username'    => (string) ($row['buyer_username'] ?? ''),
                'shipping_name'     => (string) ($row['shipping_name'] ?? ''),
                'total'             => (float) ($row['total'] ?? 0),
                'currency'          => (string) ($row['currency'] ?? 'GBP'),
                'created_at'        => (string) ($row['created_at'] ?? ''),
                'channel_label'     => (string) ($row['channel_label'] ?? $row['channel_name'] ?? ''),
                'channel_name'      => (string) ($row['channel_name'] ?? ''),
                'line_qty'          => (int) ($row['line_qty'] ?? 0),
                'line_skus'         => (string) ($row['line_skus'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Recent orders for a buyer on a channel (conversation sidebar).
     *
     * @return array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}>
     */
    public function forBuyer(int $channelId, string $buyerUsername, int $limit = 5): array
    {
        $buyerUsername = trim($buyerUsername);
        if ($channelId <= 0 || $buyerUsername === '' || !TenantContext::get()->has()) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        $orders = $this->db->query(
            "SELECT * FROM `#__sf_orders`
              WHERE `channel_id` = ? AND `buyer_username` = ? AND `tenant_id` = ?
              ORDER BY `created_at` DESC, `id` DESC
              LIMIT ?",
            [$channelId, $buyerUsername, $this->tenantId(), $limit]
        )->rows;

        return $this->hydrateOrderBundles($orders);
    }

    /**
     * @return array{order: array<string, mixed>, items: array<int, array<string, mixed>>}|null
     */
    public function bundleByExternalId(int $channelId, string $externalId): ?array
    {
        $externalId = trim($externalId);
        if ($channelId <= 0 || $externalId === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__sf_orders`
              WHERE `channel_id` = ? AND `external_order_id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$channelId, mb_substr($externalId, 0, 64), $this->tenantId()]
        )->row;

        $bundles = $this->hydrateOrderBundles($row === [] ? [] : [$row]);
        return $bundles[0] ?? null;
    }

    /**
     * @return array{order: array<string, mixed>, items: array<int, array<string, mixed>>}|null
     */
    public function bundleByTracking(int $channelId, string $tracking): ?array
    {
        $tracking = trim($tracking);
        if ($channelId <= 0 || $tracking === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__sf_orders`
              WHERE `channel_id` = ? AND `tenant_id` = ?
                AND `tracking_number` <> ''
                AND (
                    `tracking_number` = ?
                    OR `tracking_number` LIKE ?
                )
              ORDER BY `id` DESC
              LIMIT 1",
            [$channelId, $this->tenantId(), $tracking, '%' . $tracking . '%']
        )->row;

        $bundles = $this->hydrateOrderBundles($row === [] ? [] : [$row]);
        return $bundles[0] ?? null;
    }

    /**
     * Orders whose listings/line items match an eBay ItemID.
     *
     * @return array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}>
     */
    public function forListingItem(int $channelId, string $itemId, int $limit = 5): array
    {
        $itemId = trim($itemId);
        if ($channelId <= 0 || $itemId === '' || !TenantContext::get()->has()) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $tid = $this->tenantId();

        $orders = $this->db->query(
            "SELECT DISTINCT o.*
               FROM `#__sf_orders` o
               JOIN `#__sf_order_items` i
                 ON i.`order_id` = o.`id` AND i.`tenant_id` = o.`tenant_id`
               LEFT JOIN `#__sf_product_variants` v
                 ON v.`id` = i.`variant_id` AND v.`tenant_id` = o.`tenant_id`
               LEFT JOIN `#__sf_listings` l
                 ON l.`variant_id` = v.`id` AND l.`channel_id` = o.`channel_id` AND l.`tenant_id` = o.`tenant_id`
              WHERE o.`channel_id` = ? AND o.`tenant_id` = ?
                AND (
                    l.`external_id` = ?
                    OR i.`external_line_item_id` = ?
                    OR i.`external_sku` = ?
                )
              ORDER BY o.`created_at` DESC, o.`id` DESC
              LIMIT ?",
            [$channelId, $tid, $itemId, $itemId, $itemId, $limit]
        )->rows;

        return $this->hydrateOrderBundles($orders);
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}>
     */
    private function hydrateOrderBundles(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        $out = [];
        foreach ($orders as $order) {
            $id = (int) ($order['id'] ?? 0);
            $items = $id > 0
                ? $this->db->query(
                    'SELECT * FROM `#__sf_order_items` WHERE `order_id` = ? ORDER BY `id`',
                    [$id]
                )->rows
                : [];
            $out[] = [
                'order' => $order,
                'items' => $items,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildFilter(array $filters): array
    {
        $clauses = [];
        $params  = [];

        // Always scope — never list orders across tenants.
        $clauses[] = 'o.`tenant_id` = ?';
        $params[]  = TenantContext::get()->has() ? $this->tenantId() : -1;

        $channelId = (int) ($filters['channel_id'] ?? 0);
        if ($channelId > 0) {
            $clauses[] = 'o.`channel_id` = ?';
            $params[]  = $channelId;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $clauses[] = 'o.`status` = ?';
            $params[]  = $status;
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if (mb_strlen($search) >= 3) {
            $like = '%' . $search . '%';
            $clauses[] = '(o.`external_order_id` LIKE ?
                OR o.`buyer_username` LIKE ?
                OR o.`buyer_email` LIKE ?
                OR o.`shipping_name` LIKE ?
                OR CAST(o.`shipping_address` AS CHAR) LIKE ?
                OR EXISTS (
                    SELECT 1 FROM `#__sf_order_items` i
                     WHERE i.`order_id` = o.`id`
                       AND (i.`external_sku` LIKE ? OR i.`title` LIKE ?)
                ))';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        $fOrder = trim((string) ($filters['f_order'] ?? ''));
        if ($fOrder !== '') {
            $clauses[] = '(CAST(o.`id` AS CHAR) LIKE ? OR o.`external_order_id` LIKE ?)';
            $like = '%' . $fOrder . '%';
            array_push($params, $like, $like);
        }

        $fCustomer = trim((string) ($filters['f_customer'] ?? ''));
        if ($fCustomer !== '') {
            $clauses[] = 'o.`shipping_name` LIKE ?';
            $params[]  = '%' . $fCustomer . '%';
        }

        $fAddress = trim((string) ($filters['f_address'] ?? ''));
        if ($fAddress !== '') {
            $clauses[] = 'CAST(o.`shipping_address` AS CHAR) LIKE ?';
            $params[]  = '%' . $fAddress . '%';
        }

        $fChannel = trim((string) ($filters['f_channel'] ?? ''));
        if ($fChannel !== '') {
            if (ctype_digit($fChannel)) {
                $clauses[] = 'o.`channel_id` = ?';
                $params[]  = (int) $fChannel;
            } else {
                $clauses[] = '(c.`label` LIKE ? OR c.`name` LIKE ? OR c.`type` LIKE ?)';
                $like = '%' . $fChannel . '%';
                array_push($params, $like, $like, $like);
            }
        }

        $fSku = trim((string) ($filters['f_sku'] ?? ''));
        if ($fSku !== '') {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM `#__sf_order_items` i
                 WHERE i.`order_id` = o.`id`
                   AND (i.`external_sku` LIKE ? OR i.`title` LIKE ?)
            )';
            $like = '%' . $fSku . '%';
            array_push($params, $like, $like);
        }

        $fDate = trim((string) ($filters['f_date'] ?? ''));
        if ($fDate !== '') {
            $clauses[] = 'DATE(o.`created_at`) = ?';
            $params[]  = $fDate;
        }

        $fPostcode = trim((string) ($filters['f_postcode'] ?? ''));
        if ($fPostcode !== '') {
            $clauses[] = 'CAST(o.`shipping_address` AS CHAR) LIKE ?';
            $params[]  = '%' . $fPostcode . '%';
        }

        $fEmail = trim((string) ($filters['f_email'] ?? ''));
        if ($fEmail !== '') {
            $clauses[] = 'o.`buyer_email` LIKE ?';
            $params[]  = '%' . $fEmail . '%';
        }

        $fTotal = trim((string) ($filters['f_total'] ?? ''));
        if ($fTotal !== '') {
            $clauses[] = 'CAST(o.`total` AS CHAR) LIKE ?';
            $params[]  = '%' . $fTotal . '%';
        }

        $fPackaging = trim((string) ($filters['f_packaging'] ?? ''));
        if ($fPackaging !== '') {
            if (ctype_digit($fPackaging)) {
                $clauses[] = 'o.`packaging_size_id` = ?';
                $params[]  = (int) $fPackaging;
            } else {
                $clauses[] = 'pk.`name` LIKE ?';
                $params[]  = '%' . $fPackaging . '%';
            }
        }

        $fPackages = trim((string) ($filters['f_packages'] ?? ''));
        if ($fPackages !== '') {
            $clauses[] = 'o.`package_count` = ?';
            $params[]  = (int) $fPackages;
        }

        $fShipping = trim((string) ($filters['f_shipping'] ?? ''));
        if ($fShipping !== '') {
            $clauses[] = 'o.`shipping_method` LIKE ?';
            $params[]  = '%' . $fShipping . '%';
        }

        $fStatus = trim((string) ($filters['f_status'] ?? ''));
        if ($fStatus !== '') {
            $clauses[] = 'o.`status` = ?';
            $params[]  = $fStatus;
        }

        $fWeight = trim((string) ($filters['f_weight'] ?? ''));
        if ($fWeight !== '') {
            $clauses[] = 'CAST(o.`order_weight_kg` AS CHAR) LIKE ?';
            $params[]  = '%' . $fWeight . '%';
        }

        $fPickwave = trim((string) ($filters['f_pickwave'] ?? ''));
        if ($fPickwave !== '') {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM `#__sf_pickwave_orders` pwo
                 WHERE pwo.`order_id` = o.`id` AND CAST(pwo.`pickwave_id` AS CHAR) LIKE ?
            )';
            $params[] = '%' . $fPickwave . '%';
        }

        $fNotes = trim((string) ($filters['f_notes'] ?? ''));
        if ($fNotes !== '') {
            $clauses[] = 'o.`notes` LIKE ?';
            $params[]  = '%' . $fNotes . '%';
        }

        foreach ([
            'f_hold'      => 'on_hold',
            'f_priority'  => 'is_priority',
            'f_attention' => 'attention_required',
            'f_paid'      => 'is_paid',
        ] as $key => $col) {
            $val = trim((string) ($filters[$key] ?? ''));
            if ($val === '1' || $val === '0') {
                $clauses[] = "o.`{$col}` = ?";
                $params[]  = (int) $val;
            }
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }
}
