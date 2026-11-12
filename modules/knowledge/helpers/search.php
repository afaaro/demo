<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

return static function (string $q, $db, int $limit): array {
    $hits = [];
    $like = '%' . $q . '%';
    $limit = max(1, min(20, $limit));
    $cfg = knowledge_module_config();
    $default = $cfg['default_locale'];

    try {
        $rows = $db->query(
            "SELECT e.`slug`, e.`updated_at`, e.`created_at`,
                    d.`name` AS domain_name,
                    COALESCE(tr.`title`, trd.`title`, '') AS title,
                    COALESCE(tr.`summary`, trd.`summary`, '') AS summary,
                    COALESCE(tr.`body`, trd.`body`, '') AS body
               FROM `#__knowledge_entries` e
               LEFT JOIN `#__knowledge_domains` d ON d.`id` = e.`domain_id`
               LEFT JOIN `#__knowledge_entry_translations` trd
                 ON trd.`entry_id` = e.`id` AND trd.`locale` = ?
               LEFT JOIN `#__knowledge_entry_translations` tr
                 ON tr.`entry_id` = e.`id` AND tr.`locale` = ?
              WHERE e.`status` = 'published'
                AND (
                    COALESCE(tr.`title`, trd.`title`) LIKE ?
                    OR COALESCE(tr.`aliases`, trd.`aliases`) LIKE ?
                    OR COALESCE(tr.`summary`, trd.`summary`) LIKE ?
                    OR COALESCE(tr.`body`, trd.`body`) LIKE ?
                )
              ORDER BY e.`updated_at` DESC, e.`id` DESC
              LIMIT {$limit}",
            [$default, $default, $like, $like, $like, $like]
        )->rows ?? [];
    } catch (Throwable) {
        $rows = [];
    }

    foreach ($rows as $row) {
        $title = html_entity_decode((string) ($row['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (trim($title) === '') {
            $title = (string) ($row['slug'] ?? 'Entry');
        }
        $summary = trim((string) ($row['summary'] ?? ''));
        $snippet = $summary !== '' ? $summary : (string) ($row['body'] ?? '');
        $snippet = html_entity_decode(trim(preg_replace('/\s+/u', ' ', strip_tags($snippet)) ?? ''), ENT_QUOTES, 'UTF-8');
        if (mb_strlen($snippet) > 180) {
            $snippet = mb_substr($snippet, 0, 179) . '…';
        }

        $when = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');
        $meta = html_entity_decode((string) ($row['domain_name'] ?? 'Knowledge'), ENT_QUOTES, 'UTF-8');
        if ($when !== '') {
            $ts = strtotime($when);
            if ($ts !== false) {
                $meta .= ' · ' . date('j M Y', $ts);
            }
        }

        $hits[] = [
            'title'   => $title,
            'url'     => url('knowledge/view', ['slug' => (string) $row['slug']]),
            'snippet' => $snippet,
            'meta'    => $meta,
            'image'   => '',
        ];
    }

    return [
        'label' => 'Knowledge',
        'more'  => url('knowledge', ['q' => $q]),
        'hits'  => $hits,
    ];
};
