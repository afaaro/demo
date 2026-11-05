<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Page access statistics: daily rollups + recent hit log.
 */
return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__page_access_daily` (
              `access_date` date         NOT NULL,
              `route`       varchar(191) NOT NULL DEFAULT '',
              `module`      varchar(64)  NOT NULL DEFAULT '',
              `hits`        int UNSIGNED NOT NULL DEFAULT 0,
              `unique_users` int UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (`access_date`, `route`),
              KEY `idx_module_date` (`module`, `access_date`),
              KEY `idx_hits` (`hits`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__page_access_log` (
              `id`         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`    int UNSIGNED    NULL DEFAULT NULL,
              `route`      varchar(191)   NOT NULL DEFAULT '',
              `module`     varchar(64)    NOT NULL DEFAULT '',
              `method`     varchar(10)    NOT NULL DEFAULT 'GET',
              `path`       varchar(255)   NOT NULL DEFAULT '',
              `ip`         varchar(45)    NOT NULL DEFAULT '',
              `created_at` datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_created` (`created_at`),
              KEY `idx_route` (`route`),
              KEY `idx_user` (`user_id`),
              KEY `idx_module` (`module`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Tracks distinct users per route/day for unique_users increments.
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__page_access_daily_users` (
              `access_date` date         NOT NULL,
              `route`       varchar(191) NOT NULL DEFAULT '',
              `user_id`     int UNSIGNED NOT NULL,
              PRIMARY KEY (`access_date`, `route`, `user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__page_access_daily_users`');
        $db->query('DROP TABLE IF EXISTS `#__page_access_log`');
        $db->query('DROP TABLE IF EXISTS `#__page_access_daily`');
    },
];
