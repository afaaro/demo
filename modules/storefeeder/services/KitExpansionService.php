<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use System\Library\Database;

/**
 * Expand kit / bundle product lines into component order lines (Additions & Swaps).
 *
 * Size-roll SKUs (e.g. underlay `10mm (38.85x1.37)`) expand to a 1m base SKU
 * with qty = round(length × width), matching classic StoreFeeder kit allocation.
 */
final class KitExpansionService
{
    use TenantScoped;

    private const MAX_DEPTH = 3;

    public function __construct(
        private Database $db,
        private ProductBundleRepository $bundles,
    ) {}

    /**
     * Expand a channel line item into one or more rows ready for sf_order_items insert.
     *
     * @param array<string, mixed> $item Channel line (sku, title, quantity, lineItemId, lineItemCost)
     * @return array<int, array{
     *   external_line_item_id:string, variant_id:?int, external_sku:string, kit_sku:string,
     *   title:string, qty:int, price:float
     * }>
     */
    public function expandLineItem(array $item, int $depth = 0, string $originKitSku = ''): array
    {
        if (!TenantContext::get()->has()) {
            $sku = trim((string) ($item['sku'] ?? ''));
            $qty = self::extractQuantity($item);
            return [$this->row(
                (string) ($item['lineItemId'] ?? ''),
                null,
                $sku,
                $originKitSku,
                self::formatTitle($item),
                $qty,
                self::extractUnitPrice($item, $qty)
            )];
        }

        $sku = trim((string) ($item['sku'] ?? ''));
        $qty = self::extractQuantity($item);
        $title = self::formatTitle($item);
        $lineId = (string) ($item['lineItemId'] ?? '');
        $price = self::extractUnitPrice($item, $qty);
        $size = self::parseSizeRoll($sku, $title, $item);

        $variant = $this->findVariantBySku($sku);
        if ($variant === null || $depth >= self::MAX_DEPTH) {
            // Unlinked size-roll: still expand to base SKU when that product exists.
            if ($variant === null && $size !== null && $depth === 0) {
                return $this->expandSizeRollWithoutParent(
                    $item,
                    $sku,
                    $title,
                    $lineId,
                    $qty,
                    $price,
                    $size,
                    $originKitSku
                );
            }

            return [$this->row(
                $lineId,
                $variant['id'] ?? null,
                $sku,
                $originKitSku,
                $title,
                $qty,
                $price
            )];
        }

        $productId = (int) $variant['product_id'];
        $components = $this->bundles->forProduct($productId);
        $lineSku = $sku !== '' ? $sku : (string) $variant['sku'];
        $lineTitle = $title !== '' ? $title : (string) ($variant['name'] ?? $lineSku);

        // Auto-wire size roll → 1m base SKU when Additions & Swaps is empty.
        if ($components === [] && $size !== null) {
            $components = $this->autoSizeComponents($size);
        }

        // Pack variants (8 x / 16 x / …) expand onto the 1× sibling.
        $packComponents = [];
        if ($components === []) {
            $packComponents = $this->autoPackComponents($variant, $sku, $lineTitle);
            $components = $packComponents;
        }

        if ($components === []) {
            return [$this->row(
                $lineId,
                (int) $variant['id'],
                $lineSku,
                $originKitSku,
                $lineTitle,
                $qty,
                $price
            )];
        }

        $displayKit = $this->kitDisplayOnOrder($productId);
        // delete_original=Yes on any component means hide the kit parent line (classic SF).
        $deleteOriginal = false;
        foreach ($components as $comp) {
            if (!empty($comp['delete_original'])) {
                $deleteOriginal = true;
                break;
            }
        }
        $showKitLine = $displayKit && !$deleteOriginal;
        if ($packComponents !== []) {
            $showKitLine = false;
        }

        $childOrigin = $originKitSku !== '' ? $originKitSku : $lineSku;
        $out = [];

        foreach ($components as $i => $comp) {
            $compSku = (string) $comp['component_sku'];
            $bundleQty = max(1, (int) $comp['qty']);
            $compQty = $this->componentQuantity($qty, $bundleQty, $size, $compSku, count($components));

            $compVariant = $this->resolveComponentVariant(
                (int) $comp['component_product_id'],
                $compSku
            );

            $compItem = [
                'sku'          => $compVariant !== null ? (string) $compVariant['sku'] : $compSku,
                'title'        => (string) ($comp['component_name'] !== '' ? $comp['component_name'] : $compSku),
                'quantity'     => $compQty,
                'lineItemId'   => $lineId !== '' ? $lineId . ':c' . ($i + 1) : '',
                'lineItemCost' => ['value' => 0],
                '_unit_priced' => true,
            ];

            foreach ($this->expandLineItem($compItem, $depth + 1, $childOrigin) as $row) {
                $out[] = $row;
            }
        }

        if ($showKitLine || $out === []) {
            array_unshift($out, $this->row(
                $lineId,
                (int) $variant['id'],
                $lineSku,
                $originKitSku,
                $lineTitle,
                $qty,
                $price
            ));
        } elseif ($out !== []) {
            // Kit hidden on order: keep channel price on the first pickable line.
            $out[0]['price'] = $price;
        }

        // Note: update_kit_inventory is reserved for stock-adjustment side effects
        // when component lines are fulfilled (handled by InventoryService paths).

        return $out;
    }

