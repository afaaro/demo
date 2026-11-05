<?php

/** @var System\Library\Url $url */

return [
    'news' => [
        [
            'label'      => 'News',
            'icon'       => 'newspaper',
            'permission' => 'news.view',
            'children'   => [
                ['label' => 'Articles',   'url' => $url->to('news/admin/articles'),   'icon' => 'list-ul', 'route' => 'news/admin/articles'],
                ['divider' => true],
                ['label' => 'Categories',     'url' => $url->to('news/admin/categories'), 'icon' => 'tags', 'route' => 'news/admin/categories'],
            ],
        ],
    ],
];
