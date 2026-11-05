<?php
declare(strict_types=1);

namespace System\Library;

use RuntimeException;

final class ImageUploader
{
    private const MAX_BYTES     = 5 * 1024 * 1024;   // 5 MB
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const EXTENSIONS    = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function __construct(private string $baseDir) {}

    /**
     * Save an uploaded image and return its public-relative path
     * (e.g. "uploads/2026/10/a1b2c3d4.jpg"), or null if no file was uploaded.
     *
     * @throws RuntimeException on invalid file
     */
    public function save(?array $file): ?string
    {
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int) $file['error']));
        }

        if ($file['size'] <= 0 || $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Image must be smaller than 5 MB.');
        }

        // Re-sniff from tmp_name — never trust the browser-supplied type.
        $mime = $this->detectMime($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException('Only JPG, PNG, WEBP, and GIF images are allowed.');
        }

        $ext      = self::EXTENSIONS[$mime];
        $relative = 'uploads/' . date('Y/m') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target   = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR
                  . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create upload directory.');
        }

        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException('Failed to save uploaded file.');
        }

        @chmod($target, 0644);

        return $relative;
    }

    /**
     * Delete a previously-uploaded image.
     * Silently ignores paths that are empty or outside the uploads directory.
     */
    public function delete(?string $relative): void
    {
        if ($relative === null || $relative === '') {
            return;
        }

        // Reject path traversal — only allow paths inside uploads/.
        if (!str_starts_with($relative, 'uploads/') || str_contains($relative, '..')) {
            return;
        }

        $absolute = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR
                  . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime)) {
                    return $mime;
                }
            }
        }
        // Fallback — less reliable.
        return (string) mime_content_type($path);
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large.',
            UPLOAD_ERR_PARTIAL                        => 'Upload was interrupted.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Server is missing a temp directory.',
            UPLOAD_ERR_CANT_WRITE                     => 'Server could not write the file.',
            UPLOAD_ERR_EXTENSION                      => 'Upload blocked by a server extension.',
            default                                   => 'Upload failed.',
        };
    }
}