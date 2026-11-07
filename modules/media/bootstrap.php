<?php
declare(strict_types=1);

/**
 * Media module bootstrap — public menu entry.
 */

use System\Engine\Registry;

try {
    $registry = Registry::getInstance();
    if (!get_enabled_module('media')) {
        return;
    }

    $registry->get('menu')->add([
        'label'    => 'Media',
        'route'    => 'media',
        'icon'     => 'bi bi-play-btn',
        'position' => 35,
    ]);
} catch (\Throwable $e) {
    error_log('Media bootstrap failed: ' . $e->getMessage());
}
