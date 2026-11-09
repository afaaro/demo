<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__media_video_comments`');
    },

    'down' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__media_video_comments` (
                `comment_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `video_id` VARCHAR(16) NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `comment_text` TEXT NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`comment_id`),
                KEY `idx_media_comment_video` (`video_id`),
                KEY `idx_media_comment_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
];
