<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Track active logins: users.last_seen_at + user_sessions rows.
 */
return [
    'up' => function (Database $db) {
        $cols = $db->query("SHOW COLUMNS FROM `#__users` LIKE 'last_seen_at'")->rows;
        if ($cols === []) {
            $db->query("
                ALTER TABLE `#__users`
                  ADD COLUMN `last_seen_at` datetime NULL DEFAULT NULL AFTER `last_login_at`
            ");
        }

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__user_sessions` (
              `id`            varchar(128) NOT NULL,
              `user_id`       int UNSIGNED NOT NULL,
              `ip`            varchar(45)  NOT NULL DEFAULT '',
              `user_agent`    varchar(255) NOT NULL DEFAULT '',
              `last_activity` datetime     NOT NULL,
              `created_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_user` (`user_id`),
              KEY `idx_activity` (`last_activity`),
              CONSTRAINT `fk_user_sessions_user`
                FOREIGN KEY (`user_id`) REFERENCES `#__users` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__user_sessions`');
        $cols = $db->query("SHOW COLUMNS FROM `#__users` LIKE 'last_seen_at'")->rows;
        if ($cols !== []) {
            $db->query('ALTER TABLE `#__users` DROP COLUMN `last_seen_at`');
        }
    },
];
