<?php
declare(strict_types=1);

/**
 * news module bootstrap.
 *
 * Runs on every request. Only lightweight, idempotent operations
 * belong here — menu registration, event hooks, route aliases.
 *
 * Business logic helpers (markdown rendering, validation) live in
 * helpers/helper.php and are loaded only when the module is dispatched.
 */

use System\Engine\Registry;

try {
    $registry = Registry::getInstance();

    $registry->get('menu')->add([
        'label'    => 'Forum',
        'route'    => 'forum',
        'icon'     => 'bi bi-chat-dots',
        'position' => 90,
    ]);
} catch (\Throwable $e) {
    error_log('Forum bootstrap failed: ' . $e->getMessage());
}