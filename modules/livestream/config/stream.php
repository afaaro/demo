<?php
declare(strict_types=1);

/**
 * Livestream + MediaMTX settings (module-local).
 * Set enabled => false to hide public pages and reject webhooks.
 */
return [
    'enabled'          => true,
    // MediaMTX control API (optional; not required for basic OBS → HLS).
    'mediamtx_api'     => 'http://127.0.0.1:9997',
    // Host remote OBS should publish to (public IP / DynDNS).
    'srt_publish_host' => 'afaaro.myvnc.com',
    // Host for OBS on the same PC / LAN (avoids hairpin NAT).
    'srt_local_host'   => '127.0.0.1',
    'srt_port'         => 8890,
    'rtmp_port'        => 1935,
    // Browser-facing HLS base (no trailing slash) — VLC / Easy Stream.
    'hls_public_base'  => 'http://afaaro.myvnc.com:8888',
    // Upstream MediaMTX for the HTTPS watch-page proxy.
    // On soomaali.net use the public DynDNS URL. On home WAMP use 127.0.0.1
    // (Tools → Modules) so the proxy does not hairpin through the router.
    'hls_proxy_upstream' => 'http://afaaro.myvnc.com:8888',
    // Must match MediaMTX hlsCDNSecret (Authorization: Bearer …).
    'hls_cdn_secret'     => 'soomaali-hls-proxy-secret',
    // Home WAMP MediaMTX: validate new studio streams against soomaali.net.
    // Leave empty on soomaali.net itself (avoids auth loops).
    'auth_upstream'      => 'https://soomaali.net/livestream/webhook',
    // soomaali.net Live feed: when 127.0.0.1:9997 is unreachable, fetch from home.
    // Home WAMP ignores this (local MediaMTX API is reachable).
    'stats_upstream'     => 'http://afaaro.myvnc.com/demo/index.php?route=livestream/telemetry',
    // ffmpeg → {slug}_h264 for broken CDN ingest (non-monotonic DTS).
    // afaaro stays on raw path; only listed slugs use {_h264} for the website.
    'hls_transcode_h264' => true,
    'hls_transcode_slugs' => ['mogadishu'],
    'transcode_key'      => 'soomaali-hls-transcode',
    // Shared secret for MediaMTX runOnReady / runOnNotReady hooks.
    'webhook_secret'   => 'change-me-livestream-webhook',
];
