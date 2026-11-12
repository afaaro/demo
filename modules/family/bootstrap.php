<?php
declare(strict_types=1);

/**
 * family module bootstrap.
 */

use System\Engine\Registry;

try {
    if (!function_exists('get_enabled_module') || !get_enabled_module('family')) {
        return;
    }

    $registry = Registry::getInstance();

    $registry->get('menu')->add([
        'label'    => 'Family',
        'route'    => 'family',
        'icon'     => 'bi bi-diagram-3',
        'position' => 28,
    ]);
} catch (\Throwable $e) {
    error_log('Family bootstrap failed: ' . $e->getMessage());
}
