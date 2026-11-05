<?php
declare(strict_types=1);

return [
    'forum' => [
        'controller' => 'forum/home',
        'action'     => 'index',
        'methods'    => ['GET'],
    ],
    'forum/search' => [
        'controller' => 'forum/search',
        'action'     => 'index',
        'methods'    => ['GET'],
    ],
    'forum/whats-new' => [
        'controller' => 'forum/home',
        'action'     => 'whatsNew',
        'methods'    => ['GET'],
    ],
    'forum/category' => [
        'controller' => 'forum/category',
        'action'     => 'view',
        'params'     => ['slug'],
        'methods'    => ['GET'],
    ],
    'forum/thread' => [
        'controller' => 'forum/thread',
        'action'     => 'view',
        'params'     => ['id'],
        'methods'    => ['GET'],
    ],
    'forum/thread/reply' => [
        'controller' => 'forum/thread',
        'action'     => 'reply',
        'params'     => ['id'],
        'methods'    => ['POST'],
        'auth'       => true,
    ],
    'forum/thread/edit' => [
        'controller' => 'forum/thread',
        'action'     => 'edit',
        'methods'    => ['GET', 'POST'],
        'auth'       => true,
    ],
    'forum/thread/delete-post' => [
        'controller' => 'forum/thread',
        'action'     => 'deletePost',
        'methods'    => ['POST'],
        'auth'       => true,
    ],
    'forum/thread/moderate' => [
        'controller' => 'forum/thread',
        'action'     => 'moderate',
        'methods'    => ['POST'],
        'auth'       => true,
    ],
    'forum/new' => [
        'controller' => 'forum/post',
        'action'     => 'create',
        'params'     => ['category_slug'],
        'methods'    => ['GET', 'POST'],
        'auth'       => true,
    ],
    'forum/admin' => [
        'controller' => 'forum/admin/home',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'forum/admin/home' => [
        'controller' => 'forum/admin/home',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'forum/admin/threads' => [
        'controller' => 'forum/admin/threads',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'forum/admin/threads/bulk' => [
        'controller' => 'forum/admin/threads',
        'action'     => 'bulk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'forum/admin/posts' => [
        'controller' => 'forum/admin/posts',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'forum/admin/posts/bulk' => [
        'controller' => 'forum/admin/posts',
        'action'     => 'bulk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'forum/admin/categories' => [
        'controller' => 'forum/admin/categories',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'forum/admin/categories/add' => [
        'controller' => 'forum/admin/categories',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'forum/admin/categories/edit' => [
        'controller' => 'forum/admin/categories',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'forum/admin/categories/delete' => [
        'controller' => 'forum/admin/categories',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
];
