<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('DS', str_replace('\\', '/', DIRECTORY_SEPARATOR));
$root = dirname(__DIR__, 4);
require_once $root . '/system/helper.php';

if (!defined('BASEDIR')) {
    define('BASEDIR', rtrim(str_replace('\\', '/', $root), '/') . DS);
}

require_once BASEDIR . 'system' . DS . 'engine' . DS . 'autoloader.php';

$autoloader = new System\Engine\Autoloader();
$autoloader->register('System', BASEDIR . 'system');

$registry = new System\Engine\Registry();
$GLOBALS['registry'] = $registry;
$config = new System\Library\Config();
$registry->set('config', $config);
$db = new System\Library\Database([
    'hostname' => $config->get('database.hostname', 'localhost'),
    'username' => $config->get('database.username', 'root'),
    'password' => $config->get('database.password', ''),
    'database' => $config->get('database.database', 'demo'),
    'prefix'   => $config->get('database.prefix',   ''),
]);
$registry->set('db', $db);

$exists = $db->query("SELECT `id` FROM `#__modules` WHERE `name` = ?", ['search'])->row;
if (!$exists) {
    $db->insert('modules', [
        'name'         => 'search',
        'label'        => 'Search',
        'version'      => '1.0.0',
        'enabled'      => 1,
        'config'       => '{}',
        'installed_at' => date('Y-m-d H:i:s'),
    ]);
    echo "Registered module 'search'.\n";
} else {
    $db->update('modules', ['enabled' => 1, 'label' => 'Search', 'version' => '1.0.0'], ['name' => 'search']);
    echo "Module 'search' already registered; enabled.\n";
}

echo "Install complete.\n";
