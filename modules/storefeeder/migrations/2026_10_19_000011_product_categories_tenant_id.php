<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Add tenant_id to sf_product_categories (CategoryRepository).
 */
return [
    'up' => function (Database $db) {
        $table = $db->getPrefix() . 'sf_product_categories';
        $exists = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = 'tenant_id'",
            [$table]
        )->value;

        if ($exists > 0) {
            return;
        }

        $db->query(
            "ALTER TABLE `#__sf_product_categories`
               ADD COLUMN `tenant_id` int UNSIGNED NULL FIRST"
        );

        $db->query(
            "UPDATE `#__sf_product_categories` pc
               INNER JOIN `#__sf_products` p ON p.`id` = pc.`product_id`
                SET pc.`tenant_id` = p.`tenant_id`"
        );

        $db->query(
            "DELETE FROM `#__sf_product_categories` WHERE `tenant_id` IS NULL"
        );

        $db->query(
            "ALTER TABLE `#__sf_product_categories`
               MODIFY COLUMN `tenant_id` int UNSIGNED NOT NULL,
               ADD KEY `idx_tenant_id` (`tenant_id`),
               ADD CONSTRAINT `fk_pc_tenant`
                 FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                 ON DELETE RESTRICT"
        );
    },

    'down' => function (Database $db) {
        $table = $db->getPrefix() . 'sf_product_categories';
        $exists = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = 'tenant_id'",
            [$table]
        )->value;

        if ($exists === 0) {
            return;
        }

        $db->query(
            'ALTER TABLE `#__sf_product_categories`
               DROP FOREIGN KEY `fk_pc_tenant`,
               DROP KEY `idx_tenant_id`,
               DROP COLUMN `tenant_id`'
        );
    },
];
