<?php
declare(strict_types=1);

namespace System\Library;

/**
 * Page math + HTML for list screens.
 *
 * Themes: bootstrap, bootstrap-sm, tailwind, foundation.
 *
 *   $pager = Paginator::make($total, $page, 20);
 *   $rows  = $repo->search($filters, $pager->perPage, $pager->offset);
 *   echo $pager->render(fn (int $p) => $this->url->to('news', ['page' => $p]), [
 *       'theme' => 'bootstrap',
 *   ]);
 */
final class Paginator
{
    public function __construct(
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $lastPage,
        public readonly int $offset,
        public readonly int $from,
        public readonly int $to,
    ) {}

    public static function make(int $total, int $page, int $perPage): self
    {
        $total    = max(0, $total);
        $perPage  = max(1, $perPage);
        $lastPage = $total > 0 ? max(1, (int) ceil($total / $perPage)) : 1;
        $page     = min(max(1, $page), $lastPage);
        $offset   = ($page - 1) * $perPage;
        $from     = $total === 0 ? 0 : $offset + 1;
        $to       = min($total, $offset + $perPage);

        return new self($total, $page, $perPage, $lastPage, $offset, $from, $to);
    }

    public static function fromRequest(Request $request, int $total, int $perPage, string $key = 'page'): self
    {
        return self::make($total, (int) $request->get($key, 'int', 1), $perPage);
    }

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    public function hasPrev(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->lastPage;
    }

    public function summary(): string
    {
        if ($this->total === 0) {
            return '0 of 0';
        }
        return $this->from . '–' . $this->to . ' of ' . $this->total;
    }

    /**
     * Sliding window with first/last and null gaps for ellipsis.
     *
     * @return list<int|null>
     */
    public function pages(int $radius = 2): array
    {
        $last = $this->lastPage;
        if ($last <= 1) {
            return [1];
        }

        $span = ($radius * 2) + 1;
        if ($last <= $span + 4) {
            return range(1, $last);
        }

        $start = max(2, $this->page - $radius);
        $end   = min($last - 1, $this->page + $radius);

        if ($this->page <= $radius + 2) {
            $end = min($last - 1, $span);
        }
        if ($this->page >= $last - $radius - 1) {
            $start = max(2, $last - $span + 1);
        }

        $out = [1];
        if ($start > 2) {
            $out[] = null;
        }
        for ($i = $start; $i <= $end; $i++) {
            $out[] = $i;
        }
        if ($end < $last - 1) {
            $out[] = null;
        }
        $out[] = $last;

        return $out;
    }

    /**
     * @param callable(int): string $urlForPage
     * @param array{
     *     theme?: string,
     *     class?: string,
     *     window?: int,
     *     arrows?: bool,
     *     prev?: string,
     *     next?: string,
     *     label?: string
     * } $options
     */
    public function render(callable $urlForPage, array $options = []): string
    {
        if (!$this->hasPages()) {
            return '';
        }

        $theme  = strtolower(trim((string) ($options['theme'] ?? 'bootstrap')));
        $window = max(1, (int) ($options['window'] ?? 2));
        $arrows = (bool) ($options['arrows'] ?? true);
        $prev   = (string) ($options['prev'] ?? 'Previous');
        $next   = (string) ($options['next'] ?? 'Next');
        $label  = (string) ($options['label'] ?? 'Pagination');
        $class  = trim((string) ($options['class'] ?? ''));

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return match ($theme) {
            'bootstrap', 'bootstrap-sm' => $this->renderBootstrap(
                $urlForPage, $e, $window, $arrows, $prev, $next, $label, $class, $theme === 'bootstrap-sm'
            ),
            'tailwind' => $this->renderTailwind(
                $urlForPage, $e, $window, $arrows, $prev, $next, $label, $class
            ),
            'foundation' => $this->renderFoundation(
                $urlForPage, $e, $window, $arrows, $prev, $next, $label, $class
            ),
            default => $this->renderBootstrap(
                $urlForPage, $e, $window, $arrows, $prev, $next, $label, $class, false
            ),
        };
    }

    /**
     * @param callable(int): string $urlForPage
     * @param callable(string): string $e
     */
    private function renderBootstrap(
        callable $urlForPage,
        callable $e,
        int $window,
        bool $arrows,
        string $prev,
        string $next,
        string $label,
        string $class,
        bool $small,
    ): string {
        $ul = trim('pagination' . ($small ? ' pagination-sm' : '') . ' ' . $class);
        $html  = '<nav class="mt-3" aria-label="' . $e($label) . '"><ul class="' . $e($ul) . '">';

        if ($arrows) {
            $html .= $this->hasPrev()
                ? '<li class="page-item"><a class="page-link" rel="prev" href="' . $e($urlForPage($this->page - 1)) . '">' . $e($prev) . '</a></li>'
                : '<li class="page-item disabled"><span class="page-link">' . $e($prev) . '</span></li>';
        }

        foreach ($this->pages($window) as $item) {
            if ($item === null) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
                continue;
            }
            if ($item === $this->page) {
                $html .= '<li class="page-item active"><a class="page-link" href="' . $e($urlForPage($item)) . '" aria-current="page">' . $item . '</a></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="' . $e($urlForPage($item)) . '">' . $item . '</a></li>';
            }
        }

