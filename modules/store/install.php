<?php
declare(strict_types=1);

/**
 * Post-install:
 * - Remap / prune legacy migration stamps after the migration cleanup
 * - Drop leftover sf_* base tables when store_* equivalents exist
 * - Keep lightweight sf_* views so partially-deployed hosts keep working
 *
 * @var \System\Library\Database $db
 */

use System\Engine\Registry;
use System\Library\Database;

$db = $db ?? Registry::getInstance()->get('db');
if (!$db instanceof Database) {
    return;
}

$prefix = $db->getPrefix();
$escape = static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';

// --- Migration stamp cleanup / remap -----------------------------------------
$stampMap = [
    'store:2026_10_19_000001_create_sf_tenants'              => 'store:2026_11_07_000001_create_store_tenants',
    'store:2026_10_19_000002_create_sf_catalog'              => 'store:2026_11_07_000002_create_store_catalog',
    'store:2026_10_19_000003_create_sf_channels'             => 'store:2026_11_07_000003_create_store_channels',
    'store:2026_10_19_000004_create_sf_orders_ops'           => 'store:2026_11_07_000004_create_store_orders_ops',
    'store:2026_10_19_000005_create_sf_customers_comms'      => 'store:2026_11_07_000005_create_store_customers_comms',
    'store:2026_10_19_000006_create_sf_purchasing_shipping'  => 'store:2026_11_07_000006_create_store_purchasing_shipping',
    'store:2026_10_19_000007_create_sf_misc'                 => 'store:2026_11_07_000007_create_store_misc',
];

$keepStamps = array_values($stampMap);
$obsoletePrefixes = [
    'store:2026_10_18_',
    'store:2026_10_19_000008_',
    'store:2026_10_19_000009_',
    'store:2026_10_19_000010_',
    'store:2026_10_19_000011_',
    'store:2026_10_20_',
    'store:20261107_',
    'store:2026_10_19_000001_create_sf_',
    'store:2026_10_19_000002_create_sf_',
    'store:2026_10_19_000003_create_sf_',
    'store:2026_10_19_000004_create_sf_',
    'store:2026_10_19_000005_create_sf_',
    'store:2026_10_19_000006_create_sf_',
    'store:2026_10_19_000007_create_sf_',
];

try {
    $migTable = $prefix . 'migrations';
    $batch = (int) $db->query("SELECT COALESCE(MAX(`batch`), 0) FROM `{$migTable}`")->value;

    foreach ($stampMap as $old => $new) {
        $hasOld = (int) $db->query(
            "SELECT COUNT(*) FROM `{$migTable}` WHERE `migration` = ?",
            [$old]
        )->value > 0;
        $hasNew = (int) $db->query(
            "SELECT COUNT(*) FROM `{$migTable}` WHERE `migration` = ?",
            [$new]
        )->value > 0;

        if ($hasOld && !$hasNew) {
            $db->query(
                "UPDATE `{$migTable}` SET `migration` = ? WHERE `migration` = ?",
                [$new, $old]
            );
        } elseif ($hasOld && $hasNew) {
            $db->query("DELETE FROM `{$migTable}` WHERE `migration` = ?", [$old]);
        }
    }

    // Drop obsolete transition / alter stamps.
    $rows = $db->query(
        "SELECT `migration` FROM `{$migTable}` WHERE `migration` LIKE ?",
        ['store:%']
    )->rows;
    foreach ($rows as $row) {
        $key = (string) $row['migration'];
        if (in_array($key, $keepStamps, true)) {
            continue;
        }
        $drop = false;
        foreach ($obsoletePrefixes as $p) {
            if (str_starts_with($key, $p)) {
                $drop = true;
                break;
            }
        }
        // Also drop any other store: stamp that is not one of the 7 canonical creates.
        if (!$drop && !in_array($key, $keepStamps, true)) {
            $drop = true;
        }
        if ($drop) {
            $db->query("DELETE FROM `{$migTable}` WHERE `migration` = ?", [$key]);
        }
    }

    // Ensure the 7 canonical stamps exist when store tables are already present.
    $hasTenants = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'store_tenants']
    )->value > 0;
    if ($hasTenants) {
        foreach ($keepStamps as $key) {
            $exists = (int) $db->query(
                "SELECT COUNT(*) FROM `{$migTable}` WHERE `migration` = ?",
                [$key]
            )->value > 0;
            if (!$exists) {
                $db->query(
                    "INSERT INTO `{$migTable}` (`migration`, `batch`) VALUES (?, ?)",
                    [$key, max(1, $batch)]
                );
            }
        }
    }
} catch (\Throwable) {
    // migrations table may not exist yet during first install
}

// --- Drop leftover sf_* base tables ------------------------------------------
$existing = [];
$types = [];
foreach ($db->query(
    "SELECT TABLE_NAME AS t, TABLE_TYPE AS typ
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND (TABLE_NAME LIKE ? ESCAPE '\\\\' OR TABLE_NAME LIKE ? ESCAPE '\\\\')",
    [$prefix . 'sf\_%', $prefix . 'store\_%']
)->rows as $row) {
    $name = (string) $row['t'];
    $existing[$name] = true;
    $types[$name] = strtoupper((string) ($row['typ'] ?? ''));
}

$orphans = [];
foreach (array_keys($existing) as $name) {
    if (!str_starts_with($name, $prefix . 'sf_')) {
        continue;
    }
    if (($types[$name] ?? '') !== 'BASE TABLE') {
        continue;
    }
    $suffix = substr($name, strlen($prefix . 'sf_'));
    if (isset($existing[$prefix . 'store_' . $suffix])) {
        $orphans[] = $name;
    }
}

if ($orphans !== []) {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($orphans as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $escape($table));
        }
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}

// Refresh existence map after drops.
$existing = [];
foreach ($db->query(
    "SELECT TABLE_NAME AS t
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND (TABLE_NAME LIKE ? ESCAPE '\\\\' OR TABLE_NAME LIKE ? ESCAPE '\\\\')",
    [$prefix . 'sf\_%', $prefix . 'store\_%']
)->rows as $row) {
    $existing[(string) $row['t']] = true;
}

// --- Compat views for hosts still querying sf_* ------------------------------
$marker = $prefix . 'store_';
foreach (array_keys($existing) as $store) {
    if (!str_starts_with($store, $marker)) {
        continue;
    }
    $sf = $prefix . 'sf_' . substr($store, strlen($marker));
    if (isset($existing[$sf])) {
        continue;
    }
    try {
        $db->query('CREATE OR REPLACE VIEW ' . $escape($sf) . ' AS SELECT * FROM ' . $escape($store));
    } catch (\Throwable $e) {
        error_log('store install compat view skipped ' . $sf . ': ' . $e->getMessage());
    }
}
