<?php
declare(strict_types=1);

return [
    // auto → APCu when available, otherwise file
    'engine'   => 'auto',
    'expire'   => 3600,
    'prefix'   => 'app_',
    'hostname' => '127.0.0.1',
    'port'     => 6379,
    'password' => '',
    'database' => 0,
];