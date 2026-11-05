<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'forum' => [
        [
            'label'      => 'Forum',
            'icon'       => 'chat-dots',
            'permission' => 'forum.view',
            'children'   => [
                ['label' => 'Dashboard', 'url' => $url->to('forum/admin/home'), 'icon' => 'speedometer2', 'route' => 'forum/admin/home'],
                ['label' => 'Forums', 'url' => $url->to('forum/admin/categories'), 'icon' => 'diagram-3', 'route' => 'forum/admin/categories'],
                ['label' => 'Threads', 'url' => $url->to('forum/admin/threads'), 'icon' => 'chat-square-text', 'route' => 'forum/admin/threads'],
                ['label' => 'Messages', 'url' => $url->to('forum/admin/posts'), 'icon' => 'chat-left-text', 'route' => 'forum/admin/posts'],
            ],
        ],
    ],
];
