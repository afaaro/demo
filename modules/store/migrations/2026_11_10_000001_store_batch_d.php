<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Store Batch D: partial despatch, warehouse locations, order rules,
 * listing floors, bin moves, shipment parcel index.
 */
return [
    'up' => function (Database $db): void {
        try {
            $db->query("ALTER TABLE `#__store_order_items`
                ADD COLUMN `qty_shipped` int UNSIGNED NOT NULL DEFAULT 0 AFTER `qty`");
        } catch (\Throwable) {
        }

        try {
            $db->query("ALTER TABLE `#__store_shipments`
                ADD COLUMN `parcel_index` int UNSIGNED NOT NULL DEFAULT 1 AFTER `order_id`");
        } catch (\Throwable) {
        }

        try {
            $db->query("ALTER TABLE `#__store_listings`
                ADD COLUMN `min_price` decimal(10,2) DEFAULT NULL AFTER `price`");
        } catch (\Throwable) {
        }

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_inventory_locations` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL DEFAULT 1,
              `variant_id`   int UNSIGNED NOT NULL,
              `warehouse_id` int UNSIGNED NOT NULL,
              `quantity`     int NOT NULL DEFAULT 0,
              `reserved`     int NOT NULL DEFAULT 0,
              `updated_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_variant_warehouse` (`tenant_id`, `variant_id`, `warehouse_id`),
              KEY `idx_warehouse` (`warehouse_id`),
              KEY `idx_variant` (`variant_id`),
              CONSTRAINT `fk_store_inv_loc_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_inv_loc_variant`
                FOREIGN KEY (`variant_id`) REFERENCES `#__store_product_variants` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_store_inv_loc_warehouse`
                FOREIGN KEY (`warehouse_id`) REFERENCES `#__store_warehouses` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_order_rules` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`  int UNSIGNED NOT NULL DEFAULT 1,
              `name`       varchar(128) NOT NULL DEFAULT '',
              `type`       varchar(32)  NOT NULL DEFAULT 'unpaid',
              `threshold`  decimal(12,2) NOT NULL DEFAULT 0.00,
              `action`     varchar(32)  NOT NULL DEFAULT 'hold',
              `enabled`    tinyint(1)   NOT NULL DEFAULT 1,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_enabled` (`tenant_id`, `enabled`),
              CONSTRAINT `fk_store_order_rules_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_bin_moves` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL DEFAULT 1,
              `variant_id`   int UNSIGNED NOT NULL,
              `from_bin`     varchar(64) NOT NULL DEFAULT '',
              `to_bin`       varchar(64) NOT NULL DEFAULT '',
              `user_id`      int UNSIGNED DEFAULT NULL,
              `note`         varchar(255) NOT NULL DEFAULT '',
              `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_variant` (`tenant_id`, `variant_id`),
              KEY `idx_created` (`created_at`),
              CONSTRAINT `fk_store_bin_moves_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_bin_moves_variant`
                FOREIGN KEY (`variant_id`) REFERENCES `#__store_product_variants` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db): void {
        $db->query('DROP TABLE IF EXISTS `#__store_bin_moves`');
        $db->query('DROP TABLE IF EXISTS `#__store_order_rules`');
        $db->query('DROP TABLE IF EXISTS `#__store_inventory_locations`');
        try {
            $db->query('ALTER TABLE `#__store_listings` DROP COLUMN `min_price`');
        } catch (\Throwable) {
        }
        try {
            $db->query('ALTER TABLE `#__store_shipments` DROP COLUMN `parcel_index`');
        } catch (\Throwable) {
        }
        try {
            $db->query('ALTER TABLE `#__store_order_items` DROP COLUMN `qty_shipped`');
        } catch (\Throwable) {
        }
    },
];
