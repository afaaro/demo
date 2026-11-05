<?php

return [
    'tools/admin/module' => [
        'controller' => 'tools/admin/module',
        'action'     => 'index',
        'auth'       => true,
    ],
    'tools/admin/module/install' => [
        'controller' => 'tools/admin/module',
        'action'     => 'install',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/module/uninstall' => [
        'controller' => 'tools/admin/module',
        'action'     => 'uninstall',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/module/enable' => [
        'controller' => 'tools/admin/module',
        'action'     => 'enable',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/module/disable' => [
        'controller' => 'tools/admin/module',
        'action'     => 'disable',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/module/config' => [
        'controller' => 'tools/admin/module',
        'action'     => 'config',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'tools/admin/module/migrate' => [
        'controller' => 'tools/admin/module',
        'action'     => 'migrate',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    'tools/admin/media' => [
        'controller' => 'tools/admin/media',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'tools/admin/media/upload' => [
        'controller' => 'tools/admin/media',
        'action'     => 'upload',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/media/picker' => [
        'controller' => 'tools/admin/media',
        'action'     => 'picker',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'tools/admin/media/delete' => [
        'controller' => 'tools/admin/media',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/media/bulk-delete' => [
        'controller' => 'tools/admin/media',
        'action'     => 'bulkDelete',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/media/cleanup' => [
        'controller' => 'tools/admin/media',
        'action'     => 'cleanup',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // Database
    'tools/admin/database' => [
        'controller' => 'tools/admin/database',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'tools/admin/database/table' => [
        'controller' => 'tools/admin/database',
        'action'     => 'table',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'tools/admin/database/query' => [
        'controller' => 'tools/admin/database',
        'action'     => 'query',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'tools/admin/database/migrate' => [
        'controller' => 'tools/admin/database',
        'action'     => 'migrate',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/database/rollback' => [
        'controller' => 'tools/admin/database',
        'action'     => 'rollback',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/database/stamp' => [
        'controller' => 'tools/admin/database',
        'action'     => 'stamp',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/database/maintain' => [
        'controller' => 'tools/admin/database',
        'action'     => 'maintain',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/database/export' => [
        'controller' => 'tools/admin/database',
        'action'     => 'export',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // Logs
    'tools/admin/logs' => [
        'controller' => 'tools/admin/logs',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'tools/admin/logs/view' => [
        'controller' => 'tools/admin/logs',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'tools/admin/logs/download' => [
        'controller' => 'tools/admin/logs',
        'action'     => 'download',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'tools/admin/logs/clear' => [
        'controller' => 'tools/admin/logs',
        'action'     => 'clear',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/logs/delete' => [
        'controller' => 'tools/admin/logs',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'tools/admin/logs/prune' => [
        'controller' => 'tools/admin/logs',
        'action'     => 'prune',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
];