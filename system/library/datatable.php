<?php
declare(strict_types=1);

namespace System\Library;

use System\Engine\Registry;

class DataTable {
    protected array $columns = [];
    protected array $rows = [];
    protected mixed $rowAttrCallback = null;
    protected array $pagination = ['total' => 0, 'limit' => 10, 'page' => 1];
    protected array $tableAttributes = ['class' => 'table table-striped table-hover align-middle mb-0'];
    protected bool $chrome = false;
    protected string $heading = '';
    protected array $headingActions = [];
    protected bool $showSearch = false;
    protected string $searchQuery = '';
    protected string $searchPlaceholder = 'Search...';
    protected array $toolbarFilters = [];
    protected array $columnSearch = [];
    protected array $sortable = [];
    protected string $sort = '';
    protected string $sortDir = 'asc';
    protected bool $filtered = false;
    protected string $emptyMessage = 'No records found.';
    protected string $noMatchMessage = 'No records match your search.';

    public function __construct(protected readonly Registry $registry) {}

    public function setColumns(array $columns): void {
        $this->columns = $columns;
    }

    public function setRows(array $rows): void {
        $this->rows = $rows;
    }

    public function setPagination(int $total, int $limit = 10, int $page = 1): void {
        $this->pagination = [
            'total' => $total,
            'limit' => max(1, $limit),
            'page'  => max(1, $page)
        ];
    }

    public function setRowAttr(callable $callback): void {
        $this->rowAttrCallback = $callback;
    }

    public function setTableAttributes(array $attributes): void {
        $this->tableAttributes = $attributes;
    }

    public function enableChrome(bool $on = true): void {
        $this->chrome = $on;
    }

    public function setHeading(string $title, array $actions = []): void {
        $this->heading = $title;
        $this->headingActions = $actions;
    }

    public function setSearch(string $q, string $placeholder = 'Search...'): void {
        $this->showSearch = true;
        $this->searchQuery = $q;
        $this->searchPlaceholder = $placeholder !== '' ? $placeholder : 'Search...';
    }

    public function setFilters(array $filters): void {
        $this->toolbarFilters = $filters;
    }

    public function setColumnSearch(array $values): void {
        $this->columnSearch = $values;
    }

    public function setSortable(array $columns, string $sort = '', string $dir = 'asc'): void {
        $this->sortable = $columns;
        $this->sort = $sort;
        $this->sortDir = strtolower($dir) === 'desc' ? 'desc' : 'asc';
    }

    public function setFiltered(bool $filtered): void {
        $this->filtered = $filtered;
    }

    public function render(): string {
        $table = $this->renderTable();
        $pagination = $this->renderPagination();
        $pager = $pagination !== ''
            ? '<div class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-3">'
                . $pagination
                . '</div>'
            : '';

        if (!$this->chrome) {
            return $table . $pager;
        }

        $html = '<div class="dataset">';
        $html .= $this->renderHeading();
        $html .= '<div class="card shadow-sm">';

        $needsForm = $this->showSearch || $this->toolbarFilters !== [] || $this->columnSearch !== [];
        if ($needsForm) {
            $html .= $this->openFilterForm();
            $toolbar = $this->renderToolbarFields();
            if ($toolbar !== '') {
                $html .= '<div class="card-body border-bottom py-3">' . $toolbar . '</div>';
            }
            $html .= '<div class="card-body p-0">' . $table . '</div>';
            $html .= '</form>';
        } else {
            $html .= '<div class="card-body p-0">' . $table . '</div>';
        }

        $html .= $pager;
        $html .= '</div></div>';

        return $html;
    }

