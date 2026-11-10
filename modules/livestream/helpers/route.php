<?php
declare(strict_types=1);

return [
    'livestream' => [
        'controller' => 'livestream/home',
        'action'     => 'index',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
    'livestream/status' => [
        'controller' => 'livestream/home',
        'action'     => 'status',
        'auth'       => false,
        'methods'    => ['GET'],
        'csrf'       => false,
    ],
    'livestream/watch' => [
        'controller' => 'livestream/watch',
        'action'     => 'index',
        'auth'       => false,
        'methods'    => ['GET'],
    ],
    'livestream/hls' => [
        'controller' => 'livestream/hls',
        'action'     => 'index',
        'auth'       => false,
        'methods'    => ['GET'],
        'params'     => ['slug', 'file'],
        'csrf'       => false,
    ],
    'livestream/studio' => [
        'controller' => 'livestream/studio',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'livestream/studio/rotate' => [
        'controller' => 'livestream/studio',
        'action'     => 'rotate',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'livestream/studio/delete' => [
        'controller' => 'livestream/studio',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'csrf'       => true,
    ],
    'livestream/studio/stats' => [
        'controller' => 'livestream/studio',
        'action'     => 'stats',
        'auth'       => true,
        'methods'    => ['GET'],
        'csrf'       => false,
    ],
    'livestream/webhook' => [
        'controller' => 'livestream/webhook',
        'action'     => 'auth',
        'auth'       => false,
        'methods'    => ['POST'],
        'csrf'       => false,
    ],
    'livestream/webhook/status' => [
        'controller' => 'livestream/webhook',
        'action'     => 'status',
        'auth'       => false,
        'methods'    => ['POST', 'GET'],
        'csrf'       => false,
    ],
    // on-online.bat asks this before starting ffmpeg (config-driven slug list).
    'livestream/webhook/transcode' => [
        'controller' => 'livestream/webhook',
        'action'     => 'transcode',
        'auth'       => false,
        'methods'    => ['GET'],
        'csrf'       => false,
    ],
    'livestream/telemetry' => [
        'controller' => 'livestream/webhook',
        'action'     => 'telemetry',
        'auth'       => false,
        'methods'    => ['GET'],
        'csrf'       => false,
    ],
];
