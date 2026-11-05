<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class ProductBundleRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return array<int, array{
     *   id:int, product_id:int, component_product_id:int, component_sku:string,
     *   component_name:string, qty:int, delete_original:bool, update_kit_inventory:bool,
     *   created_at:string
     * }>
     */
    public function forProduct(int $productId): array
    {
        if ($productId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();

        $rows = $this->db->query(
            "SELECT b.*, p.`name` AS component_name, p.`sku` AS live_sku
               FROM `#__sf_product_bundle_components` b
               JOIN `#__sf_products` p
                 ON p.`id` = b.`component_product_id` AND p.`tenant_id` = b.`tenant_id`
              WHERE b.`product_id` = ? AND b.`tenant_id` = ?
              ORDER BY b.`id` ASC",
            [$productId, $tid]
        )->rows;

        return array_map(static function (array $r): array {
            return [
                'id'                   => (int) $r['id'],
                'product_id'           => (int) $r['product_id'],
                'component_product_id' => (int) $r['component_product_id'],
                'component_sku'        => (string) ($r['component_sku'] !== '' ? $r['component_sku'] : $r['live_sku']),
                'component_name'       => (string) ($r['component_name'] ?? ''),
                'qty'                  => max(1, (int) $r['qty']),
                'delete_original'      => !empty($r['delete_original']),
                'update_kit_inventory' => !empty($r['update_kit_inventory']),
                'created_at'           => (string) ($r['created_at'] ?? ''),
            ];
        }, $rows);
    }

    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('sf_product_bundle_components', $this->withTenant(['id' => $id]));
        if ($row === []) {
            return null;
        }
        return [
            'id'                   => (int) $row['id'],
            'product_id'           => (int) $row['product_id'],
            'component_product_id' => (int) $row['component_product_id'],
            'component_sku'        => (string) ($row['component_sku'] ?? ''),
            'qty'                  => max(1, (int) ($row['qty'] ?? 1)),
            'delete_original'      => !empty($row['delete_original']),
            'update_kit_inventory' => !empty($row['update_kit_inventory']),
            'created_at'           => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @return array{id:int, product_id:int, component_product_id:int, component_sku:string, qty:int, delete_original:bool, update_kit_inventory:bool, created_at:string}
     */
    public function add(
        int $productId,
        int $componentProductId,
        string $componentSku,
        int $qty,
        bool $deleteOriginal,
        bool $updateKitInventory,
    ): array {
        if ($productId <= 0 || $componentProductId <= 0) {
            throw new InvalidArgumentException('Invalid product.');
        }
        if ($productId === $componentProductId) {
            throw new InvalidArgumentException('A product cannot be a component of itself.');
        }
        $qty = max(1, $qty);

        if (!TenantContext::get()->has()) {
            throw new InvalidArgumentException('Tenant context is required.');
        }
        $tid = $this->tenantId();
        $existing = $this->db->query(
            "SELECT `id` FROM `#__sf_product_bundle_components`
              WHERE `product_id` = ? AND `component_product_id` = ? AND `tenant_id` = ? LIMIT 1",
            [$productId, $componentProductId, $tid]
        )->row;
        if ($existing !== []) {
            throw new RuntimeException('That component is already in the bundle.');
        }

        // Prevent simple cycles: component already lists this product as its component.
        $cycle = $this->db->query(
            "SELECT `id` FROM `#__sf_product_bundle_components`
              WHERE `product_id` = ? AND `component_product_id` = ? AND `tenant_id` = ? LIMIT 1",
            [$componentProductId, $productId, $tid]
        )->row;
        if ($cycle !== []) {
            throw new RuntimeException('That would create a circular kit relationship.');
        }

        $id = $this->db->insert('sf_product_bundle_components', $this->withTenant([
            'product_id'           => $productId,
            'component_product_id' => $componentProductId,
            'component_sku'        => mb_substr(trim($componentSku), 0, 64),
            'qty'                  => $qty,
            'delete_original'      => $deleteOriginal ? 1 : 0,
            'update_kit_inventory' => $updateKitInventory ? 1 : 0,
            'created_at'           => date('Y-m-d H:i:s'),
        ]));

        return $this->find((int) $id)
            ?? throw new RuntimeException('Failed to reload bundle component.');
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        if (!TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            'DELETE FROM `#__sf_product_bundle_components` WHERE `id` = ? AND `tenant_id` = ?',
            [$id, $this->tenantId()]
        );
    }

    public function deleteForProduct(int $productId, int $id): bool
    {
        $row = $this->find($id);
        if ($row === null || (int) $row['product_id'] !== $productId) {
            return false;
        }
        $this->delete($id);
        return true;
    }

    public function update(
        int $productId,
        int $id,
        int $qty,
        bool $deleteOriginal,
        bool $updateKitInventory,
    ): bool {
        $row = $this->find($id);
        if ($row === null || (int) $row['product_id'] !== $productId) {
            return false;
        }
        $this->db->update('sf_product_bundle_components', [
            'qty'                  => max(1, $qty),
            'delete_original'      => $deleteOriginal ? 1 : 0,
            'update_kit_inventory' => $updateKitInventory ? 1 : 0,
        ], $this->withTenant(['id' => $id]));
        return true;
    }
}
