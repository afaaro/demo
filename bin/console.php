<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('DS', str_replace('\\', '/', DIRECTORY_SEPARATOR));

require_once __DIR__ . DS . '..' . DS . 'system' . DS . 'helper.php';

if (!defined('BASEDIR')) {
    $root = getRootDirectory();
    if ($root === '') {
        fwrite(STDERR, "Unable to determine application root.\n");
        exit(1);
    }
    // getRootDirectory() returns the path to config.php; app root is its dirname.
    define('BASEDIR', rtrim(str_replace('\\', '/', dirname($root)), '/') . '/');
}

require_once BASEDIR . 'system' . DS . 'engine' . DS . 'autoloader.php';

$loader = new System\Engine\Autoloader();
$loader->addNamespace('System\\', BASEDIR . 'system');
$loader->addNamespace('Module\\', BASEDIR . 'modules');
$loader->addNamespace('Module\\Comments\\', BASEDIR . 'system' . DS . 'modules' . DS . 'comments');
$loader->register();

$registry = System\Engine\Registry::getInstance();

// Core services available in every CLI invocation.
$registry->singleton('config', fn($r) => new System\Library\Config(
    BASEDIR . 'system' . DS . 'config' . DS
));
$config = $registry->get('config');

$registry->singleton('db', fn() => new System\Library\Database([
    'hostname' => $config->get('database.hostname', 'localhost'),
    'username' => $config->get('database.username', 'root'),
    'password' => $config->get('database.password', ''),
    'database' => $config->get('database.database', 'demo'),
    'prefix'   => $config->get('database.prefix',   ''),
]));

// Built-in commands — registered directly, no module involvement.
$console = console();

$console->register('migrate',                 fn($r, $a) => (new System\Console\MigrateCommand($r))->run() ?? 0);
$console->register('migrate:rollback',        fn($r, $a) => (new System\Console\MigrateCommand($r))->rollback() ?? 0);
$console->register('migrate:status',          fn($r, $a) => (new System\Console\MigrateCommand($r))->status() ?? 0);
$console->register('migrate:stamp',           fn($r, $a) => (new System\Console\MigrateCommand($r))->stamp() ?? 0);
$console->register('migrate:module',          fn($r, $a) => (new System\Console\MigrateCommand($r))->runModule((string) ($a[0] ?? '')) ?? 0);
$console->register('migrate:rollback-module', fn($r, $a) => (new System\Console\MigrateCommand($r))->rollbackModule((string) ($a[0] ?? '')) ?? 0);
$console->register('module:install',          fn($r, $a) => (new System\Console\ModuleCommand($r))->install((string) ($a[0] ?? '')) ?? 0);
$console->register('module:uninstall',        fn($r, $a) => (new System\Console\ModuleCommand($r))->uninstall((string) ($a[0] ?? '')) ?? 0);

// Discover module commands.
$console->load($registry);

// Parse argv.
$argv    = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? 'help';
$args    = array_slice($argv, 2);

// Help.
if ($command === 'help' || $command === '') {
    printConsoleHelp($console);
    exit(0);
}

// Dispatch.
try {
    $exit = $console->run($command, $args, $registry);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    if ($config->get('app.debug', false)) {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    exit(1);
}

exit($exit);

// -----------------------------------------------------------------------------

function printConsoleHelp(System\Engine\Console $console): void
{
    echo "Usage: php bin/console.php <command> [args]\n\n";

    $all = $console->all();
    if ($all === []) {
        echo "No commands registered.\n";
        return;
    }

    // Group by module (or 'core' for built-ins).
    $grouped = [];
    foreach (array_keys($all) as $name) {
        $source = $console->source($name) ?? 'core';
        $grouped[$source][] = $name;
    }
    ksort($grouped);

    foreach ($grouped as $source => $commands) {
        echo ($source === 'core' ? "Built-in commands:" : "Module: {$source}") . "\n";
        sort($commands);
        foreach ($commands as $name) {
            printf("  %-35s\n", $name);
        }
        echo "\n";
    }
}