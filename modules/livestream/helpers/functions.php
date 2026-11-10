<?php
declare(strict_types=1);

/** @return array<string, mixed> */
function livestream_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $defaults = [
        'enabled'          => false,
        'mediamtx_api'     => 'http://127.0.0.1:9997',
        'srt_publish_host' => 'afaaro.myvnc.com',
        'srt_local_host'   => '127.0.0.1',
        'srt_port'         => 8890,
        'rtmp_port'        => 1935,
        'hls_public_base'  => 'http://afaaro.myvnc.com:8888',
        // Where the HTTPS site proxy fetches MediaMTX (usually loopback).
        'hls_proxy_upstream' => 'http://afaaro.myvnc.com:8888',
        'hls_cdn_secret'     => 'soomaali-hls-proxy-secret',
        // Home MediaMTX only: forward publish auth to soomaali.net studio DB.
        'auth_upstream'      => '',
        // soomaali.net studio: pull Live feed stats from home WAMP when local API is unreachable.
        'stats_upstream'     => '',
        // Re-encode publisher video to H.264 for the website player.
        'hls_transcode_h264' => false,
        'transcode_key'      => 'soomaali-hls-transcode',
        'webhook_secret'   => '',
    ];

    // File defaults (modules/livestream/config/stream.php).
    $path = get_module_path('livestream');
    $file = $path
        ? rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'stream.php'
        : '';
    if ($file !== '' && is_file($file)) {
        $loaded = include $file;
        if (is_array($loaded)) {
            $defaults = array_merge($defaults, $loaded);
        }
    }

    // Tools → Modules config (#__modules.config) overrides the file.
    try {
        if (function_exists('registry') && registry('db')) {
            $row = registry('db')->query(
                'SELECT `config` FROM `#__modules` WHERE `name` = ? LIMIT 1',
                ['livestream']
            )->row;
            if ($row !== [] && !empty($row['config'])) {
                $saved = json_decode((string) $row['config'], true);
                if (is_array($saved) && $saved !== []) {
                    $defaults = array_merge($defaults, $saved);
                }
            }
        }
    } catch (\Throwable) {
        // DB unavailable during early boot — keep file defaults.
    }

    if (isset($defaults['srt_port'])) {
        $defaults['srt_port'] = (int) $defaults['srt_port'];
    }
    if (isset($defaults['rtmp_port'])) {
        $defaults['rtmp_port'] = (int) $defaults['rtmp_port'];
    }
    if (array_key_exists('enabled', $defaults)) {
        $raw = $defaults['enabled'];
        $defaults['enabled'] = is_bool($raw)
            ? $raw
            : in_array((string) $raw, ['1', 'true', 'on', 'yes'], true);
    }

    $cfg = $defaults;
    return $cfg;
}

function livestream_enabled(): bool
{
    return !empty(livestream_config()['enabled']);
}

function livestream_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    if ($text === '') {
        $text = 'stream';
    }
    return substr($text, 0, 48);
}

function livestream_new_stream_key(): string
{
    return bin2hex(random_bytes(16));
}

/** MediaMTX publish streamid (Wirecast "Stream ID" field / OBS URL query). */
function livestream_srt_streamid(string $slug, string $streamKey): string
{
    $slug = trim($slug, '/');
    return 'publish:' . $slug . ':obs:' . $streamKey;
}

/**
 * OBS Custom server URL (MediaMTX custom streamid syntax).
 * Path = public slug, password = stream_key.
 */
function livestream_obs_srt_url(string $slug, string $streamKey, ?string $host = null): string
{
    $cfg = livestream_config();
    $host = trim((string) ($host ?? $cfg['srt_publish_host'] ?? 'afaaro.myvnc.com'));
    if ($host === '') {
        $host = 'afaaro.myvnc.com';
    }
    $port = (int) ($cfg['srt_port'] ?? 8890);
    // MediaMTX expects literal colons in streamid (publish:path:user:pass).
    // rawurlencode() turns ":" into "%3A"; OBS then hands MediaMTX the encoded
    // form and publish fails with "invalid stream ID 'publish%3A…'".
    $streamid = livestream_srt_streamid($slug, $streamKey);
    // latency=200ms helps avoid SRT "max recorded size exceeded" on bursty OBS publishes.
    return 'srt://' . $host . ':' . $port . '?streamid=' . $streamid . '&latency=200000';
}

/** Same-PC / LAN OBS URL — avoids router hairpin issues with DynDNS. */
function livestream_obs_srt_url_local(string $slug, string $streamKey): string
{
    $cfg = livestream_config();
    $host = trim((string) ($cfg['srt_local_host'] ?? '127.0.0.1'));
    if ($host === '') {
        $host = '127.0.0.1';
    }
    return livestream_obs_srt_url($slug, $streamKey, $host);
}

