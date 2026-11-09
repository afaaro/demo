<?php
declare(strict_types=1);

use System\Console\MigrateCommand;
use System\Engine\Controller;

class ToolsAdminDatabase extends Controller
{
    private const BROWSE_PER_PAGE = 50;
    private const QUERY_MAX_ROWS  = 200;

    // =========================================================================
    // Overview — GET /tools/admin/database
    // =========================================================================

    public function indexAction(): void
    {
        $q = trim((string) $this->request->get('q', 'string', ''));
        $tables = $this->listTables($q);
        $info   = $this->connectionInfo();

        $health = [
            'total' => 0, 'applied' => 0, 'pending' => 0,
            'pending_keys' => [], 'last_batch' => 0,
        ];
        try {
            $health = (new MigrateCommand($this->registry))->health();
        } catch (\Throwable) {
            // migrations table may be unavailable
        }

        $totalRows = 0;
        $totalBytes = 0;
        foreach ($tables as $t) {
            $totalRows  += (int) ($t['rows'] ?? 0);
            $totalBytes += (int) ($t['data_length'] ?? 0) + (int) ($t['index_length'] ?? 0);
        }

        echo $this->output('index', [
            'title'      => 'Database',
            'tables'     => $tables,
            'info'       => $info,
            'health'     => $health,
            'q'          => $q,
            'totalRows'  => $totalRows,
            'totalBytes' => $totalBytes,
        ]);
    }

    // =========================================================================
    // Table detail — GET /tools/admin/database/table?name=
    // =========================================================================

    public function tableAction(): void
    {
        $name = $this->safeTable((string) $this->request->get('name', 'string', ''));
        if ($name === null) {
            $this->notify->error('Table not found.');
            redirect_to('tools/admin/database');
            return;
        }

        $tab = trim((string) $this->request->get('tab', 'string', 'structure'));
        if (!in_array($tab, ['structure', 'browse', 'indexes'], true)) {
            $tab = 'structure';
        }

        $page = max(1, (int) $this->request->get('page', 'int', 1));
        $per  = self::BROWSE_PER_PAGE;

        $status  = $this->tableStatus($name);
        $columns = $this->tableColumns($name);
        $indexes = $this->tableIndexes($name);

        $rows = [];
        $total = 0;
        if ($tab === 'browse') {
            $quoted = $this->db->quoteIdentifier($name);
            $total  = (int) $this->db->query("SELECT COUNT(*) FROM {$quoted}")->value;
            $offset = ($page - 1) * $per;
            $rows   = $this->db->query(
                "SELECT * FROM {$quoted} LIMIT {$per} OFFSET {$offset}"
            )->rows;
        }

        echo $this->output('table', [
            'title'   => 'Table: ' . $name,
            'name'    => $name,
            'tab'     => $tab,
            'status'  => $status,
            'columns' => $columns,
            'indexes' => $indexes,
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $per,
        ]);
    }

    // =========================================================================
    // Query console — GET|POST /tools/admin/database/query
    // =========================================================================

    public function queryAction(): void
    {
        $sql = '';
        $result = null;
        $error  = null;
        $ms     = null;

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $sql = trim((string) $this->request->post('sql', 'string', ''));

            try {
                $safe = $this->assertReadOnlySelect($sql);
                $start = hrtime(true);
                $result = $this->db->query($safe)->rows;
                $ms = (hrtime(true) - $start) / 1e6;
                if (count($result) > self::QUERY_MAX_ROWS) {
                    $result = array_slice($result, 0, self::QUERY_MAX_ROWS);
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        echo $this->output('query', [
            'title'  => 'SQL Query',
            'sql'    => $sql,
            'result' => $result,
            'error'  => $error,
            'ms'     => $ms,
            'maxRows'=> self::QUERY_MAX_ROWS,
        ]);
    }

    // =========================================================================
    // Migrations — POST
    // =========================================================================

    public function migrateAction(): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();

        try {
            ob_start();
            (new MigrateCommand($this->registry))->run();
            $out = trim((string) ob_get_clean());
            $this->notify->success($out !== '' ? $out : 'Migrations ran.');
            $this->log->info('Database migrations ran', ['by' => $this->auth->id()]);
        } catch (\Throwable $e) {
            @ob_end_clean();
            $this->notify->error('Migration failed: ' . $e->getMessage());
        }

        redirect_to('tools/admin/database');
    }

    public function rollbackAction(): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();

        try {
            ob_start();
            (new MigrateCommand($this->registry))->rollback();
            $out = trim((string) ob_get_clean());
            $this->notify->success($out !== '' ? $out : 'Rollback complete.');
            $this->log->info('Database migrations rolled back', ['by' => $this->auth->id()]);
        } catch (\Throwable $e) {
            @ob_end_clean();
            $this->notify->error('Rollback failed: ' . $e->getMessage());
        }

        redirect_to('tools/admin/database');
    }

