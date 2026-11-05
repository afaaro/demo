<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * StoreFeeder-style purchase order header: dates, payment, address snapshots.
 */
return [
    'up' => function (Database $db) {
        $table = $db->getPrefix() . 'sf_purchase_orders';
        $columns = [
            'date_sent'              => "date NULL DEFAULT NULL",
            'paid_on'                => "date NULL DEFAULT NULL",
            'payment_reference'      => "varchar(128) NOT NULL DEFAULT ''",
            'payment_confirmed_by'   => "varchar(128) NOT NULL DEFAULT ''",
            'created_via'            => "varchar(64) NOT NULL DEFAULT 'Manual'",
            'include_delivery_cost'  => "tinyint(1) NOT NULL DEFAULT 0",
            'retained_delivery'      => "tinyint(1) NOT NULL DEFAULT 0",
            'estimated_pallet_count' => "int UNSIGNED NOT NULL DEFAULT 0",
            'po_change_to_status'    => "varchar(64) NOT NULL DEFAULT ''",
            'supplier_code'          => "varchar(64) NOT NULL DEFAULT ''",
            'supplier_reference'     => "varchar(64) NOT NULL DEFAULT ''",
            'del_company'            => "varchar(128) NOT NULL DEFAULT ''",
            'del_address1'           => "varchar(255) NOT NULL DEFAULT ''",
            'del_address2'           => "varchar(255) NOT NULL DEFAULT ''",
            'del_address3'           => "varchar(255) NOT NULL DEFAULT ''",
            'del_city'               => "varchar(128) NOT NULL DEFAULT ''",
            'del_county'             => "varchar(128) NOT NULL DEFAULT ''",
            'del_country'            => "varchar(128) NOT NULL DEFAULT 'UNITED KINGDOM'",
            'del_postcode'           => "varchar(32) NOT NULL DEFAULT ''",
            'sup_company'            => "varchar(128) NOT NULL DEFAULT ''",
            'sup_address1'           => "varchar(255) NOT NULL DEFAULT ''",
            'sup_address2'           => "varchar(255) NOT NULL DEFAULT ''",
            'sup_city'               => "varchar(128) NOT NULL DEFAULT ''",
            'sup_county'             => "varchar(128) NOT NULL DEFAULT ''",
            'sup_country'            => "varchar(128) NOT NULL DEFAULT 'UNITED KINGDOM'",
            'sup_postcode'           => "varchar(32) NOT NULL DEFAULT ''",
            'sup_phone'              => "varchar(64) NOT NULL DEFAULT ''",
            'sup_email'              => "varchar(255) NOT NULL DEFAULT ''",
            'sup_lead_time'          => "varchar(32) NOT NULL DEFAULT ''",
            'sup_min_order_value'    => "decimal(12,2) NOT NULL DEFAULT 0.00",
        ];

        foreach ($columns as $name => $ddl) {
            $exists = (int) $db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?",
                [$table, $name]
            )->value;
            if ($exists === 0) {
                $db->query("ALTER TABLE `#__sf_purchase_orders` ADD COLUMN `{$name}` {$ddl}");
            }
        }
    },

    'down' => function (Database $db) {
        $table = $db->getPrefix() . 'sf_purchase_orders';
        $names = [
            'date_sent', 'paid_on', 'payment_reference', 'payment_confirmed_by', 'created_via',
            'include_delivery_cost', 'retained_delivery', 'estimated_pallet_count',
            'po_change_to_status', 'supplier_code', 'supplier_reference',
            'del_company', 'del_address1', 'del_address2', 'del_address3',
            'del_city', 'del_county', 'del_country', 'del_postcode',
            'sup_company', 'sup_address1', 'sup_address2', 'sup_city', 'sup_county',
            'sup_country', 'sup_postcode', 'sup_phone', 'sup_email', 'sup_lead_time',
            'sup_min_order_value',
        ];
        foreach ($names as $name) {
            $exists = (int) $db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?",
                [$table, $name]
            )->value;
            if ($exists > 0) {
                $db->query("ALTER TABLE `#__sf_purchase_orders` DROP COLUMN `{$name}`");
            }
        }
    },
];
