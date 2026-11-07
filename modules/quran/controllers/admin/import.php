<?php

class QuranAdminImport extends Controller
{
    private function importer(): QuranImporter
    {
        $file = get_module_path('quran') . '/library/importer.php';
        if (!class_exists('QuranImporter', false) && is_file($file)) {
            require_once $file;
        }
        return new QuranImporter($this->db);
    }

    public function indexAction(): void
    {
        $this->asset->addTitle('Quran Import');
        $stats = $this->stats();
        $importer = $this->importer();
        $files = [];
        foreach (['quran-uthmani.txt', 'quran-simple.txt', 'so.abduh.txt', 'en.sahih.txt', 'quran-data.js'] as $name) {
            $path = $importer->dataDir() . DIRECTORY_SEPARATOR . $name;
            $files[$name] = is_file($path) ? filesize($path) : 0;
        }

        include get_module_path('quran') . '/views/admin/import.php';
        $this->load->view();
    }

    public function runAction(): void
    {
        if (!$this->request->isPost()) {
            redirect('quran/admin/import');
        }
        $this->request->enforceCsrf();

        try {
            $result = $this->importer()->importAll();
            $this->notify->success(
                'Imported ' . $result['ayahs'] . ' ayahs, '
                . count($result['translations']) . ' translation(s), '
                . $result['reciters'] . ' reciters.'
            );
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect('quran/admin/import');
    }

    public function uploadAction(): void
    {
        if (!$this->request->isPost()) {
            redirect('quran/admin/import');
        }
        $this->request->enforceCsrf();

        $code = trim((string) $this->request->post('code', 'string', ''));
        $name = trim((string) $this->request->post('name', 'string', ''));
        $author = trim((string) $this->request->post('author', 'string', ''));
        $language = trim((string) $this->request->post('language', 'string', ''));

        if (!preg_match('/^[a-z]{2}\.[a-z0-9_-]{1,24}$/', $code)) {
            $this->notify->error('Code must look like so.abduh or en.sahih.');
            redirect('quran/admin/import');
        }
        if ($name === '' || $language === '') {
            $this->notify->error('Name and language are required.');
            redirect('quran/admin/import');
        }

        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->notify->error('Choose a Tanzil translation .txt file.');
            redirect('quran/admin/import');
        }

        $tmp = (string) $upload['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            $this->notify->error('Invalid upload.');
            redirect('quran/admin/import');
        }

        try {
            $count = $this->importer()->importTranslationFile($tmp, $code, $name, $author, $language, 10);
            $this->notify->success("Imported {$count} ayahs for {$name}.");
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect('quran/admin/import');
    }

    public function translationsAction(): void
    {
        $this->asset->addTitle('Quran Translations');

        echo $this->dataset
            ->from('#__quran_translation')
            ->searchable(['code', 'name', 'author', 'language'])
            ->sortable(['id', 'code', 'name', 'language', 'sort_order', 'enabled'])
            ->defaultSort('sort_order', 'asc')
            ->heading('Translations', [
                ['label' => 'Import', 'url' => url('quran/admin/import'), 'class' => 'btn btn-primary'],
            ])
            ->searchPlaceholder('Search translations...')
            ->columns([
                'id'         => 'ID',
                'code'       => 'Code',
                'name'       => 'Name',
                'author'     => 'Author',
                'language'   => 'Lang',
                'sort_order' => 'Order',
                'enabled'    => static function ($row) {
                    return (int) $row['enabled'] === 1
                        ? '<span class="badge bg-success">On</span>'
                        : '<span class="badge bg-secondary">Off</span>';
                },
            ])
            ->render();

        $this->load->view();
    }

    /**
     * @return array{surahs:int,ayahs:int,translations:int,reciters:int}
     */
    private function stats(): array
    {
        return [
            'surahs'       => (int) ($this->db->query('SELECT COUNT(*) AS c FROM `#__quran_surah`')->row['c'] ?? 0),
            'ayahs'        => (int) ($this->db->query('SELECT COUNT(*) AS c FROM `#__quran_ayah`')->row['c'] ?? 0),
            'translations' => (int) ($this->db->query('SELECT COUNT(*) AS c FROM `#__quran_translation`')->row['c'] ?? 0),
            'reciters'     => (int) ($this->db->query('SELECT COUNT(*) AS c FROM `#__quran_reciter`')->row['c'] ?? 0),
        ];
    }
}
