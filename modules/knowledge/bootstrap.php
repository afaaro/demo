<?php
declare(strict_types=1);

/**
 * knowledge module bootstrap.
 *
 * Runs on every request. Only lightweight, idempotent operations
 * belong here — menu registration, event hooks, route aliases.
 */

use System\Engine\Registry;

try {
    if (!function_exists('get_enabled_module') || !get_enabled_module('knowledge')) {
        return;
    }

    $registry = Registry::getInstance();

    $registry->get('menu')->add([
        'label'    => 'Knowledge',
        'route'    => 'knowledge',
        'icon'     => 'bi bi-journal-bookmark',
        'position' => 25,
    ]);
} catch (\Throwable $e) {
    error_log('Knowledge bootstrap failed: ' . $e->getMessage());
}
