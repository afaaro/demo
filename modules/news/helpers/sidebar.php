<?php
return [
    'news' => [
        'section' => 'modules',
        'label'   => 'News',
        'icon'    => 'newspaper',
        'items'   => [
            ['label' => 'Articles',  'url' => $url->to('news/admin/articles'),       'icon' => 'speedometer2', 'active' => 'news/admin/articles'],
            ['label' => 'Categories',   'url' => $url->to('news/admin/categories'),   'icon' => 'tags',        'active' => 'news/admin/categories'],
        ],
    ],
];