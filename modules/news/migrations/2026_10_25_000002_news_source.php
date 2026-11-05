<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $source = $db->query("SHOW COLUMNS FROM `#__news` LIKE 'source'")->rows;
        if ($source === []) {
            $db->query("
                ALTER TABLE `#__news`
                  ADD COLUMN `source` varchar(160) NOT NULL DEFAULT '' AFTER `image`,
                  ADD COLUMN `source_url` varchar(500) NOT NULL DEFAULT '' AFTER `source`
            ");
        }
    },

    'down' => function (Database $db) {
        $source = $db->query("SHOW COLUMNS FROM `#__news` LIKE 'source'")->rows;
        if ($source !== []) {
            $db->query("ALTER TABLE `#__news` DROP COLUMN `source_url`, DROP COLUMN `source`");
        }
    },
];
