<?php
declare(strict_types=1);

/**
 * Site search provider for Forum threads.
 */
return static function (string $q, $db, int $limit): array {
    $hits = [];
    $like = '%' . $q . '%';
    $limit = max(1, min(20, $limit));

    try {
        $rows = $db->query(
            "SELECT t.`id`, t.`title`, t.`slug`, t.`post_count`, t.`last_post_at`, t.`created_at`,
                    c.`name` AS category_name,
                    (
                        SELECT LEFT(p.`content`, 240)
                          FROM `#__forum_posts` p
                         WHERE p.`thread_id` = t.`id` AND p.`is_deleted` = 0
                         ORDER BY p.`id` ASC
                         LIMIT 1
                    ) AS snippet
               FROM `#__forum_threads` t
               LEFT JOIN `#__forum_categories` c ON c.`id` = t.`category_id`
              WHERE t.`is_deleted` = 0
                AND (
                     t.`title` LIKE ?
                  OR EXISTS (
                        SELECT 1 FROM `#__forum_posts` p
                         WHERE p.`thread_id` = t.`id`
                           AND p.`is_deleted` = 0
                           AND p.`content` LIKE ?
                     )
                )
              ORDER BY COALESCE(t.`last_post_at`, t.`created_at`) DESC, t.`id` DESC
              LIMIT {$limit}",
            [$like, $like]
        )->rows ?? [];
    } catch (Throwable) {
        $rows = [];
    }

    foreach ($rows as $row) {
        $title = trim((string) ($row['title'] ?? ''));
        $snippet = html_entity_decode(
            trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($row['snippet'] ?? ''))) ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
        if (mb_strlen($snippet) > 160) {
            $snippet = mb_substr($snippet, 0, 159) . '…';
        }

        $meta = trim((string) ($row['category_name'] ?? 'Forum'));
        $replies = max(0, (int) ($row['post_count'] ?? 0) - 1);
        $meta .= ' · ' . $replies . ' repl' . ($replies === 1 ? 'y' : 'ies');

        $hits[] = [
            'title'   => $title !== '' ? $title : 'Thread #' . (int) $row['id'],
            'url'     => url('forum/thread', ['id' => (int) $row['id']]),
            'snippet' => $snippet,
            'meta'    => $meta,
            'image'   => '',
        ];
    }

    return [
        'label' => 'Forum',
        'more'  => url('forum/search', ['q' => $q]),
        'hits'  => $hits,
    ];
};
