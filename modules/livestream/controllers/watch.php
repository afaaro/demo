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
            $h264Online = livestream_transcode_h264_enabled()
                ? $mtx->isPathOnline(livestream_h264_slug($slugKey))
                : false;
            $isLive = $rawOnline || $h264Online;
            if ($dbLive && !$isLive) {
                try {
                    $repo->markIdle((int) ($stream['id'] ?? 0));
                } catch (\Throwable) {
                }
            }
        }

        $hlsUrl = '';
        $hlsFallback = '';
        if ($isLive) {
            if ($mtx !== null && livestream_transcode_h264_enabled()) {
                $h264Online = $mtx->isPathOnline(livestream_h264_slug($slugKey));
                $rawOnline = $mtx->isPathOnline($slugKey);
                if ($h264Online) {
                    $hlsUrl = livestream_player_hls_url($slugKey);
                    $hlsFallback = $rawOnline ? $this->playerUrlForSlug($slugKey) : '';
                } elseif ($rawOnline) {
                    $hlsUrl = $this->playerUrlForSlug($slugKey);
                } else {
                    $hlsUrl = livestream_player_hls_url($slugKey);
                    $hlsFallback = $this->playerUrlForSlug($slugKey);
                }
            } else {
                $hlsUrl = livestream_player_hls_url($slugKey);
                if (livestream_transcode_h264_enabled()) {
                    $hlsFallback = $this->playerUrlForSlug($slugKey);
                    if ($hlsFallback === $hlsUrl) {
                        $hlsFallback = '';
                    }
                }
            }
        } else {
            // Keep preferred URL ready for autoplay when the stream comes online.
            $hlsUrl = livestream_player_hls_url($slugKey);
            if (livestream_transcode_h264_enabled()) {
                $hlsFallback = $this->playerUrlForSlug($slugKey);
                if ($hlsFallback === $hlsUrl) {
                    $hlsFallback = '';
                }
            }
        }

        $title = (string) $stream['title'];
        $statusUrl = url('livestream/status', ['slug' => $slugKey]);

        $this->view->assign('title', $title);
        $this->view->setTitle($title);
        $this->view->addModuleStyle('livestream', 'livestream.css');
        // hls.js first, then module player (both defer → document order).
        $this->view->block(
            'scripts',
            '<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js" defer></script>'
        );
        $src = $this->view->e($this->view->moduleAsset('livestream', 'livestream.js'));
        $this->view->block('scripts', '<script src="' . $src . '" defer></script>');

        echo $this->view->inline(function () use ($stream, $isLive, $hlsUrl, $hlsFallback, $statusUrl): void {
            include get_module_path('livestream') . '/views/watch.php';
        }, 'main', [
            'title'       => $title,
            'stream'      => $stream,
            'isLive'      => $isLive,
            'hlsUrl'      => $hlsUrl,
            'hlsFallback' => $hlsFallback,
            'statusUrl'   => $statusUrl,
        ]);
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
