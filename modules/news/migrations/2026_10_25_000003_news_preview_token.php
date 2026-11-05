<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $cols = $db->query("SHOW COLUMNS FROM `#__news` LIKE 'preview_token'")->rows;
        if ($cols === []) {
            $db->query("
                ALTER TABLE `#__news`
                  ADD COLUMN `preview_token` varchar(64) NOT NULL DEFAULT '' AFTER `source_url`
            ");
        }
    },

    'down' => function (Database $db) {
        $cols = $db->query("SHOW COLUMNS FROM `#__news` LIKE 'preview_token'")->rows;
        if ($cols !== []) {
            $db->query("ALTER TABLE `#__news` DROP COLUMN `preview_token`");
        }
    },
];
