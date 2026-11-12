<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            ALTER TABLE `#__family_people`
              ADD COLUMN `photo` varchar(255) DEFAULT NULL AFTER `notes`
        ");
    },

    'down' => function (Database $db) {
        $db->query("
            ALTER TABLE `#__family_people`
              DROP COLUMN `photo`
        ");
    },
];
