<?php
declare(strict_types=1);

namespace Module\Media\Services;

/**
 * Optional post-upload video re-encode via ffmpeg (720p H.264/AAC).
 * Fail-soft: missing binary or encode errors leave the original file in place.
 */
final class MediaTranscoder
{
    /** @var array<string, mixed> */
    private array $config;

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $this->resolveRoot($root);
        $this->config = $this->loadConfig();
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    /**
     * Re-encode a stored video to compressed MP4 when enabled.
     *
     * @param array{relative:string,absolute:string,media_type:string} $assembled
     * @return array{relative:string,absolute:string,media_type:string,transcoded:bool}
     */
    public function maybeTranscodeVideo(array $assembled): array
    {
        $assembled['transcoded'] = false;

        if (($assembled['media_type'] ?? '') !== 'video') {
            return $assembled;
        }
        if (!$this->isEnabled()) {
            return $assembled;
        }

        $absolute = (string) ($assembled['absolute'] ?? '');
        $relative = (string) ($assembled['relative'] ?? '');
        if ($absolute === '' || !is_file($absolute)) {
            return $assembled;
        }

        $ffmpeg = $this->resolveBinary();
        if ($ffmpeg === null) {
            error_log('[media] Transcode enabled but ffmpeg binary not found.');
            return $assembled;
        }

        $result = $this->transcodeToMp4($ffmpeg, $absolute, $relative);
        if ($result === null) {
            return $assembled;
        }

        $result['media_type'] = 'video';
        $result['transcoded'] = true;
        return $result;
    }

    /**
     * @return array{relative:string,absolute:string}|null
     */
    private function transcodeToMp4(string $ffmpeg, string $sourceAbsolute, string $sourceRelative): ?array
    {
        @set_time_limit(0);

        $maxHeight = max(240, (int) ($this->config['max_height'] ?? 720));
        $videoBitrate = $this->sanitizeBitrate((string) ($this->config['video_bitrate'] ?? '2500k'), '2500k');
        $audioBitrate = $this->sanitizeBitrate((string) ($this->config['audio_bitrate'] ?? '128k'), '128k');

        $dir = dirname($sourceAbsolute);
        $base = pathinfo($sourceAbsolute, PATHINFO_FILENAME);
        $tempOut = $dir . DIRECTORY_SEPARATOR . $base . '.transcode.tmp.mp4';
        $finalAbsolute = $dir . DIRECTORY_SEPARATOR . $base . '.mp4';

        if (is_file($tempOut)) {
            @unlink($tempOut);
        }

        // Scale down only: never upscale above source resolution.
        // Comma escaped for ffmpeg filtergraph parsing.
        $vf = 'scale=-2:min(' . $maxHeight . '\,ih)';

        $cmd = [
            $ffmpeg,
            '-y',
            '-i', $sourceAbsolute,
            '-vf', $vf,
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-b:v', $videoBitrate,
            '-c:a', 'aac',
            '-b:a', $audioBitrate,
            '-movflags', '+faststart',
            '-pix_fmt', 'yuv420p',
            $tempOut,
        ];

        // Use files for stdout/stderr to avoid pipe-buffer deadlock with verbose ffmpeg.
        $logFile = $dir . DIRECTORY_SEPARATOR . $base . '.ffmpeg.log';
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'w'],
            2 => ['file', $logFile, 'a'],
        ];

        $process = @proc_open(
            $cmd,
            $descriptor,
            $pipes,
            $dir,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            error_log('[media] Failed to start ffmpeg process.');
            @unlink($logFile);
            return null;
        }

        fclose($pipes[0]);
        $code = proc_close($process);
        $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        @unlink($logFile);

        if ($code !== 0 || !is_file($tempOut) || filesize($tempOut) < 1) {
            error_log('[media] ffmpeg transcode failed (code ' . $code . '): ' . trim($log));
            @unlink($tempOut);
            return null;
        }

        // Prefer keeping a smaller file; if encode somehow grew, keep original.
        $srcSize = (int) filesize($sourceAbsolute);
        $outSize = (int) filesize($tempOut);
        if ($srcSize > 0 && $outSize > $srcSize) {
            error_log('[media] Transcode larger than source; keeping original.');
            @unlink($tempOut);
            return null;
        }

        $samePath = strtolower(str_replace('\\', '/', $sourceAbsolute))
            === strtolower(str_replace('\\', '/', $finalAbsolute));

        if ($samePath) {
            $backup = $sourceAbsolute . '.src.bak';
            if (is_file($backup)) {
                @unlink($backup);
            }
            if (!@rename($sourceAbsolute, $backup)) {
                error_log('[media] Could not park source before replace.');
                @unlink($tempOut);
                return null;
            }
            if (!@rename($tempOut, $finalAbsolute) && !@copy($tempOut, $finalAbsolute)) {
                @rename($backup, $sourceAbsolute);
                @unlink($tempOut);
                error_log('[media] Could not move transcoded file into place.');
                return null;
            }
            @unlink($tempOut);
            @unlink($backup);
        } else {
            if (is_file($finalAbsolute)) {
                @unlink($finalAbsolute);
            }
            if (!@rename($tempOut, $finalAbsolute) && !@copy($tempOut, $finalAbsolute)) {
                error_log('[media] Could not move transcoded file into place.');
                @unlink($tempOut);
                return null;
            }
            @unlink($tempOut);
            @unlink($sourceAbsolute);
        }

        $relative = preg_replace('/\.[^.\/\\\\]+$/', '.mp4', $sourceRelative) ?? $sourceRelative;
        $relative = str_replace('\\', '/', $relative);

        return [
            'relative' => $relative,
            'absolute' => $finalAbsolute,
        ];
    }

    private function resolveBinary(): ?string
    {
        $configured = trim((string) ($this->config['ffmpeg_path'] ?? ''));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $out = [];
        $code = 1;
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('where ffmpeg', $out, $code);
        } else {
            @exec('command -v ffmpeg', $out, $code);
        }
        $candidate = trim((string) ($out[0] ?? ''));
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }

        foreach ([
            'C:\\ffmpeg\\bin\\ffmpeg.exe',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
        ] as $fallback) {
            if (is_file($fallback)) {
                return $fallback;
            }
        }

        return null;
    }

    private function sanitizeBitrate(string $value, string $default): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^\d+[kmg]?$/', $value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        $defaults = [
            'enabled'       => false,
            'ffmpeg_path'   => PHP_OS_FAMILY === 'Windows'
                ? 'C:\\ffmpeg\\bin\\ffmpeg.exe'
                : '/usr/bin/ffmpeg',
            'max_height'    => 720,
            'video_bitrate' => '2500k',
            'audio_bitrate' => '128k',
        ];

        $path = null;
        if (function_exists('get_module_path')) {
            $modulePath = get_module_path('media');
            if (is_string($modulePath) && $modulePath !== '') {
                $path = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'transcode.php';
            }
        }
        if ($path === null) {
            $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'transcode.php';
        }

        if (!is_file($path)) {
            return $defaults;
        }

        $loaded = include $path;
        if (!is_array($loaded)) {
            return $defaults;
        }

        return array_merge($defaults, $loaded);
    }

    private function resolveRoot(?string $root): string
    {
        if (is_string($root) && trim($root) !== '') {
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        if (defined('BASEDIR') && is_string(BASEDIR) && trim(BASEDIR) !== '') {
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, BASEDIR), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, dirname(__DIR__, 3)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
}
