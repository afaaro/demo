<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

final class CsvExporter
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * Stream a CSV of products + variants + inventory to the given file handle.
     * Returns the number of data rows written.
     *
     * @param resource $out  A writable stream (e.g. fopen('php://output', 'w'))
     */
    public function exportProducts($out): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }

        // 1. Column layout: fixed columns + one per attribute code.
        $attributeCodes = $this->attributeCodes();

        $header = [
            'product_id',
            'product_sku',
            'product_name',
            'product_active',
            'category_slugs',
            'variant_id',
            'variant_sku',
            'variant_name',
            'variant_active',
            'price',
            'cost',
            'stock',
            'reserved',
            'available',
            'reorder_at',
            'image',
        ];
        foreach ($attributeCodes as $code) {
            $header[] = $code;
        }

        fputcsv($out, $header);

        // 2. Load every product.
        $products = $this->db->query(
            "SELECT * FROM `#__store_products` WHERE `tenant_id` = ? ORDER BY `id`",
            [$this->tenantId()]
        )->rows;

        $rowsWritten = 0;

        foreach ($products as $p) {
            $productId = (int) $p['id'];

            $categories = $this->categorySlugsFor($productId);
            $variants   = $this->variantsWithAttributes($productId);

            // No variants? Emit one row with the product as its own variant.
            if ($variants === []) {
                fputcsv($out, $this->buildRow(
                    $p,
                    null,
                    null,
                    $categories,
                    $attributeCodes,
                    []
                ));
                $rowsWritten++;
                continue;
            }

            foreach ($variants as $v) {
                $stock = $this->stockFor((int) $v['variant_id']);

                fputcsv($out, $this->buildRow(
                    $p,
                    $v,
                    $stock,
                    $categories,
                    $attributeCodes,
                    $v['attributes'] ?? []
                ));
                $rowsWritten++;
            }
        }

        return $rowsWritten;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @return array<int, string> */
    private function attributeCodes(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }

        $rows = $this->db->query(
            "SELECT `code` FROM `#__store_attributes` WHERE `tenant_id` = ? ORDER BY `sort_order`, `name`",
            [$this->tenantId()]
        )->rows;

        return array_map(fn($r) => (string) $r['code'], $rows);
    }

    /** @return array<int, string> */
    private function categorySlugsFor(int $productId): array
    {
        $rows = $this->db->query(
            "SELECT c.`slug`
               FROM `#__store_categories` c
               JOIN `#__store_product_categories` pc
                 ON pc.`category_id` = c.`id` AND pc.`tenant_id` = c.`tenant_id`
              WHERE pc.`product_id` = ? AND c.`tenant_id` = ?
              ORDER BY c.`name`",
            [$productId, $this->tenantId()]
        )->rows;

        return array_map(fn($r) => (string) $r['slug'], $rows);
    }

    /**
     * Return each variant of the product, with its attributes pre-loaded
     * as ['size' => 'M', 'color' => 'Red'].
     *
     * @return array<int, array<string, mixed>>
     */
    private function variantsWithAttributes(int $productId): array
    {
        $tid = $this->tenantId();
        $variants = $this->db->query(
            "SELECT * FROM `#__store_product_variants`
              WHERE `product_id` = ? AND `tenant_id` = ?
              ORDER BY `id`",
            [$productId, $tid]
        )->rows;

        if ($variants === []) {
            return [];
        }

        $ids = array_map(fn($v) => (int) $v['id'], $variants);

        // Load attribute assignments in one query.
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $assignments = $this->db->query(
            "SELECT vav.`variant_id`, a.`code`, av.`value`
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
            $byVariant[(int) $row['variant_id']][(string) $row['code']] = (string) $row['value'];
        }

        foreach ($variants as &$v) {
            $v['attributes'] = $byVariant[(int) $v['id']] ?? [];
        }
        unset($v);

        return $variants;
    }

    /**
     * @return array{quantity:int, reserved:int, available:int, reorder_at:int}
     */
    private function stockFor(int $variantId): array
    {
        $row = $this->db->query(
            "SELECT `quantity`, `reserved`, `reorder_at`
               FROM `#__store_inventory`
              WHERE `variant_id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$variantId, $this->tenantId()]
        )->row;

        if ($row === []) {
            return ['quantity' => 0, 'reserved' => 0, 'available' => 0, 'reorder_at' => 0];
        }

        $quantity = (int) $row['quantity'];
        $reserved = (int) $row['reserved'];

        return [
            'quantity'   => $quantity,
            'reserved'   => $reserved,
            'available'  => $quantity - $reserved,
            'reorder_at' => (int) $row['reorder_at'],
        ];
    }

    /**
     * @param array<string, mixed>       $product
     * @param array<string, mixed>|null  $variant
     * @param array<string, int>|null    $stock
     * @param array<int, string>         $categories
     * @param array<int, string>         $attributeCodes
     * @param array<string, string>      $attributes
     * @return array<int, string|int>
     */
    private function buildRow(
        array $product,
        ?array $variant,
        ?array $stock,
        array $categories,
        array $attributeCodes,
        array $attributes,
    ): array {
        $row = [
            (int)    $product['id'],
            (string) $product['sku'],
            (string) $product['name'],
            !empty($product['active']) ? '1' : '0',
            implode('|', $categories),
            $variant !== null ? (int)    $variant['id']        : '',
            $variant !== null ? (string) $variant['sku']       : (string) $product['sku'],
            $variant !== null ? (string) $variant['name']      : (string) $product['name'],
            $variant !== null ? (!empty($variant['active']) ? '1' : '0') : (!empty($product['active']) ? '1' : '0'),
            $variant !== null ? (string) $variant['price']     : (string) $product['price'],
            $variant !== null ? (string) $variant['cost']      : (string) $product['cost'],
            $stock !== null   ? $stock['quantity']             : 0,
            $stock !== null   ? $stock['reserved']             : 0,
            $stock !== null   ? $stock['available']            : 0,
            $stock !== null   ? $stock['reorder_at']           : 0,
            (string) ($product['image'] ?? ''),
        ];

        foreach ($attributeCodes as $code) {
            $row[] = $attributes[$code] ?? '';
        }

        return $row;
    }
}