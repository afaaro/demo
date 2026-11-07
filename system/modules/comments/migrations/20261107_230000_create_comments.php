<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__comments` (
              `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `module`      VARCHAR(64)  NOT NULL,
              `entity_type` VARCHAR(64)  NOT NULL,
              `entity_id`   VARCHAR(64)  NOT NULL,
              `user_id`     INT UNSIGNED NOT NULL,
              `body`        TEXT         NOT NULL,
              `status`      ENUM('visible','hidden','deleted') NOT NULL DEFAULT 'visible',
              `created_at`  DATETIME     NOT NULL,
              `updated_at`  DATETIME     NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_comments_entity` (`module`, `entity_type`, `entity_id`, `status`, `created_at`),
              KEY `idx_comments_user` (`user_id`),
              KEY `idx_comments_status_created` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // One-time copy from legacy media comments when that table exists.
        $legacy = $db->query(
            "SELECT 1 AS ok
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
              LIMIT 1",
            [$db->getPrefix() . 'media_video_comments']
        )->row;

        if ($legacy !== []) {
            $db->query(
                "INSERT INTO `#__comments`
                    (`module`, `entity_type`, `entity_id`, `user_id`, `body`, `status`, `created_at`)
                 SELECT 'media', 'video', c.`video_id`, c.`user_id`, c.`comment_text`, 'visible', c.`created_at`
                   FROM `#__media_video_comments` c
                  WHERE NOT EXISTS (
                        SELECT 1 FROM `#__comments` x
                         WHERE x.`module` = 'media'
                           AND x.`entity_type` = 'video'
                           AND x.`entity_id` = c.`video_id`
                           AND x.`user_id` = c.`user_id`
                           AND x.`body` = c.`comment_text`
                           AND x.`created_at` = c.`created_at`
                  )"
            );
        }

        $exists = $db->query(
            'SELECT `id` FROM `#__modules` WHERE `name` = ? LIMIT 1',
            ['comments']
        )->row;
        if ($exists === []) {
            $db->insert('modules', [
                'name'         => 'comments',
                'label'        => 'Comments',
                'version'      => '1.0.0',
                'enabled'      => 1,
                'config'       => '{}',
                'installed_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $db->update('modules', ['enabled' => 1], ['name' => 'comments']);
        }
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__comments`');
    },
];
