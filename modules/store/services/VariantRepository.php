<?php
declare(strict_types=1);

namespace Module\Store\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class VariantRepository
{
    use TenantScoped;

    public function __construct(private Database $db)
    {
        PackInventory::ensureColumn($db);
    }

    // --------------------------------------------------------------
    // Reads
    // --------------------------------------------------------------

    /** @return array<int, Variant> */
    public function forProduct(int $productId): array
    {
        if ($productId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT * FROM `#__store_product_variants`
              WHERE `product_id` = ? AND `tenant_id` = ?
              ORDER BY `id` ASC",
            [$productId, $tid]
        )->rows;

        if ($rows === []) {
            return [];
        }

        $variants = array_map(fn($r) => $this->hydrateVariant($r), $rows);
        $ids = array_map(fn(Variant $v) => $v->id, $variants);

        // Batch-load attribute assignments.
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $assignments = $this->db->query(
            "SELECT vav.`variant_id`, vav.`attribute_id`, vav.`attribute_value_id`,
                    a.`code`, a.`name`,
                    av.`value`, av.`label`
               FROM `#__store_variant_attribute_values` vav
               JOIN `#__store_attributes` a
                 ON a.`id` = vav.`attribute_id` AND a.`tenant_id` = vav.`tenant_id`
               JOIN `#__store_attribute_values` av
                 ON av.`id` = vav.`attribute_value_id` AND av.`tenant_id` = vav.`tenant_id`
              WHERE vav.`tenant_id` = ? AND vav.`variant_id` IN ({$placeholders})",
            array_merge([$tid], array_values($ids))
        )->rows;

        $byVariant = [];
        foreach ($assignments as $row) {
            $byVariant[(int) $row['variant_id']][] = [
                'attribute_id' => (int) $row['attribute_id'],
                'value_id'     => (int) $row['attribute_value_id'],
                'code'         => (string) $row['code'],
                'name'         => (string) $row['name'],
                'value'        => (string) $row['value'],
                'label'        => (string) $row['label'],
            ];
        }

        foreach ($variants as $v) {
            $rows = $byVariant[$v->id] ?? [];
            $keyed = [];
            foreach ($rows as $r) {
                $keyed[$r['attribute_id']] = $r;
            }
            $v->attributes = $keyed;
        }

        return $variants;
    }

    public function find(int $id): ?Variant
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $tid = $this->tenantId();
        $row = $this->db->first('store_product_variants', $this->withTenant(['id' => $id]));
        if ($row === []) {
            return null;
        }
        $v = $this->hydrateVariant($row);
        // Load attributes.
        $assignments = $this->db->query(
            "SELECT vav.`attribute_id`, vav.`attribute_value_id`,
                    a.`code`, a.`name`,
                    av.`value`, av.`label`
               FROM `#__store_variant_attribute_values` vav
               JOIN `#__store_attributes` a
                 ON a.`id` = vav.`attribute_id` AND a.`tenant_id` = vav.`tenant_id`
               JOIN `#__store_attribute_values` av
                 ON av.`id` = vav.`attribute_value_id` AND av.`tenant_id` = vav.`tenant_id`
              WHERE vav.`variant_id` = ? AND vav.`tenant_id` = ?",
            [$id, $tid]
        )->rows;

        foreach ($assignments as $r) {
            $v->attributes[(int) $r['attribute_id']] = [
                'attribute_id' => (int) $r['attribute_id'],
                'value_id'     => (int) $r['attribute_value_id'],
                'code'         => (string) $r['code'],
                'name'         => (string) $r['name'],
                'value'        => (string) $r['value'],
                'label'        => (string) $r['label'],
            ];
        }

