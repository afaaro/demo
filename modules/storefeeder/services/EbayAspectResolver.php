<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

/**
 * Resolve eBay Item Specifics (aspects) from channel defaults + product overrides.
 *
 * Channel rows: {name, source, value}
 *   source: fixed | product_brand | product_mpn | product_ean | product_upc | product_name
 * Product map: aspectName => list of string values
 */
final class EbayAspectResolver
{
    public const SOURCES = [
        'fixed'         => 'Fixed value',
        'product_brand' => 'Product Brand',
        'product_mpn'   => 'Product MPN',
        'product_ean'   => 'Product EAN / Barcode',
        'product_upc'   => 'Product UPC',
        'product_name'  => 'Product Name',
    ];

    /**
     * @param array<string, mixed> $channelSettings
     * @return list<array{name: string, source: string, value: string}>
     */
    public static function normalizeChannelRows(array $channelSettings): array
    {
        $raw = $channelSettings['general_attributes'] ?? [];
        if (!is_array($raw)) {
            return self::defaultChannelRows();
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $source = trim((string) ($row['source'] ?? 'fixed'));
            if (!isset(self::SOURCES[$source])) {
                $source = 'fixed';
            }
            $out[] = [
                'name'   => mb_substr($name, 0, 64),
                'source' => $source,
                'value'  => mb_substr(trim((string) ($row['value'] ?? '')), 0, 255),
            ];
        }
        return $out;
    }

    /** @return list<array{name: string, source: string, value: string}> */
    public static function defaultChannelRows(): array
    {
        return [
            ['name' => 'Brand', 'source' => 'product_brand', 'value' => ''],
            ['name' => 'MPN', 'source' => 'product_mpn', 'value' => ''],
            ['name' => 'Type', 'source' => 'fixed', 'value' => ''],
        ];
    }

    /**
     * Sensible General Attribute seeds per channel type.
     *
     * @return list<array{name: string, source: string, value: string}>
     */
    public static function defaultChannelRowsForType(string $type): array
    {
        return match (strtolower(trim($type))) {
            'amazon' => [
                ['name' => 'brand', 'source' => 'product_brand', 'value' => ''],
                ['name' => 'manufacturer', 'source' => 'product_brand', 'value' => ''],
                ['name' => 'ean', 'source' => 'product_ean', 'value' => ''],
            ],
            'bnq' => [
                ['name' => 'brand', 'source' => 'product_brand', 'value' => ''],
                ['name' => 'ean', 'source' => 'product_ean', 'value' => ''],
            ],
            default => self::defaultChannelRows(),
        };
    }

    /**
     * @param mixed $raw
     * @return array<string, list<string>>
     */
    public static function normalizeProductAspects(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        // Support list of {name,value} or map name=>value|list
        $isList = array_is_list($raw);
        if ($isList) {
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                $val = trim((string) ($row['value'] ?? ''));
                if ($name === '' || $val === '') {
                    continue;
                }
                $out[$name] ??= [];
                if (!in_array($val, $out[$name], true)) {
                    $out[$name][] = $val;
                }
            }
            return $out;
        }

        foreach ($raw as $name => $values) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            if (is_string($values) || is_numeric($values)) {
                $values = [(string) $values];
            }
            if (!is_array($values)) {
                continue;
            }
            $clean = [];
            foreach ($values as $v) {
                $v = trim((string) $v);
                if ($v !== '' && !in_array($v, $clean, true)) {
                    $clean[] = mb_substr($v, 0, 255);
                }
            }
            if ($clean !== []) {
                $out[mb_substr($name, 0, 64)] = $clean;
            }
        }
        return $out;
    }

    /**
     * @param list<array{name: string, source: string, value: string}> $channelRows
     * @param array<string, list<string>> $productAspects
     * @return array<string, list<string>> eBay Inventory API aspects shape
     */
    public static function resolve(Product $product, array $channelRows, array $productAspects = []): array
    {
        $out = [];

        foreach ($channelRows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $value = self::resolveSourceValue($product, (string) ($row['source'] ?? 'fixed'), (string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $out[$name] = [$value];
        }

        foreach ($productAspects as $name => $values) {
            if ($values === []) {
                continue;
            }
            $out[$name] = array_values($values);
        }

        // Always prefer product brand/mpn/ean when present and not already set by product aspects
        foreach ([
            'Brand' => trim($product->brand),
            'MPN'   => trim($product->mpn),
            'EAN'   => trim($product->barcode !== '' ? $product->barcode : $product->upc),
        ] as $name => $val) {
            if ($val === '') {
                continue;
            }
            if (!isset($productAspects[$name]) && !isset($out[$name])) {
                $out[$name] = [$val];
            }
        }

        return $out;
    }

    private static function resolveSourceValue(Product $product, string $source, string $fixed): string
    {
        return match ($source) {
            'product_brand' => trim($product->brand),
            'product_mpn'   => trim($product->mpn),
            'product_ean'   => trim($product->barcode !== '' ? $product->barcode : $product->upc),
            'product_upc'   => trim($product->upc !== '' ? $product->upc : $product->barcode),
            'product_name'  => trim($product->name),
            default         => trim($fixed),
        };
    }
}
