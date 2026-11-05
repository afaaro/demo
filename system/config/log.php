<?php
return [
    'directory'     => BASEDIR . 'storage/logs',
    'channel'       => 'app',
    'level'         => 'debug',        // emergency|alert|critical|error|warning|notice|info|debug
    'max_file_size' => 10 * 1024 * 1024,   // 10MB
    'keep_days'     => 30,
    'format'        => 'plain',        // 'plain' or 'json'
    'enabled'       => true,
];