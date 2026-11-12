<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Livestream\Services\MediaMtxClient;
use Module\Livestream\Services\StreamRepository;
use System\Engine\Controller;

class LivestreamHome extends Controller
{
    public function indexAction(): void
    {
        if (!livestream_enabled()) {
            $this->notify->error('Livestream is currently disabled.');
            redirect_to('');
        }

        $previews = $this->buildPreviews(40);

        $title = 'Multiviewer';
        $this->view->assign('title', $title);
        $this->view->setTitle($title);
        $this->view->addModuleStyle('livestream', 'livestream.css');
        if ($previews !== []) {
            $this->view->addScript(
                'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js',
                true
            );
            $this->view->addModuleScript('livestream', 'livestream.js');
        }

        $statusUrl = url('livestream/status');
        $pollMs = livestream_player_poll_ms();
        $liveSync = max(1, (int) (livestream_config()['player_live_sync'] ?? 2));
        $maxLatency = max($liveSync + 1, (int) (livestream_config()['player_max_latency'] ?? 4));

        echo $this->view->inline(function () use ($previews, $statusUrl, $pollMs, $liveSync, $maxLatency): void {
            include get_module_path('livestream') . '/views/index.php';
        }, 'main', [
            'title'      => $title,
            'previews'   => $previews,
            'statusUrl'  => $statusUrl,
            'pollMs'     => $pollMs,
            'liveSync'   => $liveSync,
            'maxLatency' => $maxLatency,
        ]);
    }

    /**
     * JSON live-status feed for multiviewer / watch autoplay (no page refresh).
     */
    public function statusAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!livestream_enabled()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'disabled']);
            return;
        }

        $slugFilter = strtolower(trim((string) $this->request->get('slug', 'string', ''), '/'));
        $previews = $this->buildPreviews(40);
        if ($slugFilter !== '') {
            $previews = array_values(array_filter(
                $previews,
                static fn(array $p): bool => strtolower((string) ($p['slug'] ?? '')) === $slugFilter
            ));
        }

        $liveCount = 0;
        foreach ($previews as $p) {
            if (!empty($p['live'])) {
                $liveCount++;
            }
        }

        echo json_encode([
            'ok'         => true,
            'live_count' => $liveCount,
            'streams'    => $previews,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPreviews(int $limit = 40): array
    {
        $repo = new StreamRepository($this->db);
        $streams = $repo->listRecent($limit);
        $mtx = $this->mediaMtxClient();

        $previews = [];
        foreach ($streams as $i => $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $dbLive = (($row['status'] ?? '') === 'live');
            // Prefer MediaMTX truth over stale DB "live" (SRT can disconnect instantly).
            $live = $dbLive;
            if ($mtx !== null) {
                $rawOnline = $mtx->isPathOnline($slug);
                $needsTc = livestream_slug_needs_transcode($slug);
                $h264Online = $needsTc
                    ? $mtx->isPathOnline(livestream_h264_slug($slug))
                    : false;
                // Transcode slugs: only "live" when {_h264} is ready (raw alone = black tile).
                // Clean sources (afaaro): live when raw publishes.
                $live = $needsTc ? $h264Online : $rawOnline;
                if ($dbLive && !$rawOnline && !$h264Online) {
                    try {
                        $repo->markIdle((int) ($row['id'] ?? 0));
                    } catch (\Throwable) {
                        // Non-fatal — multiviewer still uses live=false.
                    }
                }
            } elseif ($dbLive) {
                // soomaali.net: no local MediaMTX — heal stale "live" via home telemetry.
                $online = $this->remotePathOnline($slug);
                if ($online === false) {
                    $live = false;
                    try {
                        $repo->markIdle((int) ($row['id'] ?? 0));
                    } catch (\Throwable) {
                        // Non-fatal.
                    }
                }
            }

            $name = trim((string) ($row['title'] ?? ''));
            if ($name === '') {
                $name = $slug;
            }
            $hls = '';
            $hlsFallback = '';
            if ($live) {
                // afaaro → raw; mogadishu → {_h264}. Never fall back to broken CDN raw HLS.
                $hls = livestream_player_hls_url($slug);
            }
            $previews[] = [
                'index'        => $i + 1,
                'id'           => (int) ($row['id'] ?? 0),
                'title'        => $name,
                'slug'         => $slug,
                'live'         => $live,
                'watch'        => url('livestream/watch', ['slug' => $slug]),
                'hls'          => $hls,
                'hls_fallback' => $hlsFallback,
                'label'        => $name,
            ];
        }

        return $previews;
    }

    private function mediaMtxClient(): ?MediaMtxClient
    {
        $api = trim((string) (livestream_config()['mediamtx_api'] ?? ''));
        if ($api === '') {
            return null;
        }
        $client = new MediaMtxClient($api);
        return $client->isReachable() ? $client : null;
    }

    /**
     * Ask home WAMP telemetry whether the publish path is actually online.
     * @return bool|null null when upstream is unknown / unreachable
     */
    private function remotePathOnline(string $slug): ?bool
    {
        $cfg = livestream_config();
        $upstream = trim((string) ($cfg['stats_upstream'] ?? ''));
        $secret = trim((string) ($cfg['webhook_secret'] ?? ''));
        if ($upstream === '' || $secret === '' || !function_exists('curl_init')) {
            return null;
        }

        $sep = str_contains($upstream, '?') ? '&' : '?';
        $url = $upstream . $sep . http_build_query([
            'slug'   => $slug,
            'secret' => $secret,
        ]);
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Livestream-Secret: ' . $secret,
                'User-Agent: soomaali-livestream-status/1.0',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            return null;
        }

        if (livestream_slug_needs_transcode($slug)) {
            return !empty($decoded['web']['online']);
        }
        return !empty($decoded['source']['online']);
    }
}