    /**
     * @param array{length:float,width:float,units:int,prefix:string,base_sku:string} $size
     * @return array<int, array{external_line_item_id:string, variant_id:?int, external_sku:string, kit_sku:string, title:string, qty:int, price:float}>
     */
    private function expandSizeRollWithoutParent(
        array $item,
        string $sku,
        string $title,
        string $lineId,
        int $qty,
        float $price,
        array $size,
        string $originKitSku,
    ): array {
        $components = $this->autoSizeComponents($size);
        if ($components === []) {
            return [$this->row($lineId, null, $sku, $originKitSku, $title, $qty, $price)];
        }

        $childOrigin = $originKitSku !== '' ? $originKitSku : $sku;
        $out = [];
        foreach ($components as $i => $comp) {
            $compSku = (string) $comp['component_sku'];
            $compQty = $this->componentQuantity($qty, 1, $size, $compSku, count($components));
            $compVariant = $this->findVariantBySku($compSku);
            $compItem = [
                'sku'          => $compVariant !== null ? (string) $compVariant['sku'] : $compSku,
                'title'        => (string) ($comp['component_name'] !== '' ? $comp['component_name'] : $compSku),
                'quantity'     => $compQty,
                'lineItemId'   => $lineId !== '' ? $lineId . ':c' . ($i + 1) : '',
                'lineItemCost' => ['value' => 0],
                '_unit_priced' => true,
            ];
            foreach ($this->expandLineItem($compItem, 1, $childOrigin) as $row) {
                $out[] = $row;
            }
        }

        array_unshift($out, $this->row($lineId, null, $sku, $originKitSku, $title, $qty, $price));
        return $out;
    }

    /**
     * @param array{length:float,width:float,units:int,prefix:string,base_sku:string}|null $size
     */
    private function componentQuantity(
        int $buyerQty,
        int $bundleQty,
        ?array $size,
        string $compSku,
        int $componentCount,
    ): int {
        if ($size !== null && $this->shouldApplySizeUnits($size, $compSku, $componentCount)) {
            // Size-derived pick qty (e.g. 38.85×1.37 → 53 of the 1m base SKU).
            return max(1, $buyerQty * $size['units']);
        }

        return max(1, $buyerQty * max(1, $bundleQty));
    }

