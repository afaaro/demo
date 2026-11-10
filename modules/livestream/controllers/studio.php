<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Livestream\Services\MediaMtxClient;
use Module\Livestream\Services\StreamRepository;
use System\Engine\Controller;

class LivestreamStudio extends Controller
{
    private const MAX_STREAMS_PER_USER = 20;

    public function indexAction(): void
    {
        if (!livestream_enabled()) {
            $this->notify->error('Livestream is currently disabled.');
            redirect_to('');
        }

        $userId = (int) ($this->auth->id() ?? 0);
        if ($userId < 1) {
            redirect_to('user/login', ['next' => 'livestream/studio']);
        }

        $repo = new StreamRepository($this->db);

        if ($this->request->isPost()) {
            if (!$this->request->verifyToken()) {
                $this->notify->error('Invalid request.');
                redirect_to('livestream/studio');
            }

            $intent = strtolower(trim((string) $this->request->post('intent', 'string', 'save')));
            $title = trim((string) $this->request->post('title', 'string', ''));
            if ($title === '') {
                $title = 'My livestream';
            }
            $title = mb_substr($title, 0, 120);

            if ($intent === 'create') {
                $streams = $repo->listByUser($userId);
                if (count($streams) >= self::MAX_STREAMS_PER_USER) {
                    $this->notify->error('Stream limit reached (' . self::MAX_STREAMS_PER_USER . ').');
                    redirect_to('livestream/studio');
                }

                $base = livestream_slugify($title);
                $slug = $base;
                $n = 0;
                while ($repo->slugExists($slug)) {
                    $n++;
                    $slug = substr($base, 0, 40) . '-' . $n;
                }
                $id = $repo->create($userId, $title, $slug, livestream_new_stream_key());
                $this->notify->success('Stream created. Copy the OBS URL below.');
                redirect_to('livestream/studio', ['id' => $id]);
            }

            $streamId = (int) $this->request->post('stream_id', 'int', 0);
            $stream = $repo->findOwned($streamId, $userId);
            if ($stream === null) {
                $this->notify->error('Stream not found.');
                redirect_to('livestream/studio');
            }

            $repo->updateTitle((int) $stream['id'], $title);
            $this->notify->success('Title updated.');
            redirect_to('livestream/studio', ['id' => (int) $stream['id']]);
        }

        $streams = $repo->listByUser($userId);
        $selectedId = (int) ($this->request->get('id', 'int', 0));
        $stream = null;

        if ($selectedId > 0) {
            $stream = $repo->findOwned($selectedId, $userId);
        }
        if ($stream === null && $streams !== []) {
            $stream = $streams[0];
        }

        $cfg = livestream_config();
        $obsUrl = '';
        $obsUrlLocal = '';
        $rtmpUrl = '';
        $rtmpUrlLocal = '';
        $rtmpServer = '';
        $rtmpUser = 'obs';
        $rtmpPass = '';
        $watchUrl = '';
        $hlsUrl = '';
        $hlsUrlRaw = '';
        $srtStreamId = '';
        $srtHostPublic = trim((string) ($cfg['srt_publish_host'] ?? 'afaaro.myvnc.com'));
        $srtHostLocal = trim((string) ($cfg['srt_local_host'] ?? '127.0.0.1'));
        $srtPort = (int) ($cfg['srt_port'] ?? 8890);
        $rtmpPort = (int) ($cfg['rtmp_port'] ?? 1935);
        if ($stream !== null) {
            $slug = (string) $stream['slug'];
            $key = (string) $stream['stream_key'];
            $obsUrl = livestream_obs_srt_url($slug, $key);
            $obsUrlLocal = livestream_obs_srt_url_local($slug, $key);
            $rtmpUrl = livestream_rtmp_url($slug, $key);
            $rtmpUrlLocal = livestream_rtmp_url_local($slug, $key);
            $rtmpServer = livestream_rtmp_server($slug);
            $rtmpPass = $key;
            $srtStreamId = livestream_srt_streamid($slug, $key);
            $watchUrl = url('livestream/watch', ['slug' => $slug]);
            $hlsUrl = livestream_hls_url(livestream_playback_slug($slug));
            $hlsUrlRaw = livestream_hls_url($slug);
        }

        $title = 'Livestream studio';
        $this->view->assign('title', $title);
        $this->view->setTitle($title);
        $this->view->addModuleStyle('livestream', 'livestream.css');

        $csrf = $this->request->csrfToken();
        $canCreate = count($streams) < self::MAX_STREAMS_PER_USER;
        $statsUrl = $stream !== null
            ? url('livestream/studio/stats', ['id' => (int) $stream['id']])
            : '';
        echo $this->view->inline(function () use (
            $streams,
            $stream,
            $obsUrl,
            $obsUrlLocal,
            $rtmpUrl,
            $rtmpUrlLocal,
            $rtmpServer,
            $rtmpUser,
            $rtmpPass,
            $rtmpPort,
            $watchUrl,
            $hlsUrl,
            $hlsUrlRaw,
            $srtStreamId,
            $srtHostPublic,
            $srtHostLocal,
            $srtPort,
            $cfg,
            $csrf,
            $canCreate,
            $statsUrl
        ): void {
            include get_module_path('livestream') . '/views/studio.php';
        }, 'main', [
            'title'         => $title,
            'streams'       => $streams,
            'stream'        => $stream,
            'obsUrl'        => $obsUrl,
            'obsUrlLocal'   => $obsUrlLocal,
            'rtmpUrl'       => $rtmpUrl,
            'rtmpUrlLocal'  => $rtmpUrlLocal,
            'rtmpServer'    => $rtmpServer,
            'rtmpUser'      => $rtmpUser,
            'rtmpPass'      => $rtmpPass,
            'rtmpPort'      => $rtmpPort,
            'watchUrl'      => $watchUrl,
            'hlsUrl'        => $hlsUrl,
            'hlsUrlRaw'     => $hlsUrlRaw,
            'srtStreamId'   => $srtStreamId,
            'srtHostPublic' => $srtHostPublic,
            'srtHostLocal'  => $srtHostLocal,
            'srtPort'       => $srtPort,
            'cfg'           => $cfg,
            'csrf'          => $csrf,
            'canCreate'     => $canCreate,
            'statsUrl'      => $statsUrl,
        ]);
    }

