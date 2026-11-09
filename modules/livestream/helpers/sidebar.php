<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'livestream' => [
        'section' => 'modules',
        'label'   => 'Livestream',
        'icon'    => 'broadcast',
        'items'   => [
            [
                'label'  => 'Directory',
                'url'    => $url->to('livestream'),
                'icon'   => 'collection-play',
                'active' => 'livestream',
                'exact'  => true,
            ],
            [
                'label'  => 'Studio',
                'url'    => $url->to('livestream/studio'),
                'icon'   => 'camera-video',
                'active' => 'livestream/studio',
            ],
        ],
    ],
];
