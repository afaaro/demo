<?php
declare(strict_types=1);

use System\Engine\Controller;
use System\Engine\NotFoundException;

class ToolsAdminLogs extends Controller
{
    private const SEVERITY = [
        'emergency' => 0,
        'alert'     => 1,
        'critical'  => 2,
        'error'     => 3,
        'warning'   => 4,
        'notice'    => 5,
        'info'      => 6,
        'debug'     => 7,
    ];

    private string $logDir;

    protected function boot(): void
    {
        $configured = (string) ($this->config->get('log.directory') ?? '');
        $this->logDir = rtrim(
            $configured !== '' ? $configured : (rtrim(BASEDIR, '/\\') . '/storage/logs'),
            '/\\'
        ) . DIRECTORY_SEPARATOR;

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }
    }

    // =========================================================================
    // List — GET /tools/admin/logs
    // =========================================================================

    public function indexAction(): void
    {
        $q       = trim((string) $this->request->get('q', 'string', ''));
        $channel = strtolower(trim((string) $this->request->get('channel', 'string', '')));

        $files = $this->scanFiles();
        $channels = [];
        foreach ($files as $f) {
            $channels[$f['channel']] = true;
        }
        ksort($channels);

        if ($q !== '' || $channel !== '') {
            $files = array_values(array_filter($files, static function (array $f) use ($q, $channel) {
                if ($channel !== '' && $f['channel'] !== $channel) {
                    return false;
                }
                if ($q !== '' && stripos($f['name'], $q) === false) {
                    return false;
                }
                return true;
            }));
        }

        $byChannel = [];
        foreach ($files as $f) {
            $byChannel[$f['channel']][] = $f;
        }
        ksort($byChannel);

        $totalBytes = array_sum(array_column($files, 'size'));
        $errorHits  = 0;
        foreach (array_slice($files, 0, 8) as $f) {
            $errorHits += $this->countLevels($f['name'], ['error', 'critical', 'alert', 'emergency']);
        }

        echo $this->output('index', [
            'title'      => 'Logs',
            'byChannel'  => $byChannel,
            'files'      => $files,
            'channels'   => array_keys($channels),
            'q'          => $q,
            'channel'    => $channel,
            'totalBytes' => $totalBytes,
            'errorHits'  => $errorHits,
            'logDir'     => $this->relativeLogDir(),
            'settings'   => $this->logSettings(),
        ]);
    }

    // =========================================================================
    // View — GET /tools/admin/logs/view?file=
    // =========================================================================

    public function viewAction(): void
    {
        $file = $this->safeFile((string) $this->request->get('file', 'string', ''));
        if ($file === null) {
            throw new NotFoundException('Log file not found.');
        }

        $lines = (int) $this->request->get('lines', 'int', 500);
        $lines = max(50, min(5000, $lines));
        $level = strtolower(trim((string) $this->request->get('level', 'string', '')));
        $q     = trim((string) $this->request->get('q', 'string', ''));

        $entries = array_reverse($this->readTail($file, $lines, $level, $q));
        $absFile = $this->logDir . $file;

        echo $this->output('view', [
            'title'   => 'Log: ' . $file,
            'file'    => $file,
            'entries' => $entries,
            'size'    => (int) @filesize($absFile),
            'mtime'   => (int) @filemtime($absFile),
            'lines'   => $lines,
            'level'   => $level,
            'q'       => $q,
            'levels'  => $this->countAllLevels($file),
        ]);
    }

    // =========================================================================
    // Download — GET /tools/admin/logs/download?file=
    // =========================================================================

    public function downloadAction(): void
    {
        $file = $this->safeFile((string) $this->request->get('file', 'string', ''));
        if ($file === null) {
            throw new NotFoundException('Log file not found.');
        }

        $path = $this->logDir . $file;

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        readfile($path);
        exit;
    }

    // =========================================================================
    // Clear — POST
    // =========================================================================

    public function clearAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('tools/admin/logs');
            return;
        }
        $this->guardCsrf();

        $target  = trim((string) $this->request->post('target', 'string', 'all'));
        $file    = trim((string) $this->request->post('file', 'string', ''));
        $channel = strtolower(trim((string) $this->request->post('channel', 'string', '')));

        $cleared = 0;
        $bytes   = 0;

        if ($target === 'file' && $file !== '') {
            $safe = $this->safeFile($file);
            if ($safe === null) {
                $this->notify->error('Log file not found.');
                redirect_to('tools/admin/logs');
                return;
            }
            $path = $this->logDir . $safe;
            $size = (int) @filesize($path);
            if (@file_put_contents($path, '') !== false) {
                $cleared = 1;
                $bytes   = $size;
            }
        } elseif ($target === 'channel' && $channel !== '' && preg_match('/^[a-z0-9_-]+$/', $channel)) {
            foreach ($this->scanFiles() as $f) {
                if ($f['channel'] !== $channel) {
                    continue;
                }
                $path = $this->logDir . $f['name'];
                $size = (int) @filesize($path);
                if (@file_put_contents($path, '') !== false) {
                    $cleared++;
                    $bytes += $size;
                }
            }
        } else {
            foreach (glob($this->logDir . '*.log') ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $size = (int) @filesize($path);
                if (@file_put_contents($path, '') !== false) {
                    $cleared++;
                    $bytes += $size;
                }
            }
        }

        $this->log->info('Logs cleared', [
            'target'  => $target,
            'file'    => $file,
            'channel' => $channel,
            'count'   => $cleared,
            'bytes'   => $bytes,
            'by'      => $this->auth->id(),
        ]);

        $this->notify->success(
            $cleared === 0
                ? 'Nothing to clear.'
                : "Cleared {$cleared} file(s), freed " . self::formatBytes($bytes) . '.'
        );

        if ($target === 'file' && $file !== '') {
            redirect_to('tools/admin/logs/view', ['file' => $file]);
            return;
        }

        redirect_to('tools/admin/logs');
    }

    // =========================================================================
    // Delete — POST
    // =========================================================================

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('tools/admin/logs');
            return;
        }
        $this->guardCsrf();

        $file = $this->safeFile((string) $this->request->post('file', 'string', ''));
        if ($file === null) {
            $this->notify->error('Log file not found.');
            redirect_to('tools/admin/logs');
            return;
        }

        $path = $this->logDir . $file;
        $size = (int) @filesize($path);
        if (@unlink($path)) {
            $this->log->info('Log file deleted', [
                'file'  => $file,
                'bytes' => $size,
                'by'    => $this->auth->id(),
            ]);
            $this->notify->success('Deleted ' . $file . ' (' . self::formatBytes($size) . ').');
        } else {
            $this->notify->error('Could not delete ' . $file . '.');
        }

        redirect_to('tools/admin/logs');
    }

    // =========================================================================
    // Prune — POST (delete files older than keep_days)
    // =========================================================================

    public function pruneAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('tools/admin/logs');
            return;
        }
        $this->guardCsrf();

        $keepDays = (int) ($this->config->get('log.keep_days') ?? 30);
        if ($keepDays < 1) {
            $keepDays = 30;
        }

        $cutoff  = time() - ($keepDays * 86400);
        $removed = 0;
        $bytes   = 0;

        foreach ($this->scanFiles() as $f) {
            if ($f['mtime'] >= $cutoff) {
                continue;
            }
            // Keep today's files even if mtime is odd.
            if ($f['date'] !== '' && $f['date'] >= date('Y-m-d', $cutoff)) {
                continue;
            }

            $path = $this->logDir . $f['name'];
            $size = (int) $f['size'];
            if (@unlink($path)) {
                $removed++;
                $bytes += $size;
            }
        }

        $this->log->info('Logs pruned', [
            'keep_days' => $keepDays,
            'count'     => $removed,
            'bytes'     => $bytes,
            'by'        => $this->auth->id(),
        ]);

        $this->notify->success(
            $removed === 0
                ? "Nothing older than {$keepDays} day(s) to prune."
                : "Pruned {$removed} file(s), freed " . self::formatBytes($bytes) . '.'
        );

        redirect_to('tools/admin/logs');
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('tools/admin/logs');
            exit;
        }
    }

    /** @return array<string, mixed> */
    private function logSettings(): array
    {
        return [
            'channel'       => (string) ($this->config->get('log.channel') ?? 'app'),
            'level'         => (string) ($this->config->get('log.level') ?? 'debug'),
            'format'        => (string) ($this->config->get('log.format') ?? 'plain'),
            'keep_days'     => (int) ($this->config->get('log.keep_days') ?? 30),
            'max_file_size' => (int) ($this->config->get('log.max_file_size') ?? 10485760),
            'enabled'       => (bool) ($this->config->get('log.enabled') ?? true),
        ];
    }

    /**
     * @return list<array{name:string,channel:string,date:string,size:int,mtime:int,errors:int}>
     */
    private function scanFiles(): array
    {
        $out = [];

        foreach (glob($this->logDir . '*.log') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $name = basename($path);

            if (!preg_match('/^([a-z0-9_-]+)-(\d{4}-\d{2}-\d{2})(?:\.\d+)?\.log$/i', $name, $m)) {
                $channel = '_other';
                $date    = '';
            } else {
                $channel = strtolower($m[1]);
                $date    = $m[2];
            }

            $out[] = [
                'name'    => $name,
                'channel' => $channel,
                'date'    => $date,
                'size'    => (int) @filesize($path),
                'mtime'   => (int) @filemtime($path),
                'errors'  => 0,
            ];
        }

        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        // Light error counts for the newest few files only.
        foreach (array_slice($out, 0, 12, true) as $i => $f) {
            $out[$i]['errors'] = $this->countLevels($f['name'], ['error', 'critical', 'alert', 'emergency']);
        }

        return $out;
    }

    /** @param list<string> $levels */
    private function countLevels(string $file, array $levels): int
    {
        $path = $this->logDir . $file;
        if (!is_file($path) || filesize($path) > 2 * 1024 * 1024) {
            // Skip huge files for index scans.
            return 0;
        }

        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return 0;
        }

        $count = 0;
        foreach ($levels as $level) {
            $count += preg_match_all('/\[' . preg_quote(strtoupper($level), '/') . '\]/i', $contents) ?: 0;
            $count += preg_match_all('/"level"\s*:\s*"' . preg_quote($level, '/') . '"/i', $contents) ?: 0;
        }

        return $count;
    }

    /** @return array<string, int> */
    private function countAllLevels(string $file): array
    {
        $counts = array_fill_keys(array_keys(self::SEVERITY), 0);
        $path = $this->logDir . $file;
        if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            return $counts;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return $counts;
        }

        foreach (array_keys(self::SEVERITY) as $level) {
            $n = preg_match_all('/\[' . preg_quote(strtoupper($level), '/') . '\]/i', $contents) ?: 0;
            $n += preg_match_all('/"level"\s*:\s*"' . preg_quote($level, '/') . '"/i', $contents) ?: 0;
            $counts[$level] = $n;
        }

        return $counts;
    }

    /**
     * @return list<array{raw:string, level:string, time:string, message:string}>
     */
    private function readTail(string $file, int $lines, string $level, string $q): array
    {
        $path = $this->logDir . $file;
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $allLines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $allLines = array_values(array_filter($allLines, static fn($l) => $l !== ''));

        if ($level !== '' || $q !== '') {
            $minSeverity = match ($level) {
                'error'   => 3,
                'warning' => 4,
                'info'    => 6,
                'debug'   => 7,
                default   => null,
            };

            $allLines = array_values(array_filter($allLines, function (string $l) use ($minSeverity, $q) {
                if ($minSeverity !== null) {
                    $lvl = $this->extractLevel($l);
                    $sev = self::SEVERITY[$lvl] ?? 7;
                    if ($sev > $minSeverity) {
                        return false;
                    }
                }
                if ($q !== '' && stripos($l, $q) === false) {
                    return false;
                }
                return true;
            }));
        }

        $slice = array_slice($allLines, -$lines);
        $out = [];
        foreach ($slice as $raw) {
            $parsed = $this->parseLine($raw);
            $out[] = [
                'raw'     => $raw,
                'level'   => $parsed['level'],
                'time'    => $parsed['time'],
                'message' => $parsed['message'],
            ];
        }

        return $out;
    }

    /** @return array{level:string, time:string, message:string} */
    private function parseLine(string $line): array
    {
        $level = $this->extractLevel($line);
        $time  = '';
        $message = $line;

        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\]\s*\[([A-Z]+)\]\s*(.*)$/s', $line, $m)) {
            $time = $m[1];
            $message = $m[3];
        } elseif (str_starts_with(ltrim($line), '{')) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $time = (string) ($decoded['ts'] ?? '');
                $message = (string) ($decoded['msg'] ?? $line);
                if (!empty($decoded['ctx']) && is_array($decoded['ctx'])) {
                    $message .= ' ' . json_encode($decoded['ctx'], JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return ['level' => $level, 'time' => $time, 'message' => $message];
    }

    private function extractLevel(string $line): string
    {
        if (preg_match('/\[(emergency|alert|critical|error|warning|notice|info|debug)\]/i', $line, $m)) {
            return strtolower($m[1]);
        }

        if (str_starts_with(ltrim($line), '{')) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['level']) && is_string($decoded['level'])) {
                return strtolower($decoded['level']);
            }
        }

        return 'info';
    }

    private function safeFile(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 255) {
            return null;
        }
        if (basename($name) !== $name) {
            return null;
        }
        if (!preg_match('/^[a-z0-9._-]+\.log$/i', $name)) {
            return null;
        }

        $path = $this->logDir . $name;
        if (!is_file($path)) {
            return null;
        }

        $real = realpath($path);
        $dir  = realpath($this->logDir);
        if ($real === false || $dir === false || !str_starts_with($real, $dir)) {
            return null;
        }

        return $name;
    }

    private function relativeLogDir(): string
    {
        return 'storage/logs/';
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

    public static function levelColor(string $level): string
    {
        return match ($level) {
            'emergency', 'alert', 'critical', 'error' => 'danger',
            'warning' => 'warning',
            'notice'  => 'info',
            'info'    => 'secondary',
            'debug'   => 'light',
            default   => 'secondary',
        };
    }
}
