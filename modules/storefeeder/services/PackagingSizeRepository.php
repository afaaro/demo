<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;
use System\Library\Database;

final class PackagingSizeRepository
{
    use TenantScoped;

    public const RM_TYPES = [
        'Letter'       => 'Letter',
        'Large Letter' => 'Large Letter',
        'Parcel'       => 'Parcel',
        'Packet'       => 'Packet',
    ];

    public function __construct(private Database $db) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        return $this->db->query(
            "SELECT * FROM `#__sf_packaging_sizes`
              WHERE `tenant_id` = ?
              ORDER BY `sort_order` ASC, `size_code` ASC, `id` ASC",
            [$this->tenantId()]
        )->rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('sf_packaging_sizes', $this->withTenant(['id' => $id]));
        return $row === [] ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Packaging name is required.');
        }
        $this->db->insert('sf_packaging_sizes', $this->withTenant($this->normalize($data, $name)));
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($this->find($id) === null) {
            throw new RuntimeException('Packaging size not found.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Packaging name is required.');
        }
        $this->db->update(
            'sf_packaging_sizes',
            $this->normalize($data, $name),
            $this->withTenant(['id' => $id])
        );
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "DELETE FROM `#__sf_packaging_sizes` WHERE `id` = ? AND `tenant_id` = ?",
            [$id, $this->tenantId()]
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, string $name): array
    {
        $rm = trim((string) ($data['rm_packaging_type'] ?? 'Parcel'));
        if (!isset(self::RM_TYPES[$rm])) {
            $rm = 'Parcel';
        }
        return [
            'name'                => $name,
            'size_code'           => (int) ($data['size_code'] ?? 0),
            'packaging_weight_kg' => round((float) ($data['packaging_weight_kg'] ?? 0), 3),
            'length_mm'           => max(0, (int) ($data['length_mm'] ?? 0)),
            'width_mm'            => max(0, (int) ($data['width_mm'] ?? 0)),
            'height_mm'           => max(0, (int) ($data['height_mm'] ?? 0)),
            'packaging_cost'      => round((float) ($data['packaging_cost'] ?? 0), 2),
            'rm_packaging_type'   => $rm,
            'include_weight'      => !empty($data['include_weight']) ? 1 : 0,
            'weight_capacity_kg'  => round((float) ($data['weight_capacity_kg'] ?? 0), 3),
            'sort_order'          => (int) ($data['sort_order'] ?? $data['size_code'] ?? 0),
        ];
    }
}
