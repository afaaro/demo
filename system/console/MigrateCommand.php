<?php
declare(strict_types=1);

namespace System\Console;

use RuntimeException;
use System\Engine\Registry;
use System\Library\Database;

final class MigrateCommand
{
    private const TABLE = 'migrations';

    public function __construct(private Registry $registry) {}

    // =========================================================================
    // Public API — global
    // =========================================================================

    public function run(): void
    {
        $db = $this->registry->get('db');
        $this->ensureMigrationsTable($db);

        $applied = $this->appliedMigrations($db);
        $all     = $this->discoverMigrations();

        $pending = array_diff_key($all, array_flip($applied));
        if ($pending === []) {
            echo "Nothing to migrate.\n";
            return;
        }

        $batch = $this->nextBatch($db);

        foreach ($pending as $key => $file) {
            echo "Migrating: {$key} ... ";

            try {
                $this->runMigrationFile($file, 'up');
            } catch (\Throwable $e) {
                echo "FAILED\n";
                throw $e;
            }

            $db->query(
                "INSERT INTO `" . $db->getPrefix() . self::TABLE . "`"
                . " (`migration`, `batch`) VALUES (?, ?)",
                [$key, $batch]
            );

            echo "OK\n";
        }

        echo "\nAll migrations applied. Batch {$batch}.\n";
    }

    /**
     * Mark every pending migration as applied without executing SQL.
     * Use when the schema was imported/restored manually.
     *
     * @return int Number of migrations stamped
     */
    public function stamp(): int
    {
        $db = $this->registry->get('db');
        $this->ensureMigrationsTable($db);

        $applied = $this->appliedMigrations($db);
        $all     = $this->discoverMigrations();
        $pending = array_diff_key($all, array_flip($applied));

        if ($pending === []) {
            echo "Nothing to stamp.\n";
            return 0;
        }

        $batch = $this->nextBatch($db);
        $count = 0;

        foreach (array_keys($pending) as $key) {
            $db->query(
                "INSERT INTO `" . $db->getPrefix() . self::TABLE . "`"
                . " (`migration`, `batch`) VALUES (?, ?)",
                [$key, $batch]
            );
            echo "Stamped: {$key}\n";
            $count++;
        }

        echo "\nStamped {$count} migration(s) as applied. Batch {$batch}.\n";
        return $count;
    }

    public function rollback(): void
    {
        $db = $this->registry->get('db');
        $this->ensureMigrationsTable($db);

        $lastBatch = (int) $db->query(
            "SELECT COALESCE(MAX(`batch`), 0) FROM `" . $db->getPrefix() . self::TABLE . "`"
        )->value;

        if ($lastBatch === 0) {
            echo "Nothing to roll back.\n";
            return;
        }

        $rows = $db->query(
            "SELECT `migration` FROM `" . $db->getPrefix() . self::TABLE . "`"
            . " WHERE `batch` = ? ORDER BY `migration` DESC",
            [$lastBatch]
        )->rows;

        $all = $this->discoverMigrations();

        foreach ($rows as $row) {
            $key = (string) $row['migration'];

            if (!isset($all[$key])) {
                echo "Skipping {$key} — file not found.\n";
                continue;
            }

            echo "Rolling back: {$key} ... ";

            try {
                $this->runMigrationFile($all[$key], 'down');
            } catch (\Throwable $e) {
                echo "FAILED\n";
                throw $e;
            }

            $db->query(
                "DELETE FROM `" . $db->getPrefix() . self::TABLE . "` WHERE `migration` = ?",
                [$key]
            );

            echo "OK\n";
        }
    }

    public function status(): void
    {
        $db = $this->registry->get('db');
        $this->ensureMigrationsTable($db);

        $applied = $this->appliedMigrations($db);
        $all     = $this->discoverMigrations();

        echo str_pad('Migration', 70) . "Status\n";
        echo str_repeat('-', 80) . "\n";

        foreach ($all as $key => $file) {
            $status = in_array($key, $applied, true) ? 'applied' : 'pending';
            echo str_pad($key, 70) . $status . "\n";
        }
    }

    // =========================================================================
    // Public API — per-module
    // =========================================================================

