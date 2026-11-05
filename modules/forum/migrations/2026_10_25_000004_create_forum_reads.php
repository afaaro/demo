<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE `#__forum_reads` (
                `user_id`      INT UNSIGNED NOT NULL,
                `thread_id`    INT UNSIGNED NOT NULL,
                `last_read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `thread_id`),
                KEY `idx_read_thread` (`thread_id`),
                CONSTRAINT `fk_read_user`   FOREIGN KEY (`user_id`)   REFERENCES `#__users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_read_thread` FOREIGN KEY (`thread_id`) REFERENCES `#__forum_threads`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    },

    'down' => function (Database $db) {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $db->query('DROP TABLE IF EXISTS `#__forum_reads`');
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    },
];