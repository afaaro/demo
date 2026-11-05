<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'user' => [
        'section' => 'system',
        'label'   => 'Users',
        'icon'    => 'people',
        'items'   => [
            ['label' => 'Groups',  'url' => $url->to('user/admin/groups'),        'icon' => 'diagram-3',         'active' => 'user/admin/groups'],
            ['label' => 'Users',   'url' => $url->to('user/admin/users'),         'icon' => 'person',            'active' => 'user/admin/users', 'exact' => true],
            ['label' => 'Pending', 'url' => $url->to('user/admin/users/pending'), 'icon' => 'hourglass-split',   'active' => 'user/admin/users/pending'],
            ['label' => 'Page stats', 'url' => $url->to('user/admin/stats'),     'icon' => 'bar-chart',         'active' => 'user/admin/stats'],
        ],
    ],
];