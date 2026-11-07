<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'media' => [
        'section' => 'modules',
        'label'   => 'Media',
        'icon'    => 'play-btn',
        'items'   => [
            [
                'label'  => 'Overview',
                'url'    => $url->to('media/admin'),
                'icon'   => 'speedometer2',
                'active' => 'media/admin',
                'exact'  => true,
            ],
            [
                'label'  => 'Videos',
                'url'    => $url->to('media/admin/videos'),
                'icon'   => 'film',
                'active' => 'media/admin/videos',
            ],
            [
                'label'  => 'Channels',
                'url'    => $url->to('media/admin/channels'),
                'icon'   => 'broadcast',
                'active' => 'media/admin/channels',
            ],
            [
                'label'  => 'Studio',
                'url'    => $url->to('media/studio'),
                'icon'   => 'camera-reels',
                'active' => 'media/studio',
            ],
            [
                'label'  => 'Open feed',
                'url'    => $url->to('media'),
                'icon'   => 'box-arrow-up-right',
                'active' => 'media',
                'exact'  => true,
            ],
        ],
    ],
];
