<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'users' => [
        [
            'label'      => 'Users',
            'icon'       => 'people',
            'permission' => 'user.view',
            'children'   => [
                ['label' => 'Users', 'url' => $url->to('user/admin/users'), 'icon' => 'list', 'route' => 'user/admin/users'],
                ['label' => 'Groups', 'url' => $url->to('user/admin/groups'), 'icon' => 'list', 'route' => 'user/admin/groups'],
                ['label' => 'Page stats', 'url' => $url->to('user/admin/stats'), 'icon' => 'bar-chart', 'route' => 'user/admin/stats'],
            ],
        ],
    ],
];
