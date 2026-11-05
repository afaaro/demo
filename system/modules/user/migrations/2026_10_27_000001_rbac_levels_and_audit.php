<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Group levels (PHP-Fusion), direct user grants, auth revision, audit log.
 */
return [
    'up' => function (Database $db) {
        $cols = $db->query("SHOW COLUMNS FROM `#__user_groups` LIKE 'level'")->rows;
        if ($cols === []) {
            $db->query("
                ALTER TABLE `#__user_groups`
                  ADD COLUMN `level` int NOT NULL DEFAULT 0 AFTER `is_system`
            ");
        }

        $db->query("UPDATE `#__user_groups` SET `level` = -101 WHERE `name` = 'users'");
        $db->query("UPDATE `#__user_groups` SET `level` = -102 WHERE `name` = 'admin'");
        $db->query("UPDATE `#__user_groups` SET `level` = -103 WHERE `name` = 'sadmin'");
        $db->query("UPDATE `#__user_groups` SET `is_system` = 1 WHERE `name` IN ('users','admin','sadmin')");

        $cols = $db->query("SHOW COLUMNS FROM `#__users` LIKE 'direct_permissions'")->rows;
        if ($cols === []) {
            $db->query("
                ALTER TABLE `#__users`
                  ADD COLUMN `direct_permissions` json NULL DEFAULT NULL AFTER `status`
            ");
        }

        $cols = $db->query("SHOW COLUMNS FROM `#__users` LIKE 'auth_revision'")->rows;
        if ($cols === []) {
            $db->query("
                ALTER TABLE `#__users`
                  ADD COLUMN `auth_revision` int UNSIGNED NOT NULL DEFAULT 1 AFTER `direct_permissions`
            ");
        }

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__user_auth_audit` (
              `id`              bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `actor_user_id`   int UNSIGNED NULL DEFAULT NULL,
              `target_user_id`  int UNSIGNED NULL DEFAULT NULL,
              `target_group_id` int UNSIGNED NULL DEFAULT NULL,
              `event`           varchar(64) NOT NULL DEFAULT '',
              `summary`         varchar(255) NOT NULL DEFAULT '',
              `before_json`     json NULL DEFAULT NULL,
              `after_json`      json NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_created` (`created_at`),
              KEY `idx_actor` (`actor_user_id`),
              KEY `idx_target_user` (`target_user_id`),
              KEY `idx_target_group` (`target_group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__user_auth_audit`');

        $cols = $db->query("SHOW COLUMNS FROM `#__users` LIKE 'auth_revision'")->rows;
        if ($cols !== []) {
            $db->query('ALTER TABLE `#__users` DROP COLUMN `auth_revision`');
        }
        $cols = $db->query("SHOW COLUMNS FROM `#__users` LIKE 'direct_permissions'")->rows;
        if ($cols !== []) {
            $db->query('ALTER TABLE `#__users` DROP COLUMN `direct_permissions`');
        }
        $cols = $db->query("SHOW COLUMNS FROM `#__user_groups` LIKE 'level'")->rows;
        if ($cols !== []) {
            $db->query('ALTER TABLE `#__user_groups` DROP COLUMN `level`');
        }
    },
];
