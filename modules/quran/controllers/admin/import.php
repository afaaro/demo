<?php
declare(strict_types=1);

use System\Engine\Controller;
use System\Library\Dataset;

class QuranAdminImport extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login', ['next' => 'quran/admin/import']);
        }
    }

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
        $stats = $this->stats();
        $importer = $this->importer();
        $files = [];
        foreach (['quran-uthmani.txt', 'quran-simple.txt', 'so.abduh.txt', 'en.sahih.txt', 'quran-data.js'] as $name) {
            $path = $importer->dataDir() . DIRECTORY_SEPARATOR . $name;
            $files[$name] = is_file($path) ? filesize($path) : 0;
        }

        $this->view->assign('title', 'Quran Import');
        echo $this->view->inline(function () use ($stats, $files): void {
            include get_module_path('quran') . '/views/admin/import.php';
        }, 'admin', ['title' => 'Quran Import']);
    }

    public function runAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('quran/admin/import');
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

        redirect_to('quran/admin/import');
    }

    public function uploadAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('quran/admin/import');
        }
        $this->request->enforceCsrf();

        $code = trim((string) $this->request->post('code', 'string', ''));
        $name = trim((string) $this->request->post('name', 'string', ''));
        $author = trim((string) $this->request->post('author', 'string', ''));
        $language = trim((string) $this->request->post('language', 'string', ''));

        if (!preg_match('/^[a-z]{2}\.[a-z0-9_-]{1,24}$/', $code)) {
            $this->notify->error('Code must look like so.abduh or en.sahih.');
            redirect_to('quran/admin/import');
        }
        if ($name === '' || $language === '') {
            $this->notify->error('Name and language are required.');
            redirect_to('quran/admin/import');
        }

        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->notify->error('Choose a Tanzil translation .txt file.');
            redirect_to('quran/admin/import');
        }

        $tmp = (string) $upload['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            $this->notify->error('Invalid upload.');
            redirect_to('quran/admin/import');
        }

        try {
            $count = $this->importer()->importTranslationFile($tmp, $code, $name, $author, $language, 10);
            $this->notify->success("Imported {$count} ayahs for {$name}.");
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect_to('quran/admin/import');
    }

    public function translationsAction(): void
    {
        $dataset = (new Dataset($this->registry))
            ->from('quran_translation')
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
                'enabled'    => static function (array $row): string {
                    return (int) $row['enabled'] === 1
                        ? '<span class="badge bg-success">On</span>'
                        : '<span class="badge bg-secondary">Off</span>';
                },
            ]);

        $this->view->assign('title', 'Quran Translations');
        echo $this->view->inline(static function () use ($dataset): void {
            echo $dataset->render();
        }, 'admin', ['title' => 'Quran Translations']);
    }

    /**
     * @return array{surahs:int,ayahs:int,translations:int,reciters:int}
     */
    private function stats(): array
    {
        try {
            return [
                'surahs'       => (int) ($this->db->query('SELECT COUNT(*) AS c FROM `#__quran_surah`')->row['c'] ?? 0),
                'ayahs'        => (int) ($this->db->query('SELECT COUNT(*) AS c FROM `#__quran_ayah`')->row['c'] ?? 0),
                'translations' => (int) ($this->db->query('SELECT COUNT(*) AS c FROM `#__quran_translation`')->row['c'] ?? 0),
                'reciters'     => (int) ($this->db->query('SELECT COUNT(*) AS c FROM `#__quran_reciter`')->row['c'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['surahs' => 0, 'ayahs' => 0, 'translations' => 0, 'reciters' => 0];
        }
    }
}
