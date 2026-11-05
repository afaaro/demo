<?php
declare(strict_types=1);

namespace System\Library;

class Pagination {
    protected int $total = 0;
    protected int $page = 1;
    protected int $limit = 10;
    protected int $numLinks = 2; 
    protected string $urlPattern = '';

    /**
     * Configure Pagination Parameters
     */
    public function __construct(array $config = []) {
        $this->total   = max(0, (int)($config['total'] ?? 0));
        $this->page    = max(1, (int)($config['page'] ?? 1));
        
        // Prevent Division by Zero
        $this->limit   = max(1, (int)($config['limit'] ?? 10)); 
        
        // Ensure we always show at least 1 link on either side
        $this->numLinks = max(1, (int)($config['numLinks'] ?? 2)); 
        
        $this->urlPattern = (string)($config['urlPattern'] ?? '');
    }

    /**
     * Generate Bootstrap Pagination Component HTML
     */
    public function render(): string {
        // If all records fit on one page, don't render anything
        if ($this->total <= $this->limit) {
            return '';
        }

        $totalPages = (int)ceil($this->total / $this->limit);
        $currentPage = max(1, min($this->page, $totalPages));

        $html = '<nav aria-label="Page navigation">';
        $html .= '<ul class="pagination justify-content-center">';

        // --- 1. FIRST & PREVIOUS BUTTONS ---
        if ($currentPage > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $this->escUrl(1) . '" title="First Page"><i class="fas fa-angle-double-left"></i></a></li>';
            $html .= '<li class="page-item"><a class="page-link" href="' . $this->escUrl($currentPage - 1) . '" title="Previous"><i class="fas fa-angle-left"></i></a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link"><i class="fas fa-angle-double-left"></i></span></li>';
            $html .= '<li class="page-item disabled"><span class="page-link"><i class="fas fa-angle-left"></i></span></li>';
        }

        // --- 2. CALCULATE SLIDING WINDOW RANGE ---
        $start = max(1, $currentPage - $this->numLinks);
        $end = min($totalPages, $currentPage + $this->numLinks);

        // Adjust boundaries if we are hitting edge limits
        if ($currentPage - $start < $this->numLinks) {
            $end = min($totalPages, $end + ($this->numLinks - ($currentPage - $start)));
        }
        if ($end - $currentPage < $this->numLinks) {
            $start = max(1, $start - ($this->numLinks - ($end - $currentPage)));
        }

        // --- 3. RENDER NUMBERED DIGIT LINKS ---
        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $this->escUrl(1) . '">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($currentPage === $i) {
                $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $i . '</span></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="' . $this->escUrl($i) . '">' . $i . '</a></li>';
            }
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
            $html .= '<li class="page-item"><a class="page-link" href="' . $this->escUrl($totalPages) . '">' . $totalPages . '</a></li>';
        }

        // --- 4. NEXT & LAST BUTTONS ---
        if ($currentPage < $totalPages) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $this->escUrl($currentPage + 1) . '" title="Next"><i class="fas fa-angle-right"></i></a></li>';
            $html .= '<li class="page-item"><a class="page-link" href="' . $this->escUrl($totalPages) . '" title="Last Page"><i class="fas fa-angle-double-right"></i></a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link"><i class="fas fa-angle-right"></i></span></li>';
            $html .= '<li class="page-item disabled"><span class="page-link"><i class="fas fa-angle-double-right"></i></span></li>';
        }

        $html .= '</ul>';
        $html .= '</nav>';

        return $html;
    }

    /**
     * Helper to safely escape URLs for HTML attributes
     */
    protected function escUrl(int $pageNumber): string {
        return htmlspecialchars($this->createUrl($pageNumber), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Swap dynamic substitution markers with active digits
     */
    protected function createUrl(int $pageNumber): string {
        if (!empty($this->urlPattern)) {
            // Check if pattern contains the placeholder
            if (str_contains($this->urlPattern, '{page}')) {
                return str_replace('{page}', (string)$pageNumber, $this->urlPattern);
            }
            
            // If the pattern is provided but missing the placeholder, append it safely
            $separator = (str_contains($this->urlPattern, '?') ? '&' : '?');
            return rtrim($this->urlPattern, '?&') . $separator . 'page=' . $pageNumber;
        }

        // Fallback using the core URL router
        if (function_exists('url') && function_exists('registry')) {
            $request = registry()->get('request');
            $route = $request->get('route') ?: 'home';
            return url($route, ['page' => $pageNumber]);
        }

        // Absolute fallback
        return '?page=' . $pageNumber;
    }
}