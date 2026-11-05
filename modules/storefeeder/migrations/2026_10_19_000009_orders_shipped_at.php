<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Add sf_orders.shipped_at used by pickwave stats and despatch.
 */
return [
    'up' => function (Database $db) {
        $table = $db->getPrefix() . 'sf_orders';
        $exists = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = 'shipped_at'",
            [$table]
        )->value;

        if ($exists === 0) {
            $db->query(
                "ALTER TABLE `#__sf_orders`
                   ADD COLUMN `shipped_at` datetime DEFAULT NULL AFTER `pre_despatched`,
                   ADD KEY `idx_shipped` (`shipped_at`)"
            );
        }
    },

    'down' => function (Database $db) {
        $table = $db->getPrefix() . 'sf_orders';
        $exists = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = 'shipped_at'",
            [$table]
        )->value;

        if ($exists > 0) {
            $db->query('ALTER TABLE `#__sf_orders` DROP KEY `idx_shipped`, DROP COLUMN `shipped_at`');
        }
    },
];
