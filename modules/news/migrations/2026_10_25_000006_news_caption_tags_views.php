<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $add = function (string $column, string $ddl) use ($db): void {
            $cols = $db->query("SHOW COLUMNS FROM `#__news` LIKE '{$column}'")->rows;
            if ($cols === []) {
                $db->query("ALTER TABLE `#__news` ADD COLUMN {$ddl}");
            }
        };

        $add('image_caption', "`image_caption` varchar(300) NOT NULL DEFAULT '' AFTER `image`");
        $add('image_credit', "`image_credit` varchar(160) NOT NULL DEFAULT '' AFTER `image_caption`");
        $viewAfter = $db->query("SHOW COLUMNS FROM `#__news` LIKE 'featured'")->rows !== []
            ? 'featured'
            : 'status';
        $add('view_count', "`view_count` int UNSIGNED NOT NULL DEFAULT 0 AFTER `{$viewAfter}`");

        $idx = $db->query("SHOW INDEX FROM `#__news` WHERE Key_name = 'idx_view_count'")->rows;
        if ($idx === []) {
            $db->query("ALTER TABLE `#__news` ADD KEY `idx_view_count` (`view_count`)");
        }

        $tables = $db->query("SHOW TABLES LIKE '#__news_tags'")->rows;
        if ($tables === []) {
            $db->query("
                CREATE TABLE `#__news_tags` (
                  `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
                  `slug`       varchar(64)  NOT NULL,
                  `name`       varchar(80)  NOT NULL,
                  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_tag_slug` (`slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $pivot = $db->query("SHOW TABLES LIKE '#__news_article_tags'")->rows;
        if ($pivot === []) {
            $db->query("
                CREATE TABLE `#__news_article_tags` (
                  `news_id` int UNSIGNED NOT NULL,
                  `tag_id`  int UNSIGNED NOT NULL,
                  PRIMARY KEY (`news_id`, `tag_id`),
                  KEY `idx_tag` (`tag_id`),
                  CONSTRAINT `fk_news_article_tags_news`
                    FOREIGN KEY (`news_id`) REFERENCES `#__news` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                  CONSTRAINT `fk_news_article_tags_tag`
                    FOREIGN KEY (`tag_id`) REFERENCES `#__news_tags` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    },

    'down' => function (Database $db) {
        $db->query("DROP TABLE IF EXISTS `#__news_article_tags`");
        $db->query("DROP TABLE IF EXISTS `#__news_tags`");
        foreach (['view_count', 'image_credit', 'image_caption'] as $column) {
            $cols = $db->query("SHOW COLUMNS FROM `#__news` LIKE '{$column}'")->rows;
            if ($cols !== []) {
                if ($column === 'view_count') {
                    $db->query("ALTER TABLE `#__news` DROP KEY `idx_view_count`, DROP COLUMN `view_count`");
                } else {
                    $db->query("ALTER TABLE `#__news` DROP COLUMN `{$column}`");
                }
            }
        }
    },
];
