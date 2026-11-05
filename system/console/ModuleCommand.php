<?php
declare(strict_types=1);

namespace System\Console;

use RuntimeException;
use System\Engine\Registry;
use System\Library\Database;

final class ModuleCommand
{
    private const TABLE = 'modules';

    public function __construct(private Registry $registry) {}

    public function install(string $name): void
    {
        $db = $this->registry->get('db');

        $path = $this->findModulePath($name);
        if ($path === null) {
            throw new RuntimeException("Module '{$name}' not found.");
        }

        if ($this->isInstalled($db, $name)) {
            throw new RuntimeException("Module '{$name}' is already installed.");
        }

        // 1. Register.
        $db->insert(self::TABLE, [
            'name'         => $name,
            'label'        => $this->label($name, $path),
            'version'      => $this->version($name, $path),
            'enabled'      => 1,
            'config'       => '{}',
            'installed_at' => date('Y-m-d H:i:s'),
        ]);

        echo "Registered '{$name}'.\n";

        // 2. Run migrations.
        try {
            (new MigrateCommand($this->registry))->runModule($name);
        } catch (\Throwable $e) {
            echo "Migration failed — unregistering.\n";
            $db->delete(self::TABLE, ['name' => $name]);
            throw $e;
        }

        echo "Module '{$name}' installed.\n";
    }

    public function uninstall(string $name): void
    {
        $db = $this->registry->get('db');

        if ($this->isSystemModule($name)) {
            throw new RuntimeException("System module '{$name}' cannot be uninstalled.");
        }

        if (!$this->isInstalled($db, $name)) {
            throw new RuntimeException("Module '{$name}' is not installed.");
        }

        // 1. Roll back migrations (orphan stamps removed; FK checks off during downs).
        try {
            (new MigrateCommand($this->registry))->rollbackModule($name);
        } catch (\Throwable $e) {
            echo "Migration rollback warning: {$e->getMessage()}\n";
        }

        // 2. Module uninstall.php — force-drop leftover tables (handles consolidation).
        $path = $this->findModulePath($name);
        $script = ($path ?? '') . DIRECTORY_SEPARATOR . 'uninstall.php';
        if ($path !== null && is_file($script)) {
            $registry = $this->registry;
            require $script;
        }

        // 3. Unregister.
        $db->delete(self::TABLE, ['name' => $name]);

        echo "Module '{$name}' uninstalled.\n";
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function findModulePath(string $name): ?string
    {
        foreach ([
            BASEDIR . 'modules' . DIRECTORY_SEPARATOR . $name,
            BASEDIR . 'system' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . $name,
        ] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function isInstalled(Database $db, string $name): bool
    {
        return $db->count(self::TABLE, ['name' => $name]) > 0;
    }

    private function isSystemModule(string $name): bool
    {
        return str_contains(
            str_replace('\\', '/', $this->findModulePath($name) ?? ''),
            '/system/modules/'
        );
    }

    private function label(string $name, string $path): string
    {
        $composer = $path . '/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            if (is_array($data) && !empty($data['extra']['label'])) {
                return (string) $data['extra']['label'];
            }
        }
        return ucwords(str_replace('-', ' ', $name));
    }

    private function version(string $name, string $path): string
    {
        $composer = $path . '/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            if (is_array($data) && !empty($data['version'])) {
                return (string) $data['version'];
            }
        }
        return '1.0.0';
    }
}