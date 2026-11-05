<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_account_deletion_log` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL,
              `username`     varchar(128) NOT NULL DEFAULT '',
              `user_id`      varchar(128) NOT NULL DEFAULT '',
              `eias_token`   varchar(255) NOT NULL DEFAULT '',
              `status`       varchar(32)  NOT NULL DEFAULT 'received',
              `detail`       varchar(500) NOT NULL DEFAULT '',
              `payload_hash` char(64)     NOT NULL DEFAULT '',
              `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_created` (`created_at`),
              KEY `idx_username` (`username`),
              KEY `idx_status` (`status`),
              CONSTRAINT `fk_sf_account_deletion_log_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_sync_log` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL,
              `channel_id`   int UNSIGNED NOT NULL,
              `action`       varchar(64)  NOT NULL DEFAULT '',
              `status`       enum('ok','error','skipped') NOT NULL DEFAULT 'ok',
              `summary`      varchar(500) NOT NULL DEFAULT '',
              `details_json` json NOT NULL DEFAULT (JSON_OBJECT()),
              `user_id`      int UNSIGNED NULL DEFAULT NULL,
              `user_label`   varchar(128) NOT NULL DEFAULT '',
              `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_channel_created` (`channel_id`, `created_at`),
              CONSTRAINT `fk_sf_sync_log_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_sf_sync_log_channel`
                FOREIGN KEY (`channel_id`) REFERENCES `#__sf_channels` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_order_audit` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL,
              `order_id`     int UNSIGNED NOT NULL,
              `user_id`      int UNSIGNED NULL DEFAULT NULL,
              `user_label`   varchar(128) NOT NULL DEFAULT '',
              `action`       varchar(64)  NOT NULL DEFAULT 'updated',
              `summary`      varchar(500) NOT NULL DEFAULT '',
              `changes_json` json NOT NULL DEFAULT (JSON_OBJECT()),
              `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_order_created` (`order_id`, `created_at`),
              CONSTRAINT `fk_sf_order_audit_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_sf_order_audit_order`
                FOREIGN KEY (`order_id`) REFERENCES `#__sf_orders` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_returns` (
              `id`                int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`         int UNSIGNED NOT NULL,
              `order_id`          int UNSIGNED NULL DEFAULT NULL,
              `channel_id`        int UNSIGNED NULL DEFAULT NULL,
              `external_id`       varchar(128) NOT NULL DEFAULT '',
              `external_order_id` varchar(64) NOT NULL DEFAULT '',
              `status`            varchar(64)  NOT NULL DEFAULT 'open',
              `reason`            varchar(255) NOT NULL DEFAULT '',
              `notes`             varchar(1000) NOT NULL DEFAULT '',
              `refund_amount`     decimal(10,2) NOT NULL DEFAULT 0.00,
              `currency`          char(3) NOT NULL DEFAULT 'GBP',
              `buyer_username`    varchar(128) NOT NULL DEFAULT '',
              `items_json`        json NOT NULL DEFAULT (JSON_ARRAY()),
              `created_at`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_order` (`order_id`),
              KEY `idx_channel` (`channel_id`),
              KEY `idx_status` (`status`),
              KEY `idx_external_order` (`external_order_id`),
              CONSTRAINT `fk_sf_returns_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_sf_returns_order`
                FOREIGN KEY (`order_id`) REFERENCES `#__sf_orders` (`id`)
                ON DELETE SET NULL,
              CONSTRAINT `fk_sf_returns_channel`
                FOREIGN KEY (`channel_id`) REFERENCES `#__sf_channels` (`id`)
                ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_product_saved_filters` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`   int UNSIGNED NOT NULL,
              `user_id`     int UNSIGNED NOT NULL,
              `name`        varchar(128) NOT NULL,
              `params_json` text NOT NULL,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_sf_psf_user_name` (`tenant_id`, `user_id`, `name`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_sf_psf_user` (`user_id`),
              CONSTRAINT `fk_sf_product_saved_filters_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_order_saved_filters` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`   int UNSIGNED NOT NULL,
              `user_id`     int UNSIGNED NOT NULL,
              `name`        varchar(128) NOT NULL,
              `params_json` text NOT NULL,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_sf_osf_user_name` (`tenant_id`, `user_id`, `name`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_sf_osf_user` (`user_id`),
              CONSTRAINT `fk_sf_order_saved_filters_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__sf_order_saved_filters`');
        $db->query('DROP TABLE IF EXISTS `#__sf_product_saved_filters`');
        $db->query('DROP TABLE IF EXISTS `#__sf_returns`');
        $db->query('DROP TABLE IF EXISTS `#__sf_order_audit`');
        $db->query('DROP TABLE IF EXISTS `#__sf_sync_log`');
        $db->query('DROP TABLE IF EXISTS `#__sf_account_deletion_log`');
    },
];
