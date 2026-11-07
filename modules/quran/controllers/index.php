<?php

class QuranIndex extends Controller
{
    private function reader(): QuranReader
    {
        $file = get_module_path('quran') . '/library/reader.php';
        if (!class_exists('QuranReader', false) && is_file($file)) {
            require_once $file;
        }
        return new QuranReader($this->db);
    }

    public function indexAction(): void
    {
        $reader = $this->reader();
        $sura = max(1, min(114, (int) $this->request->get('sura', 'int', 1)));
        $aya  = max(0, (int) $this->request->get('aya', 'int', 0));

        if (!$reader->isImported()) {
            $this->renderEmpty();
            return;
        }

        $surah = $reader->surah($sura);
        if (!$surah) {
            $sura = 1;
            $surah = $reader->surah(1);
        }

        if ($aya > (int) $surah['ayah_count']) {
            $aya = 0;
        }

        $this->renderReader([
            'mode'        => 'sura',
            'current'     => $sura,
            'highlight'   => $aya,
            'title'       => $surah['name_so'] . ' — ' . $surah['name_latin'],
            'heading_ar'  => $surah['name_ar'],
            'heading'     => $surah['name_latin'] . ' (' . $surah['name_so'] . ')',
            'meta'        => $surah['revelation'] . ' · ' . $surah['ayah_count'] . ' ayahs',
            'ayahs'       => $reader->ayahsBySurah((int) $surah['id']),
            'neighbors'   => $reader->neighbors('sura', $sura),
            'surah'       => $surah,
        ], $reader);
    }

    public function juzAction(): void
    {
        $reader = $this->reader();
        $n = max(1, min(30, (int) $this->request->get('n', 'int', 1)));

        if (!$reader->isImported()) {
            $this->renderEmpty();
            return;
        }

        $this->renderReader([
            'mode'       => 'juz',
            'current'    => $n,
            'highlight'  => 0,
            'title'      => 'Juz ' . $n,
            'heading_ar' => '',
            'heading'    => 'Juz ' . $n,
            'meta'       => 'Juz ' . $n . ' of 30',
            'ayahs'      => $reader->ayahsByJuz($n),
            'neighbors'  => $reader->neighbors('juz', $n),
            'surah'      => null,
        ], $reader);
    }

    public function pageAction(): void
    {
        $reader = $this->reader();
        $n = max(1, min(604, (int) $this->request->get('n', 'int', 1)));

        if (!$reader->isImported()) {
            $this->renderEmpty();
            return;
        }

        $this->renderReader([
            'mode'       => 'page',
            'current'    => $n,
            'highlight'  => 0,
            'title'      => 'Page ' . $n,
            'heading_ar' => '',
            'heading'    => 'Page ' . $n,
            'meta'       => 'Page ' . $n . ' of 604',
            'ayahs'      => $reader->ayahsByPage($n),
            'neighbors'  => $reader->neighbors('page', $n),
            'surah'      => null,
        ], $reader);
    }

    public function searchAction(): void
    {
        $reader = $this->reader();
        $q = trim((string) $this->request->get('q', 'string', ''));
        $results = ($q !== '' && $reader->isImported()) ? $reader->search($q) : [];

        $this->bootAssets('Search the Quran');
        $view = get_module_path('quran') . '/views/search.php';
        include $view;
        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }

    private function renderReader(array $view, QuranReader $reader): void
    {
        $surahs = $reader->surahs();
        $translations = $reader->translations();
        $reciters = $reader->reciters();
        $this->bootAssets($view['title']);

        $player = [
            'ayahs'    => array_map(static function ($ayah) {
                return [
                    'id'    => (int) $ayah['id'],
                    'surah' => (int) $ayah['surah_id'],
                    'ayah'  => (int) $ayah['ayah'],
                ];
            }, $view['ayahs']),
            'reciters' => array_map(static function ($row) {
                return [
                    'id'      => (int) $row['id'],
                    'name'    => $row['name'],
                    'pattern' => $row['url_pattern'],
                ];
            }, $reciters),
            'mode'      => $view['mode'],
            'current'   => $view['current'],
            'highlight' => $view['highlight'],
        ];

        include get_module_path('quran') . '/views/reader.php';
        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }

    private function renderEmpty(): void
    {
        $this->bootAssets('Quran');
        echo '<div class="container py-5"><div class="alert alert-info">Quran text has not been imported yet. An administrator can import the Tanzil files from Quran → Import.</div></div>';
        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }

    private function bootAssets(string $title): void
    {
        $this->asset->addTitle($title);
        $this->asset->addStyle('https://fonts.googleapis.com/css2?family=Scheherazade+New:wght@400;700&display=swap');
        $this->asset->addStyle($this->asset->moduleAsset('quran', 'quran.css'));
        $this->asset->addScript($this->asset->moduleAsset('quran', 'quran.js'));
    }
}
