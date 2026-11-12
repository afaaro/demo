<?php
declare(strict_types=1);

return [
    // Family — Admin
    'family/admin'                   => ['controller' => 'family/admin/home',      'action' => 'index',   'auth' => true, 'methods' => ['GET']],
    'family/admin/home'              => ['controller' => 'family/admin/home',      'action' => 'index',   'auth' => true, 'methods' => ['GET']],

    'family/admin/people'            => ['controller' => 'family/admin/people',    'action' => 'index',   'auth' => true, 'methods' => ['GET']],
    'family/admin/people/add'        => ['controller' => 'family/admin/people',    'action' => 'add',     'auth' => true, 'methods' => ['GET', 'POST']],
    'family/admin/people/edit'       => ['controller' => 'family/admin/people',    'action' => 'edit',    'auth' => true, 'methods' => ['GET', 'POST'], 'params' => ['id']],
    'family/admin/people/delete'     => ['controller' => 'family/admin/people',    'action' => 'delete',  'auth' => true, 'methods' => ['POST']],
    'family/admin/people/quick'      => ['controller' => 'family/admin/people',    'action' => 'quick',   'auth' => true, 'methods' => ['POST']],

    'family/admin/marriages'         => ['controller' => 'family/admin/marriages', 'action' => 'index',   'auth' => true, 'methods' => ['GET']],
    'family/admin/marriages/add'     => ['controller' => 'family/admin/marriages', 'action' => 'add',     'auth' => true, 'methods' => ['GET', 'POST']],
    'family/admin/marriages/divorce' => ['controller' => 'family/admin/marriages', 'action' => 'divorce', 'auth' => true, 'methods' => ['POST']],
    'family/admin/marriages/widow'   => ['controller' => 'family/admin/marriages', 'action' => 'widow',   'auth' => true, 'methods' => ['POST']],
    'family/admin/marriages/delete'  => ['controller' => 'family/admin/marriages', 'action' => 'delete',  'auth' => true, 'methods' => ['POST']],

    'family/admin/tools/relate' => ['controller' => 'family/admin/tools', 'action' => 'relate', 'auth' => true, 'methods' => ['GET']],
    'family/admin/tools/merge'  => ['controller' => 'family/admin/tools', 'action' => 'merge',  'auth' => true, 'methods' => ['GET', 'POST']],

    // Family — Public
    'family' => [
        'controller' => 'family/home',
        'action'     => 'index',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
    'family/person' => [
        'controller' => 'family/home',
        'action'     => 'person',
        'auth'       => false,
        'methods'    => ['GET'],
        'params'     => ['slug'],
    ],
    'family/tree' => [
        'controller' => 'family/home',
        'action'     => 'tree',
        'auth'       => false,
        'methods'    => ['GET'],
        'params'     => ['slug'],
    ],
    'family/relate' => [
        'controller' => 'family/home',
        'action'     => 'relate',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
];
