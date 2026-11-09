<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use System\Engine\Controller;
use System\Engine\Exception\NotFoundException;

/**
 * Proxies MediaMTX HLS over the site HTTPS origin so browsers can play
 * http://home:8888 streams from https://soomaali.net (no mixed content).
 *
 * Playlist URL shape: /livestream/hls/{slug}/index.m3u8
 * Relative segment names then resolve under the same directory.
 *
 * Note: the router sanitizes path params and strips "." — we re-parse
 * slug/file from REQUEST_URI so index.m3u8 / *.mp4 keep their extensions.
 */
class LivestreamHls extends Controller
{
    public function indexAction(): void
    {
        if (!livestream_enabled()) {
            throw new NotFoundException('Livestream is disabled.');
        }

        [$slug, $file] = $this->resolveSlugAndFile();
        // Allow underscore so transcoded paths like london_h264 work.
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $slug)) {
            throw new NotFoundException('Invalid stream.');
        }
        if ($file === '' || !preg_match('/^[A-Za-z0-9._-]{1,180}$/', $file)) {
            throw new NotFoundException('Invalid media file.');
        }

        $cfg = livestream_config();
        $upstreamBase = rtrim((string) ($cfg['hls_proxy_upstream'] ?? ''), '/');
        if ($upstreamBase === '') {
            $upstreamBase = rtrim((string) ($cfg['hls_public_base'] ?? ''), '/');
        }
        if ($upstreamBase === '') {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'HLS upstream not configured';
            exit;
        }

        $publicBase = rtrim((string) ($cfg['hls_public_base'] ?? $upstreamBase), '/');
        $upstream = $upstreamBase . '/' . rawurlencode($slug) . '/' . str_replace('%2F', '', rawurlencode($file));
        $result = $this->fetchUpstream($upstream);
        if ($result === null) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Upstream unreachable';
            exit;
        }

        [$status, $contentType, $body] = $result;
        if ($status < 200 || $status >= 300) {
            http_response_code($status >= 400 ? $status : 502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Upstream error ' . $status;
            exit;
        }

        // MediaMTX serves an HTML reader for /path/ — never treat that as a playlist.
        $looksHtml = preg_match('/^\s*</', (string) $body) === 1
            || str_contains(strtolower($contentType), 'text/html');
        $isPlaylist = str_ends_with(strtolower($file), '.m3u8');

        if ($isPlaylist && $looksHtml) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Upstream returned HTML instead of HLS playlist';
            exit;
        }

        if ($isPlaylist) {
            $body = $this->rewritePlaylist((string) $body, $slug, $publicBase, $upstreamBase);
            $contentType = 'application/vnd.apple.mpegurl; charset=utf-8';
        } elseif ($contentType === '' || $looksHtml) {
            $contentType = $this->guessType($file);
        }

        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        echo $body;
        exit;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveSlugAndFile(): array
    {
        $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
        $uri = rawurldecode($uri);
        if (preg_match('#/livestream/hls/([a-z0-9][a-z0-9_-]{0,62})/([A-Za-z0-9._-]+)/?$#i', $uri, $m)) {
            return [strtolower($m[1]), $m[2]];
        }

        // Query-string fallback (and router params — may have dots stripped).
        $slug = trim((string) $this->request->get('slug', 'string', ''));
        $file = trim((string) ($this->request->get('file', 'string', '') ?: ($_GET['file'] ?? '')));
        if ($file === '') {
            $file = 'index.m3u8';
        }
        // If router ate the dot (indexm3u8), restore common playlist suffix.
        if (!str_contains($file, '.') && str_ends_with(strtolower($file), 'm3u8')) {
            $file = substr($file, 0, -4) . '.m3u8';
        }
        if (!str_contains($file, '.') && str_ends_with(strtolower($file), 'mp4')) {
            $file = substr($file, 0, -3) . '.mp4';
        }

        return [$slug, $file];
    }

    /**
     * @return array{0:int,1:string,2:string}|null
     */
    private function fetchUpstream(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $headers = [];
        $secret = trim((string) (livestream_config()['hls_cdn_secret'] ?? ''));
        $reqHeaders = [
            'User-Agent: soomaali-livestream-hls-proxy/1.0',
            'Accept: application/vnd.apple.mpegurl,application/x-mpegURL,*/*;q=0.1',
        ];
        if ($secret !== '') {
            $reqHeaders[] = 'Authorization: Bearer ' . $secret;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $reqHeaders,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (stripos($line, 'Content-Type:') === 0) {
                    $headers['content-type'] = trim(substr($line, 13));
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return null;
        }

        return [$status, (string) ($headers['content-type'] ?? ''), (string) $body];
    }

    private function rewritePlaylist(string $body, string $slug, string $publicBase, string $upstreamBase): string
    {
        $prefixes = [
            rtrim($publicBase, '/') . '/' . $slug . '/',
            rtrim($upstreamBase, '/') . '/' . $slug . '/',
        ];
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $out[] = $line;
                continue;
            }

            if (str_starts_with($trim, '#')) {
                $out[] = preg_replace_callback(
                    '/URI="([^"]+)"/i',
                    function (array $m) use ($slug, $prefixes): string {
                        return 'URI="' . $this->mapUri($m[1], $slug, $prefixes) . '"';
                    },
                    $line
                );
                continue;
            }

            $out[] = $this->mapUri($trim, $slug, $prefixes);
        }

        return implode("\n", $out) . "\n";
    }

    /** @param list<string> $prefixes */
    private function mapUri(string $uri, string $slug, array $prefixes): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && str_starts_with($uri, $prefix)) {
                    $file = rawurldecode(substr($uri, strlen($prefix)));
                    if (preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
                        return livestream_hls_proxy_url($slug, $file);
                    }
                }
            }
            return $uri;
        }

        return $uri;
    }

    private function guessType(string $file): string
    {
        $lower = strtolower($file);
        return match (true) {
            str_ends_with($lower, '.m3u8') => 'application/vnd.apple.mpegurl',
            str_ends_with($lower, '.mp4'),
            str_ends_with($lower, '.m4s'),
            str_ends_with($lower, '.cmfv'),
            str_ends_with($lower, '.cmfa') => 'video/mp4',
            str_ends_with($lower, '.ts') => 'video/mp2t',
            default => 'application/octet-stream',
        };
    }
}
