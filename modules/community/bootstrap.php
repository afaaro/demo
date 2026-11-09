<?php
declare(strict_types=1);

/**
 * community module bootstrap.
 *
 * Runs on every request. Only lightweight, idempotent operations
 * belong here — menu registration, event hooks, route aliases.
 */

use System\Engine\Registry;

try {
    $registry = Registry::getInstance();

    $registry->get('menu')->add([
        'label'      => 'Community',
        'route'      => 'community',
        'icon'       => 'bi bi-people',
        'position'   => 15,
        'permission' => 'community.home.index',
    ]);
} catch (\Throwable $e) {
    error_log('Community bootstrap failed: ' . $e->getMessage());
}
