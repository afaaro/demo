<?php
declare(strict_types=1);

/**
 * Site search provider for Store catalog (active products).
 */
return static function (string $q, $db, int $limit): array {
    $hits = [];
    $like = '%' . $q . '%';
    $limit = max(1, min(20, $limit));

    try {
        $rows = $db->query(
            "SELECT p.`id`, p.`sku`, p.`name`, p.`image`, p.`description`, p.`brand`
               FROM `#__store_products` p
              WHERE p.`active` = 1
                AND (
                     p.`name` LIKE ?
                  OR p.`sku` LIKE ?
                  OR p.`barcode` LIKE ?
                  OR p.`brand` LIKE ?
                  OR p.`description` LIKE ?
                )
              ORDER BY p.`name` ASC, p.`id` ASC
              LIMIT {$limit}",
            [$like, $like, $like, $like, $like]
        )->rows ?? [];
    } catch (Throwable) {
        $rows = [];
    }

    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        $sku = trim((string) ($row['sku'] ?? ''));
        $snippet = html_entity_decode(
            trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($row['description'] ?? ''))) ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
        if (mb_strlen($snippet) > 160) {
            $snippet = mb_substr($snippet, 0, 159) . '…';
        }

        $meta = $sku !== '' ? 'SKU ' . $sku : 'Product';
        $brand = trim((string) ($row['brand'] ?? ''));
        if ($brand !== '') {
            $meta .= ' · ' . $brand;
        }

        $hits[] = [
            'title'   => $name !== '' ? $name : ($sku !== '' ? $sku : 'Product #' . (int) $row['id']),
            'url'     => url('store/admin/products/edit', ['id' => (int) $row['id']]),
            'snippet' => $snippet,
            'meta'    => $meta,
            'image'   => trim((string) ($row['image'] ?? '')),
        ];
    }

    return [
        'label' => 'Store',
        'more'  => url('store/admin/products', ['q' => $q]),
        'hits'  => $hits,
    ];
};
