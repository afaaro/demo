<?php
declare(strict_types=1);

return static function (string $q, $db, int $limit): array {
    $hits = [];
    $like = '%' . $q . '%';
    $limit = max(1, min(20, $limit));

    try {
        $videos = $db->query(
            "SELECT v.video_id, v.title, v.thumbnail_path, v.created_at,
                    c.title AS channel_title
             FROM `#__media_videos` v
             INNER JOIN `#__media_channels` c ON c.channel_id = v.channel_id
             WHERE v.status = 'public'
               AND (v.title LIKE ? OR c.title LIKE ?)
             ORDER BY v.created_at DESC
             LIMIT {$limit}",
            [$like, $like]
        )->rows ?? [];
    } catch (Throwable) {
        $videos = [];
    }

    foreach ($videos as $row) {
        $hits[] = [
            'title'   => (string) ($row['title'] ?? ''),
            'url'     => url('media/watch', ['id' => (string) $row['video_id']]),
            'snippet' => '',
            'meta'    => trim((string) ($row['channel_title'] ?? 'Video')),
            'image'   => trim((string) ($row['thumbnail_path'] ?? '')),
        ];
    }

    $remaining = $limit - count($hits);
    if ($remaining > 0) {
        try {
            $channels = $db->query(
                "SELECT channel_id, title, slug, avatar
                 FROM `#__media_channels`
                 WHERE title LIKE ? OR slug LIKE ?
                 ORDER BY title ASC
                 LIMIT {$remaining}",
                [$like, $like]
            )->rows ?? [];
        } catch (Throwable) {
            $channels = [];
        }

        foreach ($channels as $row) {
            $hits[] = [
                'title'   => (string) ($row['title'] ?? ''),
                'url'     => url('media/channel', ['id' => (int) $row['channel_id']]),
                'snippet' => '',
                'meta'    => 'Channel',
                'image'   => trim((string) ($row['avatar'] ?? '')),
            ];
        }
    }

    return [
        'label' => 'Media',
        'more'  => url('media', ['q' => $q]),
        'hits'  => $hits,
    ];
};
