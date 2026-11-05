<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

// C:\wamp64\www\demo\system\modules\tools\helpers\sidebar.php
return [
    'tools' => [
        'section' => 'system',
        'label'   => 'Tools',
        'icon'    => 'wrench',
        'items'   => [
            ['label' => 'Modules', 'url' => $url->to('tools/admin/module'), 'icon' => 'folder-open', 'active' => 'tools/admin/module'],
            ['label' => 'Database', 'url' => $url->to('tools/admin/database'), 'icon' => 'database', 'active' => 'tools/admin/database'],
            ['label' => 'Logs', 'url' => $url->to('tools/admin/logs'), 'icon' => 'file-text', 'active' => 'tools/admin/logs'],
            ['label' => 'Media library', 'url' => $url->to('tools/admin/media'), 'icon' => 'images', 'active' => 'tools/admin/media'],
        ],
    ],
];