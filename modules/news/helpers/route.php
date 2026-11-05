<?php

return [
    // News — Admin
    'news/admin'                       => ['controller' => 'news/admin/home',       'action' => 'index',  'auth' => true, 'methods' => ['GET']],
    'news/admin/home'                  => ['controller' => 'news/admin/home',       'action' => 'index',  'auth' => true, 'methods' => ['GET']],

    'news/admin/articles'              => ['controller' => 'news/admin/articles',   'action' => 'index',  'auth' => true, 'methods' => ['GET']],
    'news/admin/articles/add'          => ['controller' => 'news/admin/articles',   'action' => 'add',    'auth' => true, 'methods' => ['GET','POST']],
    'news/admin/articles/edit'         => ['controller' => 'news/admin/articles',   'action' => 'edit',   'auth' => true, 'methods' => ['GET','POST'], 'params' => ['id']],
    'news/admin/articles/delete'       => ['controller' => 'news/admin/articles',   'action' => 'delete', 'auth' => true, 'methods' => ['POST']],
    'news/admin/articles/bulk'         => ['controller' => 'news/admin/articles',   'action' => 'bulk',   'auth' => true, 'methods' => ['POST']],
    'news/admin/articles/import'       => ['controller' => 'news/admin/articles',   'action' => 'import', 'auth' => true, 'methods' => ['POST']],
    'news/admin/articles/autosave'     => ['controller' => 'news/admin/articles',   'action' => 'autosave','auth' => true, 'methods' => ['POST']],
    'news/admin/articles/revision'     => ['controller' => 'news/admin/articles',   'action' => 'revision','auth' => true, 'methods' => ['GET']],

    'news/admin/categories'            => ['controller' => 'news/admin/categories', 'action' => 'index',  'auth' => true, 'methods' => ['GET']],
    'news/admin/categories/add'        => ['controller' => 'news/admin/categories', 'action' => 'add',    'auth' => true, 'methods' => ['GET','POST']],
    'news/admin/categories/edit'       => ['controller' => 'news/admin/categories', 'action' => 'edit',   'auth' => true, 'methods' => ['GET','POST'], 'params' => ['id']],
    'news/admin/categories/delete'     => ['controller' => 'news/admin/categories', 'action' => 'delete', 'auth' => true, 'methods' => ['POST']],

    // News — Public
    'news' => [
        'controller' => 'news/home',
        'action'     => 'index',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
    'news/view' => [
        'controller' => 'news/home',
        'action'     => 'view',
        'auth'       => false,
        'methods'    => ['GET'],
        'params'    => ['slug']
    ],
    'news/preview' => [
        'controller' => 'news/home',
        'action'     => 'preview',
        'auth'       => false,
        'methods'    => ['GET'],
        'params'     => ['slug'],
    ],
    'news/rss' => [
        'controller' => 'news/home',
        'action'     => 'rss',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
    'news/sitemap' => [
        'controller' => 'news/home',
        'action'     => 'sitemap',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
];