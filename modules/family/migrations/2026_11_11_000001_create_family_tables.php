<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__family_people` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `slug`        varchar(128) NOT NULL,
              `name`        varchar(160) NOT NULL,
              `gender`      enum('male','female') NOT NULL,
              `birth_date`  date DEFAULT NULL,
              `death_date`  date DEFAULT NULL,
              `father_id`   int UNSIGNED DEFAULT NULL,
              `mother_id`   int UNSIGNED DEFAULT NULL,
              `marriage_id` int UNSIGNED DEFAULT NULL,
              `notes`       text NULL,
              `active`      tinyint(1) NOT NULL DEFAULT 1,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_slug` (`slug`),
              KEY `idx_name` (`name`),
              KEY `idx_gender` (`gender`),
              KEY `idx_father` (`father_id`),
              KEY `idx_mother` (`mother_id`),
              KEY `idx_marriage` (`marriage_id`),
              KEY `idx_active` (`active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__family_marriages` (
              `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
              `husband_id`  int UNSIGNED NOT NULL,
              `wife_id`     int UNSIGNED NOT NULL,
              `status`      enum('active','divorced','widowed') NOT NULL DEFAULT 'active',
              `married_on`  date DEFAULT NULL,
              `ended_on`    date DEFAULT NULL,
              `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_husband_status` (`husband_id`, `status`),
              KEY `idx_wife_status` (`wife_id`, `status`),
              KEY `idx_couple` (`husband_id`, `wife_id`),
              CONSTRAINT `fk_family_marriage_husband`
                FOREIGN KEY (`husband_id`) REFERENCES `#__family_people` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
              CONSTRAINT `fk_family_marriage_wife`
                FOREIGN KEY (`wife_id`) REFERENCES `#__family_people` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Self-FKs added after both tables exist.
        $db->query("
            ALTER TABLE `#__family_people`
              ADD CONSTRAINT `fk_family_person_father`
                FOREIGN KEY (`father_id`) REFERENCES `#__family_people` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
              ADD CONSTRAINT `fk_family_person_mother`
                FOREIGN KEY (`mother_id`) REFERENCES `#__family_people` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
              ADD CONSTRAINT `fk_family_person_marriage`
                FOREIGN KEY (`marriage_id`) REFERENCES `#__family_marriages` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        ");
    },

    'down' => function (Database $db) {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $db->query('DROP TABLE IF EXISTS `#__family_people`');
        $db->query('DROP TABLE IF EXISTS `#__family_marriages`');
        $db->query('SET FOREIGN_KEY_CHECKS=1');
    },
];
