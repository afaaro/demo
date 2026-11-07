<?php
declare(strict_types=1);

return [
    'quran' => [
        'controller' => 'quran/index',
        'action'     => 'index',
        'params'     => ['sura', 'aya'],
        'methods'    => ['GET'],
    ],
    'quran/juz' => [
        'controller' => 'quran/index',
        'action'     => 'juz',
        'params'     => ['n'],
        'methods'    => ['GET'],
    ],
    'quran/page' => [
        'controller' => 'quran/index',
        'action'     => 'page',
        'params'     => ['n'],
        'methods'    => ['GET'],
    ],
    'quran/search' => [
        'controller' => 'quran/index',
        'action'     => 'search',
        'methods'    => ['GET'],
    ],
    'quran/admin/import' => [
        'controller' => 'quran/admin/import',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'quran/admin/import/run' => [
        'controller' => 'quran/admin/import',
        'action'     => 'run',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'quran/admin/import/upload' => [
        'controller' => 'quran/admin/import',
        'action'     => 'upload',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'quran/admin/import/translations' => [
        'controller' => 'quran/admin/import',
        'action'     => 'translations',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
];