    /**
     * @param array{length:float,width:float,units:int,prefix:string,base_sku:string} $size
     */
    private function shouldApplySizeUnits(array $size, string $compSku, int $componentCount): bool
    {
        $compSku = trim($compSku);
        if ($compSku !== '' && strcasecmp($compSku, $size['base_sku']) === 0) {
            return true;
        }
        // Base meter SKU pattern: "(1m x …)" / "(1 x …)"
        if (preg_match('/\(\s*1(?:\.0+)?\s*m?\s*[xX×]/u', $compSku) === 1) {
            return true;
        }

        return $componentCount === 1;
    }

    /**
     * @param array{length:float,width:float,units:int,prefix:string,base_sku:string} $size
     * @return list<array{component_product_id:int, component_sku:string, component_name:string, qty:int}>
     */
    /**
     * @param array{id:int, product_id:int, sku:string, name:string} $variant
     * @return list<array{component_product_id:int, component_sku:string, component_name:string, qty:int}>
     */
    private function autoPackComponents(array $variant, string $sku, string $title): array
    {
        $productId = (int) $variant['product_id'];
        if ($productId <= 0 || !TenantContext::get()->has()) {
            return [];
        }

        PackInventory::ensureColumn($this->db);
        $rows = $this->db->query(
            "SELECT `id`, `product_id`, `sku`, `name`, COALESCE(`pack_qty`, 0) AS pack_qty
               FROM `#__sf_product_variants`
              WHERE `product_id` = ? AND `tenant_id` = ?
              ORDER BY `id` ASC",
            [$productId, $this->tenantId()]
        )->rows;
        $siblings = [];
        foreach ($rows as $row) {
            $siblings[] = [
                'id'       => (int) $row['id'],
                'sku'      => (string) $row['sku'],
                'name'     => (string) ($row['name'] ?? ''),
                'pack_qty' => (int) ($row['pack_qty'] ?? 0),
            ];
        }

        $family = PackInventory::familyFor($siblings, (int) ($variant['id'] ?? 0));
        if ($family === null) {
            $family = PackInventory::family($siblings);
        }
        if ($family === null) {
            return [];
        }
        $packQty = (int) ($family['pack_qty'][(int) ($variant['id'] ?? 0)] ?? 0);
        if ($packQty <= 1) {
            $packQty = PackInventory::qtyFor([
                'sku'      => $sku !== '' ? $sku : (string) $variant['sku'],
                'name'     => $title !== '' ? $title : (string) ($variant['name'] ?? ''),
                'pack_qty' => (int) ($variant['pack_qty'] ?? 0),
            ]);
        }
        if ($packQty <= 1) {
            return [];
        }
        $baseId = (int) $family['base_id'];
        $base = null;
        foreach ($siblings as $row) {
            if ((int) $row['id'] === $baseId) {
                $base = $row;
                break;
            }
        }
        if ($base === null) {
            return [];
        }

        return [[
            'component_product_id' => $productId,
            'component_sku'        => (string) $base['sku'],
            'component_name'       => (string) ($base['name'] !== '' ? $base['name'] : $base['sku']),
            'qty'                  => $packQty,
        ]];
    }

    private function autoSizeComponents(array $size): array
    {
        $base = $this->findVariantBySku($size['base_sku']);
        if ($base === null) {
            return [];
        }

        return [[
            'component_product_id' => (int) $base['product_id'],
            'component_sku'        => (string) $base['sku'],
            'component_name'       => (string) ($base['name'] !== '' ? $base['name'] : $base['sku']),
            'qty'                  => 1,
        ]];
    }

