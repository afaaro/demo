<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'community' => [
        'section' => 'modules',
        'label'   => 'Community',
        'icon'    => 'people',
        'items'   => [
            [
                'label'  => 'Moderate posts',
                'url'    => $url->to('community/admin'),
                'icon'   => 'shield-check',
                'active' => 'community/admin',
            ],
            [
                'label'  => 'Feed',
                'url'    => $url->to('community'),
                'icon'   => 'house',
                'active' => 'community',
                'exact'  => true,
            ],
        ],
    ],
];
