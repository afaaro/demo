<?php

declare(strict_types=1);
namespace System\Library;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use InvalidArgumentException;
use BadMethodCallException;

class Database {
    private ?PDO $pdo = null;
    private string $prefix = '';

    /**
     * @param array<string, mixed> $config
     * @throws InvalidArgumentException|RuntimeException
     */
    public function __construct(array $config = [])
    {
        // Normalise config aliases
        $host    = $config['hostname'] ?? $config['host'] ?? 'localhost';
        $port    = (int) ($config['port'] ?? 3306);
        $name    = $config['database'] ?? $config['name'] ?? '';
        $user    = $config['username'] ?? $config['user'] ?? '';
        $pass    = $config['password'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';
        $this->prefix = (string) ($config['prefix'] ?? '');

        // Validate required
        if (trim($name) === '' || trim($user) === '') {
            throw new InvalidArgumentException('Database name and username are required.');
        }

        // Validate prefix
        if ($this->prefix !== '' && !preg_match('/^[A-Za-z0-9_]*$/', $this->prefix)) {
            throw new InvalidArgumentException('Table prefix may only contain letters, numbers and underscores.');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ]);
        } catch (PDOException $e) {
            error_log('[DB Connection Error] ' . $e->getMessage());
            throw new RuntimeException('Database connection failed.', 500, $e);
        }
    }

    /**
     * Execute a query with parameter binding
     *
     * @param string $sql Supports #__ table prefix
     * @param array<int|string, mixed> $params
     * @param int $fetchMode PDO::FETCH_* constant
     * @return QueryResult
     * @throws RuntimeException
     */
    public function query(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): QueryResult
    {
        $sql = $this->replacePrefix($sql);

        try {
            $stmt = $this->pdo->prepare($sql);
            $hasNamed = (count(array_filter(array_keys($params), 'is_string')) > 0);
            $stmt->execute($hasNamed ? $params : array_values($params));
        } catch (PDOException $e) {
            error_log('[DB Query Error] ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw new RuntimeException(
                'Database query failed: ' . $e->getMessage(),
                is_numeric($e->getCode()) ? (int) $e->getCode() : 0,
                $e
            );
        }

        return new QueryResult($stmt, $fetchMode);
    }

    /**
     * Prepare a statement manually
     */
    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo->prepare($this->replacePrefix($sql));
    }

    // === Read Helpers ===

    /**
     * Find one row by column value
     *
     * @return array<string, mixed>
     */
    public function findOne(string $table, mixed $value = null, string $column = 'id'): array
    {
        $table  = $this->table($table);
        $column = $this->sanitizeIdentifier($column);

        if ($value === null) {
            return $this->query("SELECT * FROM {$table} WHERE `{$column}` IS NULL LIMIT 1")->row;
        }

        return $this->query(
            "SELECT * FROM {$table} WHERE `{$column}` = ? LIMIT 1",
            [$value]
        )->row;
    }

    /**
     * Find all rows matching conditions
     *
     * @param array<string, mixed> $where
     * @return array<int, array<string, mixed>>
     */
    public function find(string $table, array $where = [], string $order = ''): array
    {
        [$whereSql, $bind] = $this->buildWhereFragment($where);
        $sql = "SELECT * FROM {$this->table($table)}{$whereSql}";

        if ($order !== '') {
            $sql .= ' ORDER BY ' . $this->sanitizeOrderBy($order);
        }

        return $this->query($sql, $bind)->rows;
    }

    /**
     * Get first row matching conditions
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    public function first(string $table, array $where = [], string $order = ''): array
    {
        [$whereSql, $bind] = $this->buildWhereFragment($where);
        $sql = "SELECT * FROM {$this->table($table)}{$whereSql}";

        if ($order !== '') {
            $sql .= ' ORDER BY ' . $this->sanitizeOrderBy($order);
        }

        return $this->query("{$sql} LIMIT 1", $bind)->row;
    }

    /**
     * Count rows matching conditions
     *
     * @param array<string, mixed> $where
     */
    public function count(string $table, array $where = []): int
    {
        [$whereSql, $bind] = $this->buildWhereFragment($where);
        return (int) $this->query(
            "SELECT COUNT(*) AS total FROM {$this->table($table)}{$whereSql}",
            $bind
        )->value;
    }

    /**
     * Check if any row exists
     *
     * @param array<string, mixed> $where
     */
    public function exists(string $table, array $where): bool
    {
        return $this->count($table, $where) > 0;
    }

    /**
     * Get key-value pairs from two columns
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $table, string$valueColumn, string $keyColumn = 'id', array$where = []): array
    {
        [$whereSql,$bind] = $this->buildWhereFragment($where);
        $key   =$this->quoteIdentifier($keyColumn);$value = $this->quoteIdentifier($valueColumn);

        $rows =$this->query(
            "SELECT {$key} AS `_key`, {$value} AS `_val` FROM {$this->table($table)}{$whereSql}",
            $bind
        )->rows;

        return array_column($rows, '_val', '_key');
    }

    // === Write Helpers ===

    /**
     * Insert a row
     *
     * @param array<string, mixed> $data
     * @return int Last insert ID
     */
    public function insert(string $table, array $data): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Insert data cannot be empty.');
        }

        $tableName = $this->table($table);
        $columns   = array_map(fn($col) => $this->quoteIdentifier($col), array_keys($data));
        $placeholders = array_fill(0, count($data), '?');

        $this->query(
            "INSERT INTO {$tableName} (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', $placeholders) . ")",
            array_values($data)
        );

        return $this->getLastId();
    }

    /**
     * Update rows
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @return bool True if any rows affected
     */
    public function update(string $table, array $data, array $where): bool
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Update data cannot be empty.');
        }
        if (empty($where)) {
            throw new InvalidArgumentException('Update WHERE condition is required.');
        }

        $tableName = $this->table($table);
        $setParts = [];
        $bind = [];

        foreach ($data as $col => $val) {
            $setParts[] = $this->quoteIdentifier($col) . ' = ?';
            $bind[] = $val;
        }

        [$whereSql, $whereBind] = $this->buildWhereFragment($where);

        return $this->query(
            "UPDATE {$tableName} SET " . implode(', ', $setParts) . $whereSql,
            [...$bind, ...$whereBind]
        )->affected > 0;
    }

    /**
     * Insert or update a row using modern MySQL 8.0.20+ alias syntax.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Key-value map of row data
     * @param array<int, string> $uniqueColumns Column names that form unique constraints/keys
     * @return int Last insert ID
     * @throws InvalidArgumentException
     */
    public function upsert(string $table, array $data, array$uniqueColumns = []): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Upsert data cannot be empty.');
        }

        $tableName = $this->table($table);
        $columns   = array_keys($data);

        // Format target column names and values placeholders
        $quotedColumns = array_map(fn($col) =>$this->quoteIdentifier((string) $col),$columns);
        $placeholders  = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $tableName,
            implode(', ', $quotedColumns),
            implode(', ', $placeholders)
        );

        // Filter out unique constraint columns from being overwritten on duplicate key
        $updatableCols = array_filter($columns,
            fn($col) => !in_array($col,$uniqueColumns, true)
        );

        if (!empty($updatableCols)) {
            $updateAssignments = array_map(
                fn($col) => $this->quoteIdentifier((string) $col)
                        . ' = `new_row`.'
                        . $this->quoteIdentifier((string) $col),
                $updatableCols
            );

            // Modern MySQL 8.0.20+ alias syntax: AS alias_name ON DUPLICATE KEY UPDATE
            $sql .= ' AS `new_row` ON DUPLICATE KEY UPDATE ' . implode(', ', $updateAssignments);
        }

        $this->query($sql, array_values($data));

        return $this->getLastId();
    }

    /**
     * Delete rows
     *
     * @param array<string, mixed> $where
     * @return bool True if any rows deleted
     */
    public function delete(string $table, array $where): bool
    {
        if (empty($where)) {
            throw new InvalidArgumentException('Delete WHERE condition is required.');
        }

        [$whereSql, $bind] = $this->buildWhereFragment($where);

        return $this->query(
            "DELETE FROM {$this->table($table)}{$whereSql}",
            $bind
        )->affected > 0;
    }

    // === Pagination ===

    /**
     * Basic pagination from single table
     *
     * @param array<string, mixed> $where
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function paginate(
        string $table,
        array $where = [],
        int $page = 1,
        int $perPage = 20,
        string $order = ''
    ): array {
        $page     = max(1, $page);
        $perPage  = max(1, $perPage);
        $offset   = ($page - 1) * $perPage;
        $tableName = $this->table($table);

        [$whereSql, $bind] = $this->buildWhereFragment($where);
        $orderSql = $order !== '' ? ' ORDER BY ' . $this->sanitizeOrderBy($order) : '';

        $total = $this->query(
            "SELECT COUNT(*) AS total FROM {$tableName}{$whereSql}",
            $bind
        )->value;

        $rows = $this->query(
            "SELECT * FROM {$tableName}{$whereSql}{$orderSql} LIMIT ? OFFSET ?",
            [...$bind, $perPage, $offset]
        )->rows;

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Advanced pagination — supports JOINs and custom SELECT
     *
     * @param string $baseSql Base SELECT without WHERE/ORDER/LIMIT
     * @param string $where Raw WHERE clause (without "WHERE ")
     * @param array<int, mixed> $params
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function paginateAdvanced(
        string $baseSql,
        string $where = '',
        array $params = [],
        int $page = 1,
        int $perPage = 20,
        string $order = ''
    ): array {
        $page        = max(1, $page);
        $perPage     = max(1, $perPage);
        $offset      = ($page - 1) * $perPage;
        $whereClause = $where !== '' ? ' WHERE ' . $where : '';
        $orderClause = $order !== '' ? ' ORDER BY ' . $this->sanitizeOrderBy($order) : '';

        $total = $this->query(
            "SELECT COUNT(*) AS total FROM ({$baseSql}{$whereClause}) AS pager_count",
            $params
        )->value;

        $rows = $this->query(
            "{$baseSql}{$whereClause}{$orderClause} LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        )->rows;

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    // === Transactions ===

    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool {
        return $this->pdo->commit();
    }

    public function rollBack(): bool {
        return $this->pdo->rollBack();
    }

    /**
     * Execute a callback inside a database transaction
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     * @throws Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->inTransaction()) {
                $this->rollBack();
            }
            throw $e;
        }
    }

    public function inTransaction(): bool {
        return $this->pdo->inTransaction();
    }

    // === Connection & Info ===

    /**
     * Check connection health
     */
    public function ping(): bool
    {
        if (!$this->pdo) return false;
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public function getLastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @deprecated Use getLastId()
     */
    public function insert_id(): int
    {
        trigger_error(__METHOD__ . '() is deprecated. Use getLastId().', E_USER_DEPRECATED);
        return $this->getLastId();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    // === Table Prefix ===

    public function table(string $table): string
    {
        return str_starts_with($table, '#__') ? $table : '#__' . ltrim($table, '#_');
    }

    private function replacePrefix(string $sql): string
    {
        return str_replace('#__', $this->prefix, $sql);
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    // === Identifier Safety ===

    /**
     * Quote an identifier (table/column name)
     */
    public function quoteIdentifier(string $name): string
    {
        $parts = explode('.', $name);
        return implode('.', array_map(
            fn($part) => '`' . str_replace('`', '``', $part) . '`',
            $parts
        ));
    }

    /**
     * Sanitise a single identifier segment
     */
    private function sanitizeIdentifier(string $identifier): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', trim($identifier, '` '));
        if ($clean === '') {
            throw new InvalidArgumentException("Invalid identifier: {$identifier}");
        }
        return $clean;
    }

    /**
     * Sanitise ORDER BY clause
     */
    private function sanitizeOrderBy(string $order): string
    {
        $parts = explode(',', $order);
        $safe = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            if (!preg_match('/^([A-Za-z0-9_.]+)(\s+(ASC|DESC))?$/i', $part, $m)) {
                throw new InvalidArgumentException("Invalid ORDER BY expression: {$part}");
            }

            $safe[] = $this->quoteIdentifier($m[1]) . (isset($m[3]) ? ' ' . strtoupper($m[3]) : '');
        }

        return implode(', ', $safe);
    }

    // === Query Building ===

    /**
     * Build WHERE clause from array
     *
     * @param array<string, mixed> $where
     * @return array{0: string, 1: array<int, mixed>} [sql_string, bindings]
     */
    private function buildWhereFragment(array $where): array
    {
        if (empty($where)) {
            return ['', []];
        }

        $clauses = [];
        $bind = [];

        foreach ($where as $col => $val) {
            $name = $this->quoteIdentifier((string) $col);

            if ($val === null) {
                $clauses[] = "{$name} IS NULL";
            } elseif (is_array($val)) {
                if ($val === []) {
                    $clauses[] = '1 = 0';
                    continue;
                }
                $placeholders = implode(', ', array_fill(0, count($val), '?'));
                $clauses[] = "{$name} IN ({$placeholders})";
                foreach ($val as $v) {
                    $bind[] = $v;
                }
            } else {
                $clauses[] = "{$name} = ?";
                $bind[] = $val;
            }
        }

        return [' WHERE ' . implode(' AND ', $clauses), $bind];
    }

    // === Legacy Compatibility ===

    /**
     * @deprecated Use parameter binding in query() instead
     */
    public function escape(?string $value): string
    {
        trigger_error(__METHOD__ . '() is deprecated. Use query() with parameters.', E_USER_DEPRECATED);
        if ($value === null) return '';
        $quoted = $this->pdo->quote($value);
        return $quoted !== false ? substr($quoted, 1, -1) : addslashes($value);
    }

    /**
     * Forward calls to PDO methods
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!method_exists($this->pdo, $name)) {
            throw new BadMethodCallException("Method {$name} does not exist.");
        }
        return $this->pdo->$name(...$arguments);
    }
}

/**
 * Query Result — structured result object
 */
class QueryResult
{
    /** @var array<int|string, mixed> */
    public array $rows = [];
    /** @var array<string, mixed> */
    public array $row = [];
    public int $num_rows = 0;
    public int $affected = 0;
    public mixed $value = null;
    /** @var array<int|string, mixed> */
    public array $pairs = [];

    public function __construct(PDOStatement $stmt, int $fetchMode = PDO::FETCH_ASSOC)
    {
        if ($stmt->columnCount() > 0) {
            $this->rows      = $stmt->fetchAll($fetchMode);
            $this->num_rows  = count($this->rows);
            $this->row       = $this->rows[0] ?? [];
            
            if ($this->num_rows > 0) {
                $firstValue = reset($this->row);
                $this->value = $firstValue !== false ? $firstValue : null;
            }
            
            if ($this->num_rows > 0 && count($this->row) === 2) {
                $keys = array_keys($this->row);
                $this->pairs = array_column($this->rows, $keys[1], $keys[0]);
            }
        } else {
            $this->affected = $stmt->rowCount();
        }
        
        $stmt->closeCursor();
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get the first column value from the first row
     * Use for COUNT(*), SUM(), etc.
     */
    public function column(mixed $default = null): mixed
    {
        return $this->value ?? $default;
    }

    /**
     * Get a single row
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return $this->row;
    }

    /**
     * Get all rows
     * @return array<int|string, mixed>
     */
    public function all(): array
    {
        return $this->rows;
    }

    /**
     * Get key-value pairs (only works when selecting 2 columns)
     * @return array<int|string, mixed>
     */
    public function pairs(): array
    {
        return $this->pairs;
    }
}