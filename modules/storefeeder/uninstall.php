<?php
declare(strict_types=1);

/**
 * Force-remove all StoreFeeder tables and migration stamps.
 * Safe with foreign keys: checks are disabled for the drop pass.
 *
 * @var \System\Library\Database $db  injected by tools module runner when available
 */

use System\Engine\Registry;
use System\Library\Database;

$db = $db ?? Registry::getInstance()->get('db');
if (!$db instanceof Database) {
    throw new RuntimeException('StoreFeeder uninstall requires a Database instance.');
}

$prefix = $db->getPrefix();
$like   = $prefix . 'sf_%';

$db->query('SET FOREIGN_KEY_CHECKS = 0');

try {
    $tables = $db->query(
        "SELECT TABLE_NAME AS t
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME LIKE ?
          ORDER BY TABLE_NAME",
        [$like]
    )->rows;

    foreach ($tables as $row) {
        $table = (string) ($row['t'] ?? '');
        if ($table === '' || !str_starts_with($table, $prefix . 'sf_')) {
            continue;
        }
        $db->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
    }

    // Clear applied migration stamps (including orphaned keys from deleted files).
    try {
        $migTable = $prefix . 'migrations';
        $db->query(
            "DELETE FROM `{$migTable}` WHERE `migration` LIKE ?",
            ['storefeeder:%']
        );
    } catch (\Throwable) {
        // migrations table may not exist yet
    }
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}
