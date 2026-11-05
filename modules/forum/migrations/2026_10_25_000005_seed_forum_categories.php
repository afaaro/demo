<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query(
            "INSERT INTO `#__forum_categories`
                (`slug`, `name`, `description`, `position`, `access_role`, `post_role`)
             VALUES
                (?, ?, ?, ?, ?, ?),
                (?, ?, ?, ?, ?, ?),
                (?, ?, ?, ?, ?, ?)",
            [
                'general',       'General Discussion', 'Talk about anything.', 10, 0, -101,
                'announcements', 'Announcements',      'Site news.',           20, 0, -102,
                'support',       'Support',            'Get help.',            30, 0, -101,
            ]
        );
    },

    'down' => function (Database $db) {
        $db->query(
            "DELETE FROM `#__forum_categories` WHERE `slug` IN (?, ?, ?)",
            ['general', 'announcements', 'support']
        );
    },
];