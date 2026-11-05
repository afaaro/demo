<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE `#__forum_posts` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `thread_id`    INT UNSIGNED NOT NULL,
                `user_id`      INT UNSIGNED NOT NULL,
                `content`      MEDIUMTEXT NOT NULL,
                `content_html` MEDIUMTEXT NOT NULL,
                `is_deleted`   TINYINT(1) NOT NULL DEFAULT 0,
                `edited_at`    DATETIME NULL DEFAULT NULL,
                `edited_by`    INT UNSIGNED NULL DEFAULT NULL,
                `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_post_thread` (`thread_id`, `created_at`),
                KEY `idx_post_user` (`user_id`),
                CONSTRAINT `fk_post_thread` FOREIGN KEY (`thread_id`) REFERENCES `#__forum_threads`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_post_user`   FOREIGN KEY (`user_id`)   REFERENCES `#__users`(`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    },

    'down' => function (Database $db) {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $db->query('DROP TABLE IF EXISTS `#__forum_posts`');
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    },
];