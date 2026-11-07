<?php

class QuranReader
{
    public function __construct(private \System\Library\Database $db) {}

    public function isImported(): bool
    {
        return (int) ($this->db->query('SELECT COUNT(*) AS c FROM `#__quran_surah`')->row['c'] ?? 0) === 114
            && (int) ($this->db->query('SELECT COUNT(*) AS c FROM `#__quran_ayah`')->row['c'] ?? 0) === 6236;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function surahs(): array
    {
        return $this->db->query(
            'SELECT * FROM `#__quran_surah` ORDER BY `number` ASC'
        )->rows ?? [];
    }

    public function surah(int $number): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM `#__quran_surah` WHERE `number` = ?',
            [$number]
        )->row;
        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ayahsBySurah(int $surahId, array $translationIds = []): array
    {
        return $this->attachTranslations(
            $this->db->query(
                'SELECT * FROM `#__quran_ayah` WHERE `surah_id` = ? ORDER BY `ayah` ASC',
                [$surahId]
            )->rows ?? [],
            $translationIds
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ayahsByJuz(int $juz, array $translationIds = []): array
    {
        return $this->attachTranslations(
            $this->db->query(
                'SELECT * FROM `#__quran_ayah` WHERE `juz` = ? ORDER BY `surah_id` ASC, `ayah` ASC',
                [$juz]
            )->rows ?? [],
            $translationIds
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ayahsByPage(int $page, array $translationIds = []): array
    {
        return $this->attachTranslations(
            $this->db->query(
                'SELECT * FROM `#__quran_ayah` WHERE `page` = ? ORDER BY `surah_id` ASC, `ayah` ASC',
                [$page]
            )->rows ?? [],
            $translationIds
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function translations(bool $enabledOnly = true): array
    {
        $sql = 'SELECT * FROM `#__quran_translation`';
        if ($enabledOnly) {
            $sql .= ' WHERE `enabled` = 1';
        }
        $sql .= ' ORDER BY `sort_order` ASC, `id` ASC';
        return $this->db->query($sql)->rows ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reciters(): array
    {
        return $this->db->query(
            'SELECT * FROM `#__quran_reciter` WHERE `enabled` = 1 ORDER BY `id` ASC'
        )->rows ?? [];
    }

    public function neighbors(string $mode, int $current): array
    {
        $max = match ($mode) {
            'juz'  => 30,
            'page' => 604,
            default => 114,
        };

        return [
            'prev' => $current > 1 ? $current - 1 : null,
            'next' => $current < $max ? $current + 1 : null,
            'max'  => $max,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $q, int $limit = 40): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $limit = max(1, min(80, $limit));
        $like = '%' . $q . '%';

        if (mb_strlen($q) >= 3) {
            $boolean = $this->booleanQuery($q);
            $rows = $this->db->query(
                "SELECT a.id, a.surah_id, a.ayah, a.text_uthmani, a.text_simple,
                        s.name_latin, s.name_ar, s.name_so,
                        t.name AS translation_name, at.text AS translation_text,
                        MATCH(a.text_simple) AGAINST (? IN BOOLEAN MODE) AS arabic_score,
                        MATCH(at.text) AGAINST (? IN BOOLEAN MODE) AS trans_score
                 FROM `#__quran_ayah` a
                 INNER JOIN `#__quran_surah` s ON s.id = a.surah_id
                 LEFT JOIN `#__quran_ayah_translation` at ON at.ayah_id = a.id
                 LEFT JOIN `#__quran_translation` t ON t.id = at.translation_id AND t.enabled = 1
                 WHERE MATCH(a.text_simple) AGAINST (? IN BOOLEAN MODE)
                    OR MATCH(at.text) AGAINST (? IN BOOLEAN MODE)
                 ORDER BY (arabic_score + trans_score) DESC, a.surah_id ASC, a.ayah ASC
                 LIMIT {$limit}",
                [$boolean, $boolean, $boolean, $boolean]
            )->rows ?? [];

            if ($rows !== []) {
                return $this->uniqueSearchHits($rows, $q);
            }
        }

        $rows = $this->db->query(
            "SELECT a.id, a.surah_id, a.ayah, a.text_uthmani, a.text_simple,
                    s.name_latin, s.name_ar, s.name_so,
                    t.name AS translation_name, at.text AS translation_text
             FROM `#__quran_ayah` a
             INNER JOIN `#__quran_surah` s ON s.id = a.surah_id
             LEFT JOIN `#__quran_ayah_translation` at ON at.ayah_id = a.id
             LEFT JOIN `#__quran_translation` t ON t.id = at.translation_id AND t.enabled = 1
             WHERE a.text_simple LIKE ?
                OR at.text LIKE ?
             ORDER BY a.surah_id ASC, a.ayah ASC
             LIMIT {$limit}",
            [$like, $like]
        )->rows ?? [];

        return $this->uniqueSearchHits($rows, $q);
    }

    /**
     * @param list<array<string, mixed>> $ayahs
     * @param list<int> $translationIds
     * @return list<array<string, mixed>>
     */
    private function attachTranslations(array $ayahs, array $translationIds): array
    {
        if ($ayahs === []) {
            return [];
        }

        $ids = array_map(static fn($row) => (int) $row['id'], $ayahs);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $filter = '';

        if ($translationIds !== []) {
            $filter = ' AND at.translation_id IN (' . implode(',', array_fill(0, count($translationIds), '?')) . ')';
            foreach ($translationIds as $id) {
                $params[] = (int) $id;
            }
        }

        $rows = $this->db->query(
            "SELECT at.ayah_id, at.translation_id, at.text, t.code, t.name, t.author, t.language
             FROM `#__quran_ayah_translation` at
             INNER JOIN `#__quran_translation` t ON t.id = at.translation_id
             WHERE at.ayah_id IN ({$placeholders}) AND t.enabled = 1 {$filter}
             ORDER BY t.sort_order ASC, t.id ASC",
            $params
        )->rows ?? [];

        $byAyah = [];
        foreach ($rows as $row) {
            $byAyah[(int) $row['ayah_id']][] = $row;
        }

        foreach ($ayahs as &$ayah) {
            $ayah['translations'] = $byAyah[(int) $ayah['id']] ?? [];
        }
        unset($ayah);

        return $ayahs;
    }

    private function booleanQuery(string $q): string
    {
        $words = preg_split('/\s+/u', $q) ?: [];
        $parts = [];
        foreach ($words as $word) {
            $word = trim($word, "+-<>()~*\"'");
            if ($word === '') {
                continue;
            }
            $parts[] = '+' . $word . '*';
        }
        return $parts !== [] ? implode(' ', $parts) : $q;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function uniqueSearchHits(array $rows, string $q): array
    {
        $hits = [];
        foreach ($rows as $row) {
            $key = (int) $row['surah_id'] . ':' . (int) $row['ayah'];
            if (isset($hits[$key])) {
                continue;
            }
            $snippet = (string) ($row['translation_text'] ?? '');
            if ($snippet === '' || stripos($snippet, $q) === false) {
                $snippet = (string) $row['text_simple'];
            }
            $row['snippet'] = $this->snippet($snippet, $q);
            $hits[$key] = $row;
        }
        return array_values($hits);
    }

    private function snippet(string $text, string $q, int $radius = 80): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $pos = mb_stripos($text, $q);
        if ($pos === false) {
            return mb_strlen($text) > 160 ? mb_substr($text, 0, 157) . '…' : $text;
        }
        $start = max(0, $pos - $radius);
        $chunk = mb_substr($text, $start, $radius * 2 + mb_strlen($q));
        if ($start > 0) {
            $chunk = '…' . $chunk;
        }
        if ($start + mb_strlen($chunk) < mb_strlen($text)) {
            $chunk .= '…';
        }
        return $chunk;
    }
}
