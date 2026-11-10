<?php
declare(strict_types=1);

/**
 * Livestream settings — edit this file (or Tools → Modules → livestream).
 *
 * Quick guide:
 * - Publish: SRT :8890 / RTMP :1935 → MediaMTX
 * - Website always plays via same-origin /livestream/hls/… proxy
 * - Add CDN/broken-DTS slugs to hls_transcode_slugs (gets ffmpeg → {slug}_h264)
 * - Leave clean sources (e.g. afaaro) out of that list → play raw path
 */
return [
    'enabled' => true,

    // --- Hosts / ports ---
    'mediamtx_api'     => 'http://127.0.0.1:9997',
    'srt_publish_host' => 'afaaro.myvnc.com',
    'srt_local_host'   => '127.0.0.1',
    'srt_port'         => 8890,
    'rtmp_port'        => 1935,

    // Browser / VLC HLS base (no trailing slash).
    'hls_public_base' => 'http://afaaro.myvnc.com:8888',
    // Home WAMP: 127.0.0.1 avoids hairpin. soomaali.net auto-uses hls_public_base.
    'hls_proxy_upstream' => 'http://127.0.0.1:8888',
    'hls_cdn_secret'     => 'soomaali-hls-proxy-secret',

    // --- Auth / hooks ---
    'auth_upstream'  => 'https://soomaali.net/livestream/webhook',
    'stats_upstream' => 'http://afaaro.myvnc.com/demo/index.php?route=livestream/telemetry',
    'webhook_secret' => 'change-me-livestream-webhook',
    'transcode_key'  => 'soomaali-hls-transcode',

    // --- Transcode (CDN / bad timestamps only) ---
    'hls_transcode_h264'  => true,
    'hls_transcode_slugs' => ['mogadishu'],

    // --- Player / latency ---
    // Status poll interval for multiview + watch (ms).
    'player_poll_ms' => 2500,
    // hls.js live edge (segment counts). 1 = lowest lag; raise if it stalls.
    'player_live_sync' => 1,
    'player_max_latency' => 2,
];
