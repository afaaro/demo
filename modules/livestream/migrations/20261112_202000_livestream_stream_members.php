<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Per-stream managers: edit title and rotate key, not delete or share.
 */
return [
    'up' => function (Database $db): void {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__livestream_stream_members` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `stream_id`  int UNSIGNED NOT NULL,
              `user_id`    int UNSIGNED NOT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_livestream_stream_member` (`stream_id`, `user_id`),
              KEY `idx_livestream_member_user` (`user_id`),
              CONSTRAINT `fk_livestream_stream_member_stream`
                FOREIGN KEY (`stream_id`) REFERENCES `#__livestream_streams` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db): void {
        $db->query('DROP TABLE IF EXISTS `#__livestream_stream_members`');
    },
];