        if ($arrows) {
            $html .= $this->hasNext()
                ? '<li class="page-item"><a class="page-link" rel="next" href="' . $e($urlForPage($this->page + 1)) . '">' . $e($next) . '</a></li>'
                : '<li class="page-item disabled"><span class="page-link">' . $e($next) . '</span></li>';
        }

        return $html . '</ul></nav>';
    }

    /**
     * @param callable(int): string $urlForPage
     * @param callable(string): string $e
     */
    private function renderTailwind(
        callable $urlForPage,
        callable $e,
        int $window,
        bool $arrows,
        string $prev,
        string $next,
        string $label,
        string $class,
    ): string {
        $nav = trim('flex flex-wrap items-center justify-center gap-1 ' . $class);
        $btn = 'inline-flex items-center justify-center min-w-8 px-3 py-1.5 text-sm rounded-md';
        $idle = $btn . ' text-gray-700 hover:bg-gray-100';
        $on   = $btn . ' bg-gray-900 text-white';
        $off  = $btn . ' text-gray-400 cursor-not-allowed';

        $html = '<nav class="' . $e($nav) . '" aria-label="' . $e($label) . '">';

        if ($arrows) {
            $html .= $this->hasPrev()
                ? '<a class="' . $e($idle) . '" rel="prev" href="' . $e($urlForPage($this->page - 1)) . '">' . $e($prev) . '</a>'
                : '<span class="' . $e($off) . '">' . $e($prev) . '</span>';
        }

        foreach ($this->pages($window) as $item) {
            if ($item === null) {
                $html .= '<span class="' . $e($off) . '">…</span>';
                continue;
            }
            if ($item === $this->page) {
                $html .= '<a class="' . $e($on) . '" href="' . $e($urlForPage($item)) . '" aria-current="page">' . $item . '</a>';
            } else {
                $html .= '<a class="' . $e($idle) . '" href="' . $e($urlForPage($item)) . '">' . $item . '</a>';
            }
        }

        if ($arrows) {
            $html .= $this->hasNext()
                ? '<a class="' . $e($idle) . '" rel="next" href="' . $e($urlForPage($this->page + 1)) . '">' . $e($next) . '</a>'
                : '<span class="' . $e($off) . '">' . $e($next) . '</span>';
        }

        return $html . '</nav>';
    }

    /**
     * @param callable(int): string $urlForPage
     * @param callable(string): string $e
     */
    private function renderFoundation(
        callable $urlForPage,
        callable $e,
        int $window,
        bool $arrows,
        string $prev,
        string $next,
        string $label,
        string $class,
    ): string {
        $ul = trim('pagination ' . $class);
        $html = '<nav aria-label="' . $e($label) . '"><ul class="' . $e($ul) . '" role="navigation">';

        if ($arrows) {
            if ($this->hasPrev()) {
                $html .= '<li class="pagination-previous"><a rel="prev" href="'
                      . $e($urlForPage($this->page - 1)) . '">' . $e($prev)
                      . ' <span class="show-for-sr">page</span></a></li>';
            } else {
                $html .= '<li class="pagination-previous disabled">' . $e($prev)
                      . ' <span class="show-for-sr">page</span></li>';
            }
        }

        foreach ($this->pages($window) as $item) {
            if ($item === null) {
                $html .= '<li class="ellipsis" aria-hidden="true"></li>';
                continue;
            }
            if ($item === $this->page) {
                $html .= '<li class="current"><span class="show-for-sr">You\'re on page</span> ' . $item . '</li>';
            } else {
                $html .= '<li><a href="' . $e($urlForPage($item)) . '" aria-label="Page ' . $item . '">' . $item . '</a></li>';
            }
        }

        if ($arrows) {
            if ($this->hasNext()) {
                $html .= '<li class="pagination-next"><a rel="next" href="'
                      . $e($urlForPage($this->page + 1)) . '">' . $e($next)
                      . ' <span class="show-for-sr">page</span></a></li>';
            } else {
                $html .= '<li class="pagination-next disabled">' . $e($next)
                      . ' <span class="show-for-sr">page</span></li>';
            }
        }

        return $html . '</ul></nav>';
    }
}