    /**
     * Run only the pending migrations for a specific module.
     * Module migrations are keyed as "{module}:{filename}".
     */
    public function runModule(string $moduleName): void
    {
        $db = $this->registry->get('db');
        $this->ensureMigrationsTable($db);

        $applied = $this->appliedMigrations($db);
        // Always discover this module's files (needed during install).
        $moduleMs = $this->discoverMigrations(false, $moduleName);

        $pending = array_diff_key($moduleMs, array_flip($applied));

        if ($pending === []) {
            echo "Nothing to migrate for module '{$moduleName}'.\n";
            return;
        }

        $batch = $this->nextBatch($db);

        foreach ($pending as $key => $file) {
            echo "Migrating: {$key} ... ";

            try {
                $this->runMigrationFile($file, 'up');
            } catch (\Throwable $e) {
                echo "FAILED\n";
                throw $e;
            }

            $db->query(
                "INSERT INTO `" . $db->getPrefix() . self::TABLE . "`"
                . " (`migration`, `batch`) VALUES (?, ?)",
                [$key, $batch]
            );

            echo "OK\n";
        }

        echo "\nModule '{$moduleName}' migrated. Batch {$batch}.\n";
    }

    /**
     * Pending / applied counts for one module (keys like "{module}:filename").
     *
     * @return array{total:int, applied:int, pending:int, pending_keys:list<string>}
     */
    public function moduleHealth(string $moduleName): array
    {
        $db = $this->registry->get('db');
        $this->ensureMigrationsTable($db);

        $applied  = $this->appliedMigrations($db);
        $moduleMs = $this->discoverMigrations(false, $moduleName);

        $pending = array_diff_key($moduleMs, array_flip($applied));
        $pendingKeys = array_keys($pending);
        sort($pendingKeys);

        return [
            'total'        => count($moduleMs),
            'applied'      => count($moduleMs) - count($pending),
            'pending'      => count($pending),
            'pending_keys' => $pendingKeys,
        ];
    }

    /**
     * Global migration health across core + all modules.
     *
     * @return array{total:int, applied:int, pending:int, pending_keys:list<string>, last_batch:int}
     */
    public function health(): array
    {
        $db = $this->registry->get('db');
        $this->ensureMigrationsTable($db);

        $applied = $this->appliedMigrations($db);
        $all     = $this->discoverMigrations();
        $pending = array_diff_key($all, array_flip($applied));
        $pendingKeys = array_keys($pending);
        sort($pendingKeys);

        $lastBatch = (int) $db->query(
            "SELECT COALESCE(MAX(`batch`), 0) FROM `" . $db->getPrefix() . self::TABLE . "`"
        )->value;

        return [
            'total'        => count($all),
            'applied'      => count($all) - count($pending),
            'pending'      => count($pending),
            'pending_keys' => $pendingKeys,
            'last_batch'   => $lastBatch,
        ];
    }

