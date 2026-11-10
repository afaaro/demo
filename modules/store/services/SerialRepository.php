<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class SerialRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @param list<array{order_item_id?:int, variant_id?:int, serial:string, lot?:string}> $rows
     */
    public function captureForOrder(int $orderId, array $rows): int
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            throw new RuntimeException('Invalid order.');
        }
        $n = 0;
        foreach ($rows as $row) {
            $serial = trim((string) ($row['serial'] ?? ''));
            if ($serial === '') {
                continue;
            }
            $this->db->insert('store_serials', $this->withTenant([
                'order_id'      => $orderId,
                'order_item_id' => (int) ($row['order_item_id'] ?? 0),
                'variant_id'    => (int) ($row['variant_id'] ?? 0),
                'serial'        => mb_substr($serial, 0, 128),
                'lot'           => mb_substr(trim((string) ($row['lot'] ?? '')), 0, 128),
                'created_at'    => date('Y-m-d H:i:s'),
            ]));
            $n++;
        }
        return $n;
    }

    /** @return list<array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        return $this->db->query(
            "SELECT * FROM `#__store_serials`
              WHERE `order_id` = ? AND `tenant_id` = ?
              ORDER BY `id` ASC",
            [$orderId, $this->tenantId()]
        )->rows;
    }

    /**
     * Items that require serial capture based on product shipping_json.
     *
     * @param array<int, array<string, mixed>> $items
     * @return list<array{item_id:int, variant_id:int, sku:string, qty:int}>
     */
    public function requiredSerialItems(array $items): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? 0);
            $vid = (int) ($item['variant_id'] ?? $item['resolved_variant_id'] ?? 0);
            $ordered = max(0, (int) ($item['qty'] ?? $item['order_qty'] ?? 0));
            $shipped = max(0, (int) ($item['qty_shipped'] ?? 0));
            $remain = max(0, $ordered - $shipped);
            if ($itemId <= 0 || $remain <= 0) {
                continue;
            }
            if (!$this->variantRequiresSerial($vid, $item)) {
                continue;
            }
            $out[] = [
                'item_id'    => $itemId,
                'variant_id' => $vid,
                'sku'        => (string) ($item['external_sku'] ?? ''),
                'qty'        => $remain,
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $item */
    public function variantRequiresSerial(int $variantId, array $item = []): bool
    {
        if ($variantId <= 0 || !TenantContext::get()->has()) {
            return false;
        }
        $row = $this->db->query(
            "SELECT v.`shipping_json` AS v_ship, p.`shipping_json` AS p_ship
               FROM `#__store_product_variants` v
               JOIN `#__store_products` p ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
              WHERE v.`id` = ? AND v.`tenant_id` = ?
              LIMIT 1",
            [$variantId, $this->tenantId()]
        )->row;
        if ($row === []) {
            return false;
        }
        foreach ([$row['v_ship'] ?? null, $row['p_ship'] ?? null] as $raw) {
            $decoded = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
            if (!empty($decoded['record_serial_on_despatch'])) {
                return true;
            }
        }
        return false;
    }
}
