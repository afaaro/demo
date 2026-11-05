<?php
declare(strict_types=1);

namespace System\Library;

use InvalidArgumentException;
use System\Engine\Registry;

class Dataset
{
    private const RESERVED = ['q', 'sort', 'dir', 'page', 'per_page', 'route'];

    private string $table = '';
    private array $searchable = [];
    private array $sortable = [];
    private string $defaultSort = 'id';
    private string $defaultDir = 'desc';
    private array $filters = [];
    private string $heading = '';
    private array $headingActions = [];
    private array $columns = [];
    private mixed $queryCallback = null;
    private int $perPage = 10;
    private array $select = ['*'];
    private string $searchPlaceholder = 'Search...';

    public function __construct(protected readonly Registry $registry) {}

    public function from(string $table): self
    {
        $this->reset();
        $this->table = $this->normalizeTable($table);
        return $this;
    }

    public function select(array $columns): self
    {
        $clean = [];
        foreach ($columns as $column) {
            $column = trim((string) $column);
            if ($column === '*') {
                $this->select = ['*'];
                return $this;
            }
            $clean[] = $this->assertIdent($column);
        }
        if ($clean !== []) {
            $this->select = $clean;
        }
        return $this;
    }

    public function searchable(array $columns): self
    {
        $this->searchable = $this->idents($columns);
        return $this;
    }

    public function sortable(array $columns): self
    {
        $this->sortable = $this->idents($columns);
        return $this;
    }

    public function defaultSort(string $column, string $dir = 'desc'): self
    {
        $this->defaultSort = $this->assertIdent($column);
        $this->defaultDir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
        return $this;
    }

    public function filter(string $name, string $type = 'select', array $options = [], ?callable $apply = null): self
    {
        $name = trim($name);
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Invalid filter name.');
        }
        if (in_array($name, self::RESERVED, true)) {
            throw new InvalidArgumentException('Filter name is reserved.');
        }