    /**
     * Mark pending migrations applied without running SQL (manual schema import).
     */
    public function stampAction(): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();

        try {
            ob_start();
            $count = (new MigrateCommand($this->registry))->stamp();
            $out = trim((string) ob_get_clean());
            $this->notify->success(
                $out !== ''
                    ? $out
                    : ($count > 0
                        ? "Stamped {$count} migration(s) as applied."
                        : 'Nothing to stamp.')
            );
            $this->log->info('Database migrations stamped', [
                'count' => $count,
                'by'    => $this->auth->id(),
            ]);
        } catch (\Throwable $e) {
            @ob_end_clean();
            $this->notify->error('Stamp failed: ' . $e->getMessage());
        }

        redirect_to('tools/admin/database');
    }

    // =========================================================================
    // Maintenance — POST optimize|analyze|check
    // =========================================================================

    public function maintainAction(): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();

        $op = strtolower(trim((string) $this->request->post('op', 'string', '')));
        if (!in_array($op, ['optimize', 'analyze', 'check'], true)) {
            $this->notify->error('Unknown maintenance operation.');
            redirect_to('tools/admin/database');
            return;
        }

        $tables = $this->postedTables();
        if ($tables === []) {
            $this->notify->error('Select at least one table.');
            redirect_to('tools/admin/database');
            return;
        }

        $verb = strtoupper($op);
        $ok = 0;
        $messages = [];

        foreach ($tables as $table) {
            $quoted = $this->db->quoteIdentifier($table);
            try {
                $rows = $this->db->query("{$verb} TABLE {$quoted}")->rows;
                $ok++;
                foreach ($rows as $row) {
                    $msg = (string) ($row['Msg_text'] ?? $row['msg_text'] ?? 'OK');
                    $messages[] = "{$table}: {$msg}";
                }
            } catch (\Throwable $e) {
                $messages[] = "{$table}: " . $e->getMessage();
            }
        }

        $this->log->info('Database maintain', [
            'op' => $op, 'tables' => $tables, 'by' => $this->auth->id(),
        ]);

        $this->notify->success(
            "{$verb} finished on {$ok}/" . count($tables) . ' table(s). '
            . implode(' · ', array_slice($messages, 0, 5))
        );
        redirect_to('tools/admin/database');
    }

    // =========================================================================
    // Drop — POST /tools/admin/database/drop
    // =========================================================================

    public function dropAction(): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();

        $tables = $this->postedTables();
        if ($tables === []) {
            $this->notify->error('Select at least one table to drop.');
            redirect_to('tools/admin/database');
            return;
        }

        $ok = 0;
        $errors = [];
        $pdo = $this->db->pdo();

        try {
            // DDL via exec — native prepared statements can silently no-op DROP.
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) {
                $quoted = $this->db->quoteIdentifier($table);
                try {
                    $pdo->exec("DROP TABLE IF EXISTS {$quoted}");
                    if ($this->tableExists($table)) {
                        // May be a view listed by SHOW TABLE STATUS.
                        $pdo->exec("DROP VIEW IF EXISTS {$quoted}");
                    }
                    if ($this->tableExists($table)) {
                        $errors[] = "{$table}: still exists after DROP";
                        continue;
                    }
                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = "{$table}: " . $e->getMessage();
                }
            }
        } finally {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable) {
                // ignore restore failure
            }
        }

        $this->log->info('Database drop tables', [
            'tables' => $tables,
            'ok'     => $ok,
            'errors' => $errors,
            'by'     => $this->auth->id(),
        ]);

        if ($errors !== []) {
            $this->notify->error(
                "Dropped {$ok}/" . count($tables) . ' table(s). '
                . implode(' · ', array_slice($errors, 0, 3))
            );
        } elseif ($ok === 0) {
            $this->notify->error('No tables were dropped.');
        } else {
            $this->notify->success(
                $ok === 1
                    ? "Dropped table `{$tables[0]}`."
                    : "Dropped {$ok} tables."
            );
        }

        redirect_to('tools/admin/database');
    }

    // =========================================================================
    // Export — POST /tools/admin/database/export
    // =========================================================================

    public function exportAction(): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();

        $tables = $this->postedTables();
        if ($tables === []) {
            // Export all when none selected.
            $tables = array_column($this->listTables(''), 'name');
        }

        if ($tables === []) {
            $this->notify->error('No tables to export.');
            redirect_to('tools/admin/database');
            return;
        }

        $dbName = (string) ($this->config->get('database.database')
            ?? $this->config->get('database.name')
            ?? 'database');
        $stamp  = date('Ymd_His');
        $file   = preg_replace('/[^A-Za-z0-9_-]/', '_', $dbName) . "_{$stamp}.sql";

        $this->log->info('Database export', [
            'tables' => $tables, 'file' => $file, 'by' => $this->auth->id(),
        ]);

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        echo "-- afaaro SQL export\n";
        echo '-- Database: ' . $dbName . "\n";
        echo '-- Generated: ' . date('c') . "\n";
        echo "-- Tables: " . implode(', ', $tables) . "\n\n";
        echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $this->streamTableDump($table);
        }

        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        exit;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('tools/admin/database');
            exit;
        }
    }

    /** @return array<string, mixed> */
    private function connectionInfo(): array
    {
        $version = '';
        try {
            $version = (string) $this->db->query('SELECT VERSION()')->value;
        } catch (\Throwable) {
            $version = 'unknown';
        }

        return [
            'hostname' => (string) ($this->config->get('database.hostname')
                ?? $this->config->get('database.host') ?? 'localhost'),
            'database' => (string) ($this->config->get('database.database')
                ?? $this->config->get('database.name') ?? ''),
            'username' => (string) ($this->config->get('database.username')
                ?? $this->config->get('database.user') ?? ''),
            'prefix'   => $this->db->getPrefix(),
            'charset'  => (string) ($this->config->get('database.charset') ?? 'utf8mb4'),
            'version'  => $version,
            'driver'   => 'mysql',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listTables(string $q): array
    {
        $rows = $this->db->query('SHOW TABLE STATUS')->rows;
        $out  = [];

        foreach ($rows as $row) {
            $name = (string) ($row['Name'] ?? $row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if ($q !== '' && stripos($name, $q) === false) {
                continue;
            }

            $out[] = [
                'name'          => $name,
                'engine'        => (string) ($row['Engine'] ?? $row['engine'] ?? ''),
                'rows'          => (int) ($row['Rows'] ?? $row['rows'] ?? 0),
                'data_length'   => (int) ($row['Data_length'] ?? $row['data_length'] ?? 0),
                'index_length'  => (int) ($row['Index_length'] ?? $row['index_length'] ?? 0),
                'collation'     => (string) ($row['Collation'] ?? $row['collation'] ?? ''),
                'auto_increment'=> $row['Auto_increment'] ?? $row['auto_increment'] ?? null,
                'comment'       => (string) ($row['Comment'] ?? $row['comment'] ?? ''),
                'updated'       => (string) ($row['Update_time'] ?? $row['update_time'] ?? ''),
            ];
        }

        usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** @return array<string, mixed> */
    private function tableStatus(string $name): array
    {
        foreach ($this->listTables('') as $t) {
            if ($t['name'] === $name) {
                return $t;
            }
        }
        return ['name' => $name];
    }

    /** @return list<array<string, mixed>> */
    private function tableColumns(string $name): array
    {
        $quoted = $this->db->quoteIdentifier($name);
        return $this->db->query("SHOW FULL COLUMNS FROM {$quoted}")->rows;
    }

    /** @return list<array<string, mixed>> */
    private function tableIndexes(string $name): array
    {
        $quoted = $this->db->quoteIdentifier($name);
        return $this->db->query("SHOW INDEX FROM {$quoted}")->rows;
    }

    private function safeTable(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return null;
        }

        return $this->tableExists($name) ? $name : null;
    }

    private function tableExists(string $name): bool
    {
        try {
            $exists = $this->db->query(
                'SELECT COUNT(*) FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE() AND table_name = ?',
                [$name]
            )->value;
            return ((int) $exists) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function postedTables(): array
    {
        $raw = (array) $this->request->post('tables', 'raw', []);

        $out = [];
        foreach ($raw as $name) {
            $safe = $this->safeTable((string) $name);
            if ($safe !== null) {
                $out[] = $safe;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Allow SELECT / WITH / SHOW / DESCRIBE / EXPLAIN only.
     * Block multi-statements and write / privileged SQL.
     */
    private function assertReadOnlySelect(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new RuntimeException('Enter a SQL query.');
        }

        $sql = rtrim($sql, " \t\n\r\0\x0B;");
        if (str_contains($sql, ';')) {
            throw new RuntimeException('Only a single statement is allowed.');
        }

        $stripped = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
        $stripped = preg_replace('/--[^\n]*/', ' ', $stripped) ?? $stripped;
        $stripped = preg_replace('/#[^\n]*/', ' ', $stripped) ?? $stripped;
        $stripped = trim($stripped);

        if (!preg_match('/^(SELECT|WITH|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $stripped)) {
            throw new RuntimeException('Only SELECT / SHOW / DESCRIBE / EXPLAIN queries are allowed.');
        }

        if (preg_match(
            '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|REVOKE|CALL|LOAD|HANDLER|LOCK|UNLOCK)\b/i',
            $stripped
        )) {
            throw new RuntimeException('Write or privileged statements are not allowed.');
        }

        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $stripped)
            || preg_match('/\bINTO\s+@/i', $stripped)
        ) {
            throw new RuntimeException('INTO OUTFILE / variable assignment is not allowed.');
        }

        if (preg_match('/^(SELECT|WITH)\b/i', $stripped) && !preg_match('/\bLIMIT\s+\d+/i', $stripped)) {
            $sql .= ' LIMIT ' . self::QUERY_MAX_ROWS;
        }

        return $sql;
    }

    private function streamTableDump(string $table): void
    {
        $quoted = $this->db->quoteIdentifier($table);

        echo "-- --------------------------------------------------------\n";
        echo "-- Table structure: {$table}\n";
        echo "-- --------------------------------------------------------\n\n";
        echo "DROP TABLE IF EXISTS {$quoted};\n";

        $create = $this->db->query("SHOW CREATE TABLE {$quoted}")->row;
        $ddl = (string) ($create['Create Table'] ?? $create['Create table'] ?? '');
        if ($ddl !== '') {
            echo $ddl . ";\n\n";
        }

        echo "-- Dumping data for table {$table}\n\n";

        $stmt = $this->db->pdo()->query("SELECT * FROM {$quoted}");
        if ($stmt === false) {
            echo "\n";
            return;
        }

        $batch = [];
        $cols  = null;
        $count = 0;

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($cols === null) {
                $cols = array_map(
                    fn($c) => $this->db->quoteIdentifier((string) $c),
                    array_keys($row)
                );
            }

            $vals = [];
            foreach ($row as $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = $this->db->pdo()->quote((string) $v);
                }
            }
            $batch[] = '(' . implode(',', $vals) . ')';
            $count++;

            if (count($batch) >= 100) {
                echo 'INSERT INTO ' . $quoted . ' (' . implode(',', $cols) . ') VALUES' . "\n"
                    . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }

        if ($batch !== [] && $cols !== null) {
            echo 'INSERT INTO ' . $quoted . ' (' . implode(',', $cols) . ') VALUES' . "\n"
                . implode(",\n", $batch) . ";\n";
        }

        echo "\n-- {$count} row(s)\n\n";
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1) . ' MB';
        }
        return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
    }
}
