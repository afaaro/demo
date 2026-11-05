<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $tables = $db->query("SHOW TABLES LIKE '#__news_revisions'")->rows;
        if ($tables !== []) {
            return;
        }

        $db->query("
            CREATE TABLE `#__news_revisions` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `news_id`    int UNSIGNED NOT NULL,
              `title`      varchar(255) NOT NULL DEFAULT '',
              `excerpt`    varchar(500) NOT NULL DEFAULT '',
              `body`       mediumtext   NOT NULL,
              `author_id`  int UNSIGNED DEFAULT NULL,
              `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_news_created` (`news_id`, `created_at`),
              CONSTRAINT `fk_news_revisions_news`
                FOREIGN KEY (`news_id`)
                REFERENCES `#__news` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query("DROP TABLE IF EXISTS `#__news_revisions`");
    },
];
