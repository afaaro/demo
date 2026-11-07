<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_purchase_orders` (
              `id`             int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`      int UNSIGNED NOT NULL,
              `supplier_name`  varchar(128) NOT NULL DEFAULT '',
              `warehouse`      varchar(128) NOT NULL DEFAULT 'Default',
              `reference`      varchar(64)  NOT NULL DEFAULT '',
              `status`         varchar(32)  NOT NULL DEFAULT 'draft',
              `notes`          text         NULL,
              `expected_date`  date NULL DEFAULT NULL,
              `shipping_cost`  decimal(12,4) NOT NULL DEFAULT 0.0000,
              `vat_rate`       decimal(5,2) NOT NULL DEFAULT 20.00,
              `raised_by`      varchar(128) NOT NULL DEFAULT '',
              `confirmed_at`   datetime     NULL,
              `received_at`    datetime     NULL,
              `date_sent`              date NULL DEFAULT NULL,
              `paid_on`                date NULL DEFAULT NULL,
              `payment_reference`      varchar(128) NOT NULL DEFAULT '',
              `payment_confirmed_by`   varchar(128) NOT NULL DEFAULT '',
              `created_via`            varchar(64) NOT NULL DEFAULT 'Manual',
              `include_delivery_cost`  tinyint(1) NOT NULL DEFAULT 0,
              `retained_delivery`      tinyint(1) NOT NULL DEFAULT 0,
              `estimated_pallet_count` int UNSIGNED NOT NULL DEFAULT 0,
              `po_change_to_status`    varchar(64) NOT NULL DEFAULT '',
              `supplier_code`          varchar(64) NOT NULL DEFAULT '',
              `supplier_reference`     varchar(64) NOT NULL DEFAULT '',
              `del_company`            varchar(128) NOT NULL DEFAULT '',
              `del_address1`           varchar(255) NOT NULL DEFAULT '',
              `del_address2`           varchar(255) NOT NULL DEFAULT '',
              `del_address3`           varchar(255) NOT NULL DEFAULT '',
              `del_city`               varchar(128) NOT NULL DEFAULT '',
              `del_county`             varchar(128) NOT NULL DEFAULT '',
              `del_country`            varchar(128) NOT NULL DEFAULT 'UNITED KINGDOM',
              `del_postcode`           varchar(32) NOT NULL DEFAULT '',
              `sup_company`            varchar(128) NOT NULL DEFAULT '',
              `sup_address1`           varchar(255) NOT NULL DEFAULT '',
              `sup_address2`           varchar(255) NOT NULL DEFAULT '',
              `sup_city`               varchar(128) NOT NULL DEFAULT '',
              `sup_county`             varchar(128) NOT NULL DEFAULT '',
              `sup_country`            varchar(128) NOT NULL DEFAULT 'UNITED KINGDOM',
              `sup_postcode`           varchar(32) NOT NULL DEFAULT '',
              `sup_phone`              varchar(64) NOT NULL DEFAULT '',
              `sup_email`              varchar(255) NOT NULL DEFAULT '',
              `sup_lead_time`          varchar(32) NOT NULL DEFAULT '',
              `sup_min_order_value`    decimal(12,2) NOT NULL DEFAULT 0.00,
              `created_at`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_po_status` (`status`),
              KEY `idx_po_supplier` (`supplier_name`),
              CONSTRAINT `fk_store_purchase_orders_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_purchase_order_lines` (
              `id`              int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`       int UNSIGNED NOT NULL,
              `po_id`           int UNSIGNED NOT NULL,
              `variant_id`      int UNSIGNED NOT NULL,
              `sku`             varchar(64)  NOT NULL DEFAULT '',
              `qty_ordered`     int          NOT NULL DEFAULT 0,
              `qty_received`    int          NOT NULL DEFAULT 0,
              `qty_written_off` int NOT NULL DEFAULT 0,
              `unit_cost`       decimal(12,4) NOT NULL DEFAULT 0.0000,
              `notes`           varchar(255) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_pol_po` (`po_id`),
              KEY `idx_pol_variant` (`variant_id`),
              CONSTRAINT `fk_store_pol_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_pol_po`
                FOREIGN KEY (`po_id`) REFERENCES `#__store_purchase_orders` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_shipping_methods` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL,
              `company`      varchar(128) NOT NULL DEFAULT '',
              `method`       varchar(128) NOT NULL DEFAULT '',
              `cost`         decimal(12,2) NOT NULL DEFAULT 0.00,
              `is_default`   tinyint(1) NOT NULL DEFAULT 0,
              `carrier_code` varchar(64) NOT NULL DEFAULT '',
              `enabled`      tinyint(1) NOT NULL DEFAULT 1,
              `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_store_ship_method_company` (`company`),
              KEY `idx_store_ship_method_default` (`is_default`),
              CONSTRAINT `fk_store_shipping_methods_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_shipping_regions` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`   int UNSIGNED NOT NULL,
              `name`        varchar(128) NOT NULL,
              `countries`   text NOT NULL,
              `postcodes`   text NOT NULL,
              `notes`       varchar(255) NOT NULL DEFAULT '',
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_ship_region_name` (`tenant_id`, `name`),
              KEY `idx_tenant_id` (`tenant_id`),
              CONSTRAINT `fk_store_shipping_regions_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_shipping_rules` (
              `id`              int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`       int UNSIGNED NOT NULL,
              `name`            varchar(128) NOT NULL DEFAULT '',
              `region_id`       int UNSIGNED DEFAULT NULL,
              `method_id`       int UNSIGNED NOT NULL,
              `min_weight_kg`   decimal(10,3) DEFAULT NULL,
              `max_weight_kg`   decimal(10,3) DEFAULT NULL,
              `min_order_total` decimal(12,2) DEFAULT NULL,
              `max_order_total` decimal(12,2) DEFAULT NULL,
              `priority`        int NOT NULL DEFAULT 100,
              `enabled`         tinyint(1) NOT NULL DEFAULT 1,
              `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_store_ship_rule_priority` (`priority`),
              KEY `idx_store_ship_rule_method` (`method_id`),
              KEY `idx_store_ship_rule_region` (`region_id`),
              CONSTRAINT `fk_store_shipping_rules_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_ship_rule_method`
                FOREIGN KEY (`method_id`) REFERENCES `#__store_shipping_methods` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_store_ship_rule_region`
                FOREIGN KEY (`region_id`) REFERENCES `#__store_shipping_regions` (`id`)
                ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_packaging_sizes` (
              `id`                  int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`           int UNSIGNED NOT NULL,
              `name`                varchar(128) NOT NULL,
              `size_code`           int NOT NULL DEFAULT 0,
              `packaging_weight_kg` decimal(10,3) NOT NULL DEFAULT 0,
              `length_mm`           int NOT NULL DEFAULT 0,
              `width_mm`            int NOT NULL DEFAULT 0,
              `height_mm`           int NOT NULL DEFAULT 0,
              `packaging_cost`      decimal(12,2) NOT NULL DEFAULT 0,
              `rm_packaging_type`   varchar(64) NOT NULL DEFAULT 'Parcel',
              `include_weight`      tinyint(1) NOT NULL DEFAULT 0,
              `weight_capacity_kg`  decimal(10,3) NOT NULL DEFAULT 0,
              `sort_order`          int NOT NULL DEFAULT 0,
              `created_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_packaging_name` (`tenant_id`, `name`),
              KEY `idx_tenant_id` (`tenant_id`),
              CONSTRAINT `fk_store_packaging_sizes_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $packaging = [
            ['Letter', 1, 0, 240, 165, 5, 0, 'Letter', 0, 0.100],
            ['Large Letter', 5, 0, 353, 250, 25, 0, 'Large Letter', 0, 1.000],
            ['Parcel', 50, 0, 450, 350, 160, 0, 'Parcel', 0, 15.000],
            ['Large Parcel', 52, 0, 995, 595, 695, 0, 'Parcel', 0, 30.000],
        ];
        foreach ($packaging as $i => $p) {
            $db->query(
                "INSERT IGNORE INTO `#__store_packaging_sizes`
                    (`tenant_id`,`name`,`size_code`,`packaging_weight_kg`,`length_mm`,`width_mm`,`height_mm`,
                     `packaging_cost`,`rm_packaging_type`,`include_weight`,`weight_capacity_kg`,`sort_order`)
                 VALUES (1,?,?,?,?,?,?,?,?,?,?,?)",
                [$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], $p[9], $i + 1]
            );
        }

        $regions = [
            ['UK Main Land', 'GB', ''],
            ['Channel Islands', 'GG,JE', ''],
            ['Royal Mail Europe', 'AT,BE,BG,HR,CY,CZ,DK,EE,FI,FR,DE,GR,HU,IE,IT,LV,LT,LU,MT,NL,PL,PT,RO,SK,SI,ES,SE', ''],
            ['Royal Mail Europe(EEU)', 'AT,BE,BG,HR,CY,CZ,DK,EE,FI,FR,DE,GR,HU,IE,IT,LV,LT,LU,MT,NL,PL,PT,RO,SK,SI,ES,SE', ''],
            ['Asia', 'CN,JP,KR,IN,SG,HK,TW,TH,MY,ID,PH,VN', ''],
            ['Royal Mail Rest of World', '', ''],
            ['Royal Mail ROW(Plus no-EEU)', '', ''],
        ];
        foreach ($regions as $r) {
            $db->query(
                "INSERT IGNORE INTO `#__store_shipping_regions` (`tenant_id`,`name`,`countries`,`postcodes`) VALUES (1,?,?,?)",
                $r
            );
        }

        $methods = [
            ['', 'Collection', 0, 0, ''],
            ['Amazon Buy Shipping', 'DPD PRIME SATURDAY', 0, 0, 'DPD'],
            ['Amazon Buy Shipping', 'HERMES PRIME', 0, 0, 'HERMES'],
            ['Amazon Buy Shipping', 'RM PRIME LARGE LETTER', 0, 0, 'ROYAL_MAIL'],
            ['Amazon Buy Shipping', 'RM PRIME PARCEL', 0, 0, 'ROYAL_MAIL'],
            ['Amz Buy Shipping', 'DPD PRIME NEXT DAY', 0, 0, 'DPD'],
            ['DPD', 'DPD NEXT DAY', 0, 0, 'DPD'],
            ['DPD', 'DPD SATURDAY ONLY', 0, 0, 'DPD'],
            ['DPD', 'DPD SUNDAY ONLY', 0, 0, 'DPD'],
            ['DPD', 'DPD TWO DAYS', 0, 0, 'DPD'],
            ['Hermes (New)', 'HERMES 2DAYS', 0, 0, 'HERMES'],
            ['Hermes (New)', 'HERMES NEXT DAY', 0, 0, 'HERMES'],
            ['Royal mail', 'PARCELFORCE 24', 0, 0, 'PARCELFORCE'],
            ['Royal mail', 'PARCELFORCE 48', 0, 0, 'PARCELFORCE'],
            ['Royal mail', 'PARCELFORCE LARGE', 0, 0, 'PARCELFORCE'],
            ['Royal mail', 'RM TRACKED 24 LARGE LETTER', 0, 0, 'ROYAL_MAIL'],
            ['Royal mail', 'RM TRACKED 24 PARCEL', 0, 1, 'ROYAL_MAIL'],
        ];
        foreach ($methods as $m) {
            $db->query(
                "INSERT INTO `#__store_shipping_methods`
                    (`tenant_id`,`company`,`method`,`cost`,`is_default`,`carrier_code`)
                 SELECT 1,?,?,?,?,?
                  WHERE NOT EXISTS (
                    SELECT 1 FROM `#__store_shipping_methods`
                     WHERE `tenant_id` = 1 AND `company` = ? AND `method` = ?
                  )",
                [$m[0], $m[1], $m[2], $m[3], $m[4], $m[0], $m[1]]
            );
        }

        // Upgrade path: older installs may lack PO header columns.
        $poTable = $db->getPrefix() . 'store_purchase_orders';
        $poColumns = [
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
        foreach ($poColumns as $name => $ddl) {
            $exists = (int) $db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?",
                [$poTable, $name]
            )->value;
            if ($exists === 0) {
                $db->query("ALTER TABLE `#__store_purchase_orders` ADD COLUMN `{$name}` {$ddl}");
            }
        }
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__store_shipping_rules`');
        $db->query('DROP TABLE IF EXISTS `#__store_packaging_sizes`');
        $db->query('DROP TABLE IF EXISTS `#__store_shipping_regions`');
        $db->query('DROP TABLE IF EXISTS `#__store_shipping_methods`');
        $db->query('DROP TABLE IF EXISTS `#__store_purchase_order_lines`');
        $db->query('DROP TABLE IF EXISTS `#__store_purchase_orders`');
    },
];