/**
 * RTMP publish URL with credentials (Larix / OBS / phone apps).
 * Query form (?user=&pass=) — user:pass@ in the URL is ignored by many encoders.
 * Path = slug, user = obs, password = stream_key (same auth as SRT).
 */
function livestream_rtmp_url(string $slug, string $streamKey, ?string $host = null): string
{
    $cfg = livestream_config();
    $host = trim((string) ($host ?? $cfg['srt_publish_host'] ?? 'afaaro.myvnc.com'));
    if ($host === '') {
        $host = 'afaaro.myvnc.com';
    }
    $port = (int) ($cfg['rtmp_port'] ?? 1935);
    $slug = trim($slug, '/');
    $q = http_build_query([
        'user' => 'obs',
        'pass' => $streamKey,
    ]);
    return "rtmp://{$host}:{$port}/{$slug}?{$q}";
}

function livestream_rtmp_url_local(string $slug, string $streamKey): string
{
    $cfg = livestream_config();
    $host = trim((string) ($cfg['srt_local_host'] ?? '127.0.0.1'));
    if ($host === '') {
        $host = '127.0.0.1';
    }
    return livestream_rtmp_url($slug, $streamKey, $host);
}

/** RTMP server base without credentials (OBS “Server” + separate user/pass). */
function livestream_rtmp_server(string $slug, ?string $host = null): string
{
    $cfg = livestream_config();
    $host = trim((string) ($host ?? $cfg['srt_publish_host'] ?? 'afaaro.myvnc.com'));
    if ($host === '') {
        $host = 'afaaro.myvnc.com';
    }
    $port = (int) ($cfg['rtmp_port'] ?? 1935);
    $slug = trim($slug, '/');
    return "rtmp://{$host}:{$port}/{$slug}";
}

function livestream_hls_url(string $slug): string
{
    $base = rtrim((string) (livestream_config()['hls_public_base'] ?? ''), '/');
    $slug = trim($slug, '/');
    return $base . '/' . rawurlencode($slug) . '/index.m3u8';
}

/** Path MediaMTX uses for browser-safe H.264 (ffmpeg shadow publish). */
function livestream_h264_slug(string $slug): string
{
    $slug = trim($slug, '/');
    if ($slug === '' || str_ends_with($slug, '_h264')) {
        return $slug;
    }
    return $slug . '_h264';
}

function livestream_transcode_h264_enabled(): bool
{
    $raw = livestream_config()['hls_transcode_h264'] ?? false;
    if (is_bool($raw)) {
        return $raw;
    }
    return in_array((string) $raw, ['1', 'true', 'on', 'yes'], true);
}

/** True when this slug should play/publish via {_h264} (CDN sources with broken DTS). */
function livestream_slug_needs_transcode(string $slug): bool
{
    if (!livestream_transcode_h264_enabled()) {
        return false;
    }
    $slug = strtolower(trim($slug, '/'));
    if ($slug === '' || str_ends_with($slug, '_h264')) {
        return false;
    }
    $only = livestream_config()['hls_transcode_slugs'] ?? null;
    // Empty / missing list ⇒ all slugs (legacy). Non-empty ⇒ allow-list only.
    if (!is_array($only) || $only === []) {
        return true;
    }
    $only = array_map(
        static fn($s): string => strtolower(trim((string) $s, '/')),
        $only
    );
    return in_array($slug, $only, true);
}

/** Slug used by the website player (H.264 shadow path when needed). */
function livestream_playback_slug(string $slug): string
{
    $slug = trim($slug, '/');
    return livestream_slug_needs_transcode($slug)
        ? livestream_h264_slug($slug)
        : $slug;
}

/** HTTPS-safe playlist URL for the watch page player (proxies HTTP MediaMTX). */
function livestream_hls_proxy_url(string $slug, string $file = 'index.m3u8'): string
{
    $slug = trim($slug, '/');
    $file = trim($file, '/');
    if ($file === '') {
        $file = 'index.m3u8';
    }
    return url('livestream/hls', [
        'slug' => $slug,
        'file' => $file,
    ], true, true);
}

/**
 * Browser player URL — always same-origin HLS proxy.
 * Avoids mixed content, hairpin NAT to :8888, and hlsCDNSecret.
 * Playback slug is raw (afaaro) or {_h264} (mogadishu) per config.
 */
function livestream_player_hls_url(string $slug): string
{
    return livestream_hls_proxy_url(livestream_playback_slug($slug), 'index.m3u8');
}

/**
 * MediaMTX base for the site HLS proxy.
 * Loopback only on local requests; soomaali.net uses hls_public_base.
 */
