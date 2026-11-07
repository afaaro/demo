<?php
declare(strict_types=1);

return static function (string $q, $db, int $limit): array {
    $hits = [];
    $like = '%' . $q . '%';
    $limit = max(1, min(20, $limit));

    try {
        $rows = $db->query(
            "SELECT n.`title`, n.`slug`, n.`excerpt`, n.`body`, n.`image`, n.`published_at`, n.`created_at`,
                    c.`name` AS category_name
               FROM `#__news` n
               LEFT JOIN `#__news_categories` c ON c.`id` = n.`category_id`
              WHERE n.`status` = 'published'
                AND (n.`title` LIKE ? OR n.`excerpt` LIKE ? OR n.`body` LIKE ?)
              ORDER BY COALESCE(n.`published_at`, n.`created_at`) DESC, n.`id` DESC
              LIMIT {$limit}",
            [$like, $like, $like]
        )->rows ?? [];
    } catch (Throwable) {
        $rows = [];
    }

    foreach ($rows as $row) {
        $title = html_entity_decode((string) ($row['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $excerpt = trim((string) ($row['excerpt'] ?? ''));
        $snippet = $excerpt !== '' ? $excerpt : (string) ($row['body'] ?? '');
        $snippet = html_entity_decode(trim(preg_replace('/\s+/u', ' ', strip_tags($snippet)) ?? ''), ENT_QUOTES, 'UTF-8');
        if (mb_strlen($snippet) > 180) {
            $snippet = mb_substr($snippet, 0, 179) . '…';
        }

        $when = (string) ($row['published_at'] ?? $row['created_at'] ?? '');
        $meta = html_entity_decode((string) ($row['category_name'] ?? 'News'), ENT_QUOTES, 'UTF-8');
        if ($when !== '') {
            $ts = strtotime($when);
            if ($ts !== false) {
                $meta .= ' · ' . date('j M Y', $ts);
            }
        }

        $hits[] = [
            'title'   => $title,
            'url'     => url('news/view', ['slug' => (string) $row['slug']]),
            'snippet' => $snippet,
            'meta'    => $meta,
            'image'   => trim((string) ($row['image'] ?? '')),
        ];
    }

    return [
        'label' => 'News',
        'more'  => url('news', ['q' => $q]),
        'hits'  => $hits,
    ];
};
