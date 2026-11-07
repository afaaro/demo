<?php
declare(strict_types=1);

/**
 * Quran module bootstrap — public menu entry.
 */

use System\Engine\Registry;

try {
    $registry = Registry::getInstance();
    if (!get_enabled_module('quran')) {
        return;
    }

    $registry->get('menu')->add([
        'label'    => 'Quran',
        'route'    => 'quran',
        'args'     => ['sura' => 1, 'aya' => 0],
        'icon'     => 'bi bi-book',
        'position' => 30,
    ]);
} catch (\Throwable $e) {
    error_log('Quran bootstrap failed: ' . $e->getMessage());
}
