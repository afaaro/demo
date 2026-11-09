<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__community_posts` (
              `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`     INT UNSIGNED NOT NULL,
              `body`        TEXT         NOT NULL,
              `visibility`  ENUM('friends','public') NOT NULL DEFAULT 'friends',
              `status`      ENUM('visible','deleted') NOT NULL DEFAULT 'visible',
              `created_at`  DATETIME     NOT NULL,
              `updated_at`  DATETIME     NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_community_posts_user_created` (`user_id`, `created_at`),
              KEY `idx_community_posts_status_created` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__community_post_media` (
              `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `post_id`     INT UNSIGNED NOT NULL,
              `path`        VARCHAR(255) NOT NULL,
              `mime`        VARCHAR(64)  NOT NULL,
              `sort_order`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
              `created_at`  DATETIME     NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_community_media_post` (`post_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__community_friendships` (
              `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`       INT UNSIGNED NOT NULL,
              `friend_id`     INT UNSIGNED NOT NULL,
              `status`        ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
              `requested_by`  INT UNSIGNED NOT NULL,
              `created_at`    DATETIME     NOT NULL,
              `updated_at`    DATETIME     NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_community_friend_pair` (`user_id`, `friend_id`),
              KEY `idx_community_friend_status` (`status`),
              KEY `idx_community_friend_requested` (`requested_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__community_reactions` (
              `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `post_id`     INT UNSIGNED NOT NULL,
              `user_id`     INT UNSIGNED NOT NULL,
              `type`        ENUM('like') NOT NULL DEFAULT 'like',
              `created_at`  DATETIME     NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_community_reaction` (`post_id`, `user_id`),
              KEY `idx_community_reaction_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__community_notifications` (
              `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`      INT UNSIGNED NOT NULL,
              `actor_id`     INT UNSIGNED NOT NULL,
              `type`         VARCHAR(32)  NOT NULL,
              `entity_type`  VARCHAR(32)  NOT NULL,
              `entity_id`    VARCHAR(64)  NOT NULL,
              `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
              `created_at`   DATETIME     NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_community_notif_user_created` (`user_id`, `created_at`),
              KEY `idx_community_notif_unread` (`user_id`, `is_read`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $exists = $db->query(
            'SELECT `id` FROM `#__modules` WHERE `name` = ? LIMIT 1',
            ['community']
        )->row;
        if ($exists === []) {
            $db->insert('modules', [
                'name'         => 'community',
                'label'        => 'Community',
                'version'      => '1.0.0',
                'enabled'      => 1,
                'config'       => json_encode([
                    'posts_per_page'     => 15,
                    'max_photo_mb'       => 5,
                    'default_visibility' => 'friends',
                ], JSON_UNESCAPED_UNICODE),
                'installed_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $db->update('modules', ['enabled' => 1, 'label' => 'Community'], ['name' => 'community']);
        }
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__community_notifications`');
        $db->query('DROP TABLE IF EXISTS `#__community_reactions`');
        $db->query('DROP TABLE IF EXISTS `#__community_friendships`');
        $db->query('DROP TABLE IF EXISTS `#__community_post_media`');
        $db->query('DROP TABLE IF EXISTS `#__community_posts`');
    },
];
