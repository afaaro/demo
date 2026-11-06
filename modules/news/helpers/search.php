<?php

declare(strict_types=1);

return static function (string $q, $db, int $limit): array {
    $hits = [];
    $like = '%' . $q . '%';
    $limit = max(1, min(20, $limit));

    try {
        $rows = $db->query(
            "SELECT p.title, p.slug, p.excerpt, p.content, p.image, p.published_at, p.created_at,
                    c.name AS category_name
             FROM `#__news_post` p
             LEFT JOIN `#__news_category` c ON c.id = p.category_id
             WHERE p.status = 'published'
               AND (p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?)
             ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC
             LIMIT {$limit}",
            [$like, $like, $like]
        )->rows ?? [];
    } catch (Throwable) {
        $rows = [];
    }

    foreach ($rows as $row) {
        $title = html_entity_decode((string) ($row['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $excerpt = trim((string) ($row['excerpt'] ?? ''));
        $snippet = $excerpt !== '' ? $excerpt : (string) ($row['content'] ?? '');
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
