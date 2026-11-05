<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__password_reset_tokens` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`    int UNSIGNED NOT NULL,
              `token_hash` char(64) NOT NULL,
              `expires_at` datetime NOT NULL,
              `used_at`    datetime DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `request_ip` varchar(45) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_prt_token` (`token_hash`),
              KEY `idx_prt_user` (`user_id`),
              KEY `idx_prt_expires` (`expires_at`),
              CONSTRAINT `fk_prt_user`
                FOREIGN KEY (`user_id`) REFERENCES `#__users` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__password_reset_tokens`');
    },
];
