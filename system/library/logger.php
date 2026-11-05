<?php
declare(strict_types=1);

namespace System\Library;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Logger — file-based with daily + size rotation, retention, channels.
 *
 * Writes to:
 *   storage/logs/{channel}-{date}.log
 *   storage/logs/{channel}-{date}.{n}.log     (when size limit is exceeded)
 *
 * Format (plain):
 *   [2026-10-04 08:33:41] [ERROR] [req:ab12cd] [user:7] [ip:1.2.3.4] message {"ctx":...}
 *
 * Format (json, config `log.format = 'json'`):
 *   {"ts":"2026-10-04T08:33:41+00:00","level":"error","channel":"app",
 *    "req":"ab12cd","user":7,"ip":"1.2.3.4","msg":"...","ctx":{...}}
 */
final class Logger
{
    public const EMERGENCY = 'emergency';
    public const ALERT     = 'alert';
    public const CRITICAL  = 'critical';
    public const ERROR     = 'error';
    public const WARNING   = 'warning';
    public const NOTICE    = 'notice';
    public const INFO      = 'info';
    public const DEBUG     = 'debug';

    /** @var array<string, int> Level name => severity (lower = more severe). */
    private const SEVERITY = [
        self::EMERGENCY => 0,
        self::ALERT     => 1,
        self::CRITICAL  => 2,
        self::ERROR     => 3,
        self::WARNING   => 4,
        self::NOTICE    => 5,
        self::INFO      => 6,
        self::DEBUG     => 7,
    ];

    private string $logDir;
    private string $channel;
    private string $minLevel;
    private int    $maxFileSize;      // bytes; 0 = no size rotation
    private int    $keepDays;         // 0 = never prune
    private int    $fileMode;
    private string $format;           // 'plain' | 'json'
    private bool   $enabled;

    /** @var array<string, mixed> Global context merged into every line. */
    private array $globalContext = [];

