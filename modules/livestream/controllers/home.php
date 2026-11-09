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

        echo $this->view->inline(function () use ($previews, $statusUrl): void {
            include get_module_path('livestream') . '/views/index.php';
        }, 'main', [
            'title'     => $title,
            'previews'  => $previews,
            'statusUrl' => $statusUrl,
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
                $h264Online = livestream_transcode_h264_enabled()
                    ? $mtx->isPathOnline(livestream_h264_slug($slug))
                    : false;
                $live = $rawOnline || $h264Online;
                if ($dbLive && !$live) {
                    try {
                        $repo->markIdle((int) ($row['id'] ?? 0));
                    } catch (\Throwable) {
                        // Non-fatal — multiviewer still uses live=false.
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
                // Prefer path that is actually online when we know.
                if ($mtx !== null && livestream_transcode_h264_enabled()) {
                    $h264Online = $mtx->isPathOnline(livestream_h264_slug($slug));
                    $rawOnline = $mtx->isPathOnline($slug);
                    if ($h264Online) {
                        $hls = livestream_player_hls_url($slug);
                        $hlsFallback = $rawOnline ? $this->playerUrlForSlug($slug) : '';
                    } elseif ($rawOnline) {
                        $hls = $this->playerUrlForSlug($slug);
                    } else {
                        $hls = livestream_player_hls_url($slug);
                        $hlsFallback = $this->playerUrlForSlug($slug);
                    }
                } else {
                    $hls = livestream_player_hls_url($slug);
                    if (livestream_transcode_h264_enabled()) {
                        $hlsFallback = $this->playerUrlForSlug($slug);
                        if ($hlsFallback === $hls) {
                            $hlsFallback = '';
                        }
                    }
                }
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

    private function playerUrlForSlug(string $slug): string
    {
        $direct = livestream_hls_url(trim($slug, '/'));
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if ($https && str_starts_with(strtolower($direct), 'http://')) {
            return livestream_hls_proxy_url(trim($slug, '/'), 'index.m3u8');
        }
        return $direct;
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
}
