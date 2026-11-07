<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_products` (
              `id`                     int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`              int UNSIGNED NOT NULL,
              `parent_id`              int UNSIGNED DEFAULT NULL,
              `sku`                    varchar(64)  NOT NULL,
              `name`                   varchar(255) NOT NULL,
              `brand`                  varchar(128) NOT NULL DEFAULT '',
              `image`                  varchar(255) NOT NULL,
              `description`            text         NOT NULL,
              `barcode`                varchar(64)  NOT NULL DEFAULT '',
              `upc`                    varchar(64)  NOT NULL DEFAULT '',
              `mpn`                    varchar(64)  NOT NULL DEFAULT '',
              `supplier_sku`           varchar(64)  NOT NULL DEFAULT '',
              `supplier_name`          varchar(128) NOT NULL DEFAULT '',
              `warehouse`              varchar(128) NOT NULL DEFAULT 'Default',
              `price`                  decimal(10,2) NOT NULL DEFAULT 0.00,
              `rrp`                    decimal(10,2) NOT NULL DEFAULT 0.00,
              `tax_class`              varchar(16) NOT NULL DEFAULT '20',
              `price_includes_vat`     tinyint(1) NOT NULL DEFAULT 0,
              `cost`                   decimal(10,2) NOT NULL DEFAULT 0.00,
              `weight_grams`           int UNSIGNED NOT NULL DEFAULT 0,
              `length_mm`              int UNSIGNED NOT NULL DEFAULT 0,
              `width_mm`               int UNSIGNED NOT NULL DEFAULT 0,
              `height_mm`              int UNSIGNED NOT NULL DEFAULT 0,
              `shipping_json`          json NOT NULL DEFAULT (JSON_OBJECT()),
              `notes`                  text NOT NULL,
              `regulatory_json`        json NOT NULL DEFAULT (JSON_OBJECT()),
              `kit_display_on_order`   tinyint(1) NOT NULL DEFAULT 1,
              `ebay_aspects_json`      json NOT NULL DEFAULT (JSON_OBJECT()),
              `ebay_category_id`       varchar(32) NOT NULL DEFAULT '',
              `ebay_category_name`     varchar(255) NOT NULL DEFAULT '',
              `ebay_condition`         varchar(64) NOT NULL DEFAULT '',
              `amazon_attributes_json` json NOT NULL DEFAULT (JSON_OBJECT()),
              `attributes`             json NOT NULL DEFAULT (JSON_OBJECT()),
              `active`                 tinyint(1)   NOT NULL DEFAULT 1,
              `created_at`             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_sku` (`tenant_id`, `sku`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_parent` (`parent_id`),
              KEY `idx_active` (`active`),
              KEY `idx_barcode` (`barcode`),
              KEY `idx_brand` (`brand`),
              CONSTRAINT `fk_store_products_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_products_parent`
                FOREIGN KEY (`parent_id`) REFERENCES `#__store_products` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_product_variants` (
              `id`             int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`      int UNSIGNED NOT NULL,
              `product_id`     int UNSIGNED NOT NULL,
              `sku`            varchar(64)  NOT NULL,
              `name`           varchar(255) NOT NULL DEFAULT '',
              `pack_qty`       int UNSIGNED NOT NULL DEFAULT 0,
              `pick_bin`       varchar(64)  NOT NULL DEFAULT '',
              `price`          decimal(10,2) NOT NULL DEFAULT 0.00,
              `cost`           decimal(10,2) NOT NULL DEFAULT 0.00,
              `weight_grams`   int UNSIGNED NOT NULL DEFAULT 0,
              `shipping_json`  json NOT NULL DEFAULT (JSON_OBJECT()),
              `active`         tinyint(1)   NOT NULL DEFAULT 1,
              `created_at`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_variant_sku` (`tenant_id`, `sku`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_product` (`product_id`),
              CONSTRAINT `fk_store_variants_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_variant_product`
                FOREIGN KEY (`product_id`) REFERENCES `#__store_products` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_attributes` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`   int UNSIGNED NOT NULL,
              `code`        varchar(64)  NOT NULL,
              `name`        varchar(128) NOT NULL,
              `type`        enum('select','text','number') NOT NULL DEFAULT 'select',
              `sort_order`  int NOT NULL DEFAULT 0,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_attr_code` (`tenant_id`, `code`),
              KEY `idx_tenant_id` (`tenant_id`),
              CONSTRAINT `fk_store_attributes_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_attribute_values` (
              `id`            int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`     int UNSIGNED NOT NULL,
              `attribute_id`  int UNSIGNED NOT NULL,
              `value`         varchar(128) NOT NULL,
              `label`         varchar(128) NOT NULL DEFAULT '',
              `sort_order`    int NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_attr_value` (`attribute_id`, `value`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_attr` (`attribute_id`),
              CONSTRAINT `fk_av_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_av_attribute`
                FOREIGN KEY (`attribute_id`) REFERENCES `#__store_attributes` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_product_attributes` (
              `product_id`    int UNSIGNED NOT NULL,
              `attribute_id`  int UNSIGNED NOT NULL,
              `sort_order`    int NOT NULL DEFAULT 0,
              PRIMARY KEY (`product_id`, `attribute_id`),
              CONSTRAINT `fk_pa_product`
                FOREIGN KEY (`product_id`) REFERENCES `#__store_products` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_pa_attribute`
                FOREIGN KEY (`attribute_id`) REFERENCES `#__store_attributes` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_variant_attribute_values` (
              `tenant_id`           int UNSIGNED NOT NULL,
              `variant_id`          int UNSIGNED NOT NULL,
              `attribute_id`        int UNSIGNED NOT NULL,
              `attribute_value_id`  int UNSIGNED NOT NULL,
              PRIMARY KEY (`variant_id`, `attribute_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_value` (`attribute_value_id`),
              CONSTRAINT `fk_vav_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_vav_variant`
                FOREIGN KEY (`variant_id`) REFERENCES `#__store_product_variants` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_vav_attribute`
                FOREIGN KEY (`attribute_id`) REFERENCES `#__store_attributes` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_vav_value`
                FOREIGN KEY (`attribute_value_id`) REFERENCES `#__store_attribute_values` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_categories` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`   int UNSIGNED NOT NULL,
              `parent_id`   int UNSIGNED DEFAULT NULL,
              `slug`        varchar(128) NOT NULL,
              `name`        varchar(128) NOT NULL,
              `description` text NOT NULL,
              `sort_order`  int NOT NULL DEFAULT 0,
              `active`      tinyint(1) NOT NULL DEFAULT 1,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_parent_slug` (`tenant_id`, `parent_id`, `slug`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_parent` (`parent_id`),
              KEY `idx_active` (`active`),
              CONSTRAINT `fk_store_categories_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_cat_parent`
                FOREIGN KEY (`parent_id`) REFERENCES `#__store_categories` (`id`)
                ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_product_categories` (
              `tenant_id`   int UNSIGNED NOT NULL,
              `product_id`  int UNSIGNED NOT NULL,
              `category_id` int UNSIGNED NOT NULL,
              PRIMARY KEY (`product_id`, `category_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_category` (`category_id`),
              CONSTRAINT `fk_pc_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_pc_product`
                FOREIGN KEY (`product_id`) REFERENCES `#__store_products` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_pc_category`
                FOREIGN KEY (`category_id`) REFERENCES `#__store_categories` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_brands` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`  int UNSIGNED NOT NULL,
              `name`       varchar(128) NOT NULL,
              `website`    varchar(255) NOT NULL DEFAULT '',
              `logo_url`   varchar(500) NOT NULL DEFAULT '',
              `notes`      varchar(500) NOT NULL DEFAULT '',
              `active`     tinyint(1) NOT NULL DEFAULT 1,
              `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_brand_name` (`tenant_id`, `name`),
              KEY `idx_tenant_id` (`tenant_id`),
              CONSTRAINT `fk_store_brands_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_suppliers` (
              `id`             int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`      int UNSIGNED NOT NULL,
              `name`           varchar(128) NOT NULL,
              `company`        varchar(128) NOT NULL DEFAULT '',
              `contact_name`   varchar(128) NOT NULL DEFAULT '',
              `email`          varchar(255) NOT NULL DEFAULT '',
              `phone`          varchar(64) NOT NULL DEFAULT '',
              `website`        varchar(255) NOT NULL DEFAULT '',
              `address`        text NULL,
              `lead_time_days` int UNSIGNED NOT NULL DEFAULT 0,
              `currency`       char(3) NOT NULL DEFAULT 'GBP',
              `po_mode`        varchar(32) NOT NULL DEFAULT 'below_reorder',
              `sold_days`      int UNSIGNED NOT NULL DEFAULT 30,
              `include_in_requisitions` tinyint(1) NOT NULL DEFAULT 1,
              `accepts_back_orders`     tinyint(1) NOT NULL DEFAULT 1,
              `min_order_value` decimal(12,2) NOT NULL DEFAULT 0.00,
              `notes`          varchar(500) NOT NULL DEFAULT '',
              `active`         tinyint(1) NOT NULL DEFAULT 1,
              `created_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_supplier_name` (`tenant_id`, `name`),
              KEY `idx_tenant_id` (`tenant_id`),
              CONSTRAINT `fk_store_suppliers_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_warehouses` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL,
              `name`         varchar(128) NOT NULL,
              `company`      varchar(128) NOT NULL DEFAULT '',
              `address`      text NULL,
              `contact_name` varchar(128) NOT NULL DEFAULT '',
              `email`        varchar(255) NOT NULL DEFAULT '',
              `phone`        varchar(64) NOT NULL DEFAULT '',
              `notes`        varchar(500) NOT NULL DEFAULT '',
              `active`       tinyint(1) NOT NULL DEFAULT 1,
              `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_warehouse_name` (`tenant_id`, `name`),
              KEY `idx_tenant_id` (`tenant_id`),
              CONSTRAINT `fk_store_warehouses_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_inventory` (
              `tenant_id`           int UNSIGNED NOT NULL,
              `variant_id`          int UNSIGNED NOT NULL,
              `quantity`            int NOT NULL DEFAULT 0,
              `reserved`            int NOT NULL DEFAULT 0,
              `reorder_at`          int NOT NULL DEFAULT 0,
              `out_of_stock_at`     datetime NULL DEFAULT NULL,
              `supplier_inventory`  int NULL DEFAULT NULL,
              `updated_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`variant_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              CONSTRAINT `fk_store_inventory_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_inv_variant`
                FOREIGN KEY (`variant_id`) REFERENCES `#__store_product_variants` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_stock_movements` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`   int UNSIGNED NOT NULL,
              `variant_id`  int UNSIGNED NOT NULL,
              `delta`       int NOT NULL,
              `reason`      varchar(64) NOT NULL DEFAULT '',
              `note`        varchar(255) NOT NULL DEFAULT '',
              `user_id`     int UNSIGNED DEFAULT NULL,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_variant` (`variant_id`),
              KEY `idx_created` (`created_at`),
              CONSTRAINT `fk_store_mov_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_mov_variant`
                FOREIGN KEY (`variant_id`) REFERENCES `#__store_product_variants` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_product_audit` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL,
              `product_id`   int UNSIGNED NOT NULL,
              `user_id`      int UNSIGNED DEFAULT NULL,
              `user_label`   varchar(128) NOT NULL DEFAULT '',
              `action`       varchar(64)  NOT NULL DEFAULT 'updated',
              `summary`      varchar(500) NOT NULL DEFAULT '',
              `changes_json` json NOT NULL DEFAULT (JSON_OBJECT()),
              `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_product_created` (`product_id`, `created_at`),
              CONSTRAINT `fk_store_product_audit_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_product_audit_product`
                FOREIGN KEY (`product_id`) REFERENCES `#__store_products` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_product_documents` (
              `id`            int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`     int UNSIGNED NOT NULL,
              `product_id`    int UNSIGNED NOT NULL,
              `title`         varchar(255) NOT NULL DEFAULT '',
              `original_name` varchar(255) NOT NULL DEFAULT '',
              `stored_path`   varchar(255) NOT NULL,
              `mime`          varchar(128) NOT NULL DEFAULT '',
              `size_bytes`    int UNSIGNED NOT NULL DEFAULT 0,
              `uploaded_by`   int UNSIGNED DEFAULT NULL,
              `created_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_product` (`product_id`),
              CONSTRAINT `fk_store_product_documents_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_product_documents_product`
                FOREIGN KEY (`product_id`) REFERENCES `#__store_products` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_product_bundle_components` (
              `id`                    int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`             int UNSIGNED NOT NULL,
              `product_id`            int UNSIGNED NOT NULL,
              `component_product_id`  int UNSIGNED NOT NULL,
              `component_sku`         varchar(64)  NOT NULL DEFAULT '',
              `qty`                   int UNSIGNED NOT NULL DEFAULT 1,
              `delete_original`       tinyint(1)   NOT NULL DEFAULT 1,
              `update_kit_inventory`  tinyint(1)   NOT NULL DEFAULT 0,
              `created_at`            datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_kit_component` (`product_id`, `component_product_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_component` (`component_product_id`),
              CONSTRAINT `fk_store_bundle_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_bundle_kit_product`
                FOREIGN KEY (`product_id`) REFERENCES `#__store_products` (`id`)
                ON DELETE CASCADE,
              CONSTRAINT `fk_store_bundle_component_product`
                FOREIGN KEY (`component_product_id`) REFERENCES `#__store_products` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query(
            "INSERT IGNORE INTO `#__store_warehouses` (`tenant_id`, `name`) VALUES (1, 'Default')"
        );

        // Upgrade path: older installs may lack pack_qty.
        $variantTable = $db->getPrefix() . 'store_product_variants';
        $hasPackQty = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = 'pack_qty'",
            [$variantTable]
        )->value;
        if ($hasPackQty === 0) {
            $db->query(
                "ALTER TABLE `#__store_product_variants`
                   ADD COLUMN `pack_qty` int UNSIGNED NOT NULL DEFAULT 0 AFTER `name`"
            );
        }
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__store_product_bundle_components`');
        $db->query('DROP TABLE IF EXISTS `#__store_product_documents`');
        $db->query('DROP TABLE IF EXISTS `#__store_product_audit`');
        $db->query('DROP TABLE IF EXISTS `#__store_stock_movements`');
        $db->query('DROP TABLE IF EXISTS `#__store_inventory`');
        $db->query('DROP TABLE IF EXISTS `#__store_warehouses`');
        $db->query('DROP TABLE IF EXISTS `#__store_suppliers`');
        $db->query('DROP TABLE IF EXISTS `#__store_brands`');
        $db->query('DROP TABLE IF EXISTS `#__store_product_categories`');
        $db->query('DROP TABLE IF EXISTS `#__store_categories`');
        $db->query('DROP TABLE IF EXISTS `#__store_variant_attribute_values`');
        $db->query('DROP TABLE IF EXISTS `#__store_product_attributes`');
        $db->query('DROP TABLE IF EXISTS `#__store_attribute_values`');
        $db->query('DROP TABLE IF EXISTS `#__store_attributes`');
        $db->query('DROP TABLE IF EXISTS `#__store_product_variants`');
        $db->query('DROP TABLE IF EXISTS `#__store_products`');
    },
];
