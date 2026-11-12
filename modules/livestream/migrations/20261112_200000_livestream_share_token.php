<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db): void {
        try {
            $db->query("ALTER TABLE `#__livestream_streams`
                ADD COLUMN `share_token` varchar(64) DEFAULT NULL AFTER `stream_key`,
                ADD UNIQUE KEY `uq_livestream_share_token` (`share_token`)");
        } catch (\Throwable) {
        }
    },

    'down' => function (Database $db): void {
        try {
            $db->query('ALTER TABLE `#__livestream_streams` DROP INDEX `uq_livestream_share_token`');
        } catch (\Throwable) {
        }
        try {
            $db->query('ALTER TABLE `#__livestream_streams` DROP COLUMN `share_token`');
        } catch (\Throwable) {
        }
    },
];
