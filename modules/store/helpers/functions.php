<?php
declare(strict_types=1);

/**
 * Shared store admin HTML helpers.
 */

if (!function_exists('store_truncate_text')) {
    function store_truncate_text(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, max(1, $max - 1)) . '…';
    }
}

if (!function_exists('store_escape_html')) {
    function store_escape_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('store_render_short_skus')) {
    /**
     * Plain short Product SKUs cell: "{sku} x{qty}" per line.
     *
     * @param array<int, array<string, mixed>> $items
     * @param callable(int): string|null       $productUrl  optional product edit URL builder
     */
    function store_render_short_skus(array $items, ?callable $productUrl = null, int $maxShow = 3): string
    {
        if ($items === []) {
            return '<span class="text-muted">—</span>';
        }

        $html  = '';
        $shown = 0;
        foreach ($items as $item) {
            if ($shown >= $maxShow) {
                break;
            }
            $sku       = trim((string) ($item['external_sku'] ?? ''));
            $title     = trim((string) ($item['title'] ?? ''));
            $qty       = max(1, (int) ($item['qty'] ?? 1));
            $productId = (int) ($item['product_id'] ?? 0);
            if ($sku === '' && $title === '') {
                continue;
            }

            $label = $sku !== '' ? $sku : store_truncate_text($title, 42);
            $line  = store_escape_html($label) . ' x' . $qty;
            $tip   = $title !== '' && strcasecmp($title, $label) !== 0 ? $title : '';

            $html .= ($html !== '' ? '<br>' : '');
            if ($productId > 0 && $productUrl !== null) {
                $href = (string) $productUrl($productId);
                $html .= '<a class="sf-sku-link" href="' . store_escape_html($href) . '"'
                    . ($tip !== '' ? ' title="' . store_escape_html($tip) . '"' : '') . '>'
                    . $line . '</a>';
            } elseif ($tip !== '') {
                $html .= '<span title="' . store_escape_html($tip) . '">' . $line . '</span>';
            } else {
                $html .= $line;
            }
            $shown++;
        }

        $extra = count($items) - $shown;
        if ($extra > 0) {
            $html .= '<br><span class="text-muted">+' . $extra . ' more</span>';
        }

        return $html !== '' ? $html : '<span class="text-muted">—</span>';
    }
}

if (!function_exists('store_render_item_titles')) {
    /**
     * Truncated product titles with qty (Items column).
     *
     * @param array<int, array<string, mixed>> $items
     * @param callable(int): string|null       $productUrl
     */
    function store_render_item_titles(array $items, ?callable $productUrl = null, int $maxShow = 3): string
    {
        if ($items === []) {
            return '<span class="text-muted">—</span>';
        }

        $html  = '';
        $shown = 0;
        foreach ($items as $item) {
            if ($shown >= $maxShow) {
                break;
            }
            $sku       = trim((string) ($item['external_sku'] ?? ''));
            $title     = trim((string) ($item['title'] ?? ''));
            $qty       = max(1, (int) ($item['qty'] ?? 1));
            $productId = (int) ($item['product_id'] ?? 0);
            if ($sku === '' && $title === '') {
                continue;
            }

            $label = $title !== '' ? store_truncate_text($title, 42) : $sku;
            $html .= ($html !== '' ? '<br>' : '');
            if ($productId > 0 && $productUrl !== null) {
                $href = (string) $productUrl($productId);
                $html .= '<a href="' . store_escape_html($href) . '">' . store_escape_html($label) . '</a>';
            } else {
                $html .= store_escape_html($label);
            }
            $html .= ' <span class="text-muted">×' . $qty . '</span>';
            $shown++;
        }

        $extra = count($items) - $shown;
        if ($extra > 0) {
            $html .= '<br><span class="text-muted">+' . $extra . ' more</span>';
        }

        return $html !== '' ? $html : '<span class="text-muted">—</span>';
    }
}