    /**
     * Parse roll/area size from SKU, title, or eBay variation aspects.
     * Example: SKU `10mm (38.85x1.37)` → 53 units of `10mm (1m x 1.37m)`.
     *
     * @param array<string, mixed> $item
     * @return array{length:float,width:float,units:int,prefix:string,base_sku:string}|null
     */
    public static function parseSizeRoll(string $sku, string $title = '', array $item = []): ?array
    {
        $candidates = array_filter([$sku, $title]);
        $aspects = $item['variationAspects'] ?? null;
        if (is_array($aspects)) {
            foreach ($aspects as $asp) {
                if (!is_array($asp)) {
                    continue;
                }
                $value = trim((string) ($asp['value'] ?? ''));
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        $length = null;
        $width = null;
        foreach ($candidates as $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            // Already a 1m base unit — do not treat as a size kit.
            if (preg_match('/\(\s*1(?:\.0+)?\s*m?\s*[xX×]/u', $text) === 1) {
                return null;
            }
            if (preg_match('/\((\d+(?:\.\d+)?)\s*[xX×]\s*(\d+(?:\.\d+)?)m?\s*\)/u', $text, $m) === 1) {
                $length = (float) $m[1];
                $width = (float) $m[2];
                break;
            }
            if (preg_match('/(\d+(?:\.\d+)?)\s*[xX×]\s*(\d+(?:\.\d+)?)m?\b/u', $text, $m) === 1) {
                $length = (float) $m[1];
                $width = (float) $m[2];
                break;
            }
        }

        if ($length === null || $width === null || $length <= 1.01) {
            return null;
        }

        $prefix = '';
        if (preg_match('/^(.+?)\s*\(/u', trim($sku), $pm) === 1) {
            $prefix = trim($pm[1]);
        }
        if ($prefix === '' && preg_match('/^(\d+(?:\.\d+)?mm)\b/iu', trim($sku), $pm) === 1) {
            $prefix = $pm[1];
        }

        $units = max(1, (int) round($length * $width));
        $widthStr = self::formatDim($width);
        $baseSku = $prefix !== ''
            ? $prefix . ' (1m x ' . $widthStr . 'm)'
            : '1m x ' . $widthStr . 'm';

        return [
            'length'   => $length,
            'width'    => $width,
            'units'    => $units,
            'prefix'   => $prefix,
            'base_sku' => $baseSku,
        ];
    }

    private static function formatDim(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        return $formatted !== '' ? $formatted : '0';
    }

    /**
     * Buyer-ordered qty from channel payloads (eBay / Amazon / Shopify / Trading API).
     *
     * @param array<string, mixed> $item
     */
    public static function extractQuantity(array $item): int
    {
        foreach ([
            'quantity',
            'QuantityPurchased',
            'QuantityOrdered',
            'quantityOrdered',
            'qty',
            'Quantity',
        ] as $key) {
            if (!array_key_exists($key, $item) || $item[$key] === null || $item[$key] === '') {
                continue;
            }
            if (is_numeric($item[$key])) {
                return max(1, (int) $item[$key]);
            }
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function extractUnitPrice(array $item, int $qty): float
    {
        if (!empty($item['_unit_priced']) && isset($item['lineItemCost']['value'])) {
            return round((float) $item['lineItemCost']['value'], 4);
        }

        $costRaw = $item['discountedLineItemCost']['value']
            ?? $item['lineItemCost']['value']
            ?? $item['price']
            ?? 0;
        $cost = (float) $costRaw;
        if ($cost <= 0) {
            return 0.0;
        }

        if ($qty > 1) {
            return round($cost / $qty, 4);
        }

        return round($cost, 4);
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function formatTitle(array $item): string
    {
        $title = trim((string) ($item['title'] ?? ''));
        $aspects = $item['variationAspects'] ?? null;
        if (!is_array($aspects) || $aspects === []) {
            return $title;
        }

        $bits = [];
        foreach ($aspects as $asp) {
            if (!is_array($asp)) {
                continue;
            }
            $name = trim((string) ($asp['name'] ?? ''));
            $value = trim((string) ($asp['value'] ?? ''));
            if ($name !== '' && $value !== '') {
                $bits[] = $name . ' - ' . $value;
            }
        }
        if ($bits === []) {
            return $title;
        }

        $suffix = implode(', ', $bits);
        return $title === '' ? $suffix : $title . ' (' . $suffix . ')';
    }

    /**
     * @return array{
     *   external_line_item_id:string, variant_id:?int, external_sku:string, kit_sku:string,
     *   title:string, qty:int, price:float
     * }
     */
    private function row(
        string $lineId,
        mixed $variantId,
        string $sku,
        string $kitSku,
        string $title,
        int $qty,
        float $price,
    ): array {
        return [
            'external_line_item_id' => $lineId,
            'variant_id'            => $variantId !== null ? (int) $variantId : null,
            'external_sku'          => $sku,
            'kit_sku'               => $kitSku,
            'title'                 => $title,
            'qty'                   => $qty,
            'price'                 => $price,
        ];
    }

    /** @return array{id:int, product_id:int, sku:string, name:string, pack_qty?:int}|null */
    private function findVariantBySku(string $sku): ?array
    {
        if ($sku === '') {
            return null;
        }
        PackInventory::ensureColumn($this->db);
        $tid = $this->tenantId();
        $row = $this->db->query(
            "SELECT `id`, `product_id`, `sku`, `name`, COALESCE(`pack_qty`, 0) AS pack_qty
               FROM `#__sf_product_variants`
              WHERE `sku` = ?
                AND `tenant_id` = ?
              LIMIT 1",
            [$sku, $tid]
        )->row;

        if ($row === []) {
            $product = $this->db->query(
                "SELECT `id`, `sku`, `name` FROM `#__sf_products`
                  WHERE `sku` = ? AND `tenant_id` = ?
                  LIMIT 1",
                [$sku, $tid]
            )->row;
            if ($product === []) {
                return null;
            }
            $v = $this->db->query(
                "SELECT `id`, `product_id`, `sku`, `name`, COALESCE(`pack_qty`, 0) AS pack_qty
                   FROM `#__sf_product_variants`
                  WHERE `product_id` = ?
                    AND `tenant_id` = ?
                  ORDER BY `id` ASC
                  LIMIT 1",
                [(int) $product['id'], $tid]
            )->row;
            if ($v === []) {
                return null;
            }
            return [
                'id'         => (int) $v['id'],
                'product_id' => (int) $v['product_id'],
                'sku'        => (string) $v['sku'],
                'name'       => (string) ($v['name'] !== '' ? $v['name'] : $product['name']),
            ];
        }

        return [
            'id'         => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'sku'        => (string) $row['sku'],
            'name'       => (string) ($row['name'] ?? ''),
        ];
    }

    /** @return array{id:int, product_id:int, sku:string, name:string}|null */
    private function resolveComponentVariant(int $productId, string $preferredSku): ?array
    {
        if ($preferredSku !== '') {
            $bySku = $this->findVariantBySku($preferredSku);
            if ($bySku !== null && (int) $bySku['product_id'] === $productId) {
                return $bySku;
            }
        }

        if (!TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT `id`, `product_id`, `sku`, `name`
               FROM `#__sf_product_variants`
              WHERE `product_id` = ?
                AND `tenant_id` = ?
              ORDER BY `active` DESC, `id` ASC
              LIMIT 1",
            [$productId, $this->tenantId()]
        )->row;

        if ($row === []) {
            return null;
        }

        return [
            'id'         => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'sku'        => (string) $row['sku'],
            'name'       => (string) ($row['name'] ?? ''),
        ];
    }

    private function kitDisplayOnOrder(int $productId): bool
    {
        if (!TenantContext::get()->has()) {
            return true;
        }
        $row = $this->db->query(
            "SELECT `kit_display_on_order` FROM `#__sf_products`
              WHERE `id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$productId, $this->tenantId()]
        )->row;
        if ($row === []) {
            return true;
        }
        return !array_key_exists('kit_display_on_order', $row) || !empty($row['kit_display_on_order']);
    }
}