        return $v;
    }

    // --------------------------------------------------------------
    // Writes
    // --------------------------------------------------------------

    /**
     * Replace the full set of variants for a product.
     *
     * @param array<int, array{
     *     id?: int|null, sku: string, name?: string, pack_qty?: int,
     *     price?: float|string, cost?: float|string, active?: bool,
     *     attributes?: array<int, int>   // [attribute_id => value_id]
     * }> $variants
     */
    public function saveForProduct(int $productId, array $variants): void
    {
        if ($productId <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $existingIds = array_map(
            fn(Variant $v) => (int) $v->id,
            $this->forProduct($productId)
        );

        $kept = [];

        foreach ($variants as $v) {
            $sku = trim((string) ($v['sku'] ?? ''));
            if ($sku === '') {
                continue;   // skip blank rows
            }

            $attrs = $v['attributes'] ?? [];
            if (!is_array($attrs)) {
                $attrs = [];
            }

            $row = [
                'product_id'   => $productId,
                'sku'          => $sku,
                'name'         => (string) ($v['name'] ?? ''),
                'pack_qty'     => max(0, min(20000, (int) ($v['pack_qty'] ?? 0))),
                'pick_bin'     => mb_substr(trim((string) ($v['pick_bin'] ?? '')), 0, 64),
                'price'        => (float)  ($v['price'] ?? 0),
                'cost'         => (float)  ($v['cost']  ?? 0),
                'weight_grams' => max(0, (int) ($v['weight_grams'] ?? 0)),
                'active'       => array_key_exists('active', $v) ? (bool) $v['active'] : true,
            ];

            $id = isset($v['id']) ? (int) $v['id'] : 0;

            if ($id > 0 && in_array($id, $existingIds, true)) {
                $this->db->update('store_product_variants', $row, $this->withTenant(['id' => $id]));
            } else {
                $id = $this->db->insert('store_product_variants', $this->withTenant($row));
            }

            $this->db->query(
                "INSERT IGNORE INTO `#__store_inventory` (`variant_id`, `tenant_id`, `quantity`, `reserved`, `reorder_at`)
                VALUES (?, ?, 0, 0, 0)",
                [$id, $tid]
            );

            // Replace attribute assignments for this variant.
            $this->db->query(
                "DELETE FROM `#__store_variant_attribute_values` WHERE `variant_id` = ? AND `tenant_id` = ?",
                [$id, $tid]
            );

            foreach ($attrs as $attributeId => $valueId) {
                $attributeId = (int) $attributeId;
                $valueId     = (int) $valueId;
                if ($attributeId <= 0 || $valueId <= 0) {
                    continue;
                }
                $this->db->query(
                    "INSERT INTO `#__store_variant_attribute_values`
                        (`variant_id`, `attribute_id`, `attribute_value_id`, `tenant_id`)
                     VALUES (?, ?, ?, ?)",
                    [$id, $attributeId, $valueId, $tid]
                );
            }

            $kept[] = $id;
        }

        // Delete variants not in the submission.
        $toDelete = array_diff($existingIds, $kept);
        foreach ($toDelete as $id) {
            $this->delete($id);
        }
    }

    public function delete(int $variantId): void
    {
        if ($variantId <= 0 || !TenantContext::get()->has()) {
            return;
        }
        // Cascades remove the attribute assignments.
        $this->db->query(
            "DELETE FROM `#__store_product_variants` WHERE `id` = ? AND `tenant_id` = ?",
            [$variantId, $this->tenantId()]
        );
    }

    /**
     * Merge selected shipping keys onto every variant of a product.
     *
     * @param array<string, mixed> $shipping Full product shipping payload
     * @param array<int, string>   $keys     Keys to copy
     * @return int Number of variants updated
     */
    public function mergeShippingForProduct(int $productId, array $shipping, array $keys): int
    {
        if ($productId <= 0 || $keys === []) {
            return 0;
        }

        $allowed = array_fill_keys($keys, true);
        $patch = [];
        foreach ($shipping as $key => $value) {
            if (isset($allowed[$key])) {
                $patch[$key] = $value;
            }
        }
        if ($patch === []) {
            return 0;
        }

        $updated = 0;
        foreach ($this->forProduct($productId) as $variant) {
            if ($variant->id === null) {
                continue;
            }
            $merged = array_merge($variant->shipping, $patch);
            $this->db->update('store_product_variants', [
                'shipping_json' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $this->withTenant(['id' => (int) $variant->id]));
            $updated++;
        }

        return $updated;
    }

    /**
     * Copy selected product-level sellable fields onto every variant.
     *
     * @param array{price?: float, cost?: float, weight_grams?: int, name?: string} $fields
     * @param array<int, string> $keys  Subset of: price, cost, weight_grams, name
     * @return int Number of variants updated
     */
    public function mergeProductFieldsForProduct(int $productId, array $fields, array $keys): int
    {
        if ($productId <= 0 || $keys === []) {
            return 0;
        }

        $patch = [];
        foreach ($keys as $key) {
            if ($key === 'price' && array_key_exists('price', $fields)) {
                $patch['price'] = round((float) $fields['price'], 2);
            } elseif ($key === 'cost' && array_key_exists('cost', $fields)) {
                $patch['cost'] = round((float) $fields['cost'], 2);
            } elseif ($key === 'weight_grams' && array_key_exists('weight_grams', $fields)) {
                $patch['weight_grams'] = max(0, (int) $fields['weight_grams']);
            } elseif ($key === 'name' && array_key_exists('name', $fields)) {
                $patch['name'] = mb_substr(trim((string) $fields['name']), 0, 255);
            }
        }
        if ($patch === []) {
            return 0;
        }

        $updated = 0;
        foreach ($this->forProduct($productId) as $variant) {
            if ($variant->id === null) {
                continue;
            }
            $this->db->update('store_product_variants', $patch, $this->withTenant(['id' => (int) $variant->id]));
            $updated++;
        }

        return $updated;
    }

    /**
     * Ensure a simple (no-attribute) product has one sellable variant for stock.
     * Does nothing if any variant already exists.
     *
     * @param array{sku?:string,name?:string,price?:float,cost?:float,active?:bool} $data
     */
    public function ensureDefaultVariant(int $productId, array $data): Variant
    {
        $existing = $this->forProduct($productId);
        if ($existing !== []) {
            return $existing[0];
        }

        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku !== '') {
            $bySku = $this->findBySku($sku);
            if ($bySku !== null) {
                if ((int) $bySku->productId === $productId) {
                    return $bySku;
                }
                // Tenant-wide unique — mint a free SKU for this product.
                $sku = $this->allocateUniqueSku($sku, $productId);
            }
        }

        return $this->create($productId, [
            'sku'        => $sku,
            'name'       => (string) ($data['name'] ?? ''),
            'price'      => (float)  ($data['price'] ?? 0),
            'cost'       => (float)  ($data['cost'] ?? 0),
            'active'     => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'attributes' => [],
        ]);
    }

    public function create(int $productId, array $data): Variant
    {
        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku === '') {
            throw new InvalidArgumentException('Variant SKU is required.');
        }

        // Tenant-wide unique key uq_tenant_variant_sku.
        $existingAny = $this->findBySku($sku);
        if ($existingAny !== null) {
            if ((int) $existingAny->productId === $productId) {
                return $existingAny;
            }
            throw new RuntimeException(
                "Variant SKU '{$sku}' already exists on another product."
            );
        }

        // Reject duplicate SKU within the same product.
        $existing = $this->findBySkuAndProduct($productId, $sku);
        if ($existing !== null) {
            return $existing;
        }

        $tid = $this->tenantId();
        $id = $this->db->transaction(function (Database $db) use ($productId, $data, $sku, $tid) {
            $id = $db->insert('store_product_variants', $this->withTenant([
                'product_id'   => $productId,
                'sku'          => $sku,
                'name'         => (string) ($data['name'] ?? ''),
                'pack_qty'     => max(0, min(20000, (int) ($data['pack_qty'] ?? 0))),
                'pick_bin'     => mb_substr(trim((string) ($data['pick_bin'] ?? '')), 0, 64),
                'price'        => (float)  ($data['price'] ?? 0),
                'cost'         => (float)  ($data['cost']  ?? 0),
                'weight_grams' => max(0, (int) ($data['weight_grams'] ?? 0)),
                'active'       => !empty($data['active']) ? 1 : 0,
            ]));

            $db->query(
                "INSERT IGNORE INTO `#__store_inventory`
                (`variant_id`, `tenant_id`, `quantity`, `reserved`, `reorder_at`)
                VALUES (?, ?, 0, 0, 0)",
                [$id, $tid]
            );

            foreach (($data['attributes'] ?? []) as $attrId => $valueId) {
                $attrId  = (int) $attrId;
                $valueId = (int) $valueId;
                if ($attrId > 0 && $valueId > 0) {
                    $db->query(
                        "INSERT INTO `#__store_variant_attribute_values`
                        (`variant_id`, `attribute_id`, `attribute_value_id`, `tenant_id`)
                        VALUES (?, ?, ?, ?)",
                        [$id, $attrId, $valueId, $tid]
                    );
                }
            }

            return $id;
        });

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload variant.');
    }

    public function update(int $variantId, array $data): Variant
    {
        $tid = $this->tenantId();
        $this->db->transaction(function (Database $db) use ($variantId, $data, $tid) {
            $row = [
                'sku'          => (string) ($data['sku']    ?? ''),
                'name'         => (string) ($data['name']   ?? ''),
                'pick_bin'     => mb_substr(trim((string) ($data['pick_bin'] ?? '')), 0, 64),
                'price'        => (float)  ($data['price']  ?? 0),
                'cost'         => (float)  ($data['cost']   ?? 0),
                'weight_grams' => max(0, (int) ($data['weight_grams'] ?? 0)),
                'active'       => !empty($data['active']) ? 1 : 0,
            ];
            if (array_key_exists('pack_qty', $data)) {
                $row['pack_qty'] = max(0, min(20000, (int) $data['pack_qty']));
            }
            $db->update('store_product_variants', $row, $this->withTenant(['id' => $variantId]));

            // Replace attribute assignments.
            $db->query(
                "DELETE FROM `#__store_variant_attribute_values` WHERE `variant_id` = ? AND `tenant_id` = ?",
                [$variantId, $tid]
            );

            foreach (($data['attributes'] ?? []) as $attrId => $valueId) {
                $attrId  = (int) $attrId;
                $valueId = (int) $valueId;
                if ($attrId > 0 && $valueId > 0) {
                    $db->query(
                        "INSERT INTO `#__store_variant_attribute_values`
                        (`variant_id`, `attribute_id`, `attribute_value_id`, `tenant_id`)
                        VALUES (?, ?, ?, ?)",
                        [$variantId, $attrId, $valueId, $tid]
                    );
                }
            }
        });

        return $this->find($variantId)
            ?? throw new RuntimeException('Failed to reload variant.');
    }

    public function findBySkuAndProduct(int $productId, string $sku): ?Variant
    {
        if ($productId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_product_variants`
            WHERE `product_id` = ? AND `sku` = ? AND `tenant_id` = ? LIMIT 1",
            [$productId, $sku, $this->tenantId()]
        )->row;

        return $row !== [] ? $this->hydrateVariant($row) : null;
    }

    public function findBySku(string $sku): ?Variant
    {
        $sku = trim($sku);
        if ($sku === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_product_variants` WHERE `sku` = ? AND `tenant_id` = ? LIMIT 1",
            [$sku, $this->tenantId()]
        )->row;

        return $row !== [] ? $this->hydrateVariant($row) : null;
    }

    /** Move a variant onto another product (used to flatten eBay variation groups). */
    public function reassignProduct(int $variantId, int $productId): void
    {
        if ($variantId <= 0 || $productId <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "UPDATE `#__store_product_variants`
                SET `product_id` = ?, `updated_at` = ?
              WHERE `id` = ? AND `tenant_id` = ?",
            [$productId, date('Y-m-d H:i:s'), $variantId, $this->tenantId()]
        );
    }

    /**
     * Build a free variant SKU derived from $base (tenant-wide unique).
     */
    public function allocateUniqueSku(string $base, int $productId = 0): string
    {
        $base = trim($base);
        if ($base === '') {
            $base = 'SKU';
        }
        $base = mb_substr($base, 0, 100);

        $candidates = [
            $base,
            $productId > 0 ? mb_substr($base . '-P' . $productId, 0, 128) : null,
        ];
        for ($i = 2; $i <= 50; $i++) {
            $candidates[] = mb_substr($base . '-' . $i, 0, 128);
        }
        $candidates[] = mb_substr($base . '-' . substr(sha1($base . '|' . $productId), 0, 8), 0, 128);

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            if ($this->findBySku($candidate) === null) {
                return $candidate;
            }
        }

        return mb_substr($base . '-' . bin2hex(random_bytes(4)), 0, 128);
    }

    /** Resolve by variant SKU, or product SKU / barcode / UPC (first active variant). */
    public function findBySkuOrBarcode(string $code): ?Variant
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $bySku = $this->findBySku($code);
        if ($bySku !== null) {
            return $bySku;
        }

        if (!TenantContext::get()->has()) {
            return null;
        }
        $tid = $this->tenantId();
        $row = $this->db->query(
            "SELECT v.*
               FROM `#__store_product_variants` v
               INNER JOIN `#__store_products` p
                 ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
              WHERE v.`tenant_id` = ?
                AND (p.`sku` = ? OR p.`barcode` = ? OR p.`upc` = ?)
              ORDER BY v.`id` ASC
              LIMIT 1",
            [$tid, $code, $code, $code]
        )->row;

        return $row !== [] ? $this->hydrateVariant($row) : null;
    }

    // --------------------------------------------------------------

    private function hydrateVariant(array $row): Variant
    {
        $v = new Variant();
        $v->id        = (int)    $row['id'];
        $v->productId = (int)    $row['product_id'];
        $v->sku         = (string) $row['sku'];
        $v->name        = (string) ($row['name'] ?? '');
        $v->packQty     = (int)    ($row['pack_qty'] ?? 0);
        $v->pickBin     = (string) ($row['pick_bin'] ?? '');
        $v->price       = (float)  ($row['price'] ?? 0);
        $v->cost        = (float)  ($row['cost']  ?? 0);
        $v->weightGrams = (int)    ($row['weight_grams'] ?? 0);
        $v->shipping    = $this->decodeJson($row['shipping_json'] ?? null);
        $v->active      = (bool)   ($row['active'] ?? true);
        $v->createdAt = $row['created_at'] ?? null;
        $v->updatedAt = $row['updated_at'] ?? null;
        return $v;
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}