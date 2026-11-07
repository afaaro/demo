<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__quran_surah` (
                `id` TINYINT UNSIGNED NOT NULL,
                `number` TINYINT UNSIGNED NOT NULL,
                `name_ar` VARCHAR(64) NOT NULL,
                `name_latin` VARCHAR(64) NOT NULL,
                `name_so` VARCHAR(64) NOT NULL,
                `ayah_count` SMALLINT UNSIGNED NOT NULL,
                `revelation` ENUM('Meccan','Medinan') NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_surah_number` (`number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__quran_ayah` (
                `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `surah_id` TINYINT UNSIGNED NOT NULL,
                `ayah` SMALLINT UNSIGNED NOT NULL,
                `text_uthmani` TEXT NOT NULL,
                `text_simple` TEXT NOT NULL,
                `juz` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `page` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `sajdah` VARCHAR(16) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ayah` (`surah_id`, `ayah`),
                KEY `idx_juz` (`juz`),
                KEY `idx_page` (`page`),
                FULLTEXT KEY `ft_simple` (`text_simple`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__quran_translation` (
                `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(32) NOT NULL,
                `name` VARCHAR(128) NOT NULL,
                `author` VARCHAR(128) NOT NULL DEFAULT '',
                `language` VARCHAR(8) NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` SMALLINT NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_translation_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__quran_ayah_translation` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `translation_id` SMALLINT UNSIGNED NOT NULL,
                `ayah_id` SMALLINT UNSIGNED NOT NULL,
                `text` TEXT NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ayah_trans` (`translation_id`, `ayah_id`),
                KEY `idx_ayah` (`ayah_id`),
                FULLTEXT KEY `ft_text` (`text`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__quran_reciter` (
                `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(128) NOT NULL,
                `slug` VARCHAR(64) NOT NULL,
                `url_pattern` VARCHAR(255) NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_reciter_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query('DROP TABLE IF EXISTS `#__quran_ayah_translation`');
        $db->query('DROP TABLE IF EXISTS `#__quran_ayah`');
        $db->query('DROP TABLE IF EXISTS `#__quran_translation`');
        $db->query('DROP TABLE IF EXISTS `#__quran_reciter`');
        $db->query('DROP TABLE IF EXISTS `#__quran_surah`');
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    },
];
