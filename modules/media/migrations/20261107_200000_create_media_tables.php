<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__media_channels` (
                `channel_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `owner_id` INT UNSIGNED NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `avatar` VARCHAR(255) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`channel_id`),
                UNIQUE KEY `uq_media_channel_slug` (`slug`),
                KEY `idx_media_channel_owner` (`owner_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__media_channel_staff` (
                `channel_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `role` ENUM('owner','manager','editor') NOT NULL DEFAULT 'editor',
                `assigned_at` DATETIME NOT NULL,
                PRIMARY KEY (`channel_id`, `user_id`),
                KEY `idx_media_staff_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__media_videos` (
                `video_id` VARCHAR(16) NOT NULL,
                `channel_id` INT UNSIGNED NOT NULL,
                `uploaded_by` INT UNSIGNED NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `media_type` ENUM('video','audio','image','embed') NOT NULL DEFAULT 'video',
                `file_path` VARCHAR(512) NULL DEFAULT NULL,
                `source_url` VARCHAR(512) NULL DEFAULT NULL,
                `thumbnail_path` VARCHAR(512) NULL DEFAULT NULL,
                `status` ENUM('processing','public','private') NOT NULL DEFAULT 'processing',
                `views_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`video_id`),
                KEY `idx_media_video_channel` (`channel_id`),
                KEY `idx_media_video_status` (`status`),
                KEY `idx_media_video_type` (`media_type`),
                KEY `idx_media_video_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__media_video_reactions` (
                `reaction_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `video_id` VARCHAR(16) NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `type` ENUM('like','dislike') NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`reaction_id`),
                UNIQUE KEY `uq_media_user_video_reaction` (`video_id`, `user_id`),
                KEY `idx_media_reaction_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

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

    'down' => function (Database $db) {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query('DROP TABLE IF EXISTS `#__media_video_comments`');
        $db->query('DROP TABLE IF EXISTS `#__media_video_reactions`');
        $db->query('DROP TABLE IF EXISTS `#__media_videos`');
        $db->query('DROP TABLE IF EXISTS `#__media_channel_staff`');
        $db->query('DROP TABLE IF EXISTS `#__media_channels`');
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    },
];
