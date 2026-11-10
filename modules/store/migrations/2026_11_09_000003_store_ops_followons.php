<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Follow-on ops schema: PO scan-receive, pickwave pack stage, support case assignee.
 */
return [
    'up' => function (Database $db): void {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_po_scans` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`  int UNSIGNED NOT NULL DEFAULT 1,
              `po_id`      int UNSIGNED NOT NULL,
              `sku_key`    varchar(128) NOT NULL DEFAULT '',
              `sku`        varchar(128) NOT NULL DEFAULT '',
              `qty`        int UNSIGNED NOT NULL DEFAULT 1,
              `user_id`    int UNSIGNED DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_po_sku` (`po_id`, `sku_key`),
              KEY `idx_tenant_id` (`tenant_id`),
              CONSTRAINT `fk_store_po_scans_po`
                FOREIGN KEY (`po_id`) REFERENCES `#__store_purchase_orders` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        try {
            $db->query("ALTER TABLE `#__store_pickwave_scans`
                ADD COLUMN `stage` varchar(16) NOT NULL DEFAULT 'pick' AFTER `qty`,
                ADD KEY `idx_wave_stage_sku` (`pickwave_id`, `stage`, `sku_key`)");
        } catch (\Throwable) {
            // Column may already exist.
        }

        try {
            $db->query("UPDATE `#__store_pickwave_scans`
                SET `stage` = 'pick'
              WHERE `stage` IS NULL OR `stage` = ''");
        } catch (\Throwable) {
        }

        try {
            $db->query("ALTER TABLE `#__store_support_cases`
                ADD COLUMN `assigned_user_id` int UNSIGNED DEFAULT NULL AFTER `alerted_at`,
                ADD KEY `idx_case_assigned` (`assigned_user_id`)");
        } catch (\Throwable) {
        }
    },

    'down' => function (Database $db): void {
        $db->query('DROP TABLE IF EXISTS `#__store_po_scans`');
        try {
            $db->query('ALTER TABLE `#__store_pickwave_scans` DROP INDEX `idx_wave_stage_sku`, DROP COLUMN `stage`');
        } catch (\Throwable) {
        }
        try {
            $db->query('ALTER TABLE `#__store_support_cases` DROP INDEX `idx_case_assigned`, DROP COLUMN `assigned_user_id`');
        } catch (\Throwable) {
        }
    },
];
