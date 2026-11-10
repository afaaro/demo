<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Optional JSON attribute map on listing templates (Amazon product-type attrs, etc.).
 */
return [
    'up' => function (Database $db): void {
        try {
            $db->query("ALTER TABLE `#__store_listing_templates`
                ADD COLUMN `attributes_json` mediumtext NULL AFTER `description_template`");
        } catch (\Throwable) {
            // already exists
        }
    },

    'down' => function (Database $db): void {
        try {
            $db->query('ALTER TABLE `#__store_listing_templates` DROP COLUMN `attributes_json`');
        } catch (\Throwable) {
        }
    },
];
