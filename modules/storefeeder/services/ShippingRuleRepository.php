<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;
use System\Library\Database;

final class ShippingRuleRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        return $this->db->query(
            "SELECT r.*,
                    m.`company` AS method_company,
                    m.`method`  AS method_name,
                    g.`name`    AS region_name
               FROM `#__sf_shipping_rules` r
               INNER JOIN `#__sf_shipping_methods` m
                 ON m.`id` = r.`method_id` AND m.`tenant_id` = r.`tenant_id`
               LEFT JOIN `#__sf_shipping_regions` g
                 ON g.`id` = r.`region_id` AND g.`tenant_id` = r.`tenant_id`
              WHERE r.`tenant_id` = ?
              ORDER BY r.`priority` ASC, r.`id` ASC",
            [$tid]
        )->rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $tid = $this->tenantId();
        $row = $this->db->query(
            "SELECT r.*,
                    m.`company` AS method_company,
                    m.`method`  AS method_name,
                    g.`name`    AS region_name
               FROM `#__sf_shipping_rules` r
               INNER JOIN `#__sf_shipping_methods` m
                 ON m.`id` = r.`method_id` AND m.`tenant_id` = r.`tenant_id`
               LEFT JOIN `#__sf_shipping_regions` g
                 ON g.`id` = r.`region_id` AND g.`tenant_id` = r.`tenant_id`
              WHERE r.`id` = ? AND r.`tenant_id` = ?
              LIMIT 1",
            [$id, $tid]
        )->row;
        return $row === [] || $row === null ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $methodId = (int) ($data['method_id'] ?? 0);
        if ($methodId <= 0) {
            throw new RuntimeException('Shipping method is required.');
        }
        $this->db->insert('sf_shipping_rules', $this->withTenant([
            'name'            => trim((string) ($data['name'] ?? '')),
            'region_id'       => $this->nullableInt($data['region_id'] ?? null),
            'method_id'       => $methodId,
            'min_weight_kg'   => $this->nullableFloat($data['min_weight_kg'] ?? null),
            'max_weight_kg'   => $this->nullableFloat($data['max_weight_kg'] ?? null),
            'min_order_total' => $this->nullableFloat($data['min_order_total'] ?? null),
            'max_order_total' => $this->nullableFloat($data['max_order_total'] ?? null),
            'priority'        => (int) ($data['priority'] ?? 100),
            'enabled'         => !empty($data['enabled']) ? 1 : 0,
        ]));
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($this->find($id) === null) {
            throw new RuntimeException('Shipping rule not found.');
        }
        $methodId = (int) ($data['method_id'] ?? 0);
        if ($methodId <= 0) {
            throw new RuntimeException('Shipping method is required.');
        }
        $this->db->update('sf_shipping_rules', [
            'name'            => trim((string) ($data['name'] ?? '')),
            'region_id'       => $this->nullableInt($data['region_id'] ?? null),
            'method_id'       => $methodId,
            'min_weight_kg'   => $this->nullableFloat($data['min_weight_kg'] ?? null),
            'max_weight_kg'   => $this->nullableFloat($data['max_weight_kg'] ?? null),
            'min_order_total' => $this->nullableFloat($data['min_order_total'] ?? null),
            'max_order_total' => $this->nullableFloat($data['max_order_total'] ?? null),
            'priority'        => (int) ($data['priority'] ?? 100),
            'enabled'         => !empty($data['enabled']) ? 1 : 0,
        ], $this->withTenant(['id' => $id]));
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "DELETE FROM `#__sf_shipping_rules` WHERE `id` = ? AND `tenant_id` = ?",
            [$id, $this->tenantId()]
        );
    }

    /**
     * First matching enabled rule by priority.
     *
     * @return array<string, mixed>|null
     */
    public function match(
        ShippingRegionRepository $regions,
        string $country,
        string $postcode,
        float $weightKg,
        float $orderTotal,
    ): ?array {
        foreach ($this->all() as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            $regionId = (int) ($rule['region_id'] ?? 0);
            if ($regionId > 0) {
                $region = $regions->find($regionId);
                if ($region === null || !$regions->matches($region, $country, $postcode)) {
                    continue;
                }
            }
            $minW = $rule['min_weight_kg'];
            $maxW = $rule['max_weight_kg'];
            if ($minW !== null && $weightKg < (float) $minW) {
                continue;
            }
            if ($maxW !== null && $weightKg > (float) $maxW) {
                continue;
            }
            $minT = $rule['min_order_total'];
            $maxT = $rule['max_order_total'];
            if ($minT !== null && $orderTotal < (float) $minT) {
                continue;
            }
            if ($maxT !== null && $orderTotal > (float) $maxT) {
                continue;
            }
            return $rule;
        }
        return null;
    }

    private function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '' || (int) $v <= 0) {
            return null;
        }
        return (int) $v;
    }

    private function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        return round((float) $v, 3);
    }
}
