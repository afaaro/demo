<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

/**
 * Store-style inventory planner / requisitions.
 *
 * Groups suggested replenishment lines by supplier using that supplier's
 * purchase-order mode (below reorder, calculated, sold-in-days, on demand).
 */
final class InventoryPlannerService
{
    use TenantScoped;

    public const MODE_BELOW_REORDER = 'below_reorder';
    public const MODE_CALCULATED    = 'below_reorder_calculated';
    public const MODE_SOLD_DAYS     = 'sold_days';
    public const MODE_ON_DEMAND     = 'on_demand';

    public function __construct(private Database $db) {}

    /**
     * @return array{
     *   groups: list<array{
     *     supplier: string,
     *     supplier_id: int,
     *     po_mode: string,
     *     po_mode_label: string,
     *     currency: string,
     *     lead_time_days: int,
     *     min_order_value: float,
     *     meets_min_order: bool,
     *     product_count: int,
     *     unit_count: int,
     *     subtotal: float,
     *     lines: list<array<string, mixed>>
     *   }>,
     *   summary: array{suppliers:int, products:int, units:int, subtotal:float}
     * }
     */
    public function build(array $filters = []): array
    {
        if (!TenantContext::get()->has()) {
            return [
                'groups'  => [],
                'summary' => ['suppliers' => 0, 'products' => 0, 'units' => 0, 'subtotal' => 0.0],
            ];
        }

        $supplierFilter = trim((string) ($filters['supplier'] ?? ''));
        $warehouseFilter = trim((string) ($filters['warehouse'] ?? ''));
        $q = trim((string) ($filters['q'] ?? ''));

        $tid = $this->tenantId();
        $params = [$tid];
        $clauses = [
            'p.`tenant_id` = ?',
            'p.`active` = 1',
            'v.`active` = 1',
            's.`active` = 1',
            's.`include_in_requisitions` = 1',
            "TRIM(COALESCE(p.`supplier_name`, '')) <> ''",
        ];

        if ($supplierFilter !== '') {
            $clauses[] = 's.`name` = ?';
            $params[] = $supplierFilter;
        }
        if ($warehouseFilter !== '') {
            if (strcasecmp($warehouseFilter, 'Default') === 0) {
                $clauses[] = "(p.`warehouse` = '' OR p.`warehouse` = 'Default')";
            } else {
                $clauses[] = 'p.`warehouse` = ?';
                $params[] = $warehouseFilter;
            }
        }
        if ($q !== '') {
            $clauses[] = '(p.`name` LIKE ? OR p.`sku` LIKE ? OR v.`sku` LIKE ? OR v.`name` LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);

        $rows = $this->db->query(
            "SELECT p.`id` AS product_id, p.`name` AS product_name, p.`sku` AS product_sku,
                    p.`supplier_name`, p.`warehouse`,
                    v.`id` AS variant_id, v.`sku` AS variant_sku, v.`name` AS variant_name,
                    v.`cost`,
                    COALESCE(i.`quantity`, 0) AS quantity,
                    COALESCE(i.`reserved`, 0) AS reserved,
                    COALESCE(i.`reorder_at`, 0) AS reorder_at,
                    i.`supplier_inventory`,
                    s.`id` AS supplier_id,
                    COALESCE(s.`po_mode`, 'below_reorder') AS po_mode,
                    COALESCE(s.`sold_days`, 30) AS sold_days,
                    COALESCE(s.`lead_time_days`, 0) AS lead_time_days,
                    COALESCE(s.`accepts_back_orders`, 1) AS accepts_back_orders,
                    COALESCE(s.`min_order_value`, 0) AS min_order_value,
                    COALESCE(s.`currency`, 'GBP') AS currency,
                    COALESCE((
                        SELECT SUM(GREATEST(l.`qty_ordered` - l.`qty_received` - l.`qty_written_off`, 0))
                          FROM `#__store_purchase_order_lines` l
                          INNER JOIN `#__store_purchase_orders` po
                                  ON po.`id` = l.`po_id` AND po.`tenant_id` = l.`tenant_id`
                         WHERE l.`variant_id` = v.`id`
                           AND l.`tenant_id` = p.`tenant_id`
                           AND po.`status` IN ('draft', 'confirmed')
                    ), 0) AS on_po
               FROM `#__store_product_variants` v
               INNER JOIN `#__store_products` p
                       ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
               INNER JOIN `#__store_suppliers` s
                       ON s.`name` = p.`supplier_name` AND s.`tenant_id` = p.`tenant_id`
               LEFT JOIN `#__store_inventory` i
                      ON i.`variant_id` = v.`id` AND i.`tenant_id` = v.`tenant_id`
              {$where}
              ORDER BY s.`name` ASC, p.`name` ASC, v.`sku` ASC",
            $params
        )->rows;

        $soldMap = $this->soldQtyByVariant($this->maxSoldDaysNeeded($rows));
        $bufferMap = $this->bufferByVariant(array_map(
            static fn(array $r): int => (int) ($r['variant_id'] ?? 0),
            $rows
        ));
        $groups = [];

        foreach ($rows as $row) {
            $available = (int) $row['quantity'] - (int) $row['reserved'];
            $onPo = (int) $row['on_po'];
            $reserved = (int) $row['reserved'];
            $reorderAt = (int) $row['reorder_at'];
            $mode = (string) $row['po_mode'];
            $soldDays = max(1, (int) $row['sold_days']);
            $lead = max(1, (int) $row['lead_time_days']);
            $vid = (int) $row['variant_id'];
            $soldInWindow = (int) ($soldMap[$vid][$soldDays] ?? $this->soldForDays($soldMap, $vid, $soldDays));
            $sold365 = (int) ($soldMap[$vid][365] ?? $this->soldForDays($soldMap, $vid, 365));

            $calcReorder = $this->calculatedReorderLevel($sold365, $lead);
            $suggest = $this->suggestQty($mode, $available, $onPo, $reserved, $reorderAt, $calcReorder, $soldInWindow);

            // Buffers (listing qty_buffer + channel sellable_buffer) increase suggested PO qty.
            $buffer = (int) ($bufferMap[$vid] ?? 0);
            if ($buffer > 0 && $suggest > 0) {
                $suggest += $buffer;
            } elseif ($buffer > 0 && $suggest <= 0 && $available <= ($reorderAt + $buffer)) {
                $suggest = $buffer;
            }

            if ($suggest <= 0) {
                continue;
            }

            // Optional: skip when supplier has no stock and does not accept backorders.
            if (empty($row['accepts_back_orders'])) {
                $supInv = $row['supplier_inventory'];
                if ($supInv !== null && $supInv !== '' && (int) $supInv < $suggest) {
                    if ((int) $supInv <= 0) {
                        continue;
                    }
                    $suggest = (int) $supInv;
                }
            }

            $cost = (float) $row['cost'];
            $lineTotal = round($suggest * $cost, 2);
            $warehouse = trim((string) ($row['warehouse'] ?? ''));
            if ($warehouse === '') {
                $warehouse = 'Default';
            }

            $supplierName = (string) $row['supplier_name'];
            if (!isset($groups[$supplierName])) {
                $groups[$supplierName] = [
                    'supplier'        => $supplierName,
                    'supplier_id'     => (int) $row['supplier_id'],
                    'po_mode'         => $mode,
                    'po_mode_label'   => self::modeLabel($mode, $soldDays),
                    'currency'        => (string) (($row['currency'] ?? 'GBP') !== '' ? $row['currency'] : 'GBP'),
                    'lead_time_days'  => (int) $row['lead_time_days'],
                    'min_order_value' => (float) $row['min_order_value'],
                    'meets_min_order' => true,
                    'product_count'   => 0,
                    'unit_count'      => 0,
                    'subtotal'        => 0.0,
                    'lines'           => [],
                ];
            }

            $groups[$supplierName]['lines'][] = [
                'product_id'       => (int) $row['product_id'],
                'product_name'     => (string) $row['product_name'],
                'product_sku'      => (string) $row['product_sku'],
                'variant_id'       => $vid,
                'variant_sku'      => (string) $row['variant_sku'],
                'variant_name'     => (string) ($row['variant_name'] ?? ''),
                'warehouse'        => $warehouse,
                'available'        => $available,
                'reserved'         => $reserved,
                'on_po'            => $onPo,
                'reorder_at'       => $reorderAt,
                'calculated_reorder' => $calcReorder,
                'sold_qty'         => $soldInWindow,
                'sold_days'        => $soldDays,
                'cost'             => $cost,
                'suggested_qty'    => $suggest,
                'buffer'           => $buffer,
                'line_total'       => $lineTotal,
                'reason'           => $this->reasonLabel($mode, $available, $reorderAt, $calcReorder, $soldInWindow, $soldDays, $reserved)
                    . ($buffer > 0 ? ' (+' . $buffer . ' buffer)' : ''),
            ];
            $groups[$supplierName]['product_count']++;
            $groups[$supplierName]['unit_count'] += $suggest;
            $groups[$supplierName]['subtotal'] = round(
                $groups[$supplierName]['subtotal'] + $lineTotal,
                2
            );
        }

        $out = [];
        foreach ($groups as $g) {
            $min = (float) $g['min_order_value'];
            $g['meets_min_order'] = $min <= 0 || $g['subtotal'] >= $min;
            $out[] = $g;
        }

        $summary = [
            'suppliers' => count($out),
            'products'  => array_sum(array_column($out, 'product_count')),
            'units'     => array_sum(array_column($out, 'unit_count')),
            'subtotal'  => round(array_sum(array_column($out, 'subtotal')), 2),
        ];

        return ['groups' => $out, 'summary' => $summary];
    }

    public static function modeLabel(string $mode, int $soldDays = 30): string
    {
        return match ($mode) {
            self::MODE_ON_DEMAND  => 'On demand (reserved / backorder)',
            self::MODE_SOLD_DAYS  => 'Sold in last ' . max(1, $soldDays) . ' days',
            self::MODE_CALCULATED => 'Below reorder (calculated)',
            default               => 'Below reorder level',
        };
    }

    /** @return list<string> */
    public static function modeOptions(): array
    {
        return [
            self::MODE_BELOW_REORDER => 'Below reorder level (manual)',
            self::MODE_CALCULATED    => 'Below reorder level (calculated)',
            self::MODE_SOLD_DAYS     => 'Sold in the last XX days',
            self::MODE_ON_DEMAND     => 'On demand (cover reserved qty)',
        ];
    }

    private function suggestQty(
        string $mode,
        int $available,
        int $onPo,
        int $reserved,
        int $reorderAt,
        int $calcReorder,
        int $soldInWindow,
    ): int {
        return match ($mode) {
            self::MODE_ON_DEMAND => max(0, $reserved - $onPo),
            self::MODE_SOLD_DAYS => max(0, $soldInWindow - max(0, $available) - $onPo),
            self::MODE_CALCULATED => max(0, $calcReorder - $available - $onPo),
            default => max(0, $reorderAt - $available - $onPo),
        };
    }

    private function calculatedReorderLevel(int $sold365, int $leadDays): int
    {
        // Daily average over ~365 days × lead time (rounded down).
        $daily = $sold365 / 365.0;
        return (int) floor($daily * max(1, $leadDays));
    }

    /**
     * Max listing qty_buffer + max channel sellable_buffer per variant.
     *
     * @param list<int> $variantIds
     * @return array<int, int>
     */
    private function bufferByVariant(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter(
            array_map('intval', $variantIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($variantIds === [] || !TenantContext::get()->has()) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $params = array_merge($variantIds, [$this->tenantId()]);
        $rows = $this->db->query(
            "SELECT l.`variant_id`,
                    COALESCE(MAX(l.`qty_buffer`), 0) AS listing_buffer
               FROM `#__store_listings` l
              WHERE l.`variant_id` IN ({$placeholders})
                AND l.`tenant_id` = ?
              GROUP BY l.`variant_id`",
            $params
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['variant_id']] = max(0, (int) ($row['listing_buffer'] ?? 0));
        }

        // Channel sellable_buffer: take max across enabled non-courier channels.
        try {
            $channels = new ChannelRepository($this->db);
            $channelBuffer = 0;
            foreach ($channels->enabled() as $ch) {
                if (in_array($ch->type, CourierShipmentService::COURIER_TYPES, true)) {
                    continue;
                }
                $channelBuffer = max($channelBuffer, max(0, ChannelSettings::int($ch, 'sellable_buffer', 0)));
            }
            if ($channelBuffer > 0) {
                foreach ($variantIds as $vid) {
                    $out[$vid] = (int) ($out[$vid] ?? 0) + $channelBuffer;
                }
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    private function reasonLabel(
        string $mode,
        int $available,
        int $reorderAt,
        int $calcReorder,
        int $soldInWindow,
        int $soldDays,
        int $reserved,
    ): string {
        return match ($mode) {
            self::MODE_ON_DEMAND => "Cover {$reserved} reserved unit(s)",
            self::MODE_SOLD_DAYS => "Sold {$soldInWindow} in last {$soldDays}d (avail {$available})",
            self::MODE_CALCULATED => "Calc reorder {$calcReorder}, avail {$available}",
            default => "Reorder at {$reorderAt}, avail {$available}",
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function maxSoldDaysNeeded(array $rows): int
    {
        $max = 365;
        foreach ($rows as $row) {
            $max = max($max, (int) ($row['sold_days'] ?? 30));
        }
        return min(365, max(1, $max));
    }

    /**
     * Prefetch sold qty per variant for 365 days; slice per window in PHP.
     *
     * @return array<int, array{365:int}>
     */
    private function soldQtyByVariant(int $maxDays): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $days = max(1, min(365, $maxDays));
        $rows = $this->db->query(
            "SELECT i.`variant_id`, COALESCE(SUM(i.`qty`), 0) AS sold
               FROM `#__store_order_items` i
               INNER JOIN `#__store_orders` o
                       ON o.`id` = i.`order_id` AND o.`tenant_id` = i.`tenant_id`
              WHERE i.`tenant_id` = ?
                AND i.`variant_id` IS NOT NULL
                AND UPPER(o.`status`) NOT IN ('CANCELLED', 'CANCELED')
                AND o.`created_at` >= (NOW() - INTERVAL {$days} DAY)
              GROUP BY i.`variant_id`",
            [$this->tenantId()]
        )->rows;

        // Also fetch last-N windows commonly used — query once at 365, then
        // for shorter windows run a second compact query.
        $map = [];
        foreach ($rows as $row) {
            $vid = (int) $row['variant_id'];
            $map[$vid][365] = (int) $row['sold'];
        }

        // Fill exact windows for 7/14/30/60/90 if needed via one more query per distinct day set is heavy;
        // instead query sold for each distinct sold_days from suppliers that use sold_days mode.
        $extraDays = $this->db->query(
            "SELECT DISTINCT COALESCE(`sold_days`, 30) AS d
               FROM `#__store_suppliers`
              WHERE `tenant_id` = ?
                AND `include_in_requisitions` = 1
                AND `po_mode` = ?",
            [$this->tenantId(), self::MODE_SOLD_DAYS]
        )->rows;

        foreach ($extraDays as $drow) {
            $d = max(1, min(365, (int) $drow['d']));
            if ($d === 365) {
                continue;
            }
            $part = $this->db->query(
                "SELECT i.`variant_id`, COALESCE(SUM(i.`qty`), 0) AS sold
                   FROM `#__store_order_items` i
                   INNER JOIN `#__store_orders` o
                           ON o.`id` = i.`order_id` AND o.`tenant_id` = i.`tenant_id`
                  WHERE i.`tenant_id` = ?
                    AND i.`variant_id` IS NOT NULL
                    AND UPPER(o.`status`) NOT IN ('CANCELLED', 'CANCELED')
                    AND o.`created_at` >= (NOW() - INTERVAL {$d} DAY)
                  GROUP BY i.`variant_id`",
                [$this->tenantId()]
            )->rows;
            foreach ($part as $row) {
                $map[(int) $row['variant_id']][$d] = (int) $row['sold'];
            }
        }

        return $map;
    }

    /**
     * @param array<int, array<int, int>> $map
     */
    private function soldForDays(array $map, int $variantId, int $days): int
    {
        $days = max(1, min(365, $days));
        if (isset($map[$variantId][$days])) {
            return (int) $map[$variantId][$days];
        }
        // Fallback: use 365 proportionally if exact window missing.
        if (isset($map[$variantId][365])) {
            return (int) round(((int) $map[$variantId][365]) * ($days / 365.0));
        }
        return 0;
    }
}
