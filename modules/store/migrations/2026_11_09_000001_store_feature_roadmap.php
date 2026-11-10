<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Schema for store feature roadmap: pickwave scans, conversation assignment/SLA,
 * return restock flag.
 */
return [
    'up' => function (Database $db): void {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_pickwave_scans` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL DEFAULT 1,
              `pickwave_id`  int UNSIGNED NOT NULL,
              `sku_key`      varchar(128) NOT NULL DEFAULT '',
              `sku`          varchar(128) NOT NULL DEFAULT '',
              `qty`          int UNSIGNED NOT NULL DEFAULT 1,
              `user_id`      int UNSIGNED DEFAULT NULL,
              `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_wave_sku` (`pickwave_id`, `sku_key`),
              KEY `idx_tenant_id` (`tenant_id`),
              CONSTRAINT `fk_store_pickwave_scans_wave`
                FOREIGN KEY (`pickwave_id`) REFERENCES `#__store_pickwaves` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Conversation assignment / SLA
        try {
            $db->query("ALTER TABLE `#__store_conversations`
                ADD COLUMN `assigned_user_id` int UNSIGNED DEFAULT NULL AFTER `unread_count`,
                ADD COLUMN `due_at` datetime DEFAULT NULL AFTER `assigned_user_id`,
                ADD COLUMN `priority` tinyint UNSIGNED NOT NULL DEFAULT 0 AFTER `due_at`,
                ADD KEY `idx_assigned` (`assigned_user_id`),
                ADD KEY `idx_due_at` (`due_at`)");
        } catch (\Throwable) {
            // Columns may already exist.
        }

        // Idempotent restock marker on returns
        try {
            $db->query("ALTER TABLE `#__store_returns`
                ADD COLUMN `restocked_at` datetime DEFAULT NULL AFTER `updated_at`");
        } catch (\Throwable) {
            // already exists
        }
    },

    'down' => function (Database $db): void {
        $db->query('DROP TABLE IF EXISTS `#__store_pickwave_scans`');
        try {
            $db->query('ALTER TABLE `#__store_conversations`
                DROP COLUMN `assigned_user_id`,
                DROP COLUMN `due_at`,
                DROP COLUMN `priority`');
        } catch (\Throwable) {
        }
        try {
            $db->query('ALTER TABLE `#__store_returns` DROP COLUMN `restocked_at`');
        } catch (\Throwable) {
        }
    },
];
