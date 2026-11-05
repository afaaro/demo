<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__news_categories` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `slug`       varchar(64)  NOT NULL,
              `name`       varchar(128) NOT NULL,
              `sort_order` int          NOT NULL DEFAULT 0,
              `active`     tinyint(1)   NOT NULL DEFAULT 1,
              `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_slug` (`slug`),
              KEY `idx_active` (`active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__news` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `category_id`  int UNSIGNED DEFAULT NULL,
              `slug`         varchar(128) NOT NULL,
              `title`        varchar(255) NOT NULL,
              `excerpt`      varchar(500) NOT NULL DEFAULT '',
              `body`         mediumtext   NOT NULL,
              `image`        varchar(500) NOT NULL DEFAULT '',
              `status`       enum('draft','published','archived') NOT NULL DEFAULT 'draft',
              `published_at` datetime     DEFAULT NULL,
              `author_id`    int UNSIGNED DEFAULT NULL,
              `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_slug` (`slug`),
              KEY `idx_category` (`category_id`),
              KEY `idx_status_published` (`status`, `published_at`),
              CONSTRAINT `fk_news_category`
                FOREIGN KEY (`category_id`)
                REFERENCES `#__news_categories` (`id`)
                ON DELETE SET NULL
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query("DROP TABLE IF EXISTS `#__news`");
        $db->query("DROP TABLE IF EXISTS `#__news_categories`");
    },
];