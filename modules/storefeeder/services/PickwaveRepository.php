<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class PickwaveRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /** @var array<string, string> */
    private const OUTSTANDING_SORTS = [
        'channel'         => 'c.`label`',
        'order_number'    => 'o.`external_order_id`',
        'channel_ref'     => 'o.`external_order_id`',
        'order_date'      => 'o.`created_at`',
        'ship_by'         => 'JSON_UNQUOTE(JSON_EXTRACT(o.`shipping_address`, \'$._ship_by\'))',
        'buyer'           => 'o.`buyer_username`',
        'postcode'        => 'COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.`shipping_address`, \'$.postalCode\')), \'\')',
        'total'           => 'o.`total`',
        'status'          => 'o.`status`',
        'shipping_method' => 'o.`shipping_method`',
        'total_units'     => '(SELECT COALESCE(SUM(i.`qty`), 0) FROM `#__sf_order_items` i WHERE i.`order_id` = o.`id`)',
        'multiple_lines'  => '(SELECT COUNT(*) FROM `#__sf_order_items` i WHERE i.`order_id` = o.`id`)',
        'product_skus'    => '(SELECT MIN(i.`external_sku`) FROM `#__sf_order_items` i WHERE i.`order_id` = o.`id`)',
        'brand'           => '(SELECT MIN(p.`brand`) FROM `#__sf_order_items` i
            LEFT JOIN `#__sf_product_variants` v ON v.`id` = i.`variant_id`
            LEFT JOIN `#__sf_products` p ON p.`id` = v.`product_id`
            WHERE i.`order_id` = o.`id` AND p.`brand` <> \'\')',
    ];

    /**
     * Orders ready to pick: not fulfilled/cancelled, not on any pickwave, has items.
     *
     * @param array{channel_id?: int, q?: string, sku?: string, brands?: array<int, string>} $filters
     * @return array<int, array<string, mixed>>
     */
    public function outstandingOrders(
        int $limit = 100,
        int $offset = 0,
        array $filters = [],
        string $sort = 'order_date',
        string $dir = 'asc',
    ): array {
        [$where, $params] = $this->outstandingFilter($filters);
        [$orderSql] = $this->outstandingOrderBy($sort, $dir);
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->query(
            "SELECT o.*, c.`name` AS channel_name, c.`label` AS channel_label
               FROM `#__sf_orders` o
               JOIN `#__sf_channels` c ON c.`id` = o.`channel_id`
              {$where}
              ORDER BY {$orderSql}
              LIMIT ? OFFSET ?",
            $params
        )->rows;
    }

    /**
     * @return array{0: string, 1: string, 2: string} orderSql, sort key, dir
     */
    public function outstandingOrderBy(string $sort, string $dir): array
    {
        $sort = strtolower(trim($sort));
        if (!isset(self::OUTSTANDING_SORTS[$sort])) {
            $sort = 'order_date';
        }
        $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $expr = self::OUTSTANDING_SORTS[$sort];

        return ["{$expr} {$dir}, o.`id` {$dir}", $sort, strtolower($dir)];
    }

    /** @param array{channel_id?: int, q?: string, sku?: string, brands?: array<int, string>} $filters */
    public function countOutstanding(array $filters = []): int
    {
        [$where, $params] = $this->outstandingFilter($filters);

        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_orders` o {$where}",
            $params
        )->value;
    }

    /**
     * Distinct brands on outstanding orders (linked products), with order counts.
     *
     * @return array<int, array{brand: string, order_count: int}>
     */
    public function outstandingBrandOptions(array $filters = []): array
    {
        // Ignore brand filter when listing options so the dropdown stays complete.
        $base = $filters;
        unset($base['brands']);
        [$where, $params] = $this->outstandingFilter($base);

        $rows = $this->db->query(
            "SELECT p.`brand` AS brand, COUNT(DISTINCT o.`id`) AS order_count
               FROM `#__sf_orders` o
               JOIN `#__sf_order_items` i ON i.`order_id` = o.`id`
               JOIN `#__sf_product_variants` v ON v.`id` = i.`variant_id`
               JOIN `#__sf_products` p ON p.`id` = v.`product_id`
              {$where}
                AND p.`brand` <> ''
              GROUP BY p.`brand`
              ORDER BY p.`brand` ASC",
            $params
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $brand = trim((string) ($row['brand'] ?? ''));
            if ($brand === '') {
                continue;
            }
            $out[] = [
                'brand'       => $brand,
                'order_count' => (int) ($row['order_count'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Outstanding orders (not fulfilled/cancelled, not already on a wave)
     * that include any of the given variant IDs — used after booking in stock.
     *
     * @param list<int> $variantIds
     * @return list<int>
     */
    public function outstandingOrderIdsForVariants(array $variantIds, int $limit = 100): array
    {
        $variantIds = array_values(array_unique(array_filter(
            array_map('intval', $variantIds),
            static fn(int $id) => $id > 0
        )));
        if ($variantIds === [] || !TenantContext::get()->has()) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $placeholders = implode(', ', array_fill(0, count($variantIds), '?'));
        $params = array_merge($variantIds, [$this->tenantId()]);

        $rows = $this->db->query(
            "SELECT DISTINCT o.`id`
               FROM `#__sf_orders` o
               INNER JOIN `#__sf_order_items` i ON i.`order_id` = o.`id`
              WHERE i.`variant_id` IN ({$placeholders})
                AND o.`tenant_id` = ?
                AND UPPER(o.`status`) NOT IN ('FULFILLED', 'CANCELLED', 'CANCELED')
                AND NOT EXISTS (
                    SELECT 1 FROM `#__sf_pickwave_orders` pwo WHERE pwo.`order_id` = o.`id`
                )
              ORDER BY o.`created_at` ASC, o.`id` ASC
              LIMIT {$limit}",
            $params
        )->rows;

        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * @param array<int, int> $orderIds
     */
    public function create(array $orderIds, string $pickerName, string $notes = '', ?int $createdBy = null): int
    {
        $orderIds = array_values(array_unique(array_filter(
            array_map(static fn($id) => (int) $id, $orderIds),
            static fn(int $id) => $id > 0
        )));

        if ($orderIds === []) {
            throw new InvalidArgumentException('Select at least one order.');
        }

        $pickerName = mb_substr(trim($pickerName), 0, 128);
        $notes      = mb_substr(trim($notes), 0, 500);

        return (int) $this->db->transaction(function (Database $db) use ($orderIds, $pickerName, $notes, $createdBy) {
            $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
            $tenantId = $this->tenantId();

            $eligible = $db->query(
                "SELECT o.`id`
                   FROM `#__sf_orders` o
                  WHERE o.`id` IN ({$placeholders})
                    AND o.`tenant_id` = ?
                    AND UPPER(o.`status`) NOT IN ('FULFILLED', 'CANCELLED')
                    AND EXISTS (
                        SELECT 1 FROM `#__sf_order_items` i WHERE i.`order_id` = o.`id`
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM `#__sf_pickwave_orders` pwo WHERE pwo.`order_id` = o.`id`
                    )",
                array_merge($orderIds, [$tenantId])
            )->rows;

            $eligibleIds = array_map(static fn($r) => (int) $r['id'], $eligible);
            if ($eligibleIds === []) {
                throw new RuntimeException('None of the selected orders are available to pick.');
            }
            if (count($eligibleIds) !== count($orderIds)) {
                throw new RuntimeException(
                    'Some selected orders are already on a pickwave, fulfilled, cancelled, or have no items.'
                );
            }

            $waveId = $db->insert('sf_pickwaves', $this->withTenant([
                'status'      => 'open',
                'picker_name' => $pickerName,
                'notes'       => $notes,
                'created_by'  => $createdBy,
                'created_at'  => date('Y-m-d H:i:s'),
            ]));

            foreach ($eligibleIds as $oid) {
                $db->insert('sf_pickwave_orders', $this->withTenant([
                    'pickwave_id' => $waveId,
                    'order_id'    => $oid,
                    'added_at'    => date('Y-m-d H:i:s'),
                ]));
            }

            return $waveId;
        });
    }

    /**
     * @return array{
     *   wave: array<string, mixed>,
     *   orders: array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}>
     * }|null
     */
    public function find(int $id): ?array
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        $wave = $this->db->first('sf_pickwaves', $this->withTenant(['id' => $id]));
        if ($wave === []) {
            return null;
        }

        $orders = $this->db->query(
            "SELECT o.*, c.`name` AS channel_name, c.`label` AS channel_label
               FROM `#__sf_pickwave_orders` pwo
               JOIN `#__sf_orders` o ON o.`id` = pwo.`order_id`
               JOIN `#__sf_channels` c ON c.`id` = o.`channel_id`
              WHERE pwo.`pickwave_id` = ?
              ORDER BY o.`created_at` ASC, o.`id` ASC",
            [$id]
        )->rows;

        $orderIds = array_map(static fn($r) => (int) $r['id'], $orders);
        $itemsBy  = $this->itemsByOrderIds($orderIds);

        $packed = [];
        foreach ($orders as $order) {
            $oid = (int) $order['id'];
            $packed[] = [
                'order' => $order,
                'items' => $itemsBy[$oid] ?? [],
            ];
        }

        return [
            'wave'   => $wave,
            'orders' => $packed,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByStatus(string $status, int $limit = 50): array
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['open', 'completed'], true)) {
            return [];
        }

        $limit = max(1, min(200, $limit));

        if (!TenantContext::get()->has()) {
            return [];
        }

        return $this->db->query(
            "SELECT w.*,
                    (SELECT COUNT(*) FROM `#__sf_pickwave_orders` pwo WHERE pwo.`pickwave_id` = w.`id`) AS order_count,
                    (SELECT COUNT(*) FROM `#__sf_pickwave_orders` pwo
                       JOIN `#__sf_orders` o ON o.`id` = pwo.`order_id`
                      WHERE pwo.`pickwave_id` = w.`id`
                        AND UPPER(o.`status`) = 'FULFILLED') AS fulfilled_count
               FROM `#__sf_pickwaves` w
              WHERE w.`status` = ?
                AND w.`tenant_id` = ?
              ORDER BY w.`created_at` DESC, w.`id` DESC
              LIMIT ?",
            [$status, $this->tenantId(), $limit]
        )->rows;
    }

    /**
     * Dashboard stats for the picking overview (StoreFeeder-style).
     *
     * @return array{
     *   outstanding: int,
     *   open_pickwaves: int,
     *   picked_today: int,
     *   picked_48h: int,
     *   picked_week: int,
     *   remaining_in_open: int
     * }
     */
    public function stats(): array
    {
        if (!TenantContext::get()->has()) {
            return [
                'outstanding'       => 0,
                'open_pickwaves'    => 0,
                'picked_today'      => 0,
                'picked_48h'        => 0,
                'picked_week'       => 0,
                'remaining_in_open' => 0,
            ];
        }

        $tenantId = $this->tenantId();
        $outstanding = $this->countOutstanding();
        $openWaves   = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_pickwaves` WHERE `status` = 'open' AND `tenant_id` = ?",
            [$tenantId]
        )->value;

        $pickedToday = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_orders`
              WHERE `tenant_id` = ?
                AND UPPER(`status`) = 'FULFILLED'
                AND `shipped_at` IS NOT NULL
                AND DATE(`shipped_at`) = CURDATE()",
            [$tenantId]
        )->value;

        $picked48h = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_orders`
              WHERE `tenant_id` = ?
                AND UPPER(`status`) = 'FULFILLED'
                AND `shipped_at` IS NOT NULL
                AND `shipped_at` >= (NOW() - INTERVAL 48 HOUR)",
            [$tenantId]
        )->value;

        $pickedWeek = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_orders`
              WHERE `tenant_id` = ?
                AND UPPER(`status`) = 'FULFILLED'
                AND `shipped_at` IS NOT NULL
                AND `shipped_at` >= (NOW() - INTERVAL 7 DAY)",
            [$tenantId]
        )->value;

        $remainingInOpen = (int) $this->db->query(
            "SELECT COUNT(*)
               FROM `#__sf_pickwave_orders` pwo
               JOIN `#__sf_pickwaves` w ON w.`id` = pwo.`pickwave_id`
               JOIN `#__sf_orders` o ON o.`id` = pwo.`order_id`
              WHERE w.`status` = 'open'
                AND w.`tenant_id` = ?
                AND o.`tenant_id` = ?
                AND UPPER(o.`status`) NOT IN ('FULFILLED', 'CANCELLED')",
            [$tenantId, $tenantId]
        )->value;

        return [
            'outstanding'       => $outstanding,
            'open_pickwaves'    => $openWaves,
            'picked_today'      => $pickedToday,
            'picked_48h'        => $picked48h,
            'picked_week'       => $pickedWeek,
            'remaining_in_open' => $remainingInOpen,
        ];
    }

    public function markCompleted(int $id): void
    {
        if ($this->db->first('sf_pickwaves', $this->withTenant(['id' => $id])) === []) {
            return;
        }
        $this->db->update('sf_pickwaves', [
            'status'       => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ], $this->withTenant(['id' => $id]));
    }

    /**
     * @param array<int, int> $orderIds
     */
    public function markOrdersFulfilled(array $orderIds): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($id) => (int) $id, $orderIds),
            static fn(int $id) => $id > 0
        )));
        if ($ids === [] || !TenantContext::get()->has()) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            "UPDATE `#__sf_orders`
                SET `status` = 'FULFILLED',
                    `shipped_at` = COALESCE(`shipped_at`, ?),
                    `updated_at` = ?
              WHERE `id` IN ({$placeholders})
                AND `tenant_id` = ?
                AND UPPER(`status`) NOT IN ('FULFILLED', 'CANCELLED')",
            array_merge([$now, $now], $ids, [$this->tenantId()])
        );

        return count($ids);
    }

    public function updateDetails(int $id, string $pickerName, string $notes, string $tag = ''): void
    {
        $wave = $this->db->first('sf_pickwaves', $this->withTenant(['id' => $id]));
        if ($wave === []) {
            throw new RuntimeException('Pickwave not found.');
        }

        $this->db->update('sf_pickwaves', [
            'picker_name' => mb_substr(trim($pickerName), 0, 128),
            'notes'       => mb_substr(trim($notes), 0, 500),
            'tag'         => mb_substr(trim($tag), 0, 128),
        ], $this->withTenant(['id' => $id]));
    }

    /**
     * Remove an order from an open pickwave. Returns true if the wave was deleted
     * because it had no orders left.
     */
    public function removeOrder(int $pickwaveId, int $orderId): bool
    {
        $result = $this->removeOrders($pickwaveId, [$orderId]);
        return !empty($result['wave_deleted']);
    }

    /**
     * @param array<int, int> $orderIds
     * @return array{removed: int, skipped: int, wave_deleted: bool}
     */
    public function removeOrders(int $pickwaveId, array $orderIds): array
    {
        $wave = $this->db->first('sf_pickwaves', $this->withTenant(['id' => $pickwaveId]));
        if ($wave === []) {
            throw new RuntimeException('Pickwave not found.');
        }
        if (strtolower((string) ($wave['status'] ?? '')) !== 'open') {
            throw new RuntimeException('Only open pickwaves can be edited.');
        }

        $orderIds = array_values(array_unique(array_filter(
            array_map(static fn($id) => (int) $id, $orderIds),
            static fn(int $id) => $id > 0
        )));
        if ($orderIds === []) {
            throw new InvalidArgumentException('Select at least one order to remove.');
        }

        $removed = 0;
        $skipped = 0;

        foreach ($orderIds as $orderId) {
            $link = $this->db->first('sf_pickwave_orders', [
                'pickwave_id' => $pickwaveId,
                'order_id'    => $orderId,
            ]);
            if ($link === []) {
                $skipped++;
                continue;
            }

            $order = TenantContext::get()->has()
                ? $this->db->first('sf_orders', $this->withTenant(['id' => $orderId]))
                : [];
            if ($order !== [] && strtoupper((string) ($order['status'] ?? '')) === 'FULFILLED') {
                $skipped++;
                continue;
            }

            $this->db->query(
                'DELETE FROM `#__sf_pickwave_orders` WHERE `pickwave_id` = ? AND `order_id` = ?',
                [$pickwaveId, $orderId]
            );
            $removed++;
        }

        if ($removed === 0) {
            throw new RuntimeException(
                $skipped > 0
                    ? 'No orders could be removed (missing or already fulfilled).'
                    : 'No orders removed.'
            );
        }

        $remaining = (int) $this->db->query(
            'SELECT COUNT(*) FROM `#__sf_pickwave_orders` WHERE `pickwave_id` = ?',
            [$pickwaveId]
        )->value;

        $waveDeleted = false;
        if ($remaining === 0) {
            $this->db->query(
                'DELETE FROM `#__sf_pickwaves` WHERE `id` = ? AND `tenant_id` = ?',
                [$pickwaveId, $this->tenantId()]
            );
            $waveDeleted = true;
        }

        return [
            'removed'      => $removed,
            'skipped'      => $skipped,
            'wave_deleted' => $waveDeleted,
        ];
    }

    public function markOrderFulfilledWithTracking(
        int $orderId,
        string $tracking,
        string $carrier,
    ): void {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->query(
            "UPDATE `#__sf_orders`
                SET `status` = 'FULFILLED',
                    `tracking_number` = ?,
                    `carrier_code` = ?,
                    `shipped_at` = COALESCE(`shipped_at`, ?),
                    `updated_at` = ?
              WHERE `id` = ?
                AND `tenant_id` = ?
                AND UPPER(`status`) NOT IN ('FULFILLED', 'CANCELLED')",
            [
                mb_substr($tracking, 0, 128),
                mb_substr($carrier, 0, 64),
                $now,
                $now,
                $orderId,
                $this->tenantId(),
            ]
        );
    }

    /**
     * @param array{channel_id?: int, q?: string, sku?: string, brands?: array<int, string>} $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function outstandingFilter(array $filters): array
    {
        if (!TenantContext::get()->has()) {
            return [' WHERE 1=0', []];
        }

        $channelId = (int) ($filters['channel_id'] ?? 0);
        $search    = trim((string) ($filters['q'] ?? ''));
        $sku       = trim((string) ($filters['sku'] ?? ''));
        $brands    = is_array($filters['brands'] ?? null) ? $filters['brands'] : [];
        $brands    = array_values(array_unique(array_filter(
            array_map(static fn($b) => trim((string) $b), $brands),
            static fn(string $b) => $b !== ''
        )));

        $clauses = [
            $this->tenantClause('o'),
            "UPPER(o.`status`) NOT IN ('FULFILLED', 'CANCELLED')",
            'EXISTS (SELECT 1 FROM `#__sf_order_items` i WHERE i.`order_id` = o.`id`)',
            'NOT EXISTS (SELECT 1 FROM `#__sf_pickwave_orders` pwo WHERE pwo.`order_id` = o.`id`)',
        ];
        $params = [$this->tenantId()];

        if ($channelId > 0) {
            $clauses[] = 'o.`channel_id` = ?';
            $params[]  = $channelId;
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $clauses[] = '(o.`external_order_id` LIKE ?
                OR o.`buyer_username` LIKE ?
                OR o.`shipping_name` LIKE ?
                OR EXISTS (
                    SELECT 1 FROM `#__sf_order_items` i2
                     WHERE i2.`order_id` = o.`id`
                       AND (i2.`external_sku` LIKE ? OR i2.`title` LIKE ?)
                ))';
            array_push($params, $like, $like, $like, $like, $like);
        }

        if ($sku !== '') {
            // StoreFeeder "Product SKUs" column filter matches SKU or line title.
            $like = '%' . $sku . '%';
            $clauses[] = 'EXISTS (
                SELECT 1 FROM `#__sf_order_items` i3
                 WHERE i3.`order_id` = o.`id`
                   AND (i3.`external_sku` LIKE ? OR i3.`title` LIKE ?)
            )';
            $params[] = $like;
            $params[] = $like;
        }

        if ($brands !== []) {
            $placeholders = implode(', ', array_fill(0, count($brands), '?'));
            $clauses[] = "EXISTS (
                SELECT 1 FROM `#__sf_order_items` i4
                 JOIN `#__sf_product_variants` v4 ON v4.`id` = i4.`variant_id`
                 JOIN `#__sf_products` p4 ON p4.`id` = v4.`product_id`
                 WHERE i4.`order_id` = o.`id`
                   AND p4.`brand` IN ({$placeholders})
            )";
            foreach ($brands as $b) {
                $params[] = $b;
            }
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param  array<int, int> $orderIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function itemsByOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
        $rows = $this->db->query(
            "SELECT * FROM `#__sf_order_items`
              WHERE `order_id` IN ({$placeholders})
              ORDER BY `id`",
            $orderIds
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['order_id']][] = $row;
        }
        return $out;
    }
}
