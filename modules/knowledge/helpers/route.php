<?php
declare(strict_types=1);

return [
    // Knowledge — Admin
    'knowledge/admin'                => ['controller' => 'knowledge/admin/home',    'action' => 'index',  'auth' => true, 'methods' => ['GET']],
    'knowledge/admin/home'           => ['controller' => 'knowledge/admin/home',    'action' => 'index',  'auth' => true, 'methods' => ['GET']],

    'knowledge/admin/entries'        => ['controller' => 'knowledge/admin/entries', 'action' => 'index',  'auth' => true, 'methods' => ['GET']],
    'knowledge/admin/entries/add'    => ['controller' => 'knowledge/admin/entries', 'action' => 'add',    'auth' => true, 'methods' => ['GET', 'POST']],
    'knowledge/admin/entries/edit'   => ['controller' => 'knowledge/admin/entries', 'action' => 'edit',   'auth' => true, 'methods' => ['GET', 'POST'], 'params' => ['id']],
    'knowledge/admin/entries/delete' => ['controller' => 'knowledge/admin/entries', 'action' => 'delete', 'auth' => true, 'methods' => ['POST']],

    'knowledge/admin/domains'        => ['controller' => 'knowledge/admin/domains', 'action' => 'index',  'auth' => true, 'methods' => ['GET']],
    'knowledge/admin/domains/add'    => ['controller' => 'knowledge/admin/domains', 'action' => 'add',    'auth' => true, 'methods' => ['GET', 'POST']],
    'knowledge/admin/domains/edit'   => ['controller' => 'knowledge/admin/domains', 'action' => 'edit',   'auth' => true, 'methods' => ['GET', 'POST'], 'params' => ['id']],
    'knowledge/admin/domains/delete' => ['controller' => 'knowledge/admin/domains', 'action' => 'delete', 'auth' => true, 'methods' => ['POST']],

    // Knowledge — Public
    'knowledge' => [
        'controller' => 'knowledge/home',
        'action'     => 'index',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
    'knowledge/view' => [
        'controller' => 'knowledge/home',
        'action'     => 'view',
        'auth'       => false,
        'methods'    => ['GET'],
        'params'     => ['slug'],
    ],
    'knowledge/vote' => [
        'controller' => 'knowledge/home',
        'action'     => 'vote',
        'auth'       => false,
        'methods'    => ['POST'],
    ],
];
