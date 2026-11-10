<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Store Batch C: preferred courier, stocktakes, reply templates,
 * shipment carrier_cost, listing qty_buffer.
 */
return [
    'up' => function (Database $db): void {
        try {
            $db->query("ALTER TABLE `#__store_orders`
                ADD COLUMN `preferred_courier` varchar(32) NOT NULL DEFAULT '' AFTER `mapped_shipping_method`");
        } catch (\Throwable) {
        }

        try {
            $db->query("ALTER TABLE `#__store_shipments`
                ADD COLUMN `carrier_cost` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `status`,
                ADD COLUMN `tracked_at` datetime DEFAULT NULL AFTER `carrier_cost`");
        } catch (\Throwable) {
        }

        try {
            $db->query("ALTER TABLE `#__store_listings`
                ADD COLUMN `qty_buffer` int UNSIGNED NOT NULL DEFAULT 0 AFTER `oversell`");
        } catch (\Throwable) {
        }

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_stocktakes` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL DEFAULT 1,
              `name`         varchar(128) NOT NULL DEFAULT '',
              `warehouse`    varchar(128) NOT NULL DEFAULT '',
              `status`       varchar(32)  NOT NULL DEFAULT 'open',
              `notes`        varchar(500) NOT NULL DEFAULT '',
              `created_by`   int UNSIGNED DEFAULT NULL,
              `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `finalized_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_status` (`tenant_id`, `status`),
              CONSTRAINT `fk_store_stocktakes_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_stocktake_lines` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL DEFAULT 1,
              `stocktake_id` int UNSIGNED NOT NULL,
              `variant_id`   int UNSIGNED NOT NULL,
              `sku`          varchar(128) NOT NULL DEFAULT '',
              `title`        varchar(255) NOT NULL DEFAULT '',
              `system_qty`   int NOT NULL DEFAULT 0,
              `counted_qty`  int DEFAULT NULL,
              `notes`        varchar(255) NOT NULL DEFAULT '',
              `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_stocktake_variant` (`stocktake_id`, `variant_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              CONSTRAINT `fk_store_stocktake_lines_session`
                FOREIGN KEY (`stocktake_id`) REFERENCES `#__store_stocktakes` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_store_stocktake_lines_variant`
                FOREIGN KEY (`variant_id`) REFERENCES `#__store_product_variants` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_reply_templates` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL DEFAULT 1,
              `name`         varchar(128) NOT NULL DEFAULT '',
              `body`         text NOT NULL,
              `channel_type` varchar(32) DEFAULT NULL,
              `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_channel` (`tenant_id`, `channel_type`),
              CONSTRAINT `fk_store_reply_templates_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db): void {
        $db->query('DROP TABLE IF EXISTS `#__store_stocktake_lines`');
        $db->query('DROP TABLE IF EXISTS `#__store_stocktakes`');
        $db->query('DROP TABLE IF EXISTS `#__store_reply_templates`');
        try {
            $db->query('ALTER TABLE `#__store_orders` DROP COLUMN `preferred_courier`');
        } catch (\Throwable) {
        }
        try {
            $db->query('ALTER TABLE `#__store_shipments` DROP COLUMN `carrier_cost`, DROP COLUMN `tracked_at`');
        } catch (\Throwable) {
        }
        try {
            $db->query('ALTER TABLE `#__store_listings` DROP COLUMN `qty_buffer`');
        } catch (\Throwable) {
        }
    },
];
