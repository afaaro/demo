<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $cols = $db->query("SHOW COLUMNS FROM `#__news` LIKE 'featured'")->rows;
        if ($cols === []) {
            $db->query("
                ALTER TABLE `#__news`
                  ADD COLUMN `featured` tinyint(1) NOT NULL DEFAULT 0 AFTER `status`,
                  ADD KEY `idx_featured` (`featured`)
            ");
        }
    },

    'down' => function (Database $db) {
        $cols = $db->query("SHOW COLUMNS FROM `#__news` LIKE 'featured'")->rows;
        if ($cols !== []) {
            $db->query("ALTER TABLE `#__news` DROP KEY `idx_featured`, DROP COLUMN `featured`");
        }
    },
];
