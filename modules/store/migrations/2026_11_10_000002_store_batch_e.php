<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Store Batch E: claims, serials, automations, ASN, category maps,
 * supplier portal tokens, customer tags_json.
 */
return [
    'up' => function (Database $db): void {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_supplier_claims` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`   int UNSIGNED NOT NULL DEFAULT 1,
              `po_id`       int UNSIGNED NOT NULL,
              `po_line_id`  int UNSIGNED NOT NULL DEFAULT 0,
              `supplier_id` int UNSIGNED NOT NULL DEFAULT 0,
              `qty`         int NOT NULL DEFAULT 0,
              `reason`      varchar(255) NOT NULL DEFAULT '',
              `status`      varchar(16)  NOT NULL DEFAULT 'open',
              `notes`       text NULL,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_status` (`tenant_id`, `status`),
              KEY `idx_po` (`po_id`),
              KEY `idx_supplier` (`supplier_id`),
              CONSTRAINT `fk_store_supplier_claims_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_serials` (
              `id`            int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`     int UNSIGNED NOT NULL DEFAULT 1,
              `order_id`      int UNSIGNED NOT NULL,
              `order_item_id` int UNSIGNED NOT NULL DEFAULT 0,
              `variant_id`    int UNSIGNED NOT NULL DEFAULT 0,
              `serial`        varchar(128) NOT NULL DEFAULT '',
              `lot`           varchar(128) NOT NULL DEFAULT '',
              `created_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_order` (`tenant_id`, `order_id`),
              KEY `idx_serial` (`tenant_id`, `serial`),
              CONSTRAINT `fk_store_serials_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_automations` (
              `id`               int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`        int UNSIGNED NOT NULL DEFAULT 1,
              `name`             varchar(128) NOT NULL DEFAULT '',
              `job_type`         varchar(32)  NOT NULL DEFAULT 'orders_sync',
              `channel_id`       int UNSIGNED DEFAULT NULL,
              `interval_minutes` int UNSIGNED NOT NULL DEFAULT 60,
              `enabled`          tinyint(1) NOT NULL DEFAULT 1,
              `last_run_at`      datetime DEFAULT NULL,
              `last_status`      varchar(32) NOT NULL DEFAULT '',
              `created_at`       datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`       datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_enabled` (`tenant_id`, `enabled`),
              CONSTRAINT `fk_store_automations_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_asn` (
              `id`            int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`     int UNSIGNED NOT NULL DEFAULT 1,
              `po_id`         int UNSIGNED NOT NULL,
              `expected_date` date DEFAULT NULL,
              `pallet_count`  int UNSIGNED NOT NULL DEFAULT 0,
              `status`        varchar(16) NOT NULL DEFAULT 'draft',
              `notes`         varchar(500) NOT NULL DEFAULT '',
              `created_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_po` (`tenant_id`, `po_id`),
              KEY `idx_status` (`status`),
              CONSTRAINT `fk_store_asn_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_asn_lines` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL DEFAULT 1,
              `asn_id`       int UNSIGNED NOT NULL,
              `sku`          varchar(128) NOT NULL DEFAULT '',
              `qty_expected` int NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_asn` (`asn_id`),
              KEY `idx_tenant_sku` (`tenant_id`, `sku`),
              CONSTRAINT `fk_store_asn_lines_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_asn_lines_asn`
                FOREIGN KEY (`asn_id`) REFERENCES `#__store_asn` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_category_maps` (
              `id`                   int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`            int UNSIGNED NOT NULL DEFAULT 1,
              `store_category_id`    int UNSIGNED NOT NULL,
              `channel_id`           int UNSIGNED NOT NULL,
              `external_category_id` varchar(64) NOT NULL DEFAULT '',
              `aspects_json`         json DEFAULT NULL,
              `created_at`           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_cat_channel` (`tenant_id`, `store_category_id`, `channel_id`),
              KEY `idx_channel` (`channel_id`),
              CONSTRAINT `fk_store_category_maps_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_supplier_portal_tokens` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`   int UNSIGNED NOT NULL DEFAULT 1,
              `supplier_id` int UNSIGNED NOT NULL,
              `token`       varchar(64) NOT NULL,
              `expires_at`  datetime DEFAULT NULL,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `revoked_at`  datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_token` (`token`),
              KEY `idx_supplier` (`tenant_id`, `supplier_id`),
              CONSTRAINT `fk_store_supplier_portal_tokens_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        try {
            $db->query("ALTER TABLE `#__store_customers`
                ADD COLUMN `tags_json` json NOT NULL DEFAULT (JSON_ARRAY()) AFTER `email`");
        } catch (\Throwable) {
        }
    },

    'down' => function (Database $db): void {
        $db->query('DROP TABLE IF EXISTS `#__store_asn_lines`');
        $db->query('DROP TABLE IF EXISTS `#__store_asn`');
        $db->query('DROP TABLE IF EXISTS `#__store_supplier_portal_tokens`');
        $db->query('DROP TABLE IF EXISTS `#__store_category_maps`');
        $db->query('DROP TABLE IF EXISTS `#__store_automations`');
        $db->query('DROP TABLE IF EXISTS `#__store_serials`');
        $db->query('DROP TABLE IF EXISTS `#__store_supplier_claims`');
        try {
            $db->query('ALTER TABLE `#__store_customers` DROP COLUMN `tags_json`');
        } catch (\Throwable) {
        }
    },
];
