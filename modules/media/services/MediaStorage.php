<?php
declare(strict_types=1);

namespace Module\Media\Services;

final class MediaStorage
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $this->resolveRoot($root);
    }

    /**
     * Project root for uploads/. Empty BASEDIR must not become "\" (drive root on Windows).
     */
    private function resolveRoot(?string $root): string
    {
        $candidates = [];
        if (is_string($root) && trim($root) !== '') {
            $candidates[] = $root;
        }
        if (defined('BASEDIR') && is_string(BASEDIR) && trim(BASEDIR) !== '') {
            $candidates[] = BASEDIR;
        }
        // modules/media/services → project root
        $candidates[] = dirname(__DIR__, 3);

        foreach ($candidates as $candidate) {
            $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $candidate), DIRECTORY_SEPARATOR);
            if ($normalized === '' || $normalized === DIRECTORY_SEPARATOR) {
                continue;
            }
            if (is_dir($normalized . DIRECTORY_SEPARATOR . 'uploads')
                || is_dir($normalized . DIRECTORY_SEPARATOR . 'modules')
            ) {
                return $normalized . DIRECTORY_SEPARATOR;
            }
        }

        $fallback = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, dirname(__DIR__, 3)), DIRECTORY_SEPARATOR);
        return $fallback . DIRECTORY_SEPARATOR;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function mediaRoot(): string
    {
        return $this->root . 'uploads' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR;
    }

    public function ensureDir(string $absoluteDir): void
    {
        if (is_dir($absoluteDir)) {
            return;
        }
        if (!mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException('Could not create upload directory.');
        }
    }

    /**
     * Assemble chunked upload parts into final media path.
     *
     * @return array{relative:string,absolute:string,media_type:string}
     */
    public function assembleChunks(string $uuid, string $filename, int $totalChunks): array
    {
        require_once dirname(__DIR__) . '/helpers/functions.php';

        $uuid = preg_replace('/[^a-zA-Z0-9_-]/', '', $uuid) ?? '';
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename) ?? '';
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($uuid === '' || $filename === '' || !in_array($ext, media_allowed_extensions(), true)) {
            throw new \InvalidArgumentException('Invalid upload parameters.');
        }

        $tempDirectory = $this->root . 'uploads' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $uuid . DIRECTORY_SEPARATOR;
        if (!is_dir($tempDirectory)) {
            throw new \RuntimeException('Upload staging folder missing.');
        }

        $mediaType = media_type_from_extension($ext);
        $subfolder = match ($mediaType) {
            'audio' => 'audio',
            'image' => 'images',
            default => 'videos',
        };

        $datePath = date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR . date('d');
        $finalDir = $this->mediaRoot() . $subfolder . DIRECTORY_SEPARATOR . $datePath . DIRECTORY_SEPARATOR;
        $this->ensureDir($finalDir);

        $secured = bin2hex(random_bytes(16)) . '.' . $ext;
        $finalAbsolute = $finalDir . $secured;

        $out = fopen($finalAbsolute, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Could not open destination file.');
        }

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $part = $tempDirectory . $i . '.part';
                $in = fopen($part, 'rb');
                if ($in === false) {
                    throw new \RuntimeException("Missing chunk {$i}.");
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                unlink($part);
            }
        } finally {
            fclose($out);
        }

        @rmdir($tempDirectory);

        $relative = 'uploads/media/' . $subfolder . '/' . date('Y/m/d') . '/' . $secured;

        return [
            'relative'   => $relative,
            'absolute'   => $finalAbsolute,
            'media_type' => $mediaType,
        ];
    }

    public function saveChunk(string $uuid, int $index, string $tmpUploadedPath): string
    {
        $uuid = preg_replace('/[^a-zA-Z0-9_-]/', '', $uuid) ?? '';
        if ($uuid === '' || $index < 0) {
            throw new \InvalidArgumentException('Invalid chunk target.');
        }
        if ($tmpUploadedPath === '' || !is_file($tmpUploadedPath)) {
            throw new \RuntimeException('Upload temp file missing.');
        }

        $tempDirectory = $this->root . 'uploads' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $uuid . DIRECTORY_SEPARATOR;
        $this->ensureDir($tempDirectory);

        $target = $tempDirectory . $index . '.part';
        if (is_file($target)) {
            @unlink($target);
        }

        if (is_uploaded_file($tmpUploadedPath)) {
            if (move_uploaded_file($tmpUploadedPath, $target)) {
                return $target;
            }
        }

        // Fallback when move_uploaded_file fails (open_basedir / Windows path quirks).
        if (@copy($tmpUploadedPath, $target)) {
            @unlink($tmpUploadedPath);
            return $target;
        }

        throw new \RuntimeException('Failed to store chunk.');
    }

    public function countChunks(string $uuid): int
    {
        $uuid = preg_replace('/[^a-zA-Z0-9_-]/', '', $uuid) ?? '';
        $tempDirectory = $this->root . 'uploads' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $uuid . DIRECTORY_SEPARATOR;
        if (!is_dir($tempDirectory)) {
            return 0;
        }
        $parts = glob($tempDirectory . '*.part') ?: [];
        return count($parts);
    }

    public function saveThumbnailJpeg(string $tmpPath, string $basename): ?string
    {
        $mime = @getimagesize($tmpPath)['mime'] ?? '';
        if ($mime !== 'image/jpeg') {
            return null;
        }

        $dir = $this->mediaRoot() . 'thumbnails' . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR;
        $this->ensureDir($dir);
        $name = preg_replace('/[^a-f0-9]/', '', $basename) . '.jpg';
        $target = $dir . $name;
        if (!move_uploaded_file($tmpPath, $target)) {
            return null;
        }
        return 'uploads/media/thumbnails/' . date('Y/m/') . $name;
    }

    public function deleteRelative(?string $relativePath): void
    {
        $relativePath = trim((string) $relativePath);
        if ($relativePath === '' || preg_match('#^https?://#i', $relativePath)) {
            return;
        }

        $mediaRoot = realpath($this->mediaRoot());
        $absolute = realpath($this->root . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
        if ($mediaRoot === false || $absolute === false || !is_file($absolute)) {
            return;
        }
        if (!str_starts_with($absolute, $mediaRoot . DIRECTORY_SEPARATOR)) {
            return;
        }
        unlink($absolute);
    }

    public function isRegisteredUploadPath(string $path): bool
    {
        require_once dirname(__DIR__) . '/helpers/functions.php';
        $ext = implode('|', media_allowed_extensions());
        if (!preg_match('#^uploads/media/(videos|audio|images)/\d{4}/\d{2}/\d{2}/[a-f0-9]{32}\.(' . $ext . ')$#', $path)) {
            return false;
        }
        return is_file($this->root . str_replace('/', DIRECTORY_SEPARATOR, $path));
    }
}
