<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class CsvImporter
{
    use TenantScoped;

    public function __construct(
        private Database           $db,
        private ProductRepository  $products,
        private VariantRepository  $variants,
        private CategoryRepository $categories,
        private AttributeRepository $attributes,
    ) {}

    /**
     * Parse and import a CSV file.
     *
     * @return array{
     *     rows_total:   int,
     *     rows_ok:      int,
     *     rows_errors:  int,
     *     created:      int,
     *     updated:      int,
     *     errors:       array<int, array{row:int, message:string}>
     * }
     */
    public function import(string $filePath): array
    {
        if (!TenantContext::get()->has()) {
            return [
                'rows_total'  => 0,
                'rows_ok'     => 0,
                'rows_errors' => 0,
                'created'     => 0,
                'updated'     => 0,
                'skipped'     => 0,
                'errors'      => [],
                'skips'       => [],
            ];
        }

        $this->attributes->deleteSystemImported();

        [$header, $rows] = $this->readSpreadsheet($filePath);
        [$keys, $attributeColumns] = $this->mapHeaders($header);

        $attributeMap = [];
        foreach ($this->attributes->all() as $attr) {
            $attributeMap[$attr->code] = ['id' => $attr->id, 'values' => []];
            foreach ($attr->values as $v) {
                $attributeMap[$attr->code]['values'][$v->value] = $v->id;
            }
        }

        $result = [
            'rows_total'  => 0,
            'rows_ok'     => 0,
            'rows_errors' => 0,
            'created'     => 0,
            'updated'     => 0,
            'skipped'     => 0,
            'errors'      => [],
            'skips'       => [],
        ];

        $seenProductSkus = [];
        $seenVariantSkus = [];
        $lineNumber = 1;

        foreach ($rows as $row) {
            $lineNumber++;
            $row = array_pad($row, count($keys), '');
            if (count($row) > count($keys)) {
                $row = array_slice($row, 0, count($keys));
            }
            $raw = array_combine($keys, $row);
            if ($raw === false) {
                $result['rows_total']++;
                $result['rows_errors']++;
                $result['errors'][] = ['row' => $lineNumber, 'message' => 'Column count mismatch.'];
                continue;
            }

            if ($this->isHintOrBlankRow($raw)) {
                continue;
            }

            $data = $this->normalizeRow($raw);
            if ($data === null) {
                continue;
            }

            $result['rows_total']++;

            try {
                [$status] = $this->importRow(
                    $data,
                    $attributeColumns,
                    $attributeMap,
                    $seenProductSkus,
                    $seenVariantSkus,
                );

                $result['rows_ok']++;
                if ($status === 'created') {
                    $result['created']++;
                }
                if ($status === 'updated') {
                    $result['updated']++;
                }
            } catch (CsvImportSkip $e) {
                $result['skipped']++;
                $result['skips'][] = ['row' => $lineNumber, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $result['rows_errors']++;
                $result['errors'][] = ['row' => $lineNumber, 'message' => $e->getMessage()];
            }
        }

        return $result;
    }

    // =========================================================================
    // Per-row
    // =========================================================================

    /**
     * @param array<string, string|null>        $data
     * @param array<int, string>                $attributeColumns  [colIndex => attributeCode]
     * @param array<string, array{id:?int, values:array<string,int>}> $attributeMap
     * @param array<string, int>                $seenProductSkus
     * @param array<string, int>                $seenVariantSkus
     * @return array{0: string, 1: int}  [status, productId]
     */
    private function importRow(
        array $data,
        array $attributeColumns,
        array &$attributeMap,
        array &$seenProductSkus,
        array &$seenVariantSkus,
    ): array {
        $productSku = trim((string) ($data['product_sku'] ?? ''));
        if ($productSku === '') {
            throw new RuntimeException('product_sku is required.');
        }

        $variantSku = trim((string) ($data['variant_sku'] ?? ''));
        $existingVariantAnywhere = $variantSku !== '' ? $this->variants->findBySku($variantSku) : null;
        $existingProduct = $this->products->findBySku($productSku);
        $incomingLeftover = $variantSku !== ''
            && $productSku === $variantSku
            && !isset($seenProductSkus[$productSku]);

        // Simple leftover row whose SKU already lives on a parent: update that
        // variant in place. Do not create a second product.
        if (
            $incomingLeftover
            && $existingVariantAnywhere !== null
            && $existingVariantAnywhere->productId !== null
            && ($existingProduct === null || (int) $existingVariantAnywhere->productId !== (int) $existingProduct->id)
        ) {
            $ownerId = (int) $existingVariantAnywhere->productId;
            if ($this->products->find($ownerId) !== null) {
                $this->upsertVariant($ownerId, $variantSku, $data, $attributeColumns, $attributeMap, $seenProductSkus);
                if (
                    $existingProduct !== null
                    && $existingProduct->id !== null
                    && (int) $existingProduct->id !== $ownerId
                    && $this->variants->forProduct((int) $existingProduct->id) === []
                ) {
                    $this->forgetDeletedProduct((int) $existingProduct->id, $seenProductSkus);
                }

                return ['updated', $ownerId];
            }
        }

        // -- Product --------------------------------------------------------
        $productId = $seenProductSkus[$productSku] ?? null;
        if ($productId !== null && $this->products->find((int) $productId) === null) {
            unset($seenProductSkus[$productSku]);
            $productId = null;
        }
        $status    = 'updated';
        $isChild   = !empty($data['is_child']);
        $image     = trim((string) ($data['image'] ?? ''));

        if ($productId === null) {
            $existing = $existingProduct;
            $payload = $this->productPayload($data, $productSku);
            if ($existing === null) {
                $product = $this->products->create($payload);
                $productId = $product->id;
                $status    = 'created';
            } else {
                $productId = $existing->id;
                $update = $payload;
                unset($update['sku']);
                if ($isChild) {
                    unset($update['name'], $update['description']);
                }
                if ($image === '') {
                    unset($update['image']);
                }
                $this->products->update($productId, $update);
                $status = 'updated';
            }
            $seenProductSkus[$productSku] = $productId;
        } elseif (!$isChild) {
            $update = $this->productPayload($data, $productSku);
            unset($update['sku']);
            if ($image === '') {
                unset($update['image']);
            }
            $this->products->update($productId, $update);
        }

        // -- Categories -----------------------------------------------------
        $categorySlugs = trim((string) ($data['category_slugs'] ?? ''));
        if ($categorySlugs !== '') {
            $parts = preg_split('/[|;,]+/', $categorySlugs) ?: [];
            $ids   = [];
            foreach ($parts as $slug) {
                $slug = trim((string) $slug);
                if ($slug === '') {
                    continue;
                }
                $ids[] = $this->categories->ensureBySlug($slug)->id;
            }
            if ($ids !== [] && $productId !== null && $this->products->find((int) $productId) !== null) {
                $this->categories->setCategoriesFor((int) $productId, $ids);
            }
        }

        // -- Variant --------------------------------------------------------
        if ($variantSku === '') {
            return [$status, $productId];
        }

        $variantKey = $productSku . '::' . $variantSku;
        $this->upsertVariant($productId, $variantSku, $data, $attributeColumns, $attributeMap, $seenProductSkus);
        $seenVariantSkus[$variantKey] = 1;

        return [$status, $productId];
    }

    /**
     * Create, update, or move a variant onto $productId when the SKU already
     * sits on a leftover simple listing.
     *
     * @param array<string, mixed> $data
     * @param list<string> $attributeColumns
     * @param array<string, array{id:?int, values:array<string,int>}> $attributeMap
     */
    private function upsertVariant(
        int $productId,
        string $variantSku,
        array $data,
        array $attributeColumns,
        array &$attributeMap,
        array &$seenProductSkus = [],
    ): void {
        $owned = $this->variants->findBySku($variantSku);
        if (
            $owned !== null
            && $owned->productId !== null
            && (int) $owned->productId !== $productId
        ) {
            $fromId = (int) $owned->productId;
            $fromIsImportedParent = in_array($fromId, $seenProductSkus, true);
            if ($fromIsImportedParent || $this->products->find($productId) === null) {
                $productId = $fromId;
            } else {
                $this->variants->reassignProduct((int) $owned->id, $productId);
                if ($fromId > 0 && $this->variants->forProduct($fromId) === []) {
                    $this->forgetDeletedProduct($fromId, $seenProductSkus);
                }
            }
        }

        if ($productId <= 0 || $this->products->find($productId) === null) {
            return;
        }

        $attrValues = [];
        foreach ($attributeColumns as $code) {
            $value = trim((string) ($data[$code] ?? ''));
            if ($value === '') {
                continue;
            }
            $resolved = $this->resolveAttributeValue($code, $value, $attributeMap);
            if ($resolved !== null) {
                $attrValues[$resolved[0]] = $resolved[1];
            }
        }

        $variantName = trim((string) ($data['variant_name'] ?? ''));
        foreach (CatalogSetupService::guessFromText($variantSku, $variantName) as $code => $value) {
            $resolved = $this->resolveAttributeValue($code, $value, $attributeMap);
            if ($resolved !== null && !isset($attrValues[$resolved[0]])) {
                $attrValues[$resolved[0]] = $resolved[1];
            }
        }

        if ($attrValues !== []) {
            $merged = array_values(array_unique(array_merge(
                $this->products->attributeIdsFor($productId),
                array_map('intval', array_keys($attrValues))
            )));
            $this->products->setAttributesFor($productId, $merged);
        }

        $variantData = [
            'sku'          => $variantSku,
            'name'         => $variantName,
            'price'        => $this->floatOr($data['price'] ?? '', 0),
            'cost'         => $this->floatOr($data['cost'] ?? '', 0),
            'active'       => $this->boolOr($data['variant_active'] ?? '', true),
            'pick_bin'     => trim((string) ($data['pick_bin'] ?? '')),
            'weight_grams' => (int) ($data['weight_grams'] ?? 0),
            'attributes'   => $attrValues,
        ];
        $pack = (int) ($data['pack_qty'] ?? 0);
        if ($pack < 1) {
            $pack = PackInventory::parseQtyFromText($variantName);
        }
        if ($pack < 1) {
            $pack = PackInventory::parseQtyFromText($variantSku);
        }
        if ($pack >= 1) {
            $variantData['pack_qty'] = $pack;
        }

        $existingVariant = $this->findVariantBySku($productId, $variantSku);
        if ($existingVariant === null) {
            $this->variants->create($productId, $variantData);
        } else {
            $this->variants->update((int) $existingVariant->id, $variantData);
        }

        $this->applyStock($productId, $variantSku, $data);
    }

    /** @param array<string, int> $seenProductSkus */
    private function forgetDeletedProduct(int $productId, array &$seenProductSkus): void
    {
        if ($productId <= 0) {
            return;
        }
        $this->products->delete($productId);
        foreach ($seenProductSkus as $sku => $id) {
            if ((int) $id === $productId) {
                unset($seenProductSkus[$sku]);
            }
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function findVariantBySku(int $productId, string $sku): ?Variant
    {
        if (!TenantContext::get()->has()) {
            return null;
        }

        $row = $this->db->query(
            "SELECT v.*
               FROM `#__store_product_variants` v
               INNER JOIN `#__store_products` p ON p.`id` = v.`product_id`
              WHERE v.`product_id` = ? AND v.`sku` = ? AND p.`tenant_id` = ?
              LIMIT 1",
            [$productId, $sku, $this->tenantId()]
        )->row;

        if ($row === []) {
            return null;
        }

        $v = new Variant();
        $v->id        = (int)    $row['id'];
        $v->productId = (int)    $row['product_id'];
        $v->sku       = (string) $row['sku'];
        $v->name      = (string) ($row['name'] ?? '');
        $v->price     = (float)  ($row['price'] ?? 0);
        $v->cost      = (float)  ($row['cost']  ?? 0);
        $v->active    = (bool)   ($row['active'] ?? true);
        return $v;
    }

    /**
     * Set stock on the variant if the CSV has a stock column with a value.
     * Uses absolute set, not delta.
     *
     * @param array<string, string|null> $data
     */
    private function applyStock(int $productId, string $variantSku, array $data): void
    {
        if (!TenantContext::get()->has()) {
            return;
        }

        $tenantId = $this->tenantId();

        $hasStock    = array_key_exists('stock', $data) && trim((string) $data['stock']) !== '';
        $hasReorder  = array_key_exists('reorder_at', $data) && trim((string) $data['reorder_at']) !== '';

        if (!$hasStock && !$hasReorder) {
            return;
        }

        // Find the variant we just created or updated.
        $variant = $this->findVariantBySku($productId, $variantSku);
        if ($variant === null || $variant->id === null) {
            return;
        }

        if ($hasStock) {
            $desired = max(0, (int) $data['stock']);

            $current = $this->db->query(
                "SELECT `quantity` FROM `#__store_inventory` WHERE `variant_id` = ? AND `tenant_id` = ? LIMIT 1",
                [$variant->id, $tenantId]
            )->value;
            $current = $current !== null ? (int) $current : 0;

            $delta = $desired - $current;
            if ($delta !== 0) {
                $this->db->transaction(function (Database $db) use ($variant, $delta, $tenantId) {
                    $db->query(
                        "INSERT IGNORE INTO `#__store_inventory`
                         (`variant_id`, `tenant_id`, `quantity`, `reserved`, `reorder_at`)
                         VALUES (?, ?, 0, 0, 0)",
                        [$variant->id, $tenantId]
                    );
                    $db->query(
                        "UPDATE `#__store_inventory` SET `quantity` = `quantity` + ? WHERE `variant_id` = ? AND `tenant_id` = ?",
                        [$delta, $variant->id, $tenantId]
                    );
                    $db->insert('store_stock_movements', $this->withTenant([
                        'variant_id' => $variant->id,
                        'delta'      => $delta,
                        'reason'     => 'import',
                        'note'       => 'CSV import',
                        'user_id'    => null,
                    ]));
                });
            }
        }

        if ($hasReorder) {
            $reorder = max(0, (int) $data['reorder_at']);
            $this->db->query(
                "INSERT IGNORE INTO `#__store_inventory`
                 (`variant_id`, `tenant_id`, `quantity`, `reserved`, `reorder_at`)
                 VALUES (?, ?, 0, 0, 0)",
                [$variant->id, $tenantId]
            );
            $this->db->query(
                "UPDATE `#__store_inventory` SET `reorder_at` = ? WHERE `variant_id` = ? AND `tenant_id` = ?",
                [$reorder, $variant->id, $tenantId]
            );
        }
    }

    private function floatOr(mixed $value, float $default): float
    {
        $value = trim((string) $value);
        return $value === '' ? $default : (float) $value;
    }

    private function boolOr(mixed $value, bool $default): bool
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true);
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function readSpreadsheet(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Spreadsheet is not readable.');
        }

        $raw = (string) file_get_contents($filePath);
        if ($raw === '') {
            throw new RuntimeException('Spreadsheet is empty.');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (str_starts_with($raw, 'PK')) {
            throw new RuntimeException('Save the .xlsx as CSV or as the Store Products.xls HTML template.');
        }

        $looksHtml = (bool) preg_match('/<(?:html|table|!--\s*Excel)/i', substr($raw, 0, 2000));
        $table = $looksHtml ? $this->readHtmlTable($raw) : $this->readCsvTable($filePath);
        if ($table === [] || $table[0] === []) {
            throw new RuntimeException('Spreadsheet has no header row.');
        }

        $header = array_shift($table);

        return [$header, $table];
    }

    /** @return list<list<string>> */
    private function readCsvTable(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not open spreadsheet.');
        }
        $first3 = fread($handle, 3);
        if ($first3 !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map(static fn($c) => trim((string) $c), $row);
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    private function readHtmlTable(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $tables = $dom->getElementsByTagName('table');
        if ($tables->length === 0) {
            throw new RuntimeException('No table found in the Store spreadsheet.');
        }
        $out = [];
        foreach ($tables->item(0)->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if (!$cell instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($cell->tagName);
                if ($tag !== 'th' && $tag !== 'td') {
                    continue;
                }
                $text = html_entity_decode(trim($cell->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = str_replace("\xc2\xa0", ' ', $text);
                $cells[] = trim($text);
            }
            if ($cells !== []) {
                $out[] = $cells;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $header
     * @return array{0: list<string>, 1: list<string>}
     */
    private function mapHeaders(array $header): array
    {
        $aliases = $this->headerAliases();
        $keys = [];
        $attributeColumns = [];
        foreach ($header as $i => $raw) {
            $norm = $this->normHeader((string) $raw);
            if ($norm === '' || AttributeRepository::isSystemName($norm) || (isset($aliases[$norm]) && $aliases[$norm] === null)) {
                $keys[] = '_skip_' . $i;
                continue;
            }
            if (isset($aliases[$norm])) {
                $keys[] = $aliases[$norm];
                continue;
            }
            $code = AttributeRepository::sanitizeCode(str_replace(' ', '_', $norm), $norm);
            if (AttributeRepository::isSystemName(str_replace('_', ' ', $code))) {
                $keys[] = '_skip_' . $i;
                continue;
            }
            $keys[] = $code;
            $attributeColumns[] = $code;
        }

        return [$keys, $attributeColumns];
    }

    /** @return array<string, string|null> */
    private function headerAliases(): array
    {
        return [
            'id' => null,
            'ids' => null,
            'order' => null,
            'orders' => null,
            'order id' => null,
            'orderid' => null,
            'order number' => null,
            'order no' => null,
            'item id' => null,
            'itemid' => null,
            'listing id' => null,
            'listingid' => null,
            'external id' => null,
            'ebay item id' => null,
            'ebay id' => null,
            'channel id' => null,
            'channel order id' => null,
            'amazon order id' => null,
            'shopify order id' => null,
            'invoice id' => null,
            'transaction id' => null,
            'customer id' => null,
            'user id' => null,
            'product id' => 'product_id',
            'product sku' => 'product_sku',
            'sku' => 'sku',
            'parent sku' => 'parent_sku',
            'name' => 'name',
            'product name' => 'product_name',
            'variant id' => 'variant_id',
            'variant sku' => 'variant_sku',
            'variant name' => 'variant_name',
            'product active' => 'product_active',
            'variant active' => 'variant_active',
            'description' => 'description',
            'inventory' => 'stock',
            'stock' => 'stock',
            'reserved' => 'reserved',
            'available' => 'available',
            'price' => 'price',
            'cost' => 'cost',
            'rrp' => 'rrp',
            'price includes vat' => 'price_includes_vat',
            'tax class' => 'tax_class',
            'shipping cost' => 'shipping_cost',
            'ean' => 'barcode',
            'upc' => 'upc',
            'archived' => 'archived',
            'discontinued' => 'discontinued',
            'brand' => 'brand',
            'warehouse' => 'warehouse',
            'image' => 'image',
            'image urls' => 'image',
            'manufacturer part number' => 'mpn',
            'weight' => 'weight',
            'category' => 'category',
            'category slugs' => 'category_slugs',
            'pickbin' => 'pick_bin',
            'pick bin' => 'pick_bin',
            'manual reorder level' => 'reorder_at',
            'reorder at' => 'reorder_at',
            'product notes' => 'notes',
            'length' => 'length',
            'height' => 'height',
            'width' => 'width',
            'suppliers' => 'supplier_name',
            'supplier skus' => 'supplier_sku',
            'packaging quantity' => 'pack_qty',
            'carton quantity' => 'carton_qty',
            'out of stock date' => null,
            'allocated on orders' => null,
            'on back order' => null,
            'on purchase order' => null,
            'additional barcodes' => null,
            'supplier currencies' => null,
            'supplier stock levels' => null,
            'supplier lead times days' => null,
            'supplier carton qty' => null,
            'supplier carton barcode' => null,
            'supplier unit of measurement' => null,
            'supplier minimum unit order quantity' => null,
            'supplier purchase order item delivery cost' => null,
            'supplier drop shipment item delivery cost' => null,
            'suppliers reference' => null,
            'product priority supplier stock' => null,
            'product sum of supplier stock' => null,
            'margin' => null,
            'lead time days' => null,
            'desired stock level' => null,
            'packaging size' => null,
            'record serial number on barcode despatch' => null,
            'hazardous material' => null,
            'is limited quantity dangerous goods lqdg' => null,
            'override domestic limited quantity dangerous goods lqdg for royal mail' => null,
            'isfragile' => null,
            'is bladed' => null,
            'is firearm' => null,
            'firearm type' => null,
            'product type description' => null,
            'detailed product type description' => null,
            'product composition' => null,
            'product harmonised code' => null,
            'country of manufacture' => null,
            'cpsc certifier id' => null,
            'cpsc certificate version id' => null,
            'cpsc product standardised identifier' => null,
            'cpsc intended use code' => null,
            'cpsc intended use description' => null,
            'cpsc disclaim reason' => null,
            'is cpsc regulated' => null,
            'eudr dds reference number' => null,
            'eudr taric code' => null,
            'product mid code' => null,
            'non standard mid code' => null,
            'pga reference number' => null,
            'product type' => null,
            'calculated reorder level' => null,
            'sold last 7 days' => null,
            'sold last 30 days' => null,
            'sold last 60 days' => null,
            'sold last 90 days' => null,
            'sold last 120 days' => null,
            'sold last 365 days' => null,
            'sold all time' => null,
            'days covered' => null,
            'last ordered date' => null,
            'days since last ordered' => null,
            'is a kit' => null,
            'component skus' => 'component_skus',
            'component quantities' => 'component_qtys',
            'component delete first products' => null,
            'component display kit products' => null,
            'component update inventories' => null,
            'component of kit skus' => null,
            'product creation date' => null,
            'product notes created date' => null,
            'product notes last modified date' => null,
            'product notes user added by' => null,
            'product notes add to picker instructions' => null,
            'product notes add to purchase orders' => null,
            'product notes confirmation required on barcode despatch' => null,
            'warehouse zone' => null,
            'product special attribute' => null,
            'customs declaration category' => null,
            'company identity' => null,
            'gpsr manufacturer' => null,
            'repair score' => null,
            'energy efficiency image url' => null,
            'energy efficiency description' => null,
            'energy efficiency product info sheet url' => null,
            'hazmat component' => null,
            'hazmat pictogram' => null,
            'hazmat signal word' => null,
            'hazmat statement' => null,
            'responsible person reference' => null,
            'responsible person email' => null,
            'responsible person company' => null,
            'responsible person first name' => null,
            'responsible person last name' => null,
            'responsible person address line 1' => null,
            'responsible person address line 2' => null,
            'responsible person address line 3' => null,
            'responsible person city' => null,
            'responsible person county' => null,
            'responsible person postcode' => null,
            'responsible person country' => null,
        ];
    }

    private function normHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = (string) preg_replace('/\s*\(read only\)\s*/i', '', $header);
        $header = (string) preg_replace('/[^a-z0-9]+/', ' ', $header);

        return trim($header);
    }

    /**
     * @param array<string, string|null> $row
     */
    private function isHintOrBlankRow(array $row): bool
    {
        $useful = 0;
        $onlyReadonly = true;
        foreach ($row as $key => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (strtoupper($value) === 'READONLY') {
                continue;
            }
            $onlyReadonly = false;
            if (!str_starts_with((string) $key, '_skip_')) {
                $useful++;
            }
        }

        return $onlyReadonly || $useful === 0;
    }

    /**
     * @param array<string, string|null> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $raw): ?array
    {
        $sku = trim((string) ($raw['sku'] ?? $raw['variant_sku'] ?? ''));
        $parent = trim((string) ($raw['parent_sku'] ?? ''));
        $productSku = trim((string) ($raw['product_sku'] ?? ''));
        if ($parent === '') {
            $parent = $productSku !== '' ? $productSku : $sku;
        }
        if ($sku === '') {
            $sku = $parent;
        }
        if ($parent === '' || $sku === '') {
            return null;
        }

        $name = trim((string) ($raw['name'] ?? $raw['product_name'] ?? $raw['variant_name'] ?? $sku));
        $archived = $this->boolOr($raw['archived'] ?? '', false)
            || $this->boolOr($raw['discontinued'] ?? '', false);
        $active = $archived
            ? false
            : $this->boolOr($raw['product_active'] ?? $raw['variant_active'] ?? '', true);

        $image = trim((string) ($raw['image'] ?? ''));
        if ($image !== '') {
            $parts = preg_split('/[\r\n,|]+/', $image) ?: [];
            $image = trim((string) ($parts[0] ?? ''));
        }

        $weightKg = $this->floatOr($raw['weight'] ?? '', 0);
        $weightGrams = $weightKg > 0 ? (int) round($weightKg * 1000) : 0;

        $pack = (int) ($raw['pack_qty'] ?? 0);
        if ($pack < 1) {
            $pack = (int) ($raw['carton_qty'] ?? 0);
        }

        $category = trim((string) ($raw['category_slugs'] ?? $raw['category'] ?? ''));

        return [
            'product_sku'        => $parent,
            'variant_sku'        => $sku,
            'product_name'       => $name,
            'variant_name'       => trim((string) ($raw['variant_name'] ?? $name)),
            'product_active'     => $active ? '1' : '0',
            'variant_active'     => $active ? '1' : '0',
            'is_child'           => $parent !== $sku,
            'description'        => (string) ($raw['description'] ?? ''),
            'price'              => $raw['price'] ?? '',
            'cost'               => $raw['cost'] ?? '',
            'rrp'                => $raw['rrp'] ?? '',
            'stock'              => $raw['stock'] ?? '',
            'reorder_at'         => $raw['reorder_at'] ?? '',
            'image'              => $image,
            'barcode'            => trim((string) ($raw['barcode'] ?? '')),
            'upc'                => trim((string) ($raw['upc'] ?? '')),
            'mpn'                => trim((string) ($raw['mpn'] ?? '')),
            'brand'              => trim((string) ($raw['brand'] ?? '')),
            'warehouse'          => trim((string) ($raw['warehouse'] ?? '')),
            'notes'              => (string) ($raw['notes'] ?? ''),
            'tax_class'          => trim((string) ($raw['tax_class'] ?? '')),
            'price_includes_vat' => $this->boolOr($raw['price_includes_vat'] ?? '', false),
            'weight_grams'       => $weightGrams,
            'length_mm'          => $this->cmToMm($raw['length'] ?? ''),
            'width_mm'           => $this->cmToMm($raw['width'] ?? ''),
            'height_mm'          => $this->cmToMm($raw['height'] ?? ''),
            'pick_bin'           => trim((string) ($raw['pick_bin'] ?? '')),
            'supplier_sku'       => trim((string) ($raw['supplier_sku'] ?? '')),
            'supplier_name'      => trim((string) ($raw['supplier_name'] ?? '')),
            'pack_qty'           => $pack,
            'category_slugs'     => $category,
        ] + $raw;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function productPayload(array $data, string $productSku): array
    {
        $name = trim((string) ($data['product_name'] ?? $productSku));
        if ($name === '') {
            $name = $productSku;
        }

        return [
            'sku'                => $productSku,
            'name'               => $name,
            'description'        => (string) ($data['description'] ?? ''),
            'barcode'            => (string) ($data['barcode'] ?? ''),
            'upc'                => (string) ($data['upc'] ?? ''),
            'mpn'                => (string) ($data['mpn'] ?? ''),
            'brand'              => (string) ($data['brand'] ?? ''),
            'warehouse'          => (string) ($data['warehouse'] ?? ''),
            'image'              => (string) ($data['image'] ?? ''),
            'notes'              => (string) ($data['notes'] ?? ''),
            'price'              => $this->floatOr($data['price'] ?? '', 0),
            'rrp'                => $this->floatOr($data['rrp'] ?? '', 0),
            'cost'               => $this->floatOr($data['cost'] ?? '', 0),
            'tax_class'          => (string) ($data['tax_class'] ?? ''),
            'price_includes_vat' => !empty($data['price_includes_vat']),
            'weight_grams'       => (int) ($data['weight_grams'] ?? 0),
            'length_mm'          => (int) ($data['length_mm'] ?? 0),
            'width_mm'           => (int) ($data['width_mm'] ?? 0),
            'height_mm'          => (int) ($data['height_mm'] ?? 0),
            'supplier_sku'       => (string) ($data['supplier_sku'] ?? ''),
            'supplier_name'      => (string) ($data['supplier_name'] ?? ''),
            'active'             => $this->boolOr($data['product_active'] ?? '', true),
        ];
    }

    private function cmToMm(mixed $value): int
    {
        $n = $this->floatOr($value, 0);
        if ($n <= 0) {
            return 0;
        }

        return (int) round($n * 10);
    }

    /**
     * @param array<string, array{id:?int, values:array<string,int>}> $attributeMap
     * @return array{0:int,1:int}|null [attribute_id, value_id]
     */
    private function resolveAttributeValue(string $code, string $value, array &$attributeMap): ?array
    {
        $code = AttributeRepository::sanitizeCode($code);
        $value = trim($value);
        if ($code === '' || $value === '' || AttributeRepository::isSystemName(str_replace('_', ' ', $code))) {
            return null;
        }

        if (!isset($attributeMap[$code])) {
            $names = CatalogSetupService::names();
            $name = $names[$code] ?? ucwords(str_replace('_', ' ', $code));
            $attr = $this->attributes->ensure($code, $name, [$value]);
            $attributeMap[$code] = ['id' => $attr->id, 'values' => []];
            foreach ($attr->values as $v) {
                $attributeMap[$code]['values'][$v->value] = (int) $v->id;
            }
        }

        $attrId = (int) ($attributeMap[$code]['id'] ?? 0);
        if ($attrId <= 0) {
            return null;
        }

        foreach ($attributeMap[$code]['values'] as $have => $id) {
            if (CatalogSetupService::valuesMatch($value, (string) $have)) {
                return [$attrId, (int) $id];
            }
        }

        $created = $this->attributes->ensureValue($attrId, $value, $value);
        if ($created === null || $created->id === null) {
            return null;
        }
        $attributeMap[$code]['values'][$created->value] = (int) $created->id;

        return [$attrId, (int) $created->id];
    }
}

final class CsvImportSkip extends RuntimeException
{
}