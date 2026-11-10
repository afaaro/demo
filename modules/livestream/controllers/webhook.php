<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Livestream\Services\MediaMtxClient;
use Module\Livestream\Services\StreamRepository;
use System\Engine\Controller;

/**
 * MediaMTX HTTP auth + ready/not-ready status hooks.
 *
 * On the home WAMP box, auth prefers soomaali.net (where studio creates streams),
 * then falls back to the local DB. Successful remote auth mirrors the stream locally.
 */
class LivestreamWebhook extends Controller
{
    public function authAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!livestream_enabled()) {
            http_response_code(403);
            echo json_encode(['error' => 'disabled']);
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = [
                'user'     => (string) $this->request->post('user', 'string', ''),
                'password' => (string) $this->request->post('password', 'string', ''),
                'action'   => (string) $this->request->post('action', 'string', ''),
                'path'     => (string) $this->request->post('path', 'string', ''),
                'protocol' => (string) $this->request->post('protocol', 'string', ''),
            ];
        }

        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        $path = trim((string) ($payload['path'] ?? ''), '/');
        $password = (string) ($payload['password'] ?? '');
        $user = (string) ($payload['user'] ?? '');

        if (in_array($action, ['read', 'playback'], true)) {
            http_response_code(200);
            echo '{}';
            return;
        }

        if ($action !== 'publish') {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            return;
        }

        // ffmpeg shadow publisher: {slug}_h264 (H.265/H.264 → browser H.264)
        if ($this->isTranscodePublish($path, $user, $password)) {
            http_response_code(200);
            echo '{}';
            return;
        }

        $repo = new StreamRepository($this->db);
        $candidates = $this->publishAuthCandidates($path, $user, $password);
        if ($candidates === []) {
            http_response_code(401);
            echo json_encode(['error' => 'credentials required']);
            return;
        }

        // Prefer soomaali.net when configured (home MediaMTX → public studio DB).
        foreach ($candidates as $candidate) {
            $forwardPayload = $payload;
            $forwardPayload['path'] = $candidate['slug'];
            $forwardPayload['password'] = $candidate['key'];
            if ($user === '' || strtolower($user) === 'obs') {
                $forwardPayload['user'] = 'obs';
            }
            $remote = $this->forwardAuthToUpstream(json_encode($forwardPayload));
            if ($remote === 200) {
                if (!str_ends_with($candidate['slug'], '_h264')) {
                    $repo->upsertMirrored($candidate['slug'], $candidate['key'], $candidate['slug']);
                    $mirrored = $repo->findBySlug($candidate['slug']);
                    if ($mirrored !== null) {
                        $repo->markLive((int) $mirrored['id']);
                    }
                }
                http_response_code(200);
                echo '{}';
                return;
            }
        }

        foreach ($candidates as $candidate) {
            $stream = $repo->findBySlug($candidate['slug']);
            if ($stream === null) {
                continue;
            }
            $expected = (string) ($stream['stream_key'] ?? '');
            if ($expected !== '' && hash_equals($expected, $candidate['key'])) {
                $repo->markLive((int) $stream['id']);
                http_response_code(200);
                echo '{}';
                return;
            }
        }

        http_response_code(403);
        echo json_encode(['error' => 'invalid key']);
    }

    /**
     * Build publish auth attempts for Wirecast/OBS/Larix variants.
     *
     * OBS Custom RTMP often uses Server=rtmp://host/slug and Stream Key=KEY
     * with empty user/pass → MediaMTX path becomes "slug/KEY".
     *
     * @return list<array{slug:string,key:string}>
     */
    private function publishAuthCandidates(string $path, string $user, string $password): array
    {
        $path = trim($path, '/');
        $user = trim($user);
        $password = trim($password);

        $raw = [];

        // Standard / Wirecast SRT / Larix: path=slug, password=stream_key
        if ($path !== '' && $password !== '' && !str_contains($path, '/')) {
            $raw[] = ['slug' => $path, 'key' => $password];
        }

        // OBS RTMP: path "slug/stream_key" (password may be empty)
        if ($path !== '' && str_contains($path, '/')) {
            $parts = array_values(array_filter(
                explode('/', $path),
                static fn(string $p): bool => $p !== ''
            ));
            if (count($parts) >= 2) {
                $slugPart = $parts[0];
                $tail = $parts[count($parts) - 1];
                // Prefer key from path tail (OBS stream key field).
                if ($tail !== '' && $tail !== $slugPart) {
                    $raw[] = ['slug' => $slugPart, 'key' => $tail];
                }
                if ($password !== '') {
                    $raw[] = ['slug' => $slugPart, 'key' => $password];
                    // Server=rtmp://host/app StreamKey=slug with separate pass — rare.
                    $raw[] = ['slug' => $tail, 'key' => $password];
                }
            }
        }

        // Some clients put the key in the username field.
        if ($path !== '' && $password === '' && $user !== ''
            && !in_array(strtolower($user), ['obs', 'transcode'], true)
            && !str_contains($path, '/')
        ) {
            $raw[] = ['slug' => $path, 'key' => $user];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $c) {
            $slug = trim((string) ($c['slug'] ?? ''), '/');
            $key = (string) ($c['key'] ?? '');
            if ($slug === '' || $key === '' || str_contains($slug, '/')) {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}$/', $slug)) {
                continue;
            }
            $id = strtolower($slug) . "\0" . $key;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = ['slug' => $slug, 'key' => $key];
        }

        return $out;
    }

    private function isTranscodePublish(string $path, string $user, string $password): bool
    {
        if (!str_ends_with($path, '_h264')) {
            return false;
        }
        if ($user !== 'transcode') {
            return false;
        }
        $secret = (string) (livestream_config()['transcode_key'] ?? '');
        return $secret !== '' && hash_equals($secret, $password);
    }

    /**
     * on-online.bat: GET …/transcode&secret=…&path=slug
     * Body is "1" (run ffmpeg → {_h264}) or "0" (raw path only).
     */
    public function transcodeAction(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');

        if (!livestream_enabled()) {
            echo '0';
            return;
        }

        $cfg = livestream_config();
        $secret = (string) ($cfg['webhook_secret'] ?? '');
        $provided = (string) ($this->request->get('secret', 'string', '') ?: ($_GET['secret'] ?? ''));
        if ($secret === '' || !hash_equals($secret, $provided)) {
            http_response_code(403);
            echo '0';
            return;
        }

        $path = trim((string) ($this->request->get('path', 'string', '') ?: ($_GET['path'] ?? '')), '/');
        if ($path === '' && isset($_SERVER['MTX_PATH'])) {
            $path = trim((string) $_SERVER['MTX_PATH'], '/');
        }
        if (str_ends_with($path, '_h264')) {
            echo '0';
            return;
        }

        echo livestream_slug_needs_transcode($path) ? '1' : '0';
    }

    public function statusAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!livestream_enabled()) {
            http_response_code(403);
            echo json_encode(['error' => 'disabled']);
            return;
        }

        $cfg = livestream_config();
        $secret = (string) ($cfg['webhook_secret'] ?? '');
        $provided = (string) (
            $this->request->get('secret', 'string', '')
            ?: $this->request->post('secret', 'string', '')
            ?: ($_SERVER['HTTP_X_LIVESTREAM_SECRET'] ?? '')
        );

        if ($secret === '' || !hash_equals($secret, $provided)) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            return;
        }

        $path = trim((string) (
            $this->request->get('path', 'string', '')
            ?: $this->request->post('path', 'string', '')
            ?: ($_GET['path'] ?? '')
        ), '/');
        $event = strtolower(trim((string) (
            $this->request->get('event', 'string', '')
            ?: $this->request->post('event', 'string', '')
            ?: ($_GET['event'] ?? '')
        )));

        if ($path === '' && isset($_SERVER['MTX_PATH'])) {
            $path = trim((string) $_SERVER['MTX_PATH'], '/');
        }

        if ($path === '' || !in_array($event, ['ready', 'notready', 'live', 'idle'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'bad request']);
            return;
        }

        $repo = new StreamRepository($this->db);
        $statusSlug = $path;
        if (str_contains($path, '/')) {
            $statusSlug = explode('/', $path, 2)[0];
        }
        $stream = $repo->findBySlug($statusSlug);
        if ($stream === null && $statusSlug !== $path) {
            $stream = $repo->findBySlug($path);
        }
        if ($stream === null) {
            // Stream may exist only on soomaali.net until first publish auth mirrors it.
            http_response_code(404);
            echo json_encode(['error' => 'not found']);
            return;
        }

        if (in_array($event, ['ready', 'live'], true)) {
            $repo->markLive((int) $stream['id']);
        } else {
            $repo->markIdle((int) $stream['id']);
        }

        http_response_code(200);
        echo json_encode(['success' => true, 'status' => in_array($event, ['ready', 'live'], true) ? 'live' : 'idle']);
    }

    /**
     * Public (secret) MediaMTX telemetry for soomaali.net studio Live feed.
     * Home WAMP only — soomaali polls this when 127.0.0.1:9997 is unreachable.
     */
    public function telemetryAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: https://soomaali.net');

        if (!livestream_enabled()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'disabled']);
            return;
        }

        $cfg = livestream_config();
        $secret = (string) ($cfg['webhook_secret'] ?? '');
        $provided = (string) (
            $this->request->get('secret', 'string', '')
            ?: ($_SERVER['HTTP_X_LIVESTREAM_SECRET'] ?? '')
        );
        if ($secret === '' || !hash_equals($secret, $provided)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden']);
            return;
        }

        $slug = strtolower(trim((string) $this->request->get('slug', 'string', ''), '/'));
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $slug)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad slug']);
            return;
        }

        $api = trim((string) ($cfg['mediamtx_api'] ?? ''));
        if ($api === '') {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => 'MediaMTX API not configured']);
            return;
        }

        $client = new MediaMtxClient($api);
        if (!$client->isReachable()) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'MediaMTX API unreachable']);
            return;
        }

        echo json_encode($client->streamTelemetry($slug));
    }

    /**
     * POST the MediaMTX auth payload to soomaali.net.
     * @return int HTTP status (0 = unreachable / not configured)
     */
    private function forwardAuthToUpstream(string $rawBody): int
    {
        $upstream = trim((string) (livestream_config()['auth_upstream'] ?? ''));
        if ($upstream === '' || !function_exists('curl_init')) {
            return 0;
        }

        // Never loop back to ourselves.
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $upHost = strtolower((string) (parse_url($upstream, PHP_URL_HOST) ?: ''));
        if ($host !== '' && $upHost !== '' && ($host === $upHost || str_starts_with($host, $upHost . ':'))) {
            return 0;
        }

        $ch = curl_init($upstream);
        if ($ch === false) {
            return 0;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: soomaali-livestream-auth-forward/1.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status;
    }
}