    /**
     * Roll back every applied migration for a module, newest first.
     */
    public function rollbackModule(string $moduleName): void
    {
        $db = $this->registry->get('db');
        $this->ensureMigrationsTable($db);

        $prefix = $moduleName . ':';

        $rows = $db->query(
            "SELECT `migration` FROM `" . $db->getPrefix() . self::TABLE . "`"
            . " WHERE `migration` LIKE ? ORDER BY `migration` DESC",
            [$prefix . '%']
        )->rows;

        if ($rows === []) {
            echo "Nothing to roll back for module '{$moduleName}'.\n";
            return;
        }

        $all = $this->discoverMigrations(false, $moduleName);

        foreach ($rows as $row) {
            $key = (string) $row['migration'];

            if (!isset($all[$key])) {
                // Migration file removed (e.g. consolidated) — drop the stamp only.
                echo "Removing orphan stamp: {$key}\n";
                $db->query(
                    "DELETE FROM `" . $db->getPrefix() . self::TABLE . "` WHERE `migration` = ?",
                    [$key]
                );
                continue;
            }

            echo "Rolling back: {$key} ... ";

            try {
                $db->query('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    $this->runMigrationFile($all[$key], 'down');
                } finally {
                    $db->query('SET FOREIGN_KEY_CHECKS = 1');
                }
            } catch (\Throwable $e) {
                echo "FAILED\n";
                throw $e;
            }

            $db->query(
                "DELETE FROM `" . $db->getPrefix() . self::TABLE . "` WHERE `migration` = ?",
                [$key]
            );

            echo "OK\n";
        }
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function ensureMigrationsTable(Database $db): void
    {
        $table = $db->getPrefix() . self::TABLE;
        $db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` ("
            . " `id` int UNSIGNED NOT NULL AUTO_INCREMENT,"
            . " `migration` varchar(255) NOT NULL,"
            . " `batch` int UNSIGNED NOT NULL DEFAULT 1,"
            . " `run_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            . " PRIMARY KEY (`id`),"
            . " UNIQUE KEY `uq_migration` (`migration`)"
            . " ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<int, string> */
    private function appliedMigrations(Database $db): array
    {
        $rows = $db->query(
            "SELECT `migration` FROM `" . $db->getPrefix() . self::TABLE . "` ORDER BY `migration`"
        )->rows;

        return array_map(fn($r) => (string) $r['migration'], $rows);
    }

    /**
     * Discover all migrations from:
     *   1. storage/migrations/*.php                          (core)
     *   2. system/modules/{name}/migrations/*.php            (system modules)
     *   3. modules/{name}/migrations/*.php                   (installed user modules)
     *
     * Returns [migrationKey => absoluteFilePath].
     * Module migrations are keyed as "{module}:{filename}" to avoid collisions.
     *
     * @param bool $installedModulesOnly When true (default), skip user modules
     *                                   that are not registered in #__modules.
     *                                   System modules are always included.
     * @param string|null $onlyModule    Limit discovery to one module name.
     * @return array<string, string>
     */
    private function discoverMigrations(
        bool $installedModulesOnly = true,
        ?string $onlyModule = null,
    ): array {
        $out = array_merge(
            $onlyModule === null ? $this->discoverCoreMigrations() : [],
            $this->discoverModuleMigrations($installedModulesOnly, $onlyModule),
        );

        ksort($out);

        return $out;
    }

    /** @return array<string, string> */
    private function discoverCoreMigrations(): array
    {
        $out = [];
        $dir = BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'migrations';

        if (is_dir($dir)) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $out[basename($file, '.php')] = $file;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function discoverModuleMigrations(
        bool $installedModulesOnly = true,
        ?string $onlyModule = null,
    ): array {
        $out = [];
        $installed = $installedModulesOnly ? $this->installedModuleNames() : null;

        foreach (get_modules($onlyModule) as $moduleName => $modulePath) {
            if ($onlyModule !== null && strcasecmp($moduleName, $onlyModule) !== 0) {
                continue;
            }

            if ($installed !== null && !$this->includeModuleMigrations($moduleName, $modulePath, $installed)) {
                continue;
            }

            $dir = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . 'migrations';

            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $key = $moduleName . ':' . basename($file, '.php');
                $out[$key] = $file;
            }
        }

        return $out;
    }

    /**
     * System modules always participate. User modules only when installed.
     *
     * @param array<string, true> $installed
     */
    private function includeModuleMigrations(string $moduleName, string $modulePath, array $installed): bool
    {
        $normalized = str_replace('\\', '/', $modulePath);
        if (str_contains($normalized, '/system/modules/')) {
            return true;
        }

        return isset($installed[strtolower($moduleName)]);
    }

    /** @return array<string, true> lowercase module name => true */
    private function installedModuleNames(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        try {
            $db = $this->registry->get('db');
            $rows = $db->query(
                'SELECT `name` FROM `' . $db->getPrefix() . 'modules`'
            )->rows;
            foreach ($rows as $row) {
                $name = strtolower(trim((string) ($row['name'] ?? '')));
                if ($name !== '') {
                    $cache[$name] = true;
                }
            }
        } catch (\Throwable) {
            // modules table may be unavailable during early bootstrap
        }

        return $cache;
    }

    private function nextBatch(Database $db): int
    {
        return 1 + (int) $db->query(
            "SELECT COALESCE(MAX(`batch`), 0) FROM `" . $db->getPrefix() . self::TABLE . "`"
        )->value;
    }

    private function runMigrationFile(string $file, string $direction): void
    {
        if (!is_file($file)) {
            throw new RuntimeException("Migration file not found: {$file}");
        }

        $migration = include $file;

        if (!is_array($migration) || !isset($migration[$direction])) {
            throw new RuntimeException(
                "Migration '{$file}' must return an array with 'up' and 'down' keys."
            );
        }

        $callback = $migration[$direction];
        if (!is_callable($callback)) {
            throw new RuntimeException(
                "Migration '{$file}' key '{$direction}' must be callable."
            );
        }

        $callback($this->registry->get('db'), $this->registry);
    }
}