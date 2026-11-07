<?php
declare(strict_types=1);

/**
 * Force-remove all Store tables and migration stamps.
 * Drops both store_* and legacy sf_* leftovers from failed renames/reinstalls.
 *
 * @var \System\Library\Database $db  injected by tools module runner when available
 */

use System\Engine\Registry;
use System\Library\Database;

$db = $db ?? Registry::getInstance()->get('db');
if (!$db instanceof Database) {
    throw new RuntimeException('Store uninstall requires a Database instance.');
}

$prefix = $db->getPrefix();
$escape = static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';

$db->query('SET FOREIGN_KEY_CHECKS = 0');

try {
    $tables = $db->query(
        "SELECT TABLE_NAME AS t
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND (
                 TABLE_NAME LIKE ? ESCAPE '\\\\'
              OR TABLE_NAME LIKE ? ESCAPE '\\\\'
            )
          ORDER BY TABLE_NAME",
        [$prefix . 'store\_%', $prefix . 'sf\_%']
    )->rows;

    foreach ($tables as $row) {
        $table = (string) ($row['t'] ?? '');
        if ($table === '') {
            continue;
        }
        $isStore = str_starts_with($table, $prefix . 'store_');
        $isLegacy = str_starts_with($table, $prefix . 'sf_');
        if (!$isStore && !$isLegacy) {
            continue;
        }
        try {
            $db->query('DROP VIEW IF EXISTS ' . $escape($table));
        } catch (\Throwable) {
            // not a view
        }
        $db->query('DROP TABLE IF EXISTS ' . $escape($table));
    }

    try {
        $migTable = $prefix . 'migrations';
        $db->query(
            "DELETE FROM `{$migTable}` WHERE `migration` LIKE ?",
            ['store:%']
        );
    } catch (\Throwable) {
        // migrations table may not exist yet
    }
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}
