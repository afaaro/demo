<?php
declare(strict_types=1);

use System\Engine\Controller;

class ToolsAdminMedia extends Controller
{
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    protected function boot(): void {}

    // =========================================================================
    // Library — GET /tools/admin/media
    // =========================================================================

    public function indexAction(): void
    {
        $stats = $this->libraryStats();

        echo $this->output('index', [
            'title'      => 'Media library',
            'stats'      => $stats,
            'pickerUrl'  => $this->url->to('tools/admin/media/picker'),
            'uploadUrl'  => $this->url->to('tools/admin/media/upload'),
            'deleteUrl'  => $this->url->to('tools/admin/media/delete'),
            'bulkUrl'    => $this->url->to('tools/admin/media/bulk-delete'),
            'cleanupUrl' => $this->url->to('tools/admin/media/cleanup'),
            'csrf'       => $this->request->csrfToken('media'),
            'csrfField'  => $this->request->csrfField('media'),
            'maxBytes'   => (int) $this->config->get('media.max_upload_bytes', 5 * 1024 * 1024),
        ]);
    }

    // =========================================================================
    // Upload — POST /tools/admin/media/upload
    // =========================================================================

    public function uploadAction(): void
    {
        $wantsJson = $this->request->isAjax()
            || $this->request->isJson()
            || $this->request->header('x-csrf-token-media') !== null
            || $this->request->header('accept') === 'application/json';

        if ($wantsJson) {
            header('Content-Type: application/json');
        }

        try {
            $this->request->checkToken('media');
        } catch (\RuntimeException) {
            http_response_code(403);
            if ($wantsJson) {
                echo json_encode(['error' => 'CSRF check failed.', 'ok' => false]);
            } else {
                $this->notify->error('CSRF check failed.');
                redirect_to('tools/admin/media');
            }
            return;
        }

        // Multi-file: files[] or single file
        $uploads = $this->normalizeUploads($_FILES['files'] ?? $_FILES['file'] ?? null);
        if ($uploads === []) {
            $this->uploadFail($wantsJson, 400, 'No file uploaded.');
            return;
        }

        $saved = [];
        $errors = [];

        foreach ($uploads as $file) {
            try {
                $saved[] = $this->storeUpload($file);
            } catch (\Throwable $e) {
                $errors[] = ($file['name'] ?? 'file') . ': ' . $e->getMessage();
            }
        }

        if ($wantsJson) {
            if ($saved === [] && $errors !== []) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $errors[0], 'errors' => $errors]);
                return;
            }
            echo json_encode([
                'ok'       => true,
                'count'    => count($saved),
                'items'    => $saved,
                'location' => $saved[0]['url'] ?? null, // TinyMCE compat
                'errors'   => $errors,
            ]);
            return;
        }

        if ($saved !== []) {
            $this->notify->success(count($saved) . ' file(s) uploaded.');
        }
        if ($errors !== []) {
            $this->notify->error(implode(' ', array_slice($errors, 0, 3)));
        }
        redirect_to('tools/admin/media');
    }

    // =========================================================================
    // Picker JSON — GET /tools/admin/media/picker
    // =========================================================================

    public function pickerAction(): void
    {
        header('Content-Type: application/json');

        try {
            $page    = max(1, (int) $this->request->get('page', 'int', 1));
            $perPage = max(1, min(60, (int) $this->request->get('per_page', 'int', 24)));
            $offset  = ($page - 1) * $perPage;

            $q    = trim((string) $this->request->get('q', 'string', ''));
            $mime = trim((string) $this->request->get('mime', 'string', ''));
            $sort = (string) $this->request->get('sort', 'string', 'newest');

            $clauses = [];
            $params  = [];

            if ($q !== '') {
                $clauses[] = '`filename` LIKE ?';
                $params[]  = '%' . $q . '%';
            }

            if ($mime !== '') {
                if ($mime === 'image') {
                    $clauses[] = '`mime` LIKE ?';
                    $params[]  = 'image/%';
                } else {
                    $clauses[] = '`mime` = ?';
                    $params[]  = $mime;
                }
            }

            $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

            $orderBy = match ($sort) {
                'oldest' => '`created_at` ASC, `id` ASC',
                'name'   => '`filename` ASC',
                'size'   => '`size_bytes` DESC, `id` DESC',
                default  => '`created_at` DESC, `id` DESC',
            };

            $total = (int) $this->db->query(
                'SELECT COUNT(*) FROM `#__media`' . $where,
                $params
            )->value;

            $rows = $this->db->query(
                'SELECT `id`, `filename`, `path`, `mime`, `size_bytes`, `width`, `height`, `created_at`, `uploaded_by`
                 FROM `#__media`' . $where . '
                 ORDER BY ' . $orderBy . '
                 LIMIT ? OFFSET ?',
                array_merge($params, [$perPage, $offset])
            )->rows;

            $items = array_map(fn(array $r): array => $this->mapItem($r), $rows);

            echo json_encode([
                'items'    => $items,
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'pages'    => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ]);
        } catch (\Throwable $e) {
            error_log('Media picker failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal error.']);
        }
    }

    // =========================================================================
    // Delete — POST /tools/admin/media/delete
    // =========================================================================

    public function deleteAction(): void
    {
        header('Content-Type: application/json');

        try {
            $this->request->checkToken('media');
        } catch (\RuntimeException) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF check failed.', 'ok' => false]);
            return;
        }

        try {
            $id = (int) $this->request->post('id', 'int', 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing id.', 'ok' => false]);
                return;
            }

            if (!$this->deleteById($id)) {
                http_response_code(404);
                echo json_encode(['error' => 'Not found.', 'ok' => false]);
                return;
            }

            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            error_log('Media delete failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal error.', 'ok' => false]);
        }
    }

    // =========================================================================
    // Bulk delete — POST /tools/admin/media/bulk-delete
    // =========================================================================

    public function bulkDeleteAction(): void
    {
        header('Content-Type: application/json');

        try {
            $this->request->checkToken('media');
        } catch (\RuntimeException) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF check failed.', 'ok' => false]);
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->request->post('ids', 'raw', [])),
            static fn(int $id) => $id > 0
        )));

        if ($ids === []) {
            http_response_code(400);
            echo json_encode(['error' => 'No items selected.', 'ok' => false]);
            return;
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ($this->deleteById($id)) {
                $deleted++;
            }
        }

        $this->log->info('Media bulk delete', [
            'count' => $deleted,
            'ids'   => $ids,
            'by'    => $this->auth->id(),
        ]);

        echo json_encode(['ok' => true, 'deleted' => $deleted]);
    }

    // =========================================================================
    // Cleanup orphans — POST /tools/admin/media/cleanup
    // =========================================================================

    public function cleanupAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('tools/admin/media');
            return;
        }

        try {
            $this->request->checkToken('media');
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('tools/admin/media');
            return;
        }

        $removedDb = 0;
        $removedFs = 0;

        // DB rows whose files are missing
        $rows = $this->db->query('SELECT `id`, `path` FROM `#__media`')->rows;
        foreach ($rows as $row) {
            $full = $this->absoluteUploadPath((string) ($row['path'] ?? ''));
            if ($full === null || !is_file($full)) {
                $this->db->query('DELETE FROM `#__media` WHERE `id` = ?', [(int) $row['id']]);
                $removedDb++;
            }
        }

        // Files under uploads/media and uploads/news with no DB row
        foreach (['uploads/media', 'uploads/news'] as $relRoot) {
            $absRoot = rtrim(BASEDIR, '/\\') . '/' . $relRoot;
            if (!is_dir($absRoot)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $ext = strtolower($fileInfo->getExtension());
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    continue;
                }
                $full = $fileInfo->getPathname();
                $web  = '/' . ltrim(str_replace('\\', '/', substr($full, strlen(rtrim(BASEDIR, '/\\')))), '/');
                $exists = (int) $this->db->query(
                    'SELECT COUNT(*) FROM `#__media` WHERE `path` = ? OR `path` = ?',
                    [$web, ltrim($web, '/')]
                )->value;
                if ($exists === 0) {
                    if (@unlink($full)) {
                        $removedFs++;
                    }
                }
            }
        }

        $this->log->info('Media cleanup', [
            'db' => $removedDb,
            'fs' => $removedFs,
            'by' => $this->auth->id(),
        ]);

        $this->notify->success(
            "Cleanup done: removed {$removedDb} missing DB row(s), {$removedFs} orphan file(s)."
        );
        redirect_to('tools/admin/media');
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @return array<string, int|string> */
    private function libraryStats(): array
    {
        try {
            $row = $this->db->query(
                'SELECT COUNT(*) AS `cnt`,
                        COALESCE(SUM(`size_bytes`), 0) AS `bytes`,
                        COALESCE(SUM(CASE WHEN `mime` LIKE \'image/%\' THEN 1 ELSE 0 END), 0) AS `images`
                 FROM `#__media`'
            )->row;
        } catch (\Throwable) {
            $row = [];
        }

        return [
            'count'  => (int) ($row['cnt'] ?? 0),
            'bytes'  => (int) ($row['bytes'] ?? 0),
            'images' => (int) ($row['images'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed>|null $files
     * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    private function normalizeUploads(?array $files): array
    {
        if ($files === null || !isset($files['tmp_name'])) {
            return [];
        }

        // Single file
        if (!is_array($files['tmp_name'])) {
            return [[
                'name'     => (string) ($files['name'] ?? ''),
                'type'     => (string) ($files['type'] ?? ''),
                'tmp_name' => (string) $files['tmp_name'],
                'error'    => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
                'size'     => (int) ($files['size'] ?? 0),
            ]];
        }

        $out = [];
        foreach ($files['tmp_name'] as $i => $tmp) {
            $out[] = [
                'name'     => (string) ($files['name'][$i] ?? ''),
                'type'     => (string) ($files['type'][$i] ?? ''),
                'tmp_name' => (string) $tmp,
                'error'    => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size'     => (int) ($files['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array<string, mixed>
     */
    private function storeUpload(array $file): array
    {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE) {
            throw new RuntimeException(
                'File exceeds server upload limit (' . ini_get('upload_max_filesize') . ').'
            );
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (code ' . $file['error'] . ').');
        }
        if ($file['tmp_name'] === '' || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid upload.');
        }

        $maxBytes = (int) $this->config->get('media.max_upload_bytes', 5 * 1024 * 1024);
        if ($file['size'] > $maxBytes) {
            throw new RuntimeException(
                'File too large (max ' . number_format($maxBytes / 1024 / 1024, 1) . ' MB).'
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) {
            throw new RuntimeException('Only JPG, PNG, GIF, WEBP allowed.');
        }

        $webPath = 'uploads/media/' . date('Y/m');
        $root    = rtrim(BASEDIR, '/\\');
        $dir     = $root . '/' . $webPath;

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create upload directory.');
        }

        $ext      = self::ALLOWED[$mime];
        $basename = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest     = $dir . '/' . $basename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Could not save file.');
        }

        $url = '/' . $webPath . '/' . $basename;
        $dimensions = @getimagesize($dest) ?: [0, 0];

        $id = (int) $this->db->insert('media', [
            'filename'    => (string) ($file['name'] !== '' ? $file['name'] : $basename),
            'path'        => $url,
            'mime'        => $mime,
            'size_bytes'  => (int) $file['size'],
            'width'       => (int) ($dimensions[0] ?? 0),
            'height'      => (int) ($dimensions[1] ?? 0),
            'uploaded_by' => $this->auth->id(),
        ]);

        $this->log->info('Media uploaded', [
            'id'   => $id,
            'path' => $url,
            'by'   => $this->auth->id(),
        ]);

        return $this->mapItem([
            'id'          => $id,
            'filename'    => (string) ($file['name'] !== '' ? $file['name'] : $basename),
            'path'        => $url,
            'mime'        => $mime,
            'size_bytes'  => (int) $file['size'],
            'width'       => (int) ($dimensions[0] ?? 0),
            'height'      => (int) ($dimensions[1] ?? 0),
            'created_at'  => date('Y-m-d H:i:s'),
            'uploaded_by' => $this->auth->id(),
        ]);
    }

    private function deleteById(int $id): bool
    {
        $row = $this->db->first('media', ['id' => $id]);
        if ($row === []) {
            return false;
        }

        $full = $this->absoluteUploadPath((string) ($row['path'] ?? ''));
        if ($full !== null && is_file($full)) {
            @unlink($full);
        }

        $this->db->query('DELETE FROM `#__media` WHERE `id` = ?', [$id]);
        return true;
    }

    private function absoluteUploadPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($path, '/uploads/')) {
            return null;
        }

        $root = rtrim(BASEDIR, '/\\');
        $full = $root . $path;
        $realUploads = realpath($root . '/uploads');
        $realFile    = realpath($full);

        // File may already be missing — still return intended path for checks.
        if ($realUploads === false) {
            return $full;
        }
        if ($realFile !== false && !str_starts_with($realFile, $realUploads)) {
            return null;
        }

        return $realFile !== false ? $realFile : $full;
    }

    /** @param array<string, mixed> $r */
    private function mapItem(array $r): array
    {
        $path = (string) $r['path'];
        $base = rtrim($this->request->basePath(), '/');

        return [
            'id'          => (int) $r['id'],
            'url'         => $base . '/' . ltrim($path, '/'),
            'path'        => $path,
            'filename'    => (string) $r['filename'],
            'mime'        => (string) $r['mime'],
            'size'        => (int) $r['size_bytes'],
            'width'       => (int) $r['width'],
            'height'      => (int) $r['height'],
            'created_at'  => (string) $r['created_at'],
            'uploaded_by' => isset($r['uploaded_by']) ? (int) $r['uploaded_by'] : null,
        ];
    }

    private function uploadFail(bool $wantsJson, int $code, string $message): void
    {
        http_response_code($code);
        if ($wantsJson) {
            echo json_encode(['error' => $message, 'ok' => false]);
            return;
        }
        $this->notify->error($message);
        redirect_to('tools/admin/media');
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1) . ' MB';
        }
        return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
    }
}
