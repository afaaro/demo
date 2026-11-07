<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_orders` (
              `id`                     int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`              int UNSIGNED NOT NULL,
              `channel_id`             int UNSIGNED NOT NULL,
              `external_order_id`      varchar(64)  NOT NULL,
              `status`                 varchar(32)  NOT NULL DEFAULT 'unknown',
              `buyer_username`         varchar(128) NOT NULL DEFAULT '',
              `buyer_email`            varchar(255) NOT NULL DEFAULT '',
              `total`                  decimal(10,2) NOT NULL DEFAULT 0.00,
              `currency`               char(3)      NOT NULL DEFAULT 'GBP',
              `shipping_cost`          decimal(10,2) NOT NULL DEFAULT 0.00,
              `discount_amount`        decimal(10,2) NOT NULL DEFAULT 0.00,
              `channel_fee`            decimal(10,2) NOT NULL DEFAULT 0.00,
              `shipping_tax`           decimal(10,2) NOT NULL DEFAULT 0.00,
              `shipping_name`          varchar(255) NOT NULL DEFAULT '',
              `shipping_address`       json         NOT NULL DEFAULT (JSON_OBJECT()),
              `tracking_number`        varchar(128) NOT NULL DEFAULT '',
              `carrier_code`           varchar(64)  NOT NULL DEFAULT '',
              `shipping_method`        varchar(128) NOT NULL DEFAULT '',
              `warehouse`              varchar(128) NOT NULL DEFAULT 'Default',
              `mapped_shipping_method` varchar(128) NOT NULL DEFAULT '',
              `special_instructions`   varchar(500) NOT NULL DEFAULT '',
              `billing_address`        json NULL,
              `packaging_size_id`      int UNSIGNED DEFAULT NULL,
              `package_count`          int UNSIGNED NOT NULL DEFAULT 1,
              `order_weight_kg`        decimal(10,3) NOT NULL DEFAULT 0.000,
              `on_hold`                tinyint(1) NOT NULL DEFAULT 0,
              `is_priority`            tinyint(1) NOT NULL DEFAULT 0,
              `attention_required`     tinyint(1) NOT NULL DEFAULT 0,
              `is_paid`                tinyint(1) NOT NULL DEFAULT 1,
              `notes`                  varchar(500) NOT NULL DEFAULT '',
              `pre_despatched`         tinyint(1) NOT NULL DEFAULT 0,
              `shipped_at`             datetime     DEFAULT NULL,
              `created_at`             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `synced_at`              datetime     DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_channel_order` (`tenant_id`, `channel_id`, `external_order_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_channel`  (`channel_id`),
              KEY `idx_status`   (`status`),
              KEY `idx_created`  (`created_at`),
              KEY `idx_shipped`  (`shipped_at`),
              KEY `idx_store_orders_packaging` (`packaging_size_id`),
              KEY `idx_store_orders_hold` (`on_hold`),
              KEY `idx_store_orders_priority` (`is_priority`),
              KEY `idx_store_orders_attention` (`attention_required`),
              CONSTRAINT `fk_store_orders_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_order_channel`
                FOREIGN KEY (`channel_id`) REFERENCES `#__store_channels` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_order_items` (
              `id`                    int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`             int UNSIGNED NOT NULL,
              `order_id`              int UNSIGNED NOT NULL,
              `external_line_item_id` varchar(64) NOT NULL DEFAULT '',
              `variant_id`            int UNSIGNED DEFAULT NULL,
              `external_sku`          varchar(128) NOT NULL DEFAULT '',
              `kit_sku`               varchar(128) NOT NULL DEFAULT '',
              `title`                 varchar(255) NOT NULL DEFAULT '',
              `qty`                   int UNSIGNED NOT NULL DEFAULT 1,
              `price`                 decimal(10,2) NOT NULL DEFAULT 0.00,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_order`    (`order_id`),
              KEY `idx_variant`  (`variant_id`),
              CONSTRAINT `fk_store_order_items_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_item_order`
                FOREIGN KEY (`order_id`) REFERENCES `#__store_orders` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_store_item_variant`
                FOREIGN KEY (`variant_id`) REFERENCES `#__store_product_variants` (`id`)
                ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_pickwaves` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL,
              `status`       varchar(16)  NOT NULL DEFAULT 'open',
              `picker_name`  varchar(128) NOT NULL DEFAULT '',
              `notes`        varchar(500) NOT NULL DEFAULT '',
              `tag`          varchar(128) NOT NULL DEFAULT '',
              `created_by`   int UNSIGNED DEFAULT NULL,
              `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `completed_at` datetime     DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_status` (`status`),
              KEY `idx_created` (`created_at`),
              CONSTRAINT `fk_store_pickwaves_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_pickwave_orders` (
              `tenant_id`   int UNSIGNED NOT NULL,
              `pickwave_id` int UNSIGNED NOT NULL,
              `order_id`    int UNSIGNED NOT NULL,
              `added_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`pickwave_id`, `order_id`),
              UNIQUE KEY `uq_order` (`order_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_pickwave` (`pickwave_id`),
              CONSTRAINT `fk_store_pwo_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_pwo_wave`
                FOREIGN KEY (`pickwave_id`) REFERENCES `#__store_pickwaves` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_store_pwo_order`
                FOREIGN KEY (`order_id`) REFERENCES `#__store_orders` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_shipments` (
              `id`                 int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`          int UNSIGNED NOT NULL,
              `order_id`           int UNSIGNED NOT NULL,
              `courier_channel_id` int UNSIGNED NOT NULL,
              `carrier_code`       varchar(64)  NOT NULL DEFAULT 'EVRI',
              `barcode`            varchar(64)  NOT NULL DEFAULT '',
              `tracking_number`    varchar(128) NOT NULL DEFAULT '',
              `client_uid`         varchar(128) NOT NULL DEFAULT '',
              `label_path`         varchar(255) NOT NULL DEFAULT '',
              `status`             varchar(32)  NOT NULL DEFAULT 'created',
              `raw_response`       mediumtext   NULL,
              `created_at`         datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_order` (`order_id`),
              KEY `idx_courier` (`courier_channel_id`),
              KEY `idx_barcode` (`barcode`),
              CONSTRAINT `fk_store_shipments_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_ship_order`
                FOREIGN KEY (`order_id`) REFERENCES `#__store_orders` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_store_ship_courier`
                FOREIGN KEY (`courier_channel_id`) REFERENCES `#__store_channels` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__store_shipments`');
        $db->query('DROP TABLE IF EXISTS `#__store_pickwave_orders`');
        $db->query('DROP TABLE IF EXISTS `#__store_pickwaves`');
        $db->query('DROP TABLE IF EXISTS `#__store_order_items`');
        $db->query('DROP TABLE IF EXISTS `#__store_orders`');
    },
];
