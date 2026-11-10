<?php
declare(strict_types=1);

namespace Module\Livestream\Services;

/**
 * Thin client for MediaMTX control API (path + SRT publisher stats).
 */
final class MediaMtxClient
{
    public function __construct(private string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** True when the control API answers (soomaali cannot reach home loopback). */
    public function isReachable(): bool
    {
        if ($this->baseUrl === '') {
            return false;
        }
        // onlinePathSet() caches /v3/paths/list for the rest of the request.
        return $this->onlinePathSet() !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPath(string $name): ?array
    {
        $name = trim($name, '/');
        if ($name === '') {
            return null;
        }
        $data = $this->getJson('/v3/paths/get/' . rawurlencode($name));
        return is_array($data) ? $data : null;
    }

    /** True when MediaMTX reports the path online with tracks. */
    public function isPathOnline(string $name): bool
    {
        $online = $this->onlinePathSet();
        $name = trim($name, '/');
        if ($online !== null) {
            return isset($online[$name]);
        }
        $path = $this->getPath($name);
        return is_array($path) && !empty($path['online']);
    }

    /**
     * One /v3/paths/list call → set of online path names (request-scoped cache).
     *
     * @return array<string, true>|null null when list API unavailable
     */
    public function onlinePathSet(): ?array
    {
        static $cache = [];
        $key = $this->baseUrl;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $data = $this->getJson('/v3/paths/list');
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return $cache[$key] = null;
        }

        $out = [];
        foreach ($data['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''), '/');
            if ($name !== '' && !empty($row['ready'])) {
                // MediaMTX v1 uses ready; older payloads used online.
                $out[$name] = true;
            } elseif ($name !== '' && !empty($row['online'])) {
                $out[$name] = true;
            }
        }
        return $cache[$key] = $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSrtConns(): array
    {
        $data = $this->getJson('/v3/srtconns/list');
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return [];
        }
        /** @var list<array<string, mixed>> $items */
        $items = [];
        foreach ($data['items'] as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }
        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSrtPublisher(string $path): ?array
    {
        foreach ($this->listSrtConns() as $conn) {
            if (($conn['path'] ?? '') === $path && ($conn['state'] ?? '') === 'publish') {
                return $conn;
            }
        }
        return null;
    }

    /**
     * Summarise ingest + optional {_h264} transcode path for the studio UI.
     *
     * @return array<string, mixed>
     */
    public function streamTelemetry(string $slug): array
    {
        $slug = trim($slug, '/');
        $h264 = $slug . '_h264';
        $source = $this->getPath($slug);
        $web = $this->getPath($h264);
        $srcSrt = $this->findSrtPublisher($slug);
        $webSrt = $this->findSrtPublisher($h264);

        return [
            'ok'           => true,
            'slug'         => $slug,
            'transcode_on' => $web !== null && !empty($web['online']),
            'source'       => $this->summarisePath($slug, $source, $srcSrt, 'ingest'),
            'web'          => $this->summarisePath($h264, $web, $webSrt, 'h264'),
            'fetched_at'   => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed>|null $path
     * @param array<string, mixed>|null $srt
     * @return array<string, mixed>
     */
    private function summarisePath(string $name, ?array $path, ?array $srt, string $role): array
    {
        if ($path === null || empty($path['online'])) {
            return [
                'name'    => $name,
                'role'    => $role,
                'online'  => false,
                'tracks'  => [],
                'readers' => 0,
            ];
        }

        $tracks = [];
        if (isset($path['tracks2']) && is_array($path['tracks2'])) {
            foreach ($path['tracks2'] as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $props = is_array($t['codecProps'] ?? null) ? $t['codecProps'] : [];
                $tracks[] = [
                    'codec'   => (string) ($t['codec'] ?? ''),
                    'width'   => isset($props['width']) ? (int) $props['width'] : null,
                    'height'  => isset($props['height']) ? (int) $props['height'] : null,
                    'profile' => isset($props['profile']) ? (string) $props['profile'] : null,
                    'level'   => isset($props['level']) ? (string) $props['level'] : null,
                    'rate'    => isset($props['sampleRate']) ? (int) $props['sampleRate'] : null,
                    'channels'=> isset($props['channelCount']) ? (int) $props['channelCount'] : null,
                ];
            }
        }

        $readers = 0;
        if (isset($path['readers']) && is_array($path['readers'])) {
            $readers = count($path['readers']);
        }

        $out = [
            'name'           => $name,
            'role'           => $role,
            'online'         => true,
            'online_since'   => (string) ($path['onlineTime'] ?? $path['readyTime'] ?? ''),
            'source_type'    => (string) (($path['source']['type'] ?? '') ?: ''),
            'tracks'         => $tracks,
            'readers'        => $readers,
            'bytes_in'       => (int) ($path['inboundBytes'] ?? $path['bytesReceived'] ?? 0),
            'bytes_out'      => (int) ($path['outboundBytes'] ?? $path['bytesSent'] ?? 0),
            'frames_error'   => (int) ($path['inboundFramesInError'] ?? 0),
            'bitrate_in_mbps'=> null,
            'rtt_ms'         => null,
            'loss_pct'       => null,
            'remote_addr'    => null,
        ];

        if ($srt !== null) {
            $out['bitrate_in_mbps'] = isset($srt['mbpsReceiveRate']) ? round((float) $srt['mbpsReceiveRate'], 3) : null;
            $out['rtt_ms'] = isset($srt['msRTT']) ? round((float) $srt['msRTT'], 2) : null;
            $out['loss_pct'] = isset($srt['packetsReceivedLossRate'])
                ? round((float) $srt['packetsReceivedLossRate'], 3)
                : null;
            $out['remote_addr'] = (string) ($srt['remoteAddr'] ?? '');
            $out['bytes_in'] = (int) ($srt['bytesReceived'] ?? $out['bytes_in']);
        }

        return $out;
    }

    /** @return array<string, mixed>|list<mixed>|null */
    private function getJson(string $path): array|null
    {
        if ($this->baseUrl === '' || !function_exists('curl_init')) {
            return null;
        }
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
