<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__livestream_streams` (
              `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`     INT UNSIGNED NOT NULL,
              `title`       VARCHAR(255) NOT NULL,
              `slug`        VARCHAR(64)  NOT NULL,
              `stream_key`  VARCHAR(64)  NOT NULL,
              `status`      ENUM('idle','live') NOT NULL DEFAULT 'idle',
              `started_at`  DATETIME NULL DEFAULT NULL,
              `ended_at`    DATETIME NULL DEFAULT NULL,
              `created_at`  DATETIME NOT NULL,
              `updated_at`  DATETIME NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_livestream_slug` (`slug`),
              UNIQUE KEY `uq_livestream_stream_key` (`stream_key`),
              KEY `idx_livestream_user` (`user_id`),
              KEY `idx_livestream_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $exists = $db->query(
            'SELECT `id` FROM `#__modules` WHERE `name` = ? LIMIT 1',
            ['livestream']
        )->row;
        if ($exists === []) {
            $db->insert('modules', [
                'name'         => 'livestream',
                'label'        => 'Livestream',
                'version'      => '1.0.0',
                'enabled'      => 1,
                'config'       => '{}',
                'installed_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $db->update('modules', ['enabled' => 1], ['name' => 'livestream']);
        }
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__livestream_streams`');
    },
];
