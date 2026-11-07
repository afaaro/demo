<?php
return [
    'install' => [
        'tables' => [
            "CREATE TABLE `#__media_channels` (
                `channel_id` INT AUTO_INCREMENT PRIMARY KEY,
                `owner_id` INT NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(255) NOT NULL UNIQUE,
                `avatar` VARCHAR(255) NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

            "CREATE TABLE `#__media_channel_staff` (
                `channel_id` INT NOT NULL,
                `user_id` INT NOT NULL,
                `role` ENUM('owner', 'manager', 'editor') NOT NULL DEFAULT 'editor',
                `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`channel_id`, `user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

            "CREATE TABLE `#__media_videos` (
                `video_id` INT AUTO_INCREMENT PRIMARY KEY,
                `channel_id` INT NOT NULL,
                `uploaded_by` INT NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `file_path` VARCHAR(255) NOT NULL,
                `thumbnail_path` VARCHAR(255) NULL DEFAULT NULL,
                `status` ENUM('processing', 'public', 'private') NOT NULL DEFAULT 'processing',
                `views_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`channel_id`) REFERENCES `#__media_channels`(`channel_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

            "CREATE TABLE IF NOT EXISTS `#__media_video_reactions` (
                `reaction_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `video_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `type` ENUM('like', 'dislike') NOT NULL,
                `created_at` DATETIME NOT NULL,
                UNIQUE KEY `unique_user_video_reaction` (`video_id`, `user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

            "CREATE TABLE IF NOT EXISTS `#__media_video_comments` (
                `comment_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `video_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `comment_text` TEXT NOT NULL,
                `created_at` DATETIME NOT NULL,
                KEY `video_id_index` (`video_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ]
    ],

    'uninstall' => [
        'tables' => [
            // Fix: Drop dependent children first to prevent foreign key execution blocks
            "DROP TABLE IF EXISTS `#__media_videos`;",
            "DROP TABLE IF EXISTS `#__media_channel_staff`;",
            "DROP TABLE IF EXISTS `#__media_channels`;",
            "DROP TABLE IF EXISTS `#__media_video_reactions`;",
            "DROP TABLE IF EXISTS `#__media_video_comments`;"
        ]
    ],
    'permissions' => [
        'media.admin.*',
    ],
];