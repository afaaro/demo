<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE `#__forum_threads` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `category_id`  INT UNSIGNED NOT NULL,
                `user_id`      INT UNSIGNED NOT NULL,
                `title`        VARCHAR(255) NOT NULL,
                `slug`         VARCHAR(255) NOT NULL,
                `post_count`   INT UNSIGNED NOT NULL DEFAULT 1,
                `view_count`   INT UNSIGNED NOT NULL DEFAULT 0,
                `is_pinned`    TINYINT(1) NOT NULL DEFAULT 0,
                `is_locked`    TINYINT(1) NOT NULL DEFAULT 0,
                `is_deleted`   TINYINT(1) NOT NULL DEFAULT 0,
                `last_post_id` INT UNSIGNED NULL DEFAULT NULL,
                `last_post_at` DATETIME NULL DEFAULT NULL,
                `last_post_by` INT UNSIGNED NULL DEFAULT NULL,
                `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_thread_slug` (`category_id`, `slug`),
                KEY `idx_thread_cat_pinned` (`category_id`, `is_pinned`, `last_post_at`),
                KEY `idx_thread_user` (`user_id`),
                CONSTRAINT `fk_thread_cat`  FOREIGN KEY (`category_id`) REFERENCES `#__forum_categories`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_thread_user` FOREIGN KEY (`user_id`)     REFERENCES `#__users`(`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    },

    'down' => function (Database $db) {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $db->query('DROP TABLE IF EXISTS `#__forum_threads`');
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    },
];