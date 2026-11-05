<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;
use System\Library\Database;

final class ShippingMethodRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        return $this->db->query(
            "SELECT * FROM `#__sf_shipping_methods`
              WHERE `tenant_id` = ?
              ORDER BY `company` ASC, `method` ASC, `id` ASC",
            [$this->tenantId()]
        )->rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('sf_shipping_methods', $this->withTenant(['id' => $id]));
        return $row === [] ? null : $row;
    }

    public function create(string $company, string $method, float $cost = 0, string $carrierCode = ''): int
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $method = trim($method);
        if ($method === '') {
            throw new RuntimeException('Shipping method name is required.');
        }
        $this->db->insert('sf_shipping_methods', $this->withTenant([
            'company'      => trim($company),
            'method'       => $method,
            'cost'         => round($cost, 2),
            'carrier_code' => strtoupper(trim($carrierCode)),
            'is_default'   => 0,
            'enabled'      => 1,
        ]));
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($this->find($id) === null) {
            throw new RuntimeException('Shipping method not found.');
        }
        $payload = [];
        if (array_key_exists('company', $data)) {
            $payload['company'] = trim((string) $data['company']);
        }
        if (array_key_exists('method', $data)) {
            $method = trim((string) $data['method']);
            if ($method === '') {
                throw new RuntimeException('Shipping method name is required.');
            }
            $payload['method'] = $method;
        }
        if (array_key_exists('cost', $data)) {
            $payload['cost'] = round((float) $data['cost'], 2);
        }
        if (array_key_exists('carrier_code', $data)) {
            $payload['carrier_code'] = strtoupper(trim((string) $data['carrier_code']));
        }
        if (array_key_exists('enabled', $data)) {
            $payload['enabled'] = !empty($data['enabled']) ? 1 : 0;
        }
        if ($payload === []) {
            return;
        }
        $this->db->update('sf_shipping_methods', $payload, $this->withTenant(['id' => $id]));
    }

    public function setDefault(int $id): void
    {
        if (!TenantContext::get()->has()) {
            return;
        }
        if ($this->find($id) === null) {
            throw new RuntimeException('Shipping method not found.');
        }
        $tid = $this->tenantId();
        $this->db->query(
            "UPDATE `#__sf_shipping_methods` SET `is_default` = 0 WHERE `tenant_id` = ?",
            [$tid]
        );
        $this->db->update('sf_shipping_methods', ['is_default' => 1], $this->withTenant(['id' => $id]));
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "DELETE FROM `#__sf_shipping_methods` WHERE `id` = ? AND `tenant_id` = ?",
            [$id, $this->tenantId()]
        );
    }
}