function livestream_hls_proxy_upstream(): string
{
    $cfg = livestream_config();
    $configured = rtrim((string) ($cfg['hls_proxy_upstream'] ?? ''), '/');
    $public = rtrim((string) ($cfg['hls_public_base'] ?? ''), '/');

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    $isLocalRequest = in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
        || str_ends_with($host, '.local');

    $configuredIsLoopback = $configured !== '' && (
        str_contains($configured, '://127.0.0.1')
        || str_contains($configured, '://localhost')
        || str_contains($configured, '://[::1]')
    );

    if ($configuredIsLoopback && !$isLocalRequest) {
        return $public !== '' ? $public : $configured;
    }
    if ($configured !== '') {
        return $configured;
    }
    return $public;
}

/**
 * Best-effort Live feed details from MediaMTX HLS (works from soomaali.net via :8888).
 *
 * @return array<string, mixed>|null
 */
function livestream_hls_telemetry(string $slug): ?array
{
    $slug = trim($slug, '/');
    if ($slug === '' || !function_exists('curl_init')) {
        return null;
    }

    $cfg = livestream_config();
    $base = livestream_hls_proxy_upstream();
    if ($base === '') {
        return null;
    }

    $secret = trim((string) ($cfg['hls_cdn_secret'] ?? ''));
    $webSlug = livestream_playback_slug($slug);
    $web = livestream_fetch_hls_summary($base, $webSlug, $secret);
    // Never probe raw ingest HLS for transcode slugs (DTS crashes MediaMTX).
    $source = ($webSlug === $slug) ? $web : null;

    if ($web === null && $source === null) {
        return null;
    }

    return [
        'ok'           => true,
        'slug'         => $slug,
        'transcode_on' => $web !== null && $webSlug !== $slug,
        'source'       => $source ?? [
            'name'    => $slug,
            'role'    => 'ingest',
            'online'  => false,
            'tracks'  => [],
            'readers' => 0,
        ],
        'web'          => $web ?? [
            'name'    => $webSlug,
            'role'    => 'h264',
            'online'  => false,
            'tracks'  => [],
            'readers' => 0,
        ],
        'fetched_at'   => gmdate('c'),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function livestream_fetch_hls_summary(string $base, string $path, string $cdnSecret): ?array
{
    $url = $base . '/' . rawurlencode($path) . '/index.m3u8';
    $headers = ['Accept: application/vnd.apple.mpegurl, application/x-mpegURL, */*'];
    if ($cdnSecret !== '') {
        $headers[] = 'Authorization: Bearer ' . $cdnSecret;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'soomaali-livestream-hls-stats/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }
    $text = (string) $body;
    if (!str_contains($text, '#EXTM3U')) {
        return null;
    }

    $bandwidth = null;
    $width = null;
    $height = null;
    $codecs = null;
    if (preg_match(
        '/#EXT-X-STREAM-INF:([^\r\n]+)/i',
        $text,
        $m
    )) {
        $inf = $m[1];
        if (preg_match('/BANDWIDTH=(\d+)/i', $inf, $b)) {
            $bandwidth = (int) $b[1];
        }
        if (preg_match('/RESOLUTION=(\d+)x(\d+)/i', $inf, $r)) {
            $width = (int) $r[1];
            $height = (int) $r[2];
        }
        if (preg_match('/CODECS="([^"]+)"/i', $inf, $c)) {
            $codecs = $c[1];
        }
    }

    $tracks = [];
    if ($codecs !== null || ($width !== null && $height !== null)) {
        $codecList = $codecs !== null ? array_map('trim', explode(',', $codecs)) : ['unknown'];
        foreach ($codecList as $i => $codec) {
            $isAudio = str_starts_with(strtolower($codec), 'mp4a')
                || str_starts_with(strtolower($codec), 'ac-');
            $tracks[] = [
                'codec'    => $codec !== '' ? $codec : ($isAudio ? 'audio' : 'video'),
                'width'    => (!$isAudio && $i === 0) ? $width : null,
                'height'   => (!$isAudio && $i === 0) ? $height : null,
                'profile'  => null,
                'level'    => null,
                'rate'     => null,
                'channels' => null,
            ];
        }
    }

    return [
        'name'            => $path,
        'role'            => str_ends_with($path, '_h264') ? 'h264' : 'ingest',
        'online'          => true,
        'online_since'    => '',
        'source_type'     => 'hls',
        'tracks'          => $tracks,
        'readers'         => null,
        'bytes_in'        => 0,
        'bytes_out'       => 0,
        'frames_error'    => 0,
        'bitrate_in_mbps' => $bandwidth !== null ? round($bandwidth / 1000000, 3) : null,
        'rtt_ms'          => null,
        'loss_pct'        => null,
        'remote_addr'     => 'HLS playlist',
    ];
}
