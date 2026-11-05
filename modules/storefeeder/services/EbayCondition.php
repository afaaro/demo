<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

/**
 * Maps Trading API / display condition values to Inventory API enums.
 */
final class EbayCondition
{
    /** @var array<string, string> Inventory enum => label */
    public const OPTIONS = [
        'NEW'                         => 'New',
        'LIKE_NEW'                    => 'Like New',
        'NEW_OTHER'                   => 'New other (see details)',
        'NEW_WITH_DEFECTS'            => 'New with defects',
        'MANUFACTURER_REFURBISHED'    => 'Manufacturer refurbished',
        'CERTIFIED_REFURBISHED'       => 'Certified refurbished',
        'EXCELLENT_REFURBISHED'       => 'Excellent — Refurbished',
        'VERY_GOOD_REFURBISHED'       => 'Very Good — Refurbished',
        'GOOD_REFURBISHED'            => 'Good — Refurbished',
        'SELLER_REFURBISHED'          => 'Seller refurbished',
        'USED_EXCELLENT'              => 'Used — Excellent',
        'USED_VERY_GOOD'              => 'Used — Very Good',
        'USED_GOOD'                   => 'Used — Good',
        'USED_ACCEPTABLE'             => 'Used — Acceptable',
        'FOR_PARTS_OR_NOT_WORKING'    => 'For parts or not working',
    ];

    /** @var array<int, string> Trading ConditionID => Inventory enum */
    private const BY_CONDITION_ID = [
        1000 => 'NEW',
        1500 => 'NEW_OTHER',
        1750 => 'NEW_WITH_DEFECTS',
        2000 => 'MANUFACTURER_REFURBISHED',
        2010 => 'CERTIFIED_REFURBISHED',
        2020 => 'EXCELLENT_REFURBISHED',
        2030 => 'VERY_GOOD_REFURBISHED',
        2040 => 'GOOD_REFURBISHED',
        2500 => 'SELLER_REFURBISHED',
        3000 => 'USED_EXCELLENT',
        4000 => 'USED_VERY_GOOD',
        5000 => 'USED_GOOD',
        6000 => 'USED_ACCEPTABLE',
        7000 => 'FOR_PARTS_OR_NOT_WORKING',
    ];

    /**
     * Normalize free text / ConditionID / Inventory enum to a known Inventory value.
     * Empty input returns empty (caller falls back to NEW / channel default).
     */
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $upper = strtoupper(str_replace([' ', '-'], '_', $raw));
        if (isset(self::OPTIONS[$upper])) {
            return $upper;
        }

        if (ctype_digit($raw)) {
            $id = (int) $raw;
            return self::BY_CONDITION_ID[$id] ?? '';
        }

        $compact = strtolower(preg_replace('/[^a-z0-9]+/i', '', $raw) ?? '');
        $aliases = [
            'new' => 'NEW',
            'likenew' => 'LIKE_NEW',
            'newother' => 'NEW_OTHER',
            'newotherseedetails' => 'NEW_OTHER',
            'newwithdefects' => 'NEW_WITH_DEFECTS',
            'manufacturerrefurbished' => 'MANUFACTURER_REFURBISHED',
            'certifiedrefurbished' => 'CERTIFIED_REFURBISHED',
            'excellentrefurbished' => 'EXCELLENT_REFURBISHED',
            'verygoodrefurbished' => 'VERY_GOOD_REFURBISHED',
            'goodrefurbished' => 'GOOD_REFURBISHED',
            'sellerrefurbished' => 'SELLER_REFURBISHED',
            'used' => 'USED_EXCELLENT',
            'usedexcellent' => 'USED_EXCELLENT',
            'usedverygood' => 'USED_VERY_GOOD',
            'verygood' => 'USED_VERY_GOOD',
            'usedgood' => 'USED_GOOD',
            'good' => 'USED_GOOD',
            'usedacceptable' => 'USED_ACCEPTABLE',
            'acceptable' => 'USED_ACCEPTABLE',
            'forpartsornotworking' => 'FOR_PARTS_OR_NOT_WORKING',
            'forparts' => 'FOR_PARTS_OR_NOT_WORKING',
        ];

        return $aliases[$compact] ?? '';
    }

    public static function orDefault(string $raw, string $default = 'NEW'): string
    {
        $n = self::normalize($raw);
        return $n !== '' ? $n : $default;
    }
}
