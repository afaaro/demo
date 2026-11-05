<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;
use System\Library\Database;

final class ShippingRegionRepository
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
            "SELECT * FROM `#__sf_shipping_regions` WHERE `tenant_id` = ? ORDER BY `name` ASC",
            [$this->tenantId()]
        )->rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('sf_shipping_regions', $this->withTenant(['id' => $id]));
        return $row === [] ? null : $row;
    }

    public function create(string $name, string $countries = '', string $postcodes = '', string $notes = ''): int
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Region name is required.');
        }
        $this->db->insert('sf_shipping_regions', $this->withTenant([
            'name'      => $name,
            'countries' => $this->normalizeList($countries),
            'postcodes' => $this->normalizeList($postcodes),
            'notes'     => mb_substr(trim($notes), 0, 255),
        ]));
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($this->find($id) === null) {
            throw new RuntimeException('Shipping region not found.');
        }
        $payload = [];
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new RuntimeException('Region name is required.');
            }
            $payload['name'] = $name;
        }
        if (array_key_exists('countries', $data)) {
            $payload['countries'] = $this->normalizeList((string) $data['countries']);
        }
        if (array_key_exists('postcodes', $data)) {
            $payload['postcodes'] = $this->normalizeList((string) $data['postcodes']);
        }
        if (array_key_exists('notes', $data)) {
            $payload['notes'] = mb_substr(trim((string) $data['notes']), 0, 255);
        }
        if ($payload === []) {
            return;
        }
        $this->db->update('sf_shipping_regions', $payload, $this->withTenant(['id' => $id]));
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "DELETE FROM `#__sf_shipping_regions` WHERE `id` = ? AND `tenant_id` = ?",
            [$id, $this->tenantId()]
        );
    }

    /** Does country/postcode fall in this region? Empty countries = catch-all (ROW). */
    public function matches(array $region, string $country, string $postcode): bool
    {
        $country = strtoupper(trim($country));
        $postcode = strtoupper(preg_replace('/\s+/', '', $postcode) ?? $postcode);

        $countries = $this->splitList((string) ($region['countries'] ?? ''));
        $postcodes = $this->splitList((string) ($region['postcodes'] ?? ''));

        if ($countries !== [] && ($country === '' || !in_array($country, $countries, true))) {
            return false;
        }

        if ($postcodes !== []) {
            if ($postcode === '') {
                return false;
            }
            $ok = false;
            foreach ($postcodes as $prefix) {
                $prefix = strtoupper(preg_replace('/\s+/', '', $prefix) ?? $prefix);
                if ($prefix !== '' && str_starts_with($postcode, $prefix)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private function normalizeList(string $raw): string
    {
        $parts = preg_split('/[\s,;]+/', strtoupper(trim($raw))) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts)));
        return implode(',', $parts);
    }

    /** @return list<string> */
    private function splitList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', strtoupper($raw)))));
    }
}
