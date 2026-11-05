<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_channels` (
              `id`                 int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`          int UNSIGNED NOT NULL,
              `name`               varchar(64)  NOT NULL,
              `type`               varchar(32)  NOT NULL,
              `label`              varchar(128) NOT NULL DEFAULT '',
              `credentials`        text         NOT NULL,
              `oauth_state`        varchar(64)  NOT NULL DEFAULT '',
              `refresh_token`      text         NOT NULL,
              `access_token`       text         NOT NULL,
              `access_expires_at`  datetime     DEFAULT NULL,
              `refresh_expires_at` datetime     DEFAULT NULL,
              `settings`           json         NOT NULL DEFAULT (JSON_OBJECT()),
              `enabled`            tinyint(1)   NOT NULL DEFAULT 0,
              `status`             enum('disconnected','connected','error') NOT NULL DEFAULT 'disconnected',
              `last_error`         varchar(500) NOT NULL DEFAULT '',
              `last_synced_at`     datetime     DEFAULT NULL,
              `created_at`         datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`         datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_channel_name` (`tenant_id`, `name`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_type` (`type`),
              KEY `idx_enabled` (`enabled`),
              CONSTRAINT `fk_sf_channels_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_listings` (
              `id`             int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`      int UNSIGNED NOT NULL,
              `variant_id`     int UNSIGNED NOT NULL,
              `channel_id`     int UNSIGNED NOT NULL,
              `external_id`    varchar(128) NOT NULL DEFAULT '',
              `external_sku`   varchar(128) NOT NULL DEFAULT '',
              `channel_title`  varchar(255) NOT NULL DEFAULT '',
              `price`          decimal(10,2) DEFAULT NULL,
              `oversell`       int NOT NULL DEFAULT 0,
              `controlled`     tinyint(1) NOT NULL DEFAULT 1,
              `status`         enum('pending','pushed','error','unlisted') NOT NULL DEFAULT 'pending',
              `last_error`     varchar(500) NOT NULL DEFAULT '',
              `last_synced_at` datetime     DEFAULT NULL,
              `created_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_variant_channel` (`variant_id`, `channel_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_channel` (`channel_id`),
              KEY `idx_status`  (`status`),
              KEY `idx_external_id` (`external_id`),
              CONSTRAINT `fk_sf_listings_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_sf_listing_variant`
                FOREIGN KEY (`variant_id`) REFERENCES `#__sf_product_variants` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_sf_listing_channel`
                FOREIGN KEY (`channel_id`) REFERENCES `#__sf_channels` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_listing_templates` (
              `id`                   int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`            int UNSIGNED NOT NULL,
              `channel_id`           int UNSIGNED NOT NULL,
              `name`                 varchar(128) NOT NULL,
              `title_template`       varchar(255) NOT NULL DEFAULT '{{title}}',
              `description_template` mediumtext   NOT NULL,
              `is_default`           tinyint(1)   NOT NULL DEFAULT 0,
              `created_at`           datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`           datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_channel` (`channel_id`),
              KEY `idx_channel_default` (`channel_id`, `is_default`),
              CONSTRAINT `fk_sf_listing_tpl_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_sf_listing_tpl_channel`
                FOREIGN KEY (`channel_id`) REFERENCES `#__sf_channels` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_channel_attributes` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`  int UNSIGNED NOT NULL,
              `channel_id` int UNSIGNED NOT NULL,
              `name`       varchar(64)  NOT NULL,
              `source`     varchar(32)  NOT NULL DEFAULT 'fixed',
              `value`      varchar(255) NOT NULL DEFAULT '',
              `sort_order` int NOT NULL DEFAULT 0,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_channel_attr_name` (`channel_id`, `name`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_channel_attr_channel` (`channel_id`),
              CONSTRAINT `fk_sf_channel_attr_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_sf_channel_attr_channel`
                FOREIGN KEY (`channel_id`) REFERENCES `#__sf_channels` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__sf_product_channel_attributes` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`   int UNSIGNED NOT NULL,
              `product_id`  int UNSIGNED NOT NULL,
              `channel_id`  int UNSIGNED NOT NULL,
              `name`        varchar(64)  NOT NULL,
              `values_json` json NOT NULL,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_product_channel_attr` (`product_id`, `channel_id`, `name`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_pca_product` (`product_id`),
              KEY `idx_pca_channel` (`channel_id`),
              CONSTRAINT `fk_sf_pca_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_sf_pca_product`
                FOREIGN KEY (`product_id`) REFERENCES `#__sf_products` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_sf_pca_channel`
                FOREIGN KEY (`channel_id`) REFERENCES `#__sf_channels` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__sf_product_channel_attributes`');
        $db->query('DROP TABLE IF EXISTS `#__sf_channel_attributes`');
        $db->query('DROP TABLE IF EXISTS `#__sf_listing_templates`');
        $db->query('DROP TABLE IF EXISTS `#__sf_listings`');
        $db->query('DROP TABLE IF EXISTS `#__sf_channels`');
    },
];
