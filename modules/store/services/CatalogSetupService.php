<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

/**
 * Seed shared variant attributes used for pack / length stock derivation.
 */
final class CatalogSetupService
{
    use TenantScoped;

    public function __construct(
        private Database $db,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @return list<string> attribute codes that now exist
     */
    public function ensure(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }

        $defs = [
            [
                'code'  => 'pack_size',
                'name'  => 'Pack Size',
                'sort'  => 10,
                'values'=> [
                    ['value' => '1', 'label' => '1 ×'],
                    ['value' => '2', 'label' => '2 ×'],
                    ['value' => '8', 'label' => '8 ×'],
                    ['value' => '16', 'label' => '16 ×'],
                    ['value' => '24', 'label' => '24 ×'],
                ],
            ],
            [
                'code'  => 'thickness',
                'name'  => 'Thickness',
                'sort'  => 20,
                'values'=> ['6mm', '8mm', '10mm', '12mm', '15mm'],
            ],
            [
                'code'  => 'size',
                'name'  => 'Size',
                'sort'  => 30,
                'values'=> [
                    'Sample 8mm',
                    'Sample 10mm',
                    'Sample 12mm',
                    '2.5m2 (1.85x1.37)',
                    '5m2 (3.7x1.37)',
                    '7.5m2 (5.55x1.37)',
                    '10m2 (7.3x1.37)',
                    '12.5m2 (9.25x1.37)',
                    '15m2 (11x1.37)',
                    '17.5m2 (12.95x1.37)',
                ],
            ],
            [
                'code'  => 'pipe_diameter',
                'name'  => 'Pipe Diameter',
                'sort'  => 40,
                'values'=> ['15mm', '22mm', '28mm', '35mm', '42mm', '54mm', '60mm', '75mm'],
            ],
            [
                'code'  => 'insulation_thickness',
                'name'  => 'Insulation Thickness',
                'sort'  => 50,
                'values'=> ['9mm', '13mm', '19mm', '25mm'],
            ],
            [
                'code'  => 'metres',
                'name'  => 'Metres',
                'sort'  => 60,
                'values'=> ['1m', '2m', '3m', '4m', '5m'],
            ],
        ];

        $codes = [];
        foreach ($defs as $def) {
            $this->attributes->ensure(
                $def['code'],
                $def['name'],
                $def['values'],
                $def['sort']
            );
            $codes[] = $def['code'];
        }

        return $codes;
    }

    /**
     * Attribute codes to tick for a product layout.
     *
     * @return array<string, list<string>>
     */
    public static function layouts(): array
    {
        return [
            'pack'     => ['pack_size'],
            'underlay' => ['thickness', 'size'],
            'pipe'     => ['pipe_diameter', 'insulation_thickness', 'metres'],
        ];
    }

    /** @return array<string, string> code => display name */
    public static function names(): array
    {
        return [
            'pack_size'             => 'Pack Size',
            'thickness'             => 'Thickness',
            'size'                  => 'Size',
            'pipe_diameter'         => 'Pipe Diameter',
            'insulation_thickness'  => 'Insulation Thickness',
            'metres'                => 'Metres',
        ];
    }

    /**
     * Guess attribute values from a Store-style SKU or name.
     *
     * @return array<string, string> code => value (e.g. pipe_diameter => 15mm)
     */
    public static function guessFromText(string $sku, string $name = ''): array
    {
        $text = trim($sku . ' ' . $name);
        if ($text === '') {
            return [];
        }

        $out = [];
        $hasDiameter = preg_match('/\bdiameter\s+/iu', $text) === 1;
        if (preg_match('/\bdiameter\s+(\d+(?:\.\d+)?)\s*m{1,2}\b/iu', $text, $m) === 1) {
            $out['pipe_diameter'] = self::mmLabel($m[1]);
        }
        if (preg_match('/\bthick(?:ness)?\s+(\d+(?:\.\d+)?)\s*m{1,2}\b/iu', $text, $m) === 1) {
            $mm = self::mmLabel($m[1]);
            if ($hasDiameter) {
                $out['insulation_thickness'] = $mm;
            } else {
                $out['thickness'] = $mm;
            }
        }
        if (preg_match('/\bleng(?:th|ht)\s+(\d+(?:\.\d+)?)\s*m\b/iu', $text, $m) === 1) {
            $out['metres'] = self::mLabel($m[1]);
        } elseif (preg_match('/\bmetres?\s*[:\-]?\s*(\d+(?:\.\d+)?)\s*m\b/iu', $text, $m) === 1) {
            $out['metres'] = self::mLabel($m[1]);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*m\s*[xX×]\s*(\d+(?:\.\d+)?)\s*m/iu', $text, $m) === 1) {
            $out['size'] = $m[1] . 'm x ' . $m[2] . 'm';
        }

        $pack = PackInventory::parseQty($sku, $name);
        if ($pack >= 1 && (
            preg_match('/(?:^|[^0-9.])(\d+)\s*[xX×](?:\s+|$)/u', $text) === 1
            || preg_match('/\b(?:pack|box|case|set)\s+of\s+(\d+)\b/iu', $text) === 1
        )) {
            $out['pack_size'] = $pack . ' ×';
        }

        if (preg_match('/^(\d+(?:\.\d+)?mm)\b/iu', trim($sku), $m) === 1
            && preg_match('/\(/u', $sku) === 1
        ) {
            $out['thickness'] = $m[1];
        }

        return $out;
    }

    public static function valuesMatch(string $wanted, string $have): bool
    {
        $a = self::normValue($wanted);
        $b = self::normValue($have);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)x$/', $a, $m) === 1 && $m[1] === $b) {
            return true;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)x$/', $b, $m) === 1 && $m[1] === $a) {
            return true;
        }
        $aMm = preg_replace('/m{1,2}$/', 'mm', $a) ?? $a;
        $bMm = preg_replace('/m{1,2}$/', 'mm', $b) ?? $b;

        return $aMm === $bMm;
    }

    private static function mmLabel(string $n): string
    {
        $n = rtrim($n, '.');

        return $n . 'mm';
    }

    private static function mLabel(string $n): string
    {
        if (preg_match('/^\d+$/', $n) === 1) {
            return ((int) $n) . 'm';
        }

        return rtrim($n, '.') . 'm';
    }

    private static function normValue(string $s): string
    {
        $s = strtolower(trim($s));
        $s = str_replace(['×', ' ', '_'], ['x', '', ''], $s);

        return $s;
    }
}
