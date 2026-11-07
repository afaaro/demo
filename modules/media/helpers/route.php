<?php
declare(strict_types=1);

return [
    'media' => [
        'controller' => 'media/home',
        'action'     => 'index',
        'methods'    => ['GET'],
    ],
    'media/watch' => [
        'controller' => 'media/watch',
        'action'     => 'index',
        'params'     => ['id'],
        'methods'    => ['GET'],
    ],
    'media/channel' => [
        'controller' => 'media/channel',
        'action'     => 'index',
        'params'     => ['id'],
        'methods'    => ['GET'],
    ],

    'media/studio' => [
        'controller' => 'media/studio',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'media/studio/create' => [
        'controller' => 'media/studio',
        'action'     => 'create',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'media/studio/edit' => [
        'controller' => 'media/studio',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'media/studio/delete' => [
        'controller' => 'media/studio',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    'media/upload' => [
        'controller' => 'media/upload',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'media/upload/chunk' => [
        'controller' => 'media/upload',
        'action'     => 'chunk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'media/upload/save' => [
        'controller' => 'media/upload',
        'action'     => 'save',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'media/upload/link' => [
        'controller' => 'media/upload',
        'action'     => 'link',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    'media/interact/view' => [
        'controller' => 'media/interact',
        'action'     => 'view',
        'methods'    => ['GET', 'POST'],
    ],
    'media/interact/reaction' => [
        'controller' => 'media/interact',
        'action'     => 'reaction',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'media/admin' => [
        'controller' => 'media/admin/home',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'media/admin/videos' => [
        'controller' => 'media/admin/videos',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'media/admin/channels' => [
        'controller' => 'media/admin/channels',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
];
