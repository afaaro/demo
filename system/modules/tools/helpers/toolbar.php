<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'tools' => [
        [
            'label'      => 'Tools',
            'icon'       => 'wrench',
            'permission' => 'tools.view',
            'children'   => [
                ['label' => 'System Info',  'url' => $url->to('tools/admin/system-info'),  'icon' => 'info-circle', 'route' => 'tools/admin/system-info'],
                ['label' => 'Modules',      'url' => $url->to('tools/admin/module'),      'icon' => 'folder-open', 'route' => 'tools/admin/module'],
                ['label' => 'Logs',         'url' => $url->to('tools/admin/logs'),         'icon' => 'file-text', 'route' => 'tools/admin/logs'],
                ['label' => 'Database',     'url' => $url->to('tools/admin/database'),     'icon' => 'database', 'route' => 'tools/admin/database'],
                ['label' => 'Cache',        'url' => $url->to('tools/admin/cache'),        'icon' => 'hdd-network', 'route' => 'tools/admin/cache'],
                ['label' => 'Maintenance',  'url' => $url->to('tools/admin/maintenance'),  'icon' => 'tools', 'route' => 'tools/admin/maintenance'],
                ['label' => 'Media library',        'url' => $url->to('tools/admin/media'),        'icon' => 'images', 'route' => 'tools/admin/media'],
            ],
        ],
    ],
];
