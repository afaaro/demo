<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'comments' => [
        'section' => 'system',
        'label'   => 'Comments',
        'icon'    => 'chat-dots',
        'items'   => [
            [
                'label'  => 'Moderate',
                'url'    => $url->to('comments/admin'),
                'icon'   => 'shield-check',
                'active' => 'comments/admin',
            ],
            [
                'label'  => 'Public hub',
                'url'    => $url->to('comments'),
                'icon'   => 'chat-left-text',
                'active' => 'comments',
                'exact'  => true,
            ],
        ],
    ],
];
