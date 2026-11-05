<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Explicit pack size on variants (0 = auto-detect from name like "8 x …").
 */
return [
    'up' => function (Database $db) {
        $table = $db->getPrefix() . 'sf_product_variants';
        $exists = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = 'pack_qty'",
            [$table]
        )->value;
        if ($exists === 0) {
            $db->query(
                "ALTER TABLE `#__sf_product_variants`
                   ADD COLUMN `pack_qty` int UNSIGNED NOT NULL DEFAULT 0 AFTER `name`"
            );
        }
    },
    'down' => function (Database $db) {
        $table = $db->getPrefix() . 'sf_product_variants';
        $exists = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = 'pack_qty'",
            [$table]
        )->value;
        if ($exists > 0) {
            $db->query('ALTER TABLE `#__sf_product_variants` DROP COLUMN `pack_qty`');
        }
    },
];
