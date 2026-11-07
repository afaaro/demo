<?php
declare(strict_types=1);

return [
    'comments' => [
        'controller' => 'comments/index',
        'action'     => 'index',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
    'comments/list' => [
        'controller' => 'comments/api',
        'action'     => 'list',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
    'comments/post' => [
        'controller' => 'comments/api',
        'action'     => 'post',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'comments/admin' => [
        'controller' => 'comments/admin',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'comments/admin/hide' => [
        'controller' => 'comments/admin',
        'action'     => 'hide',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'comments/admin/restore' => [
        'controller' => 'comments/admin',
        'action'     => 'restore',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'comments/admin/delete' => [
        'controller' => 'comments/admin',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
];

