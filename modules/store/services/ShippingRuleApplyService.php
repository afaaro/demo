<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

/**
 * Evaluate shipping rules and persist mapped method + preferred courier on orders.
 */
final class ShippingRuleApplyService
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @param array<string, mixed> $order store_orders row (or partial)
     * @return array{mapped_shipping_method: string, preferred_courier: string, method_id: int}|null
     */
    public function applyToOrderId(int $orderId): ?array
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_orders` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
            [$orderId, $this->tenantId()]
        )->row;
        if ($row === [] || $row === null) {
            return null;
        }
        return $this->applyToOrder($row);
    }

    /**
     * @param array<string, mixed> $order
     * @return array{mapped_shipping_method: string, preferred_courier: string, method_id: int}|null
     */
    public function applyToOrder(array $order): ?array
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        $addr = $order['shipping_address'] ?? [];
        if (is_string($addr)) {
            $decoded = json_decode($addr, true);
            $addr = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($addr)) {
            $addr = [];
        }

        $country = strtoupper(trim((string) ($addr['countryCode'] ?? $addr['country'] ?? 'GB')));
        $postcode = trim((string) ($addr['postalCode'] ?? $addr['postcode'] ?? ''));
        $weightKg = (float) ($order['order_weight_kg'] ?? 0);
        if ($weightKg <= 0) {
            $weightKg = $this->estimateWeightKg($orderId);
        }
        $total = (float) ($order['total'] ?? 0);

        $rules = new ShippingRuleRepository($this->db);
        $regions = new ShippingRegionRepository($this->db);
        $match = $rules->match($regions, $country, $postcode, $weightKg, $total);
        if ($match === null) {
            return null;
        }

        $methodId = (int) ($match['method_id'] ?? 0);
        $methods = new ShippingMethodRepository($this->db);
        $method = $methods->find($methodId);
        if ($method === null || empty($method['enabled'])) {
            return null;
        }

        $mapped = trim((string) ($method['company'] ?? ''));
        $mapped = ($mapped !== '' ? $mapped . ' — ' : '') . trim((string) ($method['method'] ?? ''));
        $mapped = mb_substr($mapped !== '' ? $mapped : (string) ($match['name'] ?? ''), 0, 128);

        $carrier = strtolower(trim((string) ($method['carrier_code'] ?? '')));
        $preferred = $this->normalizeCourierType($carrier, $mapped);

        $this->db->update('store_orders', [
            'mapped_shipping_method' => $mapped,
            'preferred_courier'      => $preferred,
        ], $this->withTenant(['id' => $orderId]));

        return [
            'mapped_shipping_method' => $mapped,
            'preferred_courier'      => $preferred,
            'method_id'              => $methodId,
        ];
    }

    private function estimateWeightKg(int $orderId): float
    {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(
                      GREATEST(
                        COALESCE(NULLIF(v.`weight_grams`, 0), NULLIF(p.`weight_grams`, 0), 0)
                      ) * i.`qty`
                    ), 0) AS grams
               FROM `#__store_order_items` i
               LEFT JOIN `#__store_product_variants` v
                 ON v.`id` = i.`variant_id` AND v.`tenant_id` = i.`tenant_id`
               LEFT JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = i.`tenant_id`
              WHERE i.`order_id` = ? AND i.`tenant_id` = ?",
            [$orderId, $this->tenantId()]
        )->row;
        $grams = (float) ($row['grams'] ?? 0);
        return $grams > 0 ? round($grams / 1000, 3) : 0.0;
    }

    private function normalizeCourierType(string $carrierCode, string $mappedLabel): string
    {
        $hay = strtolower($carrierCode . ' ' . $mappedLabel);
        if (str_contains($hay, 'evri') || str_contains($hay, 'hermes')) {
            return 'evri';
        }
        if (str_contains($hay, 'royal') || $carrierCode === 'rm' || str_contains($hay, 'royal_mail')) {
            return 'royalmail';
        }
        if (str_contains($hay, 'parcel') || str_contains($hay, 'pfw')) {
            return 'parcelforce';
        }
        if (str_contains($hay, 'dpd')) {
            return 'dpd';
        }
        return match (strtolower($carrierCode)) {
            'evri', 'hermes' => 'evri',
            'royalmail', 'royal_mail', 'rm' => 'royalmail',
            'parcelforce', 'pf' => 'parcelforce',
            'dpd' => 'dpd',
            default => $carrierCode !== '' ? preg_replace('/[^a-z0-9]/', '', strtolower($carrierCode)) ?? '' : '',
        };
    }
}
