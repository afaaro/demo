<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__media` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `filename`    varchar(255) NOT NULL,
              `path`        varchar(500) NOT NULL,
              `mime`        varchar(128) NOT NULL DEFAULT '',
              `size_bytes`  int UNSIGNED NOT NULL DEFAULT 0,
              `width`       int UNSIGNED NOT NULL DEFAULT 0,
              `height`      int UNSIGNED NOT NULL DEFAULT 0,
              `uploaded_by` int UNSIGNED DEFAULT NULL,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query("DROP TABLE IF EXISTS `#__media`");
    },
];