<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Supplier settings for Inventory Planner (requisitions).
 */
return [
    'up' => function (Database $db) {
        $cols = [
            'po_mode' => "varchar(32) NOT NULL DEFAULT 'below_reorder'",
            'sold_days' => 'int UNSIGNED NOT NULL DEFAULT 30',
            'include_in_requisitions' => 'tinyint(1) NOT NULL DEFAULT 1',
            'accepts_back_orders' => 'tinyint(1) NOT NULL DEFAULT 1',
            'min_order_value' => 'decimal(12,2) NOT NULL DEFAULT 0.00',
        ];

        foreach ($cols as $name => $definition) {
            $exists = (int) $db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?",
                [$db->getPrefix() . 'sf_suppliers', $name]
            )->value;
            if ($exists === 0) {
                $db->query(
                    "ALTER TABLE `#__sf_suppliers` ADD COLUMN `{$name}` {$definition}"
                );
            }
        }
    },

    'down' => function (Database $db) {
        foreach ([
            'min_order_value',
            'accepts_back_orders',
            'include_in_requisitions',
            'sold_days',
            'po_mode',
        ] as $name) {
            try {
                $db->query("ALTER TABLE `#__sf_suppliers` DROP COLUMN `{$name}`");
            } catch (\Throwable) {
                // ignore
            }
        }
    },
];
