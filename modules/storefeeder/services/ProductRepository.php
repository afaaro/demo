<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

/**
 * ProductRepository — data access for `sf_products`.
 *
 * In the EAV design, a "product" is the parent row only. Variants live in
 * `sf_product_variants` and are handled by VariantRepository.
 *
 * The `sf_products` table holds: sku, name, image, description, barcode,
 * price, cost, weight_grams, active.
 */
final class ProductRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    // =========================================================================
    // Reads
    // =========================================================================

    public function find(int $id): ?Product
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__sf_products` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
            [$id, $this->tenantId()]
        )->row;

        return $row !== [] ? $this->hydrate($row) : null;
    }

    public function findBySku(string $sku): ?Product
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__sf_products` WHERE `sku` = ? AND `tenant_id` = ? LIMIT 1",
            [$sku, $this->tenantId()]
        )->row;

        return $row !== [] ? $this->hydrate($row) : null;
    }

    /**
     * List products with optional filters.
     *
     * @param int    $limit         Page size
     * @param int    $offset        Page offset
     * @param string $search        Matches SKU, name, or barcode
     * @param int    $categoryId    Category id; includes descendants
     * @param string $stockFilter   '' | 'in' | 'out' | 'low'
     * @param string $activeFilter  '' | '1' | '0'
     * @return array<int, Product>
     */
    /**
     * @param array<string, string> $columnFilters
     * @return array<int, Product>
     */
    public function all(
        int $limit = 100,
        int $offset = 0,
        string $search = '',
        int $categoryId = 0,
        string $stockFilter = '',
        string $activeFilter = '',
        array $columnFilters = [],
        string $sort = 'name',
        string $dir = 'asc',
    ): array {
        [$where, $params] = $this->buildProductFilter(
            $search, $categoryId, $stockFilter, $activeFilter, $columnFilters
        );

        $sql = "SELECT p.* FROM `#__sf_products` p";

        if ($categoryId > 0) {
            $sql .= " JOIN `#__sf_product_categories` pc ON pc.`product_id` = p.`id`";
        }

        if (in_array($stockFilter, ['in', 'out'], true)) {
            $sql .= " LEFT JOIN (
                        SELECT v.`product_id`,
                               SUM(i.`quantity` - i.`reserved`) AS available
                          FROM `#__sf_product_variants` v
                          LEFT JOIN `#__sf_inventory` i ON i.`variant_id` = v.`id`
                         GROUP BY v.`product_id`
                      ) s ON s.`product_id` = p.`id`";
        }

        $sql .= $where;
        $sql .= ' GROUP BY p.`id` ORDER BY ' . $this->managerOrderBy($sort, $dir);
        $sql .= ' LIMIT ? OFFSET ?';

        $params[] = $limit;
        $params[] = $offset;

        $rows = $this->db->query($sql, $params)->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /**
     * @param array<string, string> $columnFilters
     */
    public function count(
        string $search = '',
        int $categoryId = 0,
        string $stockFilter = '',
        string $activeFilter = '',
        array $columnFilters = [],
    ): int {
        [$where, $params] = $this->buildProductFilter(
            $search, $categoryId, $stockFilter, $activeFilter, $columnFilters
        );

        $sql = "SELECT COUNT(DISTINCT p.`id`) FROM `#__sf_products` p";

        if ($categoryId > 0) {
            $sql .= " JOIN `#__sf_product_categories` pc ON pc.`product_id` = p.`id`";
        }

        if (in_array($stockFilter, ['in', 'out'], true)) {
            $sql .= " LEFT JOIN (
                        SELECT v.`product_id`,
                               SUM(i.`quantity` - i.`reserved`) AS available
                          FROM `#__sf_product_variants` v
                          LEFT JOIN `#__sf_inventory` i ON i.`variant_id` = v.`id`
                         GROUP BY v.`product_id`
                      ) s ON s.`product_id` = p.`id`";
        }

        return (int) $this->db->query($sql . $where, $params)->value;
    }

    private function managerOrderBy(string $sort, string $dir): string
    {
        $dirSql = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $map = [
            'sku'           => 'p.`sku`',
            'name'          => 'p.`name`',
            'ean'           => 'p.`barcode`',
            'mpn'           => 'p.`mpn`',
            'supplier_sku'  => 'p.`supplier_sku`',
            'price'         => 'p.`price`',
            'created'       => 'p.`created_at`',
            'supplier'      => 'p.`supplier_name`',
            'warehouse'     => 'p.`warehouse`',
        ];
        $col = $map[$sort] ?? 'p.`name`';
        return $col . ' ' . $dirSql . ', p.`id` ASC';
    }

    /**
     * Sum available stock across all variants of a product.
     */
    public function totalStock(int $productId): int
    {
        return (int) $this->db->query(
            "SELECT COALESCE(SUM(i.`quantity` - i.`reserved`), 0)
               FROM `#__sf_product_variants` v
               LEFT JOIN `#__sf_inventory` i ON i.`variant_id` = v.`id`
              WHERE v.`product_id` = ?",
            [$productId]
        )->value;
    }

    // =========================================================================
    // Writes
    // =========================================================================

    /**
     * @param array{
     *     sku: string, name: string, description?: string, barcode?: string,
     *     image?: string, price?: float|string, cost?: float|string,
     *     weight_grams?: int, active?: bool
     * } $data
     */
    public function create(array $data): Product
    {
        $sku  = trim((string) ($data['sku']  ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        if ($sku === '') {
            throw new InvalidArgumentException('SKU is required.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if ($this->findBySku($sku) !== null) {
            throw new RuntimeException("SKU '{$sku}' already exists.");
        }

        $now = date('Y-m-d H:i:s');

        try {
            $payload = [
                'sku'                => $sku,
                'name'               => $name,
                'brand'              => mb_substr(trim((string) ($data['brand'] ?? '')), 0, 128),
                'image'              => (string) ($data['image'] ?? ''),
                'description'        => (string) ($data['description'] ?? ''),
                'barcode'            => (string) ($data['barcode']     ?? ''),
                'upc'                => mb_substr(trim((string) ($data['upc'] ?? '')), 0, 64),
                'mpn'                => mb_substr(trim((string) ($data['mpn'] ?? '')), 0, 64),
                'supplier_sku'       => mb_substr(trim((string) ($data['supplier_sku'] ?? '')), 0, 64),
                'supplier_name'      => mb_substr(trim((string) ($data['supplier_name'] ?? '')), 0, 128),
                'warehouse'          => mb_substr(trim((string) ($data['warehouse'] ?? 'Default')), 0, 128) ?: 'Default',
                'price'              => (float)  ($data['price']       ?? 0),
                'rrp'                => (float)  ($data['rrp']         ?? 0),
                'cost'               => (float)  ($data['cost']        ?? 0),
                'tax_class'          => mb_substr(trim((string) ($data['tax_class'] ?? '20')), 0, 16) ?: '20',
                'price_includes_vat' => !empty($data['price_includes_vat']) ? 1 : 0,
                'weight_grams'       => (int)    ($data['weight_grams'] ?? 0),
                'length_mm'          => max(0, (int) ($data['length_mm'] ?? 0)),
                'width_mm'           => max(0, (int) ($data['width_mm'] ?? 0)),
                'height_mm'          => max(0, (int) ($data['height_mm'] ?? 0)),
                'shipping_json'      => $this->encodeJson($data['shipping'] ?? []),
                'notes'              => (string) ($data['notes'] ?? ''),
                'regulatory_json'    => $this->encodeJson($data['regulatory'] ?? []),
                'ebay_category_id'   => mb_substr(trim((string) ($data['ebay_category_id'] ?? '')), 0, 32),
                'ebay_category_name' => mb_substr(trim((string) ($data['ebay_category_name'] ?? '')), 0, 255),
                'ebay_condition'     => EbayCondition::normalize(
                    (string) ($data['ebay_condition'] ?? '')
                ),
                'kit_display_on_order' => array_key_exists('kit_display_on_order', $data)
                    ? (!empty($data['kit_display_on_order']) ? 1 : 0)
                    : 1,
                'active'             => !empty($data['active']) ? 1 : 0,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
            if (TenantContext::get()->has()) {
                $payload = $this->withTenant($payload);
            }
            $id = $this->db->insert('sf_products', $payload);
        } catch (\Throwable $e) {
            // Catch the race condition where the SKU exists between our
            // pre-check and the insert.
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new RuntimeException("SKU '{$sku}' already exists.");
            }
            throw $e;
        }

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload product.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Product
    {
        $allowed = [
            'sku', 'name', 'brand', 'image', 'description', 'barcode', 'upc',
            'mpn', 'supplier_sku', 'supplier_name', 'warehouse',
            'price', 'rrp', 'cost', 'tax_class', 'price_includes_vat',
            'weight_grams', 'length_mm', 'width_mm', 'height_mm',
            'shipping', 'notes', 'regulatory',
            'ebay_category_id', 'ebay_category_name', 'ebay_condition',
            'kit_display_on_order', 'active',
        ];
        $data = array_intersect_key($data, array_flip($allowed));

        if (array_key_exists('shipping', $data)) {
            $data['shipping_json'] = $this->encodeJson($data['shipping']);
            unset($data['shipping']);
        }
        if (array_key_exists('regulatory', $data)) {
            $data['regulatory_json'] = $this->encodeJson($data['regulatory']);
            unset($data['regulatory']);
        }
        if (array_key_exists('ebay_category_id', $data)) {
            $data['ebay_category_id'] = mb_substr(trim((string) $data['ebay_category_id']), 0, 32);
        }
        if (array_key_exists('ebay_category_name', $data)) {
            $data['ebay_category_name'] = mb_substr(trim((string) $data['ebay_category_name']), 0, 255);
        }
        if (array_key_exists('ebay_condition', $data)) {
            $data['ebay_condition'] = EbayCondition::normalize((string) $data['ebay_condition']);
        }
        if (isset($data['notes'])) {
            $data['notes'] = (string) $data['notes'];
        }
        if (array_key_exists('kit_display_on_order', $data)) {
            $data['kit_display_on_order'] = !empty($data['kit_display_on_order']) ? 1 : 0;
        }

        if (isset($data['brand'])) {
            $data['brand'] = mb_substr(trim((string) $data['brand']), 0, 128);
        }
        if (isset($data['upc'])) {
            $data['upc'] = mb_substr(trim((string) $data['upc']), 0, 64);
        }
        if (isset($data['mpn'])) {
            $data['mpn'] = mb_substr(trim((string) $data['mpn']), 0, 64);
        }
        if (isset($data['supplier_sku'])) {
            $data['supplier_sku'] = mb_substr(trim((string) $data['supplier_sku']), 0, 64);
        }
        if (isset($data['supplier_name'])) {
            $data['supplier_name'] = mb_substr(trim((string) $data['supplier_name']), 0, 128);
        }
        if (isset($data['warehouse'])) {
            $data['warehouse'] = mb_substr(trim((string) $data['warehouse']), 0, 128) ?: 'Default';
        }
        if (isset($data['tax_class'])) {
            $data['tax_class'] = mb_substr(trim((string) $data['tax_class']), 0, 16) ?: '20';
        }
        if (isset($data['price_includes_vat'])) {
            $data['price_includes_vat'] = !empty($data['price_includes_vat']) ? 1 : 0;
        }
        foreach (['length_mm', 'width_mm', 'height_mm', 'weight_grams'] as $dim) {
            if (isset($data[$dim])) {
                $data[$dim] = max(0, (int) $data[$dim]);
            }
        }

        if (isset($data['sku'])) {
            $sku = trim((string) $data['sku']);
            if ($sku === '') {
                throw new InvalidArgumentException('SKU cannot be empty.');
            }
            $existing = $this->findBySku($sku);
            if ($existing !== null && $existing->id !== $id) {
                throw new RuntimeException("SKU '{$sku}' already exists.");
            }
            $data['sku'] = $sku;
        }

        if (isset($data['active'])) {
            $data['active'] = !empty($data['active']) ? 1 : 0;
        }

        if ($data === []) {
            throw new InvalidArgumentException('Nothing to update.');
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            $this->db->update('sf_products', $data, ['id' => $id]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new RuntimeException('SKU already exists.');
            }
            throw $e;
        }

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload product.');
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        // Cascades remove variants, listings, inventory, and attribute rows.
        $this->db->query(
            "DELETE FROM `#__sf_products` WHERE `id` = ? AND `tenant_id` = ?",
            [$id, $this->tenantId()]
        );
    }

    /**
     * Wipe this tenant's catalog so listings can be re-imported cleanly.
     * Orders are kept (line item variant_id is nulled). Purchase-order lines
     * for these variants are removed.
     */
    public function clearAll(): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        $tid = $this->tenantId();

        return (int) $this->db->transaction(function (Database $db) use ($tid) {
            $count = (int) $db->query(
                'SELECT COUNT(*) FROM `#__sf_products` WHERE `tenant_id` = ?',
                [$tid]
            )->value;

            if ($count === 0) {
                return 0;
            }

            $db->query(
                'DELETE FROM `#__sf_purchase_order_lines` WHERE `tenant_id` = ?',
                [$tid]
            );
            $db->query(
                'UPDATE `#__sf_order_items` SET `variant_id` = NULL
                  WHERE `tenant_id` = ? AND `variant_id` IS NOT NULL',
                [$tid]
            );
            $db->query(
                'DELETE FROM `#__sf_products` WHERE `tenant_id` = ?',
                [$tid]
            );

            return $count;
        });
    }

    // =========================================================================
    // Attributes attached to this product
    // =========================================================================

    /** @return array<int, int> */
    public function attributeIdsFor(int $productId): array
    {
        $rows = $this->db->query(
            "SELECT `attribute_id` FROM `#__sf_product_attributes`
              WHERE `product_id` = ?
              ORDER BY `sort_order`, `attribute_id`",
            [$productId]
        )->rows;

        return array_map(fn($r) => (int) $r['attribute_id'], $rows);
    }

    /**
     * Replace the set of attributes attached to a product.
     *
     * @param array<int, int> $attributeIds
     */
    public function setAttributesFor(int $productId, array $attributeIds): void
    {
        if ($productId <= 0 || $this->find($productId) === null) {
            return;
        }
        $this->db->transaction(function (Database $db) use ($productId, $attributeIds) {
            $db->query(
                "DELETE FROM `#__sf_product_attributes` WHERE `product_id` = ?",
                [$productId]
            );

            $i = 0;
            foreach ($attributeIds as $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    continue;
                }
                $db->query(
                    "INSERT INTO `#__sf_product_attributes`
                        (`product_id`, `attribute_id`, `sort_order`)
                     VALUES (?, ?, ?)",
                    [$productId, $id, $i++]
                );
            }
        });
    }

    // =========================================================================
    // Internals — filtering
    // =========================================================================

    /**
     * Build the WHERE clause and bindings for the product list filters.
     *
     * @return array{0: string, 1: array<int, mixed>}  [where clause, bind params]
     */
    /**
     * @param array<string, string> $columnFilters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildProductFilter(
        string $search,
        int $categoryId,
        string $stockFilter,
        string $activeFilter,
        array $columnFilters = [],
    ): array {
        $clauses = [];
        $params  = [];

        // Always scope — never list products across tenants.
        $clauses[] = 'p.`tenant_id` = ?';
        $params[]  = TenantContext::get()->has() ? $this->tenantId() : -1;

        if ($search !== '') {
            $like      = '%' . $search . '%';
            $clauses[] = "(p.`sku` LIKE ? OR p.`name` LIKE ? OR p.`barcode` LIKE ?"
                . " OR p.`upc` LIKE ? OR p.`mpn` LIKE ? OR p.`supplier_sku` LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        if ($categoryId > 0) {
            // Category and all its descendants.
            $descendants  = $this->descendantCategoryIds($categoryId);
            $placeholders = implode(', ', array_fill(0, count($descendants), '?'));
            $clauses[]    = "pc.`category_id` IN ({$placeholders})";
            foreach ($descendants as $id) {
                $params[] = $id;
            }
        }

        if ($stockFilter === 'in') {
            $clauses[] = "s.`available` > 0";
        } elseif ($stockFilter === 'out') {
            $clauses[] = "(s.`available` IS NULL OR s.`available` <= 0)";
        } elseif ($stockFilter === 'low') {
            // Match InventoryService::lowStock — any variant at/below its reorder_at.
            $clauses[] = "EXISTS (
                SELECT 1
                  FROM `#__sf_product_variants` v
                  INNER JOIN `#__sf_inventory` i ON i.`variant_id` = v.`id`
                 WHERE v.`product_id` = p.`id`
                   AND (i.`quantity` - i.`reserved`) <= i.`reorder_at`
            )";
        }

        if ($activeFilter === '1') {
            $clauses[] = "p.`active` = 1";
        } elseif ($activeFilter === '0') {
            $clauses[] = "p.`active` = 0";
        }

        $colMap = [
            'sku'          => 'p.`sku`',
            'name'         => 'p.`name`',
            'brand'        => 'p.`brand`',
            'ean'          => 'p.`barcode`',
            'mpn'          => 'p.`mpn`',
            'supplier_sku' => 'p.`supplier_sku`',
            'supplier'     => 'p.`supplier_name`',
            'warehouse'    => 'p.`warehouse`',
        ];
        foreach ($colMap as $key => $column) {
            $val = trim((string) ($columnFilters[$key] ?? ''));
            if ($val === '') {
                continue;
            }
            $clauses[] = $column . ' LIKE ?';
            $params[] = '%' . $val . '%';
        }

        $pickbin = trim((string) ($columnFilters['pickbin'] ?? ''));
        if ($pickbin !== '') {
            // Match products with any variant pick bin containing the filter.
            // Special values: "-" / "empty" / "none" → no pick bin set.
            $pickLower = strtolower($pickbin);
            if (in_array($pickLower, ['-', 'empty', 'none', 'n/a'], true)) {
                $clauses[] = 'NOT EXISTS (
                    SELECT 1 FROM `#__sf_product_variants` v
                     WHERE v.`product_id` = p.`id`
                       AND TRIM(v.`pick_bin`) <> \'\'
                )';
            } else {
                $clauses[] = 'EXISTS (
                    SELECT 1 FROM `#__sf_product_variants` v
                     WHERE v.`product_id` = p.`id`
                       AND v.`pick_bin` LIKE ?
                )';
                $params[] = '%' . $pickbin . '%';
            }
        }

        $onPo = strtolower(trim((string) ($columnFilters['on_po'] ?? '')));
        if ($onPo === 'yes' || $onPo === '1') {
            $clauses[] = 'EXISTS (
                SELECT 1
                  FROM `#__sf_purchase_order_lines` l
                  INNER JOIN `#__sf_purchase_orders` po ON po.`id` = l.`po_id`
                  INNER JOIN `#__sf_product_variants` v ON v.`id` = l.`variant_id`
                 WHERE v.`product_id` = p.`id`
                   AND po.`status` = \'confirmed\'
                   AND (l.`qty_ordered` - l.`qty_received` - l.`qty_written_off`) > 0
            )';
        } elseif ($onPo === 'no' || $onPo === '0') {
            $clauses[] = 'NOT EXISTS (
                SELECT 1
                  FROM `#__sf_purchase_order_lines` l
                  INNER JOIN `#__sf_purchase_orders` po ON po.`id` = l.`po_id`
                  INNER JOIN `#__sf_product_variants` v ON v.`id` = l.`variant_id`
                 WHERE v.`product_id` = p.`id`
                   AND po.`status` = \'confirmed\'
                   AND (l.`qty_ordered` - l.`qty_received` - l.`qty_written_off`) > 0
            )';
        }

        $onBackOrder = strtolower(trim((string) ($columnFilters['on_back_order'] ?? '')));
        if ($onBackOrder === 'yes' || $onBackOrder === '1') {
            $clauses[] = 'EXISTS (
                SELECT 1
                  FROM `#__sf_product_variants` v
                  INNER JOIN `#__sf_inventory` i ON i.`variant_id` = v.`id`
                 WHERE v.`product_id` = p.`id`
                   AND i.`reserved` > 0
            )';
        } elseif ($onBackOrder === 'no' || $onBackOrder === '0') {
            $clauses[] = 'NOT EXISTS (
                SELECT 1
                  FROM `#__sf_product_variants` v
                  INNER JOIN `#__sf_inventory` i ON i.`variant_id` = v.`id`
                 WHERE v.`product_id` = p.`id`
                   AND i.`reserved` > 0
            )';
        }

        $type = strtolower(trim((string) ($columnFilters['type'] ?? '')));
        if ($type === 'kit') {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM `#__sf_product_bundle_components` b
                 WHERE b.`product_id` = p.`id`
            )';
        } elseif ($type === 'variable') {
            $clauses[] = '(
                SELECT COUNT(*) FROM `#__sf_product_variants` v
                 WHERE v.`product_id` = p.`id`
            ) > 1';
        } elseif ($type === 'simple') {
            $clauses[] = '(
                SELECT COUNT(*) FROM `#__sf_product_variants` v
                 WHERE v.`product_id` = p.`id`
            ) <= 1
            AND NOT EXISTS (
                SELECT 1 FROM `#__sf_product_bundle_components` b
                 WHERE b.`product_id` = p.`id`
            )';
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }

    /**
     * Return the given category id plus every descendant id.
     *
     * @return array<int, int>
     */
    private function descendantCategoryIds(int $categoryId): array
    {
        $ids   = [$categoryId];
        $queue = [$categoryId];

        while ($queue !== []) {
            $parentId = array_pop($queue);
            $rows = $this->db->query(
                "SELECT `id` FROM `#__sf_categories` WHERE `parent_id` = ?",
                [$parentId]
            )->rows;
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (!in_array($id, $ids, true)) {
                    $ids[]   = $id;
                    $queue[] = $id;
                }
            }
        }
        return $ids;
    }

    // =========================================================================
    // Internals — hydration
    // =========================================================================

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Product
    {
        $p = new Product();
        $p->id          = (int)    $row['id'];
        $p->sku         = (string) $row['sku'];
        $p->name        = (string) $row['name'];
        $p->brand       = (string) ($row['brand'] ?? '');
        $p->image       = (string) ($row['image'] ?? '');
        $p->description = (string) ($row['description']  ?? '');
        $p->barcode     = (string) ($row['barcode']      ?? '');
        $p->upc         = (string) ($row['upc']          ?? '');
        $p->mpn         = (string) ($row['mpn']          ?? '');
        $p->supplierSku = (string) ($row['supplier_sku'] ?? '');
        $p->supplierName = (string) ($row['supplier_name'] ?? '');
        $p->warehouse   = (string) (($row['warehouse'] ?? '') !== '' ? $row['warehouse'] : 'Default');
        $p->price       = (float)  ($row['price']        ?? 0);
        $p->rrp         = (float)  ($row['rrp']          ?? 0);
        $p->cost        = (float)  ($row['cost']         ?? 0);
        $p->taxClass    = (string) ($row['tax_class']    ?? '20');
        $p->priceIncludesVat = (bool) ($row['price_includes_vat'] ?? false);
        $p->weightGrams = (int)    ($row['weight_grams'] ?? 0);
        $p->lengthMm    = (int)    ($row['length_mm']    ?? 0);
        $p->widthMm     = (int)    ($row['width_mm']     ?? 0);
        $p->heightMm    = (int)    ($row['height_mm']    ?? 0);
        $p->shipping    = $this->decodeJson($row['shipping_json'] ?? null);
        $p->notes       = (string) ($row['notes'] ?? '');
        $p->regulatory  = $this->decodeJson($row['regulatory_json'] ?? null);
        $p->ebayCategoryId = (string) ($row['ebay_category_id'] ?? '');
        $p->ebayCategoryName = (string) ($row['ebay_category_name'] ?? '');
        $p->ebayCondition = EbayCondition::normalize((string) ($row['ebay_condition'] ?? ''));
        $p->kitDisplayOnOrder = !array_key_exists('kit_display_on_order', $row)
            || !empty($row['kit_display_on_order']);
        $p->active      = (bool)   ($row['active']       ?? true);
        $p->createdAt   = $row['created_at'] ?? null;
        $p->updatedAt   = $row['updated_at'] ?? null;
        return $p;
    }

    /** @param mixed $raw */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param mixed $data */
    private function encodeJson(mixed $data): string
    {
        if (!is_array($data)) {
            return '{}';
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }
}