<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

/**
 * Sum line weights → order_weight_kg and suggest packaging_size_id.
 */
final class OrderPackagingService
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return array{order_weight_kg: float, packaging_size_id: int|null}
     */
    public function suggestAndPersist(int $orderId, bool $overwritePackaging = false): array
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return ['order_weight_kg' => 0.0, 'packaging_size_id' => null];
        }

        $order = $this->db->query(
            "SELECT `id`, `order_weight_kg`, `packaging_size_id`
               FROM `#__store_orders`
              WHERE `id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$orderId, $this->tenantId()]
        )->row;
        if ($order === [] || $order === null) {
            return ['order_weight_kg' => 0.0, 'packaging_size_id' => null];
        }

        $weightKg = $this->sumWeightKg($orderId);
        $packagingId = (int) ($order['packaging_size_id'] ?? 0);
        if ($overwritePackaging || $packagingId <= 0) {
            $suggested = $this->suggestPackagingId($weightKg);
            if ($suggested !== null) {
                $packagingId = $suggested;
            }
        }

        $payload = [
            'order_weight_kg' => $weightKg,
        ];
        if ($packagingId > 0) {
            $payload['packaging_size_id'] = $packagingId;
        }
        $this->db->update('store_orders', $payload, $this->withTenant(['id' => $orderId]));

        return [
            'order_weight_kg'   => $weightKg,
            'packaging_size_id' => $packagingId > 0 ? $packagingId : null,
        ];
    }

    public function sumWeightKg(int $orderId): float
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return 0.0;
        }
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
        return round(((float) ($row['grams'] ?? 0)) / 1000, 3);
    }

    public function suggestPackagingId(float $weightKg): ?int
    {
        $sizes = (new PackagingSizeRepository($this->db))->all();
        if ($sizes === []) {
            return null;
        }

        // Prefer smallest capacity that fits weight (when capacity > 0); else first by sort.
        $candidates = [];
        foreach ($sizes as $size) {
            $cap = (float) ($size['weight_capacity_kg'] ?? 0);
            if ($cap > 0 && $weightKg > $cap) {
                continue;
            }
            $candidates[] = $size;
        }
        if ($candidates === []) {
            $candidates = $sizes;
        }

        usort($candidates, static function (array $a, array $b): int {
            $capA = (float) ($a['weight_capacity_kg'] ?? 0);
            $capB = (float) ($b['weight_capacity_kg'] ?? 0);
            if ($capA > 0 && $capB > 0 && $capA !== $capB) {
                return $capA <=> $capB;
            }
            $sortA = (int) ($a['sort_order'] ?? 0);
            $sortB = (int) ($b['sort_order'] ?? 0);
            if ($sortA !== $sortB) {
                return $sortA <=> $sortB;
            }
            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        return (int) ($candidates[0]['id'] ?? 0) ?: null;
    }
}
