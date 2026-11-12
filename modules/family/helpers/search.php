<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

return static function (string $q, $db, int $limit): array {
    $hits = [];
    $like = '%' . $q . '%';
    $limit = max(1, min(20, $limit));

    try {
        $rows = $db->query(
            "SELECT p.`name`, p.`slug`, p.`gender`, p.`birth_date`, p.`death_date`,
                    f.`name` AS father_name, m.`name` AS mother_name
               FROM `#__family_people` p
               LEFT JOIN `#__family_people` f ON f.`id` = p.`father_id`
               LEFT JOIN `#__family_people` m ON m.`id` = p.`mother_id`
              WHERE p.`active` = 1
                AND (p.`name` LIKE ? OR p.`slug` LIKE ? OR p.`notes` LIKE ?)
              ORDER BY p.`name` ASC
              LIMIT {$limit}",
            [$like, $like, $like]
        )->rows ?? [];
    } catch (Throwable) {
        $rows = [];
    }

    foreach ($rows as $row) {
        $name = html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $meta = ucfirst((string) ($row['gender'] ?? ''));
        $parents = trim(
            trim((string) ($row['father_name'] ?? ''))
            . (($row['father_name'] ?? '') && ($row['mother_name'] ?? '') ? ' & ' : '')
            . trim((string) ($row['mother_name'] ?? ''))
        );
        if ($parents !== '') {
            $meta .= ' · ' . html_entity_decode($parents, ENT_QUOTES, 'UTF-8');
        }
        if (!empty($row['birth_date'])) {
            $meta .= ' · b. ' . $row['birth_date'];
        }

        $hits[] = [
            'title'   => $name,
            'url'     => url('family/person', ['slug' => (string) $row['slug']]),
            'snippet' => $meta,
            'meta'    => 'Family',
            'image'   => '',
        ];
    }

    return [
        'label' => 'Family',
        'more'  => url('family', ['q' => $q]),
        'hits'  => $hits,
    ];
};
