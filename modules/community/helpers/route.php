<?php
declare(strict_types=1);

return [
    'community' => [
        'controller' => 'community/home',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],

    'community/post/create' => [
        'controller' => 'community/post',
        'action'     => 'create',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'community/post/delete' => [
        'controller' => 'community/post',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'community/post' => [
        'controller' => 'community/post',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],

    'community/profile' => [
        'controller' => 'community/profile',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],

    'community/friends' => [
        'controller' => 'community/friends',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'community/friends/request' => [
        'controller' => 'community/friends',
        'action'     => 'request',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'community/friends/accept' => [
        'controller' => 'community/friends',
        'action'     => 'accept',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'community/friends/decline' => [
        'controller' => 'community/friends',
        'action'     => 'decline',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'community/friends/unfriend' => [
        'controller' => 'community/friends',
        'action'     => 'unfriend',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'community/friends/cancel' => [
        'controller' => 'community/friends',
        'action'     => 'cancel',
        'auth'       => true,
        'permission' => 'community.friends.request',
        'methods'    => ['POST'],
        'csrf'       => true,
    ],

    'community/react' => [
        'controller' => 'community/react',
        'action'     => 'toggle',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],

    'community/comment' => [
        'controller' => 'community/comment',
        'action'     => 'post',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],

    'community/notifications' => [
        'controller' => 'community/notifications',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'community/notifications/read' => [
        'controller' => 'community/notifications',
        'action'     => 'read',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],

    'community/admin/hide' => [
        'controller' => 'community/admin',
        'action'     => 'hide',
        'auth'       => true,
        'permission' => 'community.admin',
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'community/admin/restore' => [
        'controller' => 'community/admin',
        'action'     => 'restore',
        'auth'       => true,
        'permission' => 'community.admin',
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'community/admin' => [
        'controller' => 'community/admin',
        'action'     => 'index',
        'auth'       => true,
        'permission' => 'community.admin',
        'methods'    => ['GET'],
    ],
];