    public function statsAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!livestream_enabled()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'disabled']);
            return;
        }

        $userId = (int) ($this->auth->id() ?? 0);
        if ($userId < 1) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'auth']);
            return;
        }

        $repo = new StreamRepository($this->db);
        $streamId = (int) $this->request->get('id', 'int', 0);
        $stream = $repo->findOwned($streamId, $userId);
        if ($stream === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not found']);
            return;
        }

        $cfg = livestream_config();
        $api = trim((string) ($cfg['mediamtx_api'] ?? ''));
        $upstream = trim((string) ($cfg['stats_upstream'] ?? ''));
        $slug = (string) $stream['slug'];

        $payload = null;
        $via = 'mediamtx';
        if ($api !== '') {
            $client = new MediaMtxClient($api);
            if ($client->isReachable()) {
                $payload = $client->streamTelemetry($slug);
            }
        }

        // soomaali.net cannot reach home 127.0.0.1:9997 — try home telemetry URL.
        if ($payload === null && $upstream !== '') {
            $payload = $this->fetchStatsUpstream($upstream, $slug, (string) ($cfg['webhook_secret'] ?? ''));
            if ($payload !== null) {
                $via = 'upstream';
            }
        }

        // Last resort: HLS probe — only while DB says live (avoid offline CDN spam).
        if ($payload === null && (($stream['status'] ?? '') === 'live')) {
            $payload = livestream_hls_telemetry($slug);
            if ($payload !== null) {
                $via = 'hls';
            }
        }

        if ($payload === null) {
            // Publisher stopped / API unreachable — return offline stub, do not hit :8888.
            echo json_encode([
                'ok'          => true,
                'stream_id'   => (int) $stream['id'],
                'title'       => (string) $stream['title'],
                'slug'        => $slug,
                'db_status'   => (string) ($stream['status'] ?? 'idle'),
                'via'         => 'offline',
                'transcode_on'=> false,
                'source'      => [
                    'name'   => $slug,
                    'role'   => 'ingest',
                    'online' => false,
                    'tracks' => [],
                ],
                'web'         => [
                    'name'   => livestream_playback_slug($slug),
                    'role'   => 'h264',
                    'online' => false,
                    'tracks' => [],
                ],
            ]);
            return;
        }

        $payload['stream_id'] = (int) $stream['id'];
        $payload['title'] = (string) $stream['title'];
        $payload['db_status'] = (string) ($stream['status'] ?? '');
        $payload['via'] = $via;
        echo json_encode($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchStatsUpstream(string $upstream, string $slug, string $secret): ?array
    {
        if (!function_exists('curl_init') || $secret === '') {
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
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Livestream-Secret: ' . $secret,
                'User-Agent: soomaali-livestream-stats/1.0',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) && !empty($decoded['ok']) ? $decoded : null;
    }

    public function rotateAction(): void
    {
        if (!livestream_enabled()) {
            redirect_to('');
        }
        $userId = (int) ($this->auth->id() ?? 0);
        if ($userId < 1 || !$this->request->isPost() || !$this->request->verifyToken()) {
            $this->notify->error('Invalid request.');
            redirect_to('livestream/studio');
        }

        $repo = new StreamRepository($this->db);
        $streamId = (int) $this->request->post('stream_id', 'int', 0);
        $stream = $repo->findOwned($streamId, $userId);
        if ($stream === null) {
            $this->notify->error('No stream found.');
            redirect_to('livestream/studio');
        }

        $repo->rotateKey((int) $stream['id'], livestream_new_stream_key());
        $this->notify->success('Stream key rotated. Update OBS with the new URL.');
        redirect_to('livestream/studio', ['id' => (int) $stream['id']]);
    }

    public function deleteAction(): void
    {
        if (!livestream_enabled()) {
            redirect_to('');
        }
        $userId = (int) ($this->auth->id() ?? 0);
        if ($userId < 1 || !$this->request->isPost() || !$this->request->verifyToken()) {
            $this->notify->error('Invalid request.');
            redirect_to('livestream/studio');
        }

        $repo = new StreamRepository($this->db);
        $streamId = (int) $this->request->post('stream_id', 'int', 0);
        if (!$repo->deleteOwned($streamId, $userId)) {
            $this->notify->error('Stream not found.');
            redirect_to('livestream/studio');
        }

        $this->notify->success('Stream deleted.');
        redirect_to('livestream/studio');
    }
}
