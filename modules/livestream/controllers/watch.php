<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Livestream\Services\MediaMtxClient;
use Module\Livestream\Services\StreamRepository;
use System\Engine\Controller;
use System\Engine\Exception\NotFoundException;

class LivestreamWatch extends Controller
{
    public function indexAction(): void
    {
        if (!livestream_enabled()) {
            throw new NotFoundException('Livestream is disabled.');
        }

        $slug = trim((string) $this->request->get('slug', 'string', ''));
        if ($slug === '') {
            throw new NotFoundException('Stream not found.');
        }

        $repo = new StreamRepository($this->db);
        $stream = $repo->findBySlug($slug);
        if ($stream === null) {
            throw new NotFoundException('Stream not found.');
        }

        $slugKey = (string) $stream['slug'];
        $dbLive = (($stream['status'] ?? '') === 'live');
        $isLive = $dbLive;
        $mtx = $this->mediaMtxClient();
        if ($mtx !== null) {
            $rawOnline = $mtx->isPathOnline($slugKey);
            $needsTc = livestream_slug_needs_transcode($slugKey);
            $h264Online = $needsTc
                ? $mtx->isPathOnline(livestream_h264_slug($slugKey))
                : false;
            // Match multiview: transcode slugs wait for {_h264}; others use raw.
            $isLive = $needsTc ? $h264Online : $rawOnline;
            if ($dbLive && !$rawOnline && !$h264Online) {
                try {
                    $repo->markIdle((int) ($stream['id'] ?? 0));
                } catch (\Throwable) {
                }
            }
        }

        // Always preferred playback path; never fall back to broken raw CDN HLS.
        $hlsUrl = livestream_player_hls_url($slugKey);
        $hlsFallback = '';

        $title = (string) $stream['title'];
        $statusUrl = url('livestream/status', ['slug' => $slugKey]);
        $pollMs = livestream_player_poll_ms();
        $liveSync = max(1, (int) (livestream_config()['player_live_sync'] ?? 2));
        $maxLatency = max($liveSync + 1, (int) (livestream_config()['player_max_latency'] ?? 4));

        $this->view->assign('title', $title);
        $this->view->setTitle($title);
        $this->view->addModuleStyle('livestream', 'livestream.css');
        $this->view->block(
            'scripts',
            '<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js" defer></script>'
        );
        $src = $this->view->e($this->view->moduleAsset('livestream', 'livestream.js'));
        $this->view->block('scripts', '<script src="' . $src . '" defer></script>');

        echo $this->view->inline(function () use ($stream, $isLive, $hlsUrl, $hlsFallback, $statusUrl, $pollMs, $liveSync, $maxLatency): void {
            include get_module_path('livestream') . '/views/watch.php';
        }, 'main', [
            'title'       => $title,
            'stream'      => $stream,
            'isLive'      => $isLive,
            'hlsUrl'      => $hlsUrl,
            'hlsFallback' => $hlsFallback,
            'statusUrl'   => $statusUrl,
            'pollMs'      => $pollMs,
            'liveSync'    => $liveSync,
            'maxLatency'  => $maxLatency,
        ]);
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
