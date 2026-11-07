<?php
declare(strict_types=1);

namespace Module\Store\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class ProductDocumentRepository
{
    use TenantScoped;

    private const MAX_BYTES = 10 * 1024 * 1024;

    /** @var array<string, string> mime => extension */
    private const ALLOWED = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private Database $db,
        private string $baseDir,
    ) {}

    /**
     * @return array<int, array{
     *   id:int, product_id:int, title:string, original_name:string,
     *   stored_path:string, mime:string, size_bytes:int, uploaded_by:?int, created_at:string
     * }>
     */
    public function forProduct(int $productId): array
    {
        if ($productId <= 0 || !TenantContext::get()->has()) {
            return [];
        }

        $rows = $this->db->query(
            "SELECT * FROM `#__store_product_documents`
              WHERE `product_id` = ? AND `tenant_id` = ?
              ORDER BY `id` DESC",
            [$productId, $this->tenantId()]
        )->rows;

        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('store_product_documents', $this->withTenant(['id' => $id]));
        return $row === [] ? null : $this->hydrate($row);
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     */
    public function upload(
        int $productId,
        array $file,
        string $title = '',
        ?int $userId = null,
    ): array {
        if ($productId <= 0) {
            throw new InvalidArgumentException('Invalid product.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Choose a file to upload.');
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (error ' . (int) $file['error'] . ').');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('File must be smaller than 10 MB.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }

        $mime = $this->detectMime($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            // Fallback by extension for CSV/text edge cases.
            $extGuess = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $mime = match ($extGuess) {
                'csv' => 'text/csv',
                'txt' => 'text/plain',
                'pdf' => 'application/pdf',
                default => $mime,
            };
        }
        if (!isset(self::ALLOWED[$mime])) {
            throw new RuntimeException('Allowed: PDF, Word, Excel, CSV, TXT, JPG, PNG, WEBP, GIF.');
        }

        $ext = self::ALLOWED[$mime];
        $original = mb_substr(basename((string) ($file['name'] ?? ('document.' . $ext))), 0, 255);
        $title = trim($title);
        if ($title === '') {
            $title = pathinfo($original, PATHINFO_FILENAME) ?: $original;
        }
        $title = mb_substr($title, 0, 255);

        $relative = 'documents/' . date('Y/m') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create documents directory.');
        }
        if (!@move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Failed to save uploaded file.');
        }
        @chmod($target, 0644);

        $id = $this->db->insert('store_product_documents', $this->withTenant([
            'product_id'    => $productId,
            'title'         => $title,
            'original_name' => $original,
            'stored_path'   => $relative,
            'mime'          => mb_substr($mime, 0, 128),
            'size_bytes'    => $size,
            'uploaded_by'   => $userId,
            'created_at'    => date('Y-m-d H:i:s'),
        ]));

        return $this->find((int) $id)
            ?? throw new RuntimeException('Failed to reload document.');
    }

    public function delete(int $id): void
    {
        $doc = $this->find($id);
        if ($doc === null) {
            return;
        }
        if (TenantContext::get()->has()) {
            $this->db->query(
                'DELETE FROM `#__store_product_documents` WHERE `id` = ? AND `tenant_id` = ?',
                [$id, $this->tenantId()]
            );
        } else {
            $this->db->query('DELETE FROM `#__store_product_documents` WHERE `id` = ?', [$id]);
        }
        $this->unlinkStored((string) $doc['stored_path']);
    }

    public function absolutePath(string $relative): ?string
    {
        if ($relative === '' || !str_starts_with($relative, 'documents/') || str_contains($relative, '..')) {
            return null;
        }
        $path = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        return is_file($path) ? $path : null;
    }

    private function unlinkStored(string $relative): void
    {
        $path = $this->absolutePath($relative);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function detectMime(string $tmpPath): string
    {
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f !== false) {
                $mime = (string) finfo_file($f, $tmpPath);
                finfo_close($f);
                if ($mime !== '') {
                    return $mime;
                }
            }
        }
        return (string) (mime_content_type($tmpPath) ?: 'application/octet-stream');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'product_id'    => (int) $row['product_id'],
            'title'         => (string) ($row['title'] ?? ''),
            'original_name' => (string) ($row['original_name'] ?? ''),
            'stored_path'   => (string) ($row['stored_path'] ?? ''),
            'mime'          => (string) ($row['mime'] ?? ''),
            'size_bytes'    => (int) ($row['size_bytes'] ?? 0),
            'uploaded_by'   => isset($row['uploaded_by']) && $row['uploaded_by'] !== null
                ? (int) $row['uploaded_by'] : null,
            'created_at'    => (string) ($row['created_at'] ?? ''),
        ];
    }
}
