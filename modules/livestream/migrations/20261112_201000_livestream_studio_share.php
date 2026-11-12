<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * One share token per user that lists broadcast settings for every stream they own.
 */
return [
    'up' => function (Database $db): void {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__livestream_studio_shares` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`     int UNSIGNED NOT NULL,
              `share_token` varchar(64) NOT NULL,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_livestream_studio_share_user` (`user_id`),
              UNIQUE KEY `uq_livestream_studio_share_token` (`share_token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db): void {
        $db->query('DROP TABLE IF EXISTS `#__livestream_studio_shares`');
    },
];
