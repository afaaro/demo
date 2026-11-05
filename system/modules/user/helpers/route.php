<?php

return [
    // ==================================================
    // Public — No login required
    // ==================================================
    'user/login' => [
        'controller' => 'user/home',
        'action'     => 'login',
        'auth'       => false,
        'methods'    => ['GET', 'POST'],
    ],
    'user/register' => [
        'controller' => 'user/register',
        'action'     => 'index',
        'auth'       => false,
        'methods'    => ['GET', 'POST'],
    ],
    'user/password/forgot' => [
        'controller' => 'user/password',
        'action'     => 'forgot',
        'auth'       => false,
        'methods'    => ['GET', 'POST'],
    ],
    'user/password/reset' => [
        'controller' => 'user/password',
        'action'     => 'reset',
        'auth'       => false,
        'methods'    => ['GET', 'POST'],
    ],

    // ==================================================
    // Authenticated — Must be logged in
    // ==================================================
    'user/logout' => [
        'controller' => 'user/home',
        'action'     => 'logout',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
    'user/profile' => [
        'controller' => 'user/profile',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'user/profile/edit' => [
        'controller' => 'user/profile',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'user/settings' => [
        'controller' => 'user/settings',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'user/settings/password' => [
        'controller' => 'user/settings',
        'action'     => 'changePassword',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'user/settings/delete' => [
        'controller' => 'user/settings',
        'action'     => 'deleteAccount',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'user/groups' => [
        'controller' => 'user/groups',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'user/groups/all' => [
        'controller' => 'user/groups',
        'action'     => 'all',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'user/groups/view' => [
        'controller' => 'user/groups',
        'action'     => 'view',
        'auth'       => true,
        'params'     => ['id'],
        'methods'    => ['GET'],
    ],
    // ==================================================
    // Admin — gated by group-editor permission keys
    // (e.g. user.admin.users.index). Do not hard-require
    // the admin group here or only "*" accounts can enter.
    // ==================================================
    'user/admin/dashboard' => [
        'controller' => 'user/admin/dashboard',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'user/admin/stats' => [
        'controller' => 'user/admin/stats',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],

    'user/admin/users' => [
        'controller' => 'user/admin/users',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'user/admin/users/search' => [
        'controller' => 'user/admin/users',
        'action'     => 'search',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'user/admin/users/edit' => [
        'controller' => 'user/admin/users',
        'action'     => 'edit',
        'params'     => ['id'],
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'user/admin/users/status' => [
        'controller' => 'user/admin/users',
        'action'     => 'updateStatus',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'user/admin/users/delete' => [
        'controller' => 'user/admin/users',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'user/admin/users/add'  => [
        'controller' => 'user/admin/users',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'user/admin/users/pending' => [
        'controller' => 'user/admin/users',
        'action'     => 'pending',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'user/admin/users/approve' => [
        'controller' => 'user/admin/users',
        'action'     => 'approve',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'user/admin/users/bulk-approve' => [
        'controller' => 'user/admin/users',
        'action'     => 'bulkApprove',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'user/admin/users/reject' => [
        'controller' => 'user/admin/users',
        'action'     => 'reject',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // Group editor — gated by user.admin.groups.* keys in Action only.
    'user/admin/groups' => [
        'controller' => 'user/admin/groups',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'user/admin/groups/add' => [
        'controller' => 'user/admin/groups',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'user/admin/groups/edit' => [
        'controller' => 'user/admin/groups',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'user/admin/groups/delete' => [
        'controller' => 'user/admin/groups',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'user/admin/groups/clone' => [
        'controller' => 'user/admin/groups',
        'action'     => 'clone',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'user/admin/groups/add-member' => [
        'controller' => 'user/admin/groups',
        'action'     => 'addMember',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'user/admin/groups/remove-member' => [
        'controller' => 'user/admin/groups',
        'action'     => 'removeMember',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
];