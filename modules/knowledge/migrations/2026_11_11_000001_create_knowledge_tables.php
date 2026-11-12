<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__knowledge_domains` (
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
            CREATE TABLE IF NOT EXISTS `#__knowledge_entries` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `domain_id`  int UNSIGNED DEFAULT NULL,
              `slug`       varchar(128) NOT NULL,
              `status`     enum('draft','published','archived') NOT NULL DEFAULT 'draft',
              `source_url` varchar(500) NOT NULL DEFAULT '',
              `author_id`  int UNSIGNED DEFAULT NULL,
              `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_slug` (`slug`),
              KEY `idx_domain` (`domain_id`),
              KEY `idx_status` (`status`),
              CONSTRAINT `fk_knowledge_entry_domain`
                FOREIGN KEY (`domain_id`)
                REFERENCES `#__knowledge_domains` (`id`)
                ON DELETE SET NULL
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__knowledge_entry_translations` (
              `id`       int UNSIGNED NOT NULL AUTO_INCREMENT,
              `entry_id` int UNSIGNED NOT NULL,
              `locale`   varchar(16)  NOT NULL,
              `title`    varchar(255) NOT NULL DEFAULT '',
              `aliases`  varchar(500) NOT NULL DEFAULT '',
              `summary`  varchar(500) NOT NULL DEFAULT '',
              `body`     mediumtext   NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_entry_locale` (`entry_id`, `locale`),
              KEY `idx_locale_title` (`locale`, `title`),
              CONSTRAINT `fk_knowledge_entry_tr`
                FOREIGN KEY (`entry_id`)
                REFERENCES `#__knowledge_entries` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__knowledge_polls` (
              `id`        int UNSIGNED NOT NULL AUTO_INCREMENT,
              `entry_id`  int UNSIGNED NOT NULL,
              `active`    tinyint(1)   NOT NULL DEFAULT 1,
              `closes_at` datetime     DEFAULT NULL,
              `created_at` datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_entry` (`entry_id`),
              CONSTRAINT `fk_knowledge_poll_entry`
                FOREIGN KEY (`entry_id`)
                REFERENCES `#__knowledge_entries` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__knowledge_poll_translations` (
              `id`       int UNSIGNED NOT NULL AUTO_INCREMENT,
              `poll_id`  int UNSIGNED NOT NULL,
              `locale`   varchar(16)  NOT NULL,
              `question` varchar(500) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_poll_locale` (`poll_id`, `locale`),
              CONSTRAINT `fk_knowledge_poll_tr`
                FOREIGN KEY (`poll_id`)
                REFERENCES `#__knowledge_polls` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__knowledge_poll_options` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `poll_id`    int UNSIGNED NOT NULL,
              `sort_order` int          NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_poll` (`poll_id`),
              CONSTRAINT `fk_knowledge_poll_opt`
                FOREIGN KEY (`poll_id`)
                REFERENCES `#__knowledge_polls` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__knowledge_poll_option_translations` (
              `id`        int UNSIGNED NOT NULL AUTO_INCREMENT,
              `option_id` int UNSIGNED NOT NULL,
              `locale`    varchar(16)  NOT NULL,
              `label`     varchar(255) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_option_locale` (`option_id`, `locale`),
              CONSTRAINT `fk_knowledge_poll_opt_tr`
                FOREIGN KEY (`option_id`)
                REFERENCES `#__knowledge_poll_options` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__knowledge_poll_votes` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `poll_id`    int UNSIGNED NOT NULL,
              `option_id`  int UNSIGNED NOT NULL,
              `user_id`    int UNSIGNED DEFAULT NULL,
              `voter_key`  varchar(80)  NOT NULL,
              `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_poll_voter` (`poll_id`, `voter_key`),
              KEY `idx_option` (`option_id`),
              CONSTRAINT `fk_knowledge_vote_poll`
                FOREIGN KEY (`poll_id`)
                REFERENCES `#__knowledge_polls` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE,
              CONSTRAINT `fk_knowledge_vote_option`
                FOREIGN KEY (`option_id`)
                REFERENCES `#__knowledge_poll_options` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__knowledge_poll_votes`');
        $db->query('DROP TABLE IF EXISTS `#__knowledge_poll_option_translations`');
        $db->query('DROP TABLE IF EXISTS `#__knowledge_poll_options`');
        $db->query('DROP TABLE IF EXISTS `#__knowledge_poll_translations`');
        $db->query('DROP TABLE IF EXISTS `#__knowledge_polls`');
        $db->query('DROP TABLE IF EXISTS `#__knowledge_entry_translations`');
        $db->query('DROP TABLE IF EXISTS `#__knowledge_entries`');
        $db->query('DROP TABLE IF EXISTS `#__knowledge_domains`');
    },
];
