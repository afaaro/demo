<?php

/**
 * Imports official Tanzil UTF-8 files (sura|aya|text) without altering ayah wording.
 */
class QuranImporter
{
    private string $dataDir;

    public function __construct(private \System\Library\Database $db)
    {
        $this->dataDir = rtrim((string) get_module_path('quran'), '/\\') . DIRECTORY_SEPARATOR . 'data';
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    /**
     * @return array{surahs:int,ayahs:int,translations:int,reciters:int}
     */
    public function importAll(): array
    {
        $this->importSurahs();
        $ayahs = $this->importAyahs();
        $translations = $this->importBundledTranslations();
        $reciters = $this->ensureReciters();
        $this->ensurePublicMenu();

        return [
            'surahs'       => 114,
            'ayahs'        => $ayahs,
            'translations' => $translations,
            'reciters'     => $reciters,
        ];
    }

    public function importSurahs(): int
    {
        $surahs = require dirname(__DIR__) . '/helpers/surahs.php';
        $this->db->query('DELETE FROM `#__quran_surah`');

        $rows = [];
        $params = [];
        foreach ($surahs as $number => $row) {
            [$nameAr, $nameLatin, $nameSo, $ayahCount, $revelation] = $row;
            $rows[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push($params, (int) $number, (int) $number, $nameAr, $nameLatin, $nameSo, (int) $ayahCount, $revelation);
        }

        $this->db->query(
            'INSERT INTO `#__quran_surah`
                (`id`, `number`, `name_ar`, `name_latin`, `name_so`, `ayah_count`, `revelation`)
             VALUES ' . implode(',', $rows),
            $params
        );

        return count($surahs);
    }

    public function importAyahs(): int
    {
        $uthmani = $this->parseTanzilFile($this->requireFile('quran-uthmani.txt'));
        $simple  = $this->parseTanzilFile($this->requireFile('quran-simple.txt'));

        if (count($uthmani) !== 6236) {
            throw new RuntimeException('Uthmani file must contain 6236 ayahs, got ' . count($uthmani) . '.');
        }
        if (count($simple) !== 6236) {
            throw new RuntimeException('Simple file must contain 6236 ayahs, got ' . count($simple) . '.');
        }

        $juzStarts  = require dirname(__DIR__) . '/helpers/juz.php';
        $sajdahList = require dirname(__DIR__) . '/helpers/sajdah.php';
        $pageStarts = $this->pageStarts();

        $sajdahMap = [];
        foreach ($sajdahList as [$sura, $aya, $type]) {
            $sajdahMap[$sura . ':' . $aya] = $type;
        }

        $this->db->query('DELETE FROM `#__quran_ayah_translation`');
        $this->db->query('DELETE FROM `#__quran_ayah`');

        $this->db->beginTransaction();
        try {
            $chunk = [];
            $params = [];
            $count = 0;

            foreach ($uthmani as $key => $textUthmani) {
                if (!isset($simple[$key])) {
                    throw new RuntimeException("Simple text missing for ayah {$key}.");
                }

                [$sura, $aya] = array_map('intval', explode(':', $key, 2));
                $juz  = $this->lookupRange($juzStarts, $sura, $aya);
                $page = $this->lookupRange($pageStarts, $sura, $aya);
                $sajdah = $sajdahMap[$key] ?? '';

                $chunk[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($params, $sura, $aya, $textUthmani, $simple[$key], $juz, $page, $sajdah);
                $count++;

                if (count($chunk) >= 200) {
                    $this->insertAyahChunk($chunk, $params);
                    $chunk = [];
                    $params = [];
                }
            }

            if ($chunk !== []) {
                $this->insertAyahChunk($chunk, $params);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    public function importBundledTranslations(): array
    {
        $imported = [];

        foreach ($this->bundledTranslations() as $spec) {
            $file = $this->dataDir . DIRECTORY_SEPARATOR . $spec['file'];
            if (!is_file($file)) {
                continue;
            }
            $this->importTranslationFile($file, $spec['code'], $spec['name'], $spec['author'], $spec['language'], $spec['sort']);
            $imported[] = $spec['code'];
        }

        return $imported;
    }

    public function importTranslationFile(
        string $file,
        string $code,
        string $name,
        string $author,
        string $language,
        int $sortOrder = 0
    ): int {
        $verses = $this->parseTanzilFile($file);
        if ($verses === []) {
            throw new RuntimeException('Translation file has no ayah lines: ' . basename($file));
        }

        $existing = $this->db->query(
            'SELECT `id` FROM `#__quran_translation` WHERE `code` = ?',
            [$code]
        )->row;

        if ($existing) {
            $translationId = (int) $existing['id'];
            $this->db->update('quran_translation', [
                'name'       => $name,
                'author'     => $author,
                'language'   => $language,
                'enabled'    => 1,
                'sort_order' => $sortOrder,
            ], ['id' => $translationId]);
            $this->db->query(
                'DELETE FROM `#__quran_ayah_translation` WHERE `translation_id` = ?',
                [$translationId]
            );
        } else {
            $this->db->insert('quran_translation', [
                'code'       => $code,
                'name'       => $name,
                'author'     => $author,
                'language'   => $language,
                'enabled'    => 1,
                'sort_order' => $sortOrder,
            ]);
            $translationId = $this->db->insert_id();
        }

        $ayahIds = $this->ayahIdMap();
        $this->db->beginTransaction();
        try {
            $chunk = [];
            $params = [];
            $count = 0;

            foreach ($verses as $key => $text) {
                if (!isset($ayahIds[$key])) {
                    continue;
                }
                $chunk[] = '(?, ?, ?)';
                array_push($params, $translationId, $ayahIds[$key], $text);
                $count++;

                if (count($chunk) >= 200) {
                    $this->insertTranslationChunk($chunk, $params);
                    $chunk = [];
                    $params = [];
                }
            }

            if ($chunk !== []) {
                $this->insertTranslationChunk($chunk, $params);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $count;
    }

    public function ensureReciters(): int
    {
        $reciters = [
            [
                'name'        => 'Mishary Rashid Alafasy',
                'slug'        => 'alafasy',
                'url_pattern' => 'https://everyayah.com/data/Alafasy_128kbps/{surah:03d}{ayah:03d}.mp3',
                'enabled'     => 1,
            ],
            [
                'name'        => 'Mahmoud Khalil Al-Husary',
                'slug'        => 'husary',
                'url_pattern' => 'https://everyayah.com/data/Husary_128kbps/{surah:03d}{ayah:03d}.mp3',
                'enabled'     => 1,
            ],
        ];

        foreach ($reciters as $row) {
            $exists = $this->db->query(
                'SELECT `id` FROM `#__quran_reciter` WHERE `slug` = ?',
                [$row['slug']]
            )->row;
            if ($exists) {
                $this->db->update('quran_reciter', $row, ['id' => (int) $exists['id']]);
            } else {
                $this->db->insert('quran_reciter', $row);
            }
        }

        return count($reciters);
    }

    public function ensurePublicMenu(): void
    {
        try {
            $exists = $this->db->query(
                "SELECT `id` FROM `#__menu_items` WHERE `url` = ? LIMIT 1",
                ['quran']
            )->row;
            if ($exists) {
                return;
            }
            $max = (int) ($this->db->query(
                'SELECT COALESCE(MAX(`sort_order`), 0) AS c FROM `#__menu_items` WHERE `parent_id` = 0'
            )->row['c'] ?? 0);
            $this->db->insert('menu_items', [
                'title'      => 'Quran',
                'url'        => 'quran',
                'module'     => 'quran',
                'parent_id'  => 0,
                'sort_order' => $max + 1,
                'status'     => 1,
            ]);
        } catch (\Throwable) {
            // Public menu table is optional for import success.
        }
    }

    /**
     * @return array<string, string> keyed as "sura:aya"
     */
    public function parseTanzilFile(string $file): array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }

        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $out = [];

        foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('|', $line, 3);
            if (count($parts) < 3) {
                continue;
            }
            $sura = (int) $parts[0];
            $aya  = (int) $parts[1];
            if ($sura < 1 || $sura > 114 || $aya < 1) {
                continue;
            }
            $out[$sura . ':' . $aya] = $parts[2];
        }

        return $out;
    }

    /**
     * @return list<array{file:string,code:string,name:string,author:string,language:string,sort:int}>
     */
    public function bundledTranslations(): array
    {
        return [
            [
                'file'     => 'so.abduh.txt',
                'code'     => 'so.abduh',
                'name'     => 'Somali',
                'author'   => 'Mahmud Muhammad Abduh',
                'language' => 'so',
                'sort'     => 1,
            ],
            [
                'file'     => 'en.sahih.txt',
                'code'     => 'en.sahih',
                'name'     => 'Sahih International',
                'author'   => 'Saheeh International',
                'language' => 'en',
                'sort'     => 2,
            ],
        ];
    }

    private function requireFile(string $name): string
    {
        $path = $this->dataDir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            throw new RuntimeException("Missing Tanzil file: {$name}. Place it in modules/quran/data/.");
        }
        return $path;
    }

    /**
     * @return array<int, array{0:int,1:int}>
     */
    private function pageStarts(): array
    {
        $file = dirname(__DIR__) . '/helpers/pages.php';
        if (is_file($file)) {
            $pages = require $file;
            if (is_array($pages) && $pages !== []) {
                return $pages;
            }
        }

        $js = $this->dataDir . DIRECTORY_SEPARATOR . 'quran-data.js';
        if (is_file($js)) {
            return $this->pagesFromTanzilJs((string) file_get_contents($js));
        }

        throw new RuntimeException('Page map missing. Add helpers/pages.php or data/quran-data.js.');
    }

    /**
     * @return array<int, array{0:int,1:int}>
     */
    private function pagesFromTanzilJs(string $js): array
    {
        if (!preg_match('/Page\s*=\s*\[(.*?)\];/s', $js, $m)) {
            throw new RuntimeException('Could not parse Page array from quran-data.js.');
        }

        preg_match_all('/\[\s*(\d+)\s*,\s*(\d+)\s*\]/', $m[1], $pairs, PREG_SET_ORDER);
        $pages = [];
        $n = 0;
        foreach ($pairs as $pair) {
            $sura = (int) $pair[1];
            $aya  = (int) $pair[2];
            if ($sura < 1 || $sura > 114 || $aya < 1) {
                continue;
            }
            $n++;
            $pages[$n] = [$sura, $aya];
        }

        if (count($pages) !== 604) {
            throw new RuntimeException('Expected 604 pages, got ' . count($pages) . '.');
        }

        return $pages;
    }

    /**
     * @param array<int, array{0:int,1:int}> $starts
     */
    private function lookupRange(array $starts, int $sura, int $aya): int
    {
        $current = 1;
        foreach ($starts as $index => [$startSura, $startAya]) {
            if ($sura > $startSura || ($sura === $startSura && $aya >= $startAya)) {
                $current = (int) $index;
                continue;
            }
            break;
        }
        return $current;
    }

    /**
     * @return array<string, int>
     */
    private function ayahIdMap(): array
    {
        $rows = $this->db->query('SELECT `id`, `surah_id`, `ayah` FROM `#__quran_ayah`')->rows ?? [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['surah_id'] . ':' . (int) $row['ayah']] = (int) $row['id'];
        }
        return $map;
    }

    /**
     * @param list<string> $chunk
     * @param list<mixed> $params
     */
    private function insertAyahChunk(array $chunk, array $params): void
    {
        $this->db->query(
            'INSERT INTO `#__quran_ayah`
                (`surah_id`, `ayah`, `text_uthmani`, `text_simple`, `juz`, `page`, `sajdah`)
             VALUES ' . implode(',', $chunk),
            $params
        );
    }

    /**
     * @param list<string> $chunk
     * @param list<mixed> $params
     */
    private function insertTranslationChunk(array $chunk, array $params): void
    {
        $this->db->query(
            'INSERT INTO `#__quran_ayah_translation` (`translation_id`, `ayah_id`, `text`)
             VALUES ' . implode(',', $chunk),
            $params
        );
    }
}