    /**
     * @param array{
     *     dir?: string,
     *     channel?: string,
     *     level?: string,
     *     max_file_size?: int,
     *     keep_days?: int,
     *     file_mode?: int,
     *     format?: string,
     *     enabled?: bool
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->logDir = rtrim(
            (string) ($options['dir'] ?? BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'logs'),
            '/\\'
        ) . DIRECTORY_SEPARATOR;

        $this->channel     = $this->sanitizeChannel((string) ($options['channel'] ?? 'app'));
        $this->minLevel    = $this->normalizeLevel((string) ($options['level'] ?? self::DEBUG));
        $this->maxFileSize = (int)  ($options['max_file_size'] ?? 10 * 1024 * 1024);   // 10MB
        $this->keepDays    = (int)  ($options['keep_days']    ?? 30);
        $this->fileMode    = (int)  ($options['file_mode']    ?? 0640);
        $this->format      = in_array(($options['format'] ?? 'plain'), ['plain', 'json'], true)
            ? (string) $options['format']
            : 'plain';
        $this->enabled     = (bool) ($options['enabled'] ?? true);

        $this->ensureDirectory();
    }

    // =========================================================================
    // Configuration
    // =========================================================================

    /**
     * Set context merged into every subsequent log line.
     *
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): static
    {
        $this->globalContext = array_merge($this->globalContext, $context);
        return $this;
    }

    public function channel(string $channel): static
    {
        $clone = clone $this;
        $clone->channel = $this->sanitizeChannel($channel);
        return $clone;
    }

    public function setMinLevel(string $level): static
    {
        $this->minLevel = $this->normalizeLevel($level);
        return $this;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    // =========================================================================
    // PSR-3-style API
    // =========================================================================

    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $level = $this->normalizeLevel($level);

        if (!$this->shouldLog($level)) {
            return;
        }

        $this->write($level, $message, $context);
    }

    public function emergency(string $message, array $context = []): void { $this->log(self::EMERGENCY, $message, $context); }
    public function alert(string $message, array $context = []): void     { $this->log(self::ALERT,     $message, $context); }
    public function critical(string $message, array $context = []): void  { $this->log(self::CRITICAL,  $message, $context); }
    public function error(string $message, array $context = []): void     { $this->log(self::ERROR,     $message, $context); }
    public function warning(string $message, array $context = []): void   { $this->log(self::WARNING,   $message, $context); }
    public function notice(string $message, array $context = []): void    { $this->log(self::NOTICE,    $message, $context); }
    public function info(string $message, array $context = []): void      { $this->log(self::INFO,      $message, $context); }
    public function debug(string $message, array $context = []): void     { $this->log(self::DEBUG,     $message, $context); }

    /**
     * Log an exception with its stack trace.
     */
    public function exception(Throwable $e, string $level = self::ERROR): void
    {
        $this->log($level, $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'code'      => $e->getCode(),
            'trace'     => $e->getTraceAsString(),
        ]);
    }

    // =========================================================================
    // Maintenance
    // =========================================================================

    /**
     * Delete log files older than keep_days. Returns the number of files removed.
     * Safe to call on every request; it's cheap.
     */
    public function prune(): int
    {
        if ($this->keepDays <= 0 || !is_dir($this->logDir)) {
            return 0;
        }

        $cutoff  = time() - ($this->keepDays * 86400);
        $removed = 0;

        foreach (glob($this->logDir . '*.log') ?: [] as $file) {
            if (!is_file($file)) continue;
            if (filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /** Path to today's log file for the current channel (for admin tooling). */
    public function currentFile(): string
    {
        return $this->buildPath(new DateTimeImmutable(), 0);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function write(string $level, string $message, array $context): void
    {
        $now  = new DateTimeImmutable();
        $line = $this->formatLine($now, $level, $message, $context);
        $path = $this->resolvePath($now, $line);

        $flags = FILE_APPEND | LOCK_EX;

        // Attempt write; on failure (e.g. permissions), fail silently rather
        // than throwing inside a logger, which would cause recursion if the
        // error handler also logs.
        $existed = is_file($path);
        $ok = @file_put_contents($path, $line, $flags);

        if ($ok === false) {
            @error_log('[Logger] failed to write to ' . $path);
            return;
        }

        if (!$existed) {
            @chmod($path, $this->fileMode);
        }
    }

    private function formatLine(DateTimeImmutable $now, string $level, string $message, array $context): string
    {
        $merged = array_merge($this->globalContext, $context);

        if ($this->format === 'json') {
            $payload = [
                'ts'      => $now->format(DATE_ATOM),
                'level'   => $level,
                'channel' => $this->channel,
                'msg'     => $message,
            ];

            // Hoist well-known context keys to top level for easy grepping.
            foreach (['req', 'user', 'ip'] as $k) {
                if (isset($merged[$k])) {
                    $payload[$k] = $merged[$k];
                    unset($merged[$k]);
                }
            }

            if ($merged !== []) {
                $payload['ctx'] = $merged;
            }

            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return ($json !== false ? $json : '{"error":"json_encode failed"}') . "\n";
        }

        // Plain format
        $parts = [
            '[' . $now->format('Y-m-d H:i:s') . ']',
            '[' . strtoupper($level) . ']',
        ];

        if (!empty($merged['req']))  $parts[] = '[req:'  . $merged['req']  . ']';
        if (!empty($merged['user'])) $parts[] = '[user:' . $merged['user'] . ']';
        if (!empty($merged['ip']))   $parts[] = '[ip:'   . $merged['ip']   . ']';

        $parts[] = $message;

        // Remaining context serialized as JSON for consistency.
        $extra = array_diff_key($merged, ['req' => 1, 'user' => 1, 'ip' => 1]);
        if ($extra !== []) {
            $json = json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                $parts[] = $json;
            }
        }

        return implode(' ', $parts) . "\n";
    }

    /**
     * Choose the file to write to. If today's file exceeds max_file_size,
     * append a numeric suffix. Called on every write, but only checks
     * file size when the file already exists.
     */
    private function resolvePath(DateTimeImmutable $now, string $line): string
    {
        $base = $this->buildPath($now, 0);

        if ($this->maxFileSize <= 0) {
            return $base;
        }

        if (!is_file($base)) {
            return $base;
        }

        $currentSize = (int) @filesize($base);

        if ($currentSize + strlen($line) <= $this->maxFileSize) {
            return $base;
        }

        // Find next available numbered file for today.
        for ($n = 1; $n < 1000; $n++) {
            $candidate = $this->buildPath($now, $n);
            if (!is_file($candidate)) {
                return $candidate;
            }
            if ((int) @filesize($candidate) + strlen($line) <= $this->maxFileSize) {
                return $candidate;
            }
        }

        return $base;   // give up — extremely unlikely
    }

    private function buildPath(DateTimeImmutable $now, int $n): string
    {
        $suffix = $n > 0 ? '.' . $n : '';
        return $this->logDir
             . $this->channel
             . '-'
             . $now->format('Y-m-d')
             . $suffix
             . '.log';
    }

    private function shouldLog(string $level): bool
    {
        $min = self::SEVERITY[$this->minLevel] ?? self::SEVERITY[self::DEBUG];
        $cur = self::SEVERITY[$level]          ?? self::SEVERITY[self::DEBUG];
        return $cur <= $min;
    }

    private function normalizeLevel(string $level): string
    {
        $level = strtolower(trim($level));
        return isset(self::SEVERITY[$level]) ? $level : self::INFO;
    }

    private function sanitizeChannel(string $channel): string
    {
        $clean = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?? '';
        return $clean !== '' ? strtolower($clean) : 'app';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->logDir)) {
            if (!@mkdir($this->logDir, 0750, true) && !is_dir($this->logDir)) {
                throw new RuntimeException("Log directory is not creatable: {$this->logDir}");
            }
        }

        if (!is_writable($this->logDir)) {
            throw new RuntimeException("Log directory is not writable: {$this->logDir}");
        }
    }
}