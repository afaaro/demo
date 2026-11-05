<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Forum category access: 0 public, -101 member, -102 admin (not group ids 1/2/4).
 */
return [
    'up' => function (Database $db) {
        $db->query("
            UPDATE `#__forum_categories`
               SET `access_role` = 0
             WHERE `access_role` IN (1)
        ");
        $db->query("
            UPDATE `#__forum_categories`
               SET `post_role` = -101
             WHERE `post_role` IN (2)
        ");
        $db->query("
            UPDATE `#__forum_categories`
               SET `post_role` = -102
             WHERE `post_role` IN (4)
        ");
    },

    'down' => function (Database $db) {
        $db->query("
            UPDATE `#__forum_categories`
               SET `access_role` = 1
             WHERE `access_role` = 0
        ");
        $db->query("
            UPDATE `#__forum_categories`
               SET `post_role` = 2
             WHERE `post_role` = -101
        ");
        $db->query("
            UPDATE `#__forum_categories`
               SET `post_role` = 4
             WHERE `post_role` = -102
        ");
    },
];
