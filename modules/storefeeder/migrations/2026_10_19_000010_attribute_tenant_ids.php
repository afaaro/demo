<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Add missing tenant_id columns used by AttributeRepository / VariantRepository.
 */
return [
    'up' => function (Database $db) {
        $prefix = $db->getPrefix();

        $hasColumn = static function (Database $db, string $table, string $column) use ($prefix): bool {
            return (int) $db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?",
                [$prefix . $table, $column]
            )->value > 0;
        };

        // --- sf_attribute_values.tenant_id ---
        if (!$hasColumn($db, 'sf_attribute_values', 'tenant_id')) {
            $db->query(
                "ALTER TABLE `#__sf_attribute_values`
                   ADD COLUMN `tenant_id` int UNSIGNED NULL AFTER `id`"
            );
            $db->query(
                "UPDATE `#__sf_attribute_values` av
                   INNER JOIN `#__sf_attributes` a ON a.`id` = av.`attribute_id`
                    SET av.`tenant_id` = a.`tenant_id`"
            );
            $db->query(
                "DELETE FROM `#__sf_attribute_values` WHERE `tenant_id` IS NULL"
            );
            $db->query(
                "ALTER TABLE `#__sf_attribute_values`
                   MODIFY COLUMN `tenant_id` int UNSIGNED NOT NULL,
                   ADD KEY `idx_tenant_id` (`tenant_id`),
                   ADD CONSTRAINT `fk_av_tenant`
                     FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                     ON DELETE RESTRICT"
            );
        }

        // --- sf_variant_attribute_values.tenant_id ---
        if (!$hasColumn($db, 'sf_variant_attribute_values', 'tenant_id')) {
            $db->query(
                "ALTER TABLE `#__sf_variant_attribute_values`
                   ADD COLUMN `tenant_id` int UNSIGNED NULL FIRST"
            );
            $db->query(
                "UPDATE `#__sf_variant_attribute_values` vav
                   INNER JOIN `#__sf_product_variants` v ON v.`id` = vav.`variant_id`
                    SET vav.`tenant_id` = v.`tenant_id`"
            );
            $db->query(
                "DELETE FROM `#__sf_variant_attribute_values` WHERE `tenant_id` IS NULL"
            );
            $db->query(
                "ALTER TABLE `#__sf_variant_attribute_values`
                   MODIFY COLUMN `tenant_id` int UNSIGNED NOT NULL,
                   ADD KEY `idx_tenant_id` (`tenant_id`),
                   ADD CONSTRAINT `fk_vav_tenant`
                     FOREIGN KEY (`tenant_id`) REFERENCES `#__sf_tenants` (`id`)
                     ON DELETE RESTRICT"
            );
        }
    },

    'down' => function (Database $db) {
        $prefix = $db->getPrefix();

        $hasColumn = static function (Database $db, string $table, string $column) use ($prefix): bool {
            return (int) $db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?",
                [$prefix . $table, $column]
            )->value > 0;
        };

        if ($hasColumn($db, 'sf_variant_attribute_values', 'tenant_id')) {
            $db->query(
                'ALTER TABLE `#__sf_variant_attribute_values`
                   DROP FOREIGN KEY `fk_vav_tenant`,
                   DROP KEY `idx_tenant_id`,
                   DROP COLUMN `tenant_id`'
            );
        }

        if ($hasColumn($db, 'sf_attribute_values', 'tenant_id')) {
            $db->query(
                'ALTER TABLE `#__sf_attribute_values`
                   DROP FOREIGN KEY `fk_av_tenant`,
                   DROP KEY `idx_tenant_id`,
                   DROP COLUMN `tenant_id`'
            );
        }
    },
];
