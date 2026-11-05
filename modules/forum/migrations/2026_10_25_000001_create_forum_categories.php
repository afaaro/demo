<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE `#__forum_categories` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `parent_id`     INT UNSIGNED NULL DEFAULT NULL,
                `slug`          VARCHAR(64) NOT NULL,
                `name`          VARCHAR(128) NOT NULL,
                `description`   TEXT NULL,
                `position`      INT UNSIGNED NOT NULL DEFAULT 100,
                `access_role`   INT UNSIGNED NOT NULL DEFAULT 1,
                `post_role`     INT UNSIGNED NOT NULL DEFAULT 2,
                `threads_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `posts_count`   INT UNSIGNED NOT NULL DEFAULT 0,
                `status`        ENUM('visible','hidden','locked') NOT NULL DEFAULT 'visible',
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_cat_slug` (`slug`),
                KEY `idx_cat_parent` (`parent_id`),
                KEY `idx_cat_status` (`status`),
                CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `#__forum_categories`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    },

    'down' => function (Database $db) {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $db->query('DROP TABLE IF EXISTS `#__forum_categories`');
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    },
];