    private function renderHeading(): string {
        if ($this->heading === '' && $this->headingActions === []) {
            return '';
        }

        $html = '<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">';
        if ($this->heading !== '') {
            $html .= '<h2 class="mb-0">' . htmlspecialchars($this->heading, ENT_QUOTES, 'UTF-8') . '</h2>';
        } else {
            $html .= '<div></div>';
        }

        if ($this->headingActions !== []) {
            $html .= '<div class="d-flex gap-2 flex-wrap">';
            foreach ($this->headingActions as $action) {
                if (!is_array($action) || empty($action['url']) || empty($action['label'])) {
                    continue;
                }
                $class = htmlspecialchars((string) ($action['class'] ?? 'btn btn-primary'), ENT_QUOTES, 'UTF-8');
                $html .= '<a href="' . htmlspecialchars((string) $action['url'], ENT_QUOTES, 'UTF-8') . '" class="' . $class . '">';
                $html .= htmlspecialchars((string) $action['label'], ENT_QUOTES, 'UTF-8');
                $html .= '</a>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function openFilterForm(): string
    {
        $route = $this->currentRoute();
        $action = function_exists('url') ? url($route) : '?route=' . urlencode($route);

        // A GET form replaces the action's query string with its own fields. When pretty URLs
        // are off the action is "index.php?route=..."; move that query into hidden inputs so
        // search/filter submits keep the route.
        $hidden = [];
        $queryPos = strpos($action, '?');
        if ($queryPos !== false) {
            parse_str((string) substr($action, $queryPos + 1), $hidden);
            $action = substr($action, 0, $queryPos);
        }

        $html = '<form id="dataset-filter" method="get" action="'
            . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')
            . '">';
        foreach ($hidden as $name => $value) {
            if (is_scalar($value)) {
                $html .= '<input type="hidden" name="' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8')
                    . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
            }
        }
        if ($this->sort !== '') {
            $html .= '<input type="hidden" name="sort" value="' . htmlspecialchars($this->sort, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<input type="hidden" name="dir" value="' . htmlspecialchars($this->sortDir, ENT_QUOTES, 'UTF-8') . '">';
        }

        return $html;
    }

    private function renderToolbarFields(): string {
        if (!$this->showSearch && $this->toolbarFilters === []) {
            return '';
        }

        $html = '<div class="row g-2 align-items-end">';

        if ($this->showSearch) {
            $html .= '<div class="col-md-4">';
            $html .= '<input type="search" name="q" class="form-control" placeholder="'
                . htmlspecialchars($this->searchPlaceholder, ENT_QUOTES, 'UTF-8')
                . '" value="' . htmlspecialchars($this->searchQuery, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '</div>';
        }

        foreach ($this->toolbarFilters as $filter) {
            if (!is_array($filter) || empty($filter['name']) || !is_array($filter['options'] ?? null)) {
                continue;
            }
            $name = (string) $filter['name'];
            $value = (string) ($filter['value'] ?? '');
            $html .= '<div class="col-md-3">';
            $html .= '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="form-select" onchange="this.form.submit()">';
            foreach ($filter['options'] as $optValue => $optLabel) {
                $selected = (string) $optValue === $value ? ' selected' : '';
                $html .= '<option value="' . htmlspecialchars((string) $optValue, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>';
                $html .= htmlspecialchars((string) $optLabel, ENT_QUOTES, 'UTF-8');
                $html .= '</option>';
            }
            $html .= '</select></div>';
        }

        $html .= '<div class="col-auto">';
        $html .= '<button type="submit" class="btn btn-primary"><i class="fa fa-search me-1"></i> Search</button>';
        if ($this->filtered) {
            $html .= ' <a href="' . $this->clearUrl() . '" class="btn btn-outline-secondary">Clear</a>';
        }
        $html .= '</div></div>';

        return $html;
    }

    private function renderTable(): string {
        $keepHeaders = $this->chrome && $this->columnSearch !== [];
        if ($this->rows === [] && !$keepHeaders) {
            return $this->emptyAlert();
        }

        $attrString = '';
        foreach ($this->tableAttributes as $k => $v) {
            $attrString .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '"';
        }

        $html = '<div class="table-responsive">';
        $html .= '<table' . $attrString . '>';
        $html .= '<thead><tr>';
        foreach ($this->columns as $key => $label) {
            $headerText = is_string($label) ? $label : ucwords(str_replace('_', ' ', (string) $key));
            $html .= '<th>' . $this->renderHeader((string) $key, $headerText) . '</th>';
        }
        $html .= '</tr>';
        $html .= $this->renderFilterRow();
        $html .= '</thead><tbody>';

        if ($this->rows === []) {
            $colspan = max(1, count($this->columns));
            $html .= '<tr><td colspan="' . $colspan . '">' . $this->emptyAlert() . '</td></tr>';
        } else {
            foreach ($this->rows as $row) {
                $attributes = '';
                if ($this->rowAttrCallback) {
                    $attrArray = ($this->rowAttrCallback)($row);
                    if (is_array($attrArray)) {
                        foreach ($attrArray as $name => $value) {
                            $attributes .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
                        }
                    }
                }

                $html .= '<tr' . $attributes . '>';
                foreach ($this->columns as $key => $column) {
                    $html .= '<td>';
                    if ($column instanceof \Closure || (is_array($column) && is_callable($column))) {
                        $html .= $column($row);
                    } elseif (isset($row[$key])) {
                        $html .= htmlspecialchars((string) $row[$key], ENT_QUOTES, 'UTF-8');
                    }
                    $html .= '</td>';
                }
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private function emptyAlert(): string
    {
        $message = $this->filtered ? $this->noMatchMessage : $this->emptyMessage;
        return '<div class="alert alert-info m-3 mb-3"><i class="fa fa-info-circle me-2"></i> '
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }

    private function renderFilterRow(): string
    {
        if ($this->columnSearch === []) {
            return '';
        }

        $html = '<tr class="dataset-column-filters">';
        foreach ($this->columns as $key => $label) {
            $key = (string) $key;
            $html .= '<th class="fw-normal py-2">';
            if (array_key_exists($key, $this->columnSearch)) {
                $headerText = is_string($label) ? $label : ucwords(str_replace('_', ' ', $key));
                $html .= '<input type="search" name="c[' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ']" '
                    . 'class="form-control form-control-sm" '
                    . 'placeholder="' . htmlspecialchars($headerText, ENT_QUOTES, 'UTF-8') . '" '
                    . 'value="' . htmlspecialchars((string) $this->columnSearch[$key], ENT_QUOTES, 'UTF-8') . '">';
            }
            $html .= '</th>';
        }
        $html .= '</tr>';

        return $html;
    }

    private function renderHeader(string $key, string $label): string {
        $labelHtml = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if (!in_array($key, $this->sortable, true)) {
            return $labelHtml;
        }

        $nextDir = ($this->sort === $key && $this->sortDir === 'asc') ? 'desc' : 'asc';
        $url = $this->buildUrl(['sort' => $key, 'dir' => $nextDir], true);
        $icon = 'fa-sort text-muted';
        if ($this->sort === $key) {
            $icon = $this->sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
        }

        return '<a href="' . $url . '" class="text-decoration-none text-body">'
            . $labelHtml
            . ' <i class="fa ' . $icon . '"></i></a>';
    }

    /**
     * Generate restricted window pagination items cluster blocks
     */
    private function renderPagination(): string {
        $total = $this->pagination['total'];
        $limit = $this->pagination['limit'];
        $current = $this->pagination['page'];
        $pages = (int)ceil($total / $limit);

        if ($pages <= 1) return '';

        // Clamp current page to valid range
        $current = max(1, min($current, $pages));

        // Calculate record window boundaries
        $startRange = (($current - 1) * $limit) + 1;
        $endRange = min($total, $current * $limit);

        $html = '<div class="text-muted small d-none d-sm-block">';
        $html .= 'Showing <strong>' . $startRange . '</strong> to <strong>' . $endRange . '</strong> of <strong>' . $total . '</strong> entries';
        $html .= '</div>';
        
        $html .= '<nav aria-label="Table Navigation Routing">';
        $html .= '<ul class="pagination pagination-sm mb-0 flex-wrap">';

        // Jump button targeting the absolute first page index
        if ($current > 3) {
            $html .= '<li class="page-item"><a class="page-link px-2" href="' . $this->buildPageUrl(1) . '">« First</a></li>';
        }

        // Generate context-window constraints (Displays current page, 2 pages behind, and 2 pages ahead)
        $startPage = max(1, $current - 2);
        $endPage = min($pages, $current + 2);

        for ($i = $startPage; $i <= $endPage; $i++) {
            $active = ($i == $current) ? 'active' : '';
            $html .= '<li class="page-item ' . $active . '">';
            $html .= '<a class="page-link" href="' . $this->buildPageUrl($i) . '">' . $i . '</a>';
            $html .= '</li>';
        }

        // Jump button targeting the absolute final page index
        if ($current < $pages - 2) {
            $html .= '<li class="page-item"><a class="page-link px-2" href="' . $this->buildPageUrl($pages) . '">Last »</a></li>';
        }

        $html .= '</ul></nav>';

        return $html;
    }

    private function currentRoute(): string
    {
        $request = $this->registry->get('request');
        $route = $request ? $request->get('route', 'string', 'home') : 'home';
        return $route !== '' ? (string) $route : 'home';
    }

    private function queryParams(): array
    {
        $request = $this->registry->get('request');
        $params = is_object($request) && method_exists($request, 'all') ? $request->all() : $_GET;
        if (!is_array($params)) {
            $params = [];
        }
        unset($params['route'], $params['page']);
        return $params;
    }

    private function buildUrl(array $overrides = [], bool $resetPage = false): string
    {
        $params = $this->queryParams();
        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }
        if ($resetPage) {
            unset($params['page']);
        }

        $route = $this->currentRoute();
        if (function_exists('url')) {
            $url = url($route, $params);
        } else {
            $query = http_build_query($params);
            $url = '?route=' . urlencode($route) . ($query ? '&' . $query : '');
        }

        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }

    private function buildPageUrl(int $pageNumber): string
    {
        $params = $this->queryParams();
        $params['page'] = $pageNumber;

        $route = $this->currentRoute();

        $url = url($route, $params);

        // $this->registry->get('log')->error('PAGINATION URL DEBUG', [
        //     'page'   => $pageNumber,
        //     'route'  => $route,
        //     'params' => $params,
        //     'url'    => $url,
        // ]);

        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }

    private function clearUrl(): string
    {
        $route = $this->currentRoute();
        $url = function_exists('url') ? url($route) : '?route=' . urlencode($route);
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}