        $this->filters[$name] = [
            'type'    => $type === 'text' ? 'text' : 'select',
            'options' => $options,
            'apply'   => $apply,
        ];
        return $this;
    }

    public function heading(string $title, array $actions = []): self
    {
        $this->heading = $title;
        $this->headingActions = $actions;
        return $this;
    }

    public function columns(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    public function query(callable $callback): self
    {
        $this->table = '';
        $this->queryCallback = $callback;

        return $this;
    }

    public function perPage(int $n): self
    {
        $this->perPage = max(1, min(100, $n));
        return $this;
    }

    public function searchPlaceholder(string $text): self
    {
        $this->searchPlaceholder = $text !== '' ? $text : 'Search...';
        return $this;
    }

    public function render(): string
    {
        // $reflection = new \ReflectionClass($this);

        // $this->registry->get('log')->error('DATASET CLASS INFO', [
        //     'class' => get_class($this),
        //     'file'  => $reflection->getFileName(),
        // ]);
        try {
            $state = $this->state();
            $result = $this->queryCallback !== null
                ? $this->runCallback($state)
                : $this->runQuery($state);

            $table = new DataTable($this->registry);
            $table->setColumns($this->columns);
            $table->setRows($result['rows']);
            $table->setPagination($result['total'], $state['per_page'], $state['page']);
            $table->enableChrome(true);
            $table->setHeading($this->heading, $this->headingActions);
            if ($this->searchable !== [] || $this->queryCallback !== null) {
                $table->setSearch($state['q'], $this->searchPlaceholder);
            }
            $table->setFilters($this->toolbarFilters($state['filters']));
            $table->setColumnSearch($this->columnSearchValues($state['columns']));
            $table->setSortable($this->sortable, $state['sort'], $state['dir']);
            $table->setFiltered($this->isFiltered($state));

            $html = $table->render();

            return $html;
        } finally {
            $this->reset();
        }
    }

    private function state(): array
    {
        $request = $this->registry->get('request');

        // Listing controls come from the query string only.
        $q = trim((string) $request->query('q', 'string', ''));

        $sort = trim((string) $request->query('sort', 'string', $this->defaultSort));

        if ($this->sortable !== [] && !in_array($sort, $this->sortable, true)) {
            $sort = in_array($this->defaultSort, $this->sortable, true)
                ? $this->defaultSort
                : $this->sortable[0];
        } elseif ($this->sortable === [] && $sort === '') {
            $sort = $this->defaultSort;
        }

        $dir = strtolower((string) $request->query('dir', 'string', $this->defaultDir));
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        $page = max(1, (int) $request->query('page', 'int', 1));

        $perPage = (int) $request->query('per_page', 'int', $this->perPage);
        $perPage = max(1, min(100, $perPage > 0 ? $perPage : $this->perPage));

        $filters = [];

        foreach ($this->filters as $name => $def) {
            $value = trim((string) $request->query($name, 'string', ''));

            if ($value === '') {
                continue;
            }

            if ($def['type'] === 'select' && $def['options'] !== []) {
                $allowed = array_map('strval', array_keys($def['options']));

                if (!in_array($value, $allowed, true)) {
                    continue;
                }
            }

            $filters[$name] = $value;
        }

        $columns = [];
        $rawColumns = $request->query('c', 'raw', []);

        if (!is_array($rawColumns)) {
            $rawColumns = [];
        }

        foreach ($this->searchable as $column) {
            $value = $rawColumns[$column] ?? '';

            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $columns[$column] = $value;
            }
        }

        return [
            'q'        => $q,
            'sort'     => $sort,
            'dir'      => $dir,
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => ($page - 1) * $perPage,
            'filters'  => $filters,
            'columns'  => $columns,
        ];
    }

    private function runCallback(array $state): array
    {
        $db = $this->registry->get('db');
        $result = ($this->queryCallback)($db, $state);
        if (!is_array($result)) {
            return ['rows' => [], 'total' => 0];
        }

        $rows = $result['rows'] ?? [];
        return [
            'rows'  => is_array($rows) ? $rows : [],
            'total' => (int) ($result['total'] ?? 0),
        ];
    }

    private function runQuery(array $state): array
    {
        if ($this->table === '') {
            throw new InvalidArgumentException('Dataset source table is not set.');
        }

        $db = $this->registry->get('db');
        [$whereSql, $params] = $this->buildWhere($state);

        $countSql = "SELECT COUNT(*) AS total FROM {$this->table}{$whereSql}";
        $total = (int) ($db->query($countSql, $params)->row['total'] ?? 0);

        $select = $this->select === ['*']
            ? '*'
            : implode(', ', array_map(static fn(string $c) => '`' . $c . '`', $this->select));

        $order = '';
        if ($this->isIdent($state['sort'])) {
            $order = ' ORDER BY `' . $state['sort'] . '` ' . ($state['dir'] === 'asc' ? 'ASC' : 'DESC');
        }

        $listSql = "SELECT {$select} FROM {$this->table}{$whereSql}{$order} LIMIT ?, ?";
        $listParams = $params;
        $listParams[] = (int) $state['offset'];
        $listParams[] = (int) $state['per_page'];

        try {
            $result = $db->query($listSql, $listParams);

            $rows = $result->rows ?? [];

        } catch (\Throwable $e) {
            $this->registry->get('log')->error('DATASET QUERY FAILED', [
                'message' => $e->getMessage(),
                'sql'     => $listSql,
                'params'  => $listParams,
            ]);

            throw $e;
        }

        return [
            'rows'  => is_array($rows) ? $rows : [],
            'total' => $total,
        ];
    }

    private function buildWhere(array $state): array
    {
        $clauses = [];
        $params = [];

        if ($state['q'] !== '' && $this->searchable !== []) {
            $likes = [];
            $needle = $this->likeContains($state['q']);
            foreach ($this->searchable as $column) {
                $likes[] = '`' . $column . '` LIKE ? ESCAPE \'\\\\\'';
                $params[] = $needle;
            }
            $clauses[] = '(' . implode(' OR ', $likes) . ')';
        }

        foreach ($state['columns'] ?? [] as $column => $value) {
            if (!$this->isIdent($column) || !in_array($column, $this->searchable, true)) {
                continue;
            }
            $clauses[] = '`' . $column . '` LIKE ? ESCAPE \'\\\\\'';
            $params[] = $this->likeContains($value);
        }

        foreach ($state['filters'] as $name => $value) {
            $def = $this->filters[$name] ?? null;
            if ($def === null) {
                continue;
            }

            if (is_callable($def['apply'])) {
                $applied = ($def['apply'])($value);
                if (!is_array($applied) || !isset($applied['sql']) || !isset($applied['params'])) {
                    continue;
                }
                $clauses[] = '(' . (string) $applied['sql'] . ')';
                foreach ((array) $applied['params'] as $param) {
                    $params[] = $param;
                }
                continue;
            }

            if (!$this->isIdent($name)) {
                continue;
            }
            $clauses[] = '`' . $name . '` = ?';
            $params[] = $value;
        }

        if ($clauses === []) {
            return ['', []];
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function toolbarFilters(array $active): array
    {
        $out = [];
        foreach ($this->filters as $name => $def) {
            if ($def['type'] !== 'select') {
                continue;
            }
            $out[] = [
                'name'    => $name,
                'options' => $def['options'],
                'value'   => (string) ($active[$name] ?? ''),
            ];
        }
        return $out;
    }

    private function columnSearchValues(array $active): array
    {
        $out = [];
        foreach ($this->searchable as $column) {
            $out[$column] = (string) ($active[$column] ?? '');
        }
        return $out;
    }

    private function isFiltered(array $state): bool
    {
        return $state['q'] !== '' || $state['filters'] !== [] || $state['columns'] !== [];
    }

    private function likeContains(string $q): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        return '%' . $escaped . '%';
    }

    private function normalizeTable(string $table): string
    {
        $table = trim($table);
        if (str_starts_with($table, '#__')) {
            $table = substr($table, 3);
        }
        return '#__' . $this->assertIdent($table);
    }

    private function idents(array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            $out[] = $this->assertIdent((string) $column);
        }
        return $out;
    }

    private function assertIdent(string $name): string
    {
        $name = trim($name);
        if (!$this->isIdent($name)) {
            throw new InvalidArgumentException('Invalid identifier.');
        }
        return $name;
    }

    private function isIdent(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $name);
    }

    private function reset(): void
    {
        $this->table = '';
        $this->searchable = [];
        $this->sortable = [];
        $this->defaultSort = 'id';
        $this->defaultDir = 'desc';
        $this->filters = [];
        $this->heading = '';
        $this->headingActions = [];
        $this->columns = [];
        $this->queryCallback = null;
        $this->perPage = 10;
        $this->select = ['*'];
        $this->searchPlaceholder = 'Search...';
    }
}
