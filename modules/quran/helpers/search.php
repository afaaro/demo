<?php

declare(strict_types=1);

return static function (string $q, $db, int $limit): array {
    $hits = [];
    $limit = max(1, min(20, $limit));
    $file = get_module_path('quran') . '/library/reader.php';

    try {
        if (!class_exists('QuranReader', false) && is_file($file)) {
            require_once $file;
        }
        if (!class_exists('QuranReader', false)) {
            return ['label' => 'Quran', 'more' => url('quran/search', ['q' => $q]), 'hits' => []];
        }

        $reader = new QuranReader($db);
        if (!$reader->isImported()) {
            return ['label' => 'Quran', 'more' => url('quran/search', ['q' => $q]), 'hits' => []];
        }

        $rows = $reader->search($q, $limit);
    } catch (Throwable) {
        return ['label' => 'Quran', 'more' => url('quran/search', ['q' => $q]), 'hits' => []];
    }

    foreach ($rows as $row) {
        $sura = (int) ($row['surah_id'] ?? 0);
        $aya = (int) ($row['ayah'] ?? 0);
        $name = trim((string) ($row['name_so'] ?? ''));
        $latin = trim((string) ($row['name_latin'] ?? ''));
        $label = $name !== '' ? $name : $latin;
        if ($name !== '' && $latin !== '' && $name !== $latin) {
            $label = $name . ' — ' . $latin;
        }

        $snippet = trim((string) ($row['snippet'] ?? $row['translation_text'] ?? $row['text_simple'] ?? ''));

        $hits[] = [
            'title'   => $sura . ':' . $aya . ($label !== '' ? ' · ' . $label : ''),
            'url'     => url('quran', ['sura' => $sura, 'aya' => $aya]),
            'snippet' => $snippet,
            'meta'    => 'Quran',
            'image'   => '',
        ];
    }

    return [
        'label' => 'Quran',
        'more'  => url('quran/search', ['q' => $q]),
        'hits'  => $hits,
    ];
};
