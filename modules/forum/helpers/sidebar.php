<?php
return [
    'forum' => [
        'section' => 'modules',
        'label'   => 'Forum',
        'icon'    => 'chat-dots',
        'items'   => [
            [
                'label'  => 'Dashboard',
                'url'    => $url->to('forum/admin/home'),
                'icon'   => 'speedometer2',
                'active' => 'forum/admin/home',
            ],
            [
                'label'  => 'Forums',
                'url'    => $url->to('forum/admin/categories'),
                'icon'   => 'diagram-3',
                'active' => 'forum/admin/categories',
            ],
            [
                'label'  => 'Threads',
                'url'    => $url->to('forum/admin/threads'),
                'icon'   => 'chat-square-text',
                'active' => 'forum/admin/threads',
            ],
            [
                'label'  => 'Messages',
                'url'    => $url->to('forum/admin/posts'),
                'icon'   => 'chat-left-text',
                'active' => 'forum/admin/posts',
            ],
            [
                'label'  => 'View forum',
                'url'    => $url->to('forum'),
                'icon'   => 'box-arrow-up-right',
                'active' => 'forum',
                'exact'  => true,
            ],
        ],
    ],
];
