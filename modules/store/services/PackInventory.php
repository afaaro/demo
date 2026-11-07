<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

/**
 * Derive pack-variant sellable qty from a 1× sibling, matching Store:
 * "8 x Gripper Sticks" stock = floor(1× stock / 8).
 * Size rolls like "8mm (3.7m x 1.37m)" stock = floor(1m sibling / round(L×W)),
 * grouped by thickness prefix + width so 8mm / 10mm / 12mm each have their own base.
 * Length-only rolls like "Vinyl 10m" use a "1m" sibling of the same prefix.
 * Anything else: explicit pack_qty, or a Pack Size attribute.
 */
final class PackInventory
{
    public static function ensureColumn(Database $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $table = $db->getPrefix() . 'store_product_variants';
        $exists = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = 'pack_qty'",
            [$table]
        )->value;
        if ($exists === 0) {
            $db->query(
                "ALTER TABLE `#__store_product_variants`
                   ADD COLUMN `pack_qty` int UNSIGNED NOT NULL DEFAULT 0 AFTER `name`"
            );
        }
    }

    /**
     * Pack size for a variant: explicit pack_qty, then Pack Size attribute, then name/SKU.
     *
     * @param array{sku?:string, name?:string, pack_qty?:int, pack_label?:string} $row
     */
    public static function qtyFor(array $row): int
    {
        $explicit = (int) ($row['pack_qty'] ?? 0);
        if ($explicit > 0) {
            return min(20000, $explicit);
        }

        $fromAttr = self::parseQtyFromText((string) ($row['pack_label'] ?? ''));
        if ($fromAttr > 0) {
            return $fromAttr;
        }

        $packLabel = trim((string) ($row['pack_label'] ?? ''));
        if (preg_match('/^\d{1,5}$/', $packLabel) === 1) {
            $n = (int) $packLabel;
            if ($n >= 1 && $n <= 20000) {
                return $n;
            }
        }

        return self::parseQty((string) ($row['sku'] ?? ''), (string) ($row['name'] ?? ''));
    }

    /**
     * Units in a pack from name/SKU.
     * Matches "8 x …", "pack of 8", "box of 8", "8-pack", "8pk", "x8", pair/dozen, …
     * Size rolls like "38.85x1.37" (no space after x) are ignored.
     */
    public static function parseQty(string $sku, string $name = ''): int
    {
        foreach ([$name, $sku] as $text) {
            $n = self::parseQtyFromText($text);
            if ($n > 0) {
                return $n;
            }
        }

        return 1;
    }

    public static function parseQtyFromText(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        $patterns = [
            '/(?:^|[^0-9.])(\d+)\s*[xX×]\s+/u',
            '/\b(?:pack|packs|box|boxes|case|cases|set|sets|carton|cartons|tray|trays|bundle|bundles|bag|bags)\s+of\s+(\d+)\b/iu',
            '/\b(\d+)\s*-?\s*packs?\b/iu',
            '/\b(\d+)\s*-?\s*pks?\b/iu',
            '/\b(\d+)\s*-?\s*(?:pcs?|pieces?)\b/iu',
            '/(?:^|[^\d])[xX×]\s*(\d+)\b/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $n = (int) $m[1];
                if ($n >= 1 && $n <= 20000) {
                    return $n;
                }
            }
        }

        $words = [
            '/\bhalf[-\s]?dozen\b/iu' => 6,
            '/\bdozen\b/iu'           => 12,
            '/\b(?:pair|pairs|twin)\b/iu' => 2,
            '/\btriple\b/iu'          => 3,
            '/\b(?:quad|quadruple)\b/iu' => 4,
            '/\bsingles?\b/iu'        => 1,
        ];
        foreach ($words as $pattern => $n) {
            if (preg_match($pattern, $text) === 1) {
                return $n;
            }
        }

        return 0;
    }

    /**
     * Size-roll parse including 1m bases. Null for N× pack names.
     *
     * @return array{length:float,width:float,units:int,prefix:string,is_base:bool}|null
     */
    public static function parseSize(string $sku, string $name = '', string $sizeLabel = ''): ?array
    {
        if (self::parseQty($sku, $name) > 1) {
            return null;
        }

        $length = null;
        $width = null;
        $prefix = '';
        foreach ([$sku, $name] as $text) {
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            if (preg_match('/\((\d+(?:\.\d+)?)\s*m?\s*[xX×]\s*(\d+(?:\.\d+)?)m?\s*\)/u', $text, $m) === 1) {
                $length = (float) $m[1];
                $width = (float) $m[2];
                break;
            }
        }
        if ($length === null) {
            foreach ([$sku, $name] as $text) {
                $text = trim($text);
                if ($text === '') {
                    continue;
                }
                if (preg_match('/^(\d+(?:\.\d+)?mm)\b.*?(\d+(?:\.\d+)?)[xX×](\d+(?:\.\d+)?)/iu', $text, $m) === 1) {
                    $prefix = $m[1];
                    $length = (float) $m[2];
                    $width = (float) $m[3];
                    break;
                }
            }
        }

        if ($length === null || $width === null || $length <= 0 || $width <= 0) {
            return null;
        }

        if ($prefix === '' && preg_match('/^(.+?)\s*\(/u', trim($sku), $pm) === 1) {
            $prefix = trim($pm[1]);
        }
        if ($prefix === '' && preg_match('/^(\d+(?:\.\d+)?mm)\b/iu', trim($sku), $pm) === 1) {
            $prefix = $pm[1];
        }
        if ($prefix === '' && preg_match('/^(.+?)\s*\(/u', trim($name), $pm) === 1) {
            $prefix = trim($pm[1]);
        }

        $isBase = $length <= 1.01;
        $units = max(1, (int) round($length * $width));
        if (!$isBase && preg_match('/(\d+(?:\.\d+)?)\s*m\s*[²2]/iu', $sizeLabel, $sm) === 1) {
            $units = max(1, (int) round((float) $sm[1]));
        }

        return [
            'length'  => $length,
            'width'   => $width,
            'units'   => $units,
            'prefix'  => $prefix,
            'is_base' => $isBase,
        ];
    }

    /**
     * Length-only rolls: "Vinyl 10m", or pipe insulation
     * "Diameter 15m - Thick 9m - Lenght 2m" (labelled length, not the Diameter metres).
     *
     * @return array{length:float,units:int,prefix:string,is_base:bool}|null
     */
    public static function parseLength(string $sku, string $name = '', string $metresLabel = ''): ?array
    {
        if (self::parseQty($sku, $name) > 1) {
            return null;
        }
        if (self::parseSize($sku, $name) !== null) {
            return null;
        }

        $length = self::parseMetresValue($metresLabel);

        if ($length === null) {
            foreach ([$sku, $name] as $text) {
                $text = trim($text);
                if ($text === '') {
                    continue;
                }
                // Prefer "Lenght 2m" / "Length: 3m" / "Metres 5m" so Diameter 15m is not the length.
                if (preg_match(
                    '/\b(?:leng(?:th|ht)|metres?|meters?)\s*[:\-]?\s*(\d+(?:\.\d+)?)\s*(?:metres?|meters?|m)\b/iu',
                    $text,
                    $m
                ) === 1) {
                    $length = (float) $m[1];
                    break;
                }
            }
        }

        if ($length === null) {
            foreach ([$sku, $name] as $text) {
                $text = trim($text);
                if ($text === '' || preg_match('/\bdiameter\b/iu', $text) === 1) {
                    continue;
                }
                if (preg_match('/\((\d+(?:\.\d+)?)\s*(?:metres?|meters?|m)\)/iu', $text, $m) === 1) {
                    $length = (float) $m[1];
                    break;
                }
                if (preg_match('/(?:^|[\s\-_\/])(\d+(?:\.\d+)?)\s*(?:metres?\b|meters?\b|m(?!m)\b)/iu', $text, $m) === 1) {
                    $length = (float) $m[1];
                    break;
                }
                if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:metres?\b|meters?\b|m(?!m)\b)/iu', $text, $m) === 1) {
                    $length = (float) $m[1];
                    break;
                }
            }
        }

        if ($length === null || $length <= 0) {
            return null;
        }

        $source = trim($sku !== '' ? $sku : $name);
        $prefix = trim((string) preg_replace(
            '/[-\s]*\b(?:leng(?:th|ht)|metres?|meters?)\s*[:\-]?\s*\d+(?:\.\d+)?\s*(?:metres?|meters?|m)\b/iu',
            '',
            $source
        ), " \t-");
        if ($prefix === '' || strcasecmp($prefix, $source) === 0) {
            $prefix = trim((string) preg_replace(
                [
                    '/\((\d+(?:\.\d+)?)\s*(?:metres?|meters?|m)\)/iu',
                    '/(?:^|[\s\-_\/])(\d+(?:\.\d+)?)\s*(?:metres?\b|meters?\b|m(?!m)\b)/iu',
                ],
                ' ',
                $source
            ));
        }
        $prefix = trim((string) preg_replace('/[\s\-]+/u', ' ', $prefix));

        return [
            'length'  => $length,
            'units'   => max(1, (int) round($length)),
            'prefix'  => $prefix,
            'is_base' => $length <= 1.01,
        ];
    }

    public static function parseMetresValue(string $label): ?float
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:metres?|meters?|m)\b/iu', $label, $m) === 1) {
            return (float) $m[1];
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $label) === 1) {
            return (float) $label;
        }

        return null;
    }

    /**
     * @param list<array{id:int, sku:string, name:string, pack_qty?:int, pack_label?:string, available?:int, size_label?:string}> $siblings
     * @return array{base_id:int, pack_qty:array<int,int>}|null
     */
    public static function family(array $siblings): ?array
    {
        return self::nxFamily($siblings);
    }

    /**
     * @param list<array{id:int, sku:string, name:string, pack_qty?:int, pack_label?:string, available?:int, size_label?:string}> $siblings
     * @return array{base_id:int, pack_qty:array<int,int>}|null
     */
    public static function familyFor(array $siblings, int $variantId): ?array
    {
        foreach (self::families($siblings) as $family) {
            if (isset($family['pack_qty'][$variantId])) {
                return $family;
            }
        }

        return null;
    }

    /**
     * All pack families on a product: N× packs, size-rolls, then length-only rolls.
     *
     * @param list<array{id:int, sku:string, name:string, pack_qty?:int, pack_label?:string, available?:int, size_label?:string}> $siblings
     * @return list<array{base_id:int, pack_qty:array<int,int>}>
     */
    public static function families(array $siblings): array
    {
        $out = [];
        $used = [];
        $nx = self::nxFamily($siblings);
        if ($nx !== null) {
            $out[] = $nx;
            foreach (array_keys($nx['pack_qty']) as $id) {
                $used[(int) $id] = true;
            }
        }
        foreach (self::sizeFamilies($siblings, $used) as $family) {
            $out[] = $family;
            foreach (array_keys($family['pack_qty']) as $id) {
                $used[(int) $id] = true;
            }
        }
        foreach (self::lengthFamilies($siblings, $used) as $family) {
            $out[] = $family;
        }

        return $out;
    }

    /**
     * True when this variant is a size or length roll (not an N× pack).
     *
     * @param array{sku?:string, name?:string, size_label?:string} $row
     */
    private static function isRollRow(array $row): bool
    {
        $sku = (string) ($row['sku'] ?? '');
        $name = (string) ($row['name'] ?? '');
        $sizeLabel = (string) ($row['size_label'] ?? '');

        return self::parseSize($sku, $name, $sizeLabel) !== null
            || self::parseLength($sku, $name, (string) ($row['metres_label'] ?? '')) !== null;
    }

    /**
     * @param list<array{id:int, sku:string, name:string, pack_qty?:int, pack_label?:string, available?:int, size_label?:string}> $siblings
     * @return array{base_id:int, pack_qty:array<int,int>}|null
     */
    private static function nxFamily(array $siblings): ?array
    {
        if (count($siblings) < 2) {
            return null;
        }

        $packQty = [];
        $hasPack = false;
        $ones = [];
        foreach ($siblings as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || self::isRollRow($row)) {
                continue;
            }
            $qty = self::qtyFor($row);
            $packQty[$id] = $qty;
            if ($qty > 1) {
                $hasPack = true;
            }
            if ($qty === 1) {
                $ones[] = $row;
            }
        }

        if (!$hasPack || $ones === [] || count($packQty) < 2) {
            return null;
        }

        usort($ones, static function (array $a, array $b): int {
            return ((int) ($b['available'] ?? 0)) <=> ((int) ($a['available'] ?? 0));
        });

        return [
            'base_id'  => (int) $ones[0]['id'],
            'pack_qty' => $packQty,
        ];
    }

    /**
     * @param list<array{id:int, sku:string, name:string, pack_qty?:int, available?:int, size_label?:string}> $siblings
     * @param array<int, true> $used
     * @return list<array{base_id:int, pack_qty:array<int,int>}>
     */
    private static function sizeFamilies(array $siblings, array $used): array
    {
        $groups = [];
        foreach ($siblings as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($used[$id])) {
                continue;
            }
            $parsed = self::parseSize(
                (string) ($row['sku'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['size_label'] ?? '')
            );
            if ($parsed === null) {
                continue;
            }
            $key = strtolower($parsed['prefix']) . '|' . self::formatDim($parsed['width']);
            $groups[$key][] = ['row' => $row, 'parsed' => $parsed];
        }

        return self::rollGroupsToFamilies($groups);
    }

    /**
     * @param list<array{id:int, sku:string, name:string, pack_qty?:int, available?:int}> $siblings
     * @param array<int, true> $used
     * @return list<array{base_id:int, pack_qty:array<int,int>}>
     */
    private static function lengthFamilies(array $siblings, array $used): array
    {
        $groups = [];
        foreach ($siblings as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($used[$id])) {
                continue;
            }
            $attrs = self::attrMap($row);
            $metresLabel = trim((string) ($row['metres_label'] ?? ''));
            if ($metresLabel === '') {
                $metresLabel = self::metresFromAttrs($attrs);
            }
            $parsed = self::parseLength(
                (string) ($row['sku'] ?? ''),
                (string) ($row['name'] ?? ''),
                $metresLabel
            );
            if ($parsed === null) {
                continue;
            }
            $attrKey = self::groupKeyFromAttrs($attrs);
            $key = $attrKey !== '' ? $attrKey : strtolower($parsed['prefix']);
            $groups[$key][] = ['row' => $row, 'parsed' => $parsed];
        }

        return self::rollGroupsToFamilies($groups);
    }

    /**
     * @param array<string, list<array{row:array, parsed:array{units:int, is_base:bool}}>> $groups
     * @return list<array{base_id:int, pack_qty:array<int,int>}>
     */
    private static function rollGroupsToFamilies(array $groups): array
    {
        $out = [];
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }

            $packQty = [];
            $bases = [];
            $hasRoll = false;
            foreach ($members as $member) {
                $id = (int) $member['row']['id'];
                $packQty[$id] = max(1, (int) $member['parsed']['units']);
                if ($member['parsed']['is_base']) {
                    $bases[] = $member['row'];
                } else {
                    $hasRoll = true;
                }
            }
            if ($bases === [] || !$hasRoll) {
                continue;
            }

            usort($bases, static function (array $a, array $b): int {
                return ((int) ($b['available'] ?? 0)) <=> ((int) ($a['available'] ?? 0));
            });

            $out[] = [
                'base_id'  => (int) $bases[0]['id'],
                'pack_qty' => $packQty,
            ];
        }

        return $out;
    }

    private static function formatDim(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted !== '' ? $formatted : '0';
    }

    /**
     * @param array{attr_blob?:string} $row
     * @return array<string, string>
     */
    private static function attrMap(array $row): array
    {
        $blob = trim((string) ($row['attr_blob'] ?? ''));
        if ($blob === '') {
            return [];
        }
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $blob) ?: [] as $line) {
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $eq)));
            $value = trim(substr($line, $eq + 1));
            if ($name !== '' && $value !== '') {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $attrs
     */
    private static function metresFromAttrs(array $attrs): string
    {
        foreach ($attrs as $name => $value) {
            if (preg_match('/^(metres?|meters?|leng(?:th|ht))$/i', $name) === 1) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Identity other than length (Pipe Diameter + Insulation Thickness).
     *
     * @param array<string, string> $attrs
     */
    private static function groupKeyFromAttrs(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $name => $value) {
            if (preg_match('/^(metres?|meters?|leng(?:th|ht)|size|pack(?:\s+size|\s+qty)?)$/i', $name) === 1) {
                continue;
            }
            $parts[] = $name . '=' . strtolower($value);
        }
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @param array{base_id:int, pack_qty:array<int,int>} $family
     */
    public static function sellable(array $family, int $variantId, int $baseAvailable): int
    {
        $pack = (int) ($family['pack_qty'][$variantId] ?? 1);
        if ($pack <= 1 || $variantId === (int) $family['base_id']) {
            return max(0, $baseAvailable);
        }

        return (int) floor(max(0, $baseAvailable) / $pack);
    }

    /**
     * Units of the 1× SKU represented by $delta of this variant.
     *
     * @param array{base_id:int, pack_qty:array<int,int>} $family
     */
    public static function toBaseDelta(array $family, int $variantId, int $delta): array
    {
        $pack = (int) ($family['pack_qty'][$variantId] ?? 1);
        $baseId = (int) $family['base_id'];
        if ($pack <= 1 || $variantId === $baseId) {
            return ['variant_id' => $variantId, 'delta' => $delta];
        }

        return ['variant_id' => $baseId, 'delta' => $delta * $pack];
    }
}
