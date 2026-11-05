<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\Channel;
use Module\StoreFeeder\Services\ChannelRepository;
use Module\StoreFeeder\Services\Channels\Ebay\EbayPlatformApp;
use Module\StoreFeeder\Services\CredentialsVault;
use Module\StoreFeeder\Services\TenantContext;
use System\Engine\Controller;

/**
 * eBay Marketplace Account Deletion notifications.
 *
 * Uses the first connected eBay channel in the DB for:
 *   - App credentials (client_id / client_secret)
 *   - Sandbox flag
 *   - notification_endpoint_url / notification_verification_token settings
 *
 * Public webhook:
 *   GET  /storefeeder/ebay/account-deletion?challenge_code=...  → challenge response
 *   POST /storefeeder/ebay/account-deletion                    → deletion notification
 *
 * Admin status UI:
 *   GET  /storefeeder/admin/account-deletion
 */
class StoreFeederAdminAccountDeletion extends Controller
{
    private ?Channel $ebayChannel = null;
    /** @var array<string, string>|null */
    private ?array $ebayCredentials = null;
    // =========================================================================
    // Public webhook entry
    // =========================================================================

    public function handleAction(): void
    {
        if ($this->request->isPost()) {
            $this->notifyAction();
            return;
        }
        $this->verifyAction();
    }

    // =========================================================================
    // GET — challenge-response verification
    // =========================================================================

    public function verifyAction(): void
    {
        $challenge = (string) $this->request->get('challenge_code', 'string', '');
        if ($challenge === '') {
            $this->json(400, ['error' => 'Missing challenge_code.']);
        }

        $token    = $this->token();
        $endpoint = $this->endpointUrl();

        if ($token === '') {
            $this->json(500, ['error' => 'Verification token not configured.']);
        }
        if ($endpoint === '') {
            $this->json(500, ['error' => 'Endpoint URL not configured.']);
        }

        // SHA-256(challengeCode + verificationToken + endpoint) as hex
        $hash = hash('sha256', $challenge . $token . $endpoint);

        $this->json(200, ['challengeResponse' => $hash]);
    }

    // =========================================================================
    // POST — marketplace account deletion notification
    // =========================================================================

    public function notifyAction(): void
    {
        $rawBody = (string) file_get_contents('php://input');

        if ($rawBody === '') {
            $this->log->warning('Account deletion: empty POST body');
            $this->json(400, ['error' => 'Empty body.']);
        }

        if (!$this->verifySignature($rawBody)) {
            $this->logEvent('', '', '', 'sig_failed', 'HTTP 412 signature verification failed', $rawBody);
            $this->json(412, ['error' => 'Invalid signature.']);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->json(400, ['error' => 'Invalid JSON.']);
        }

        $data      = is_array($payload['notification']['data'] ?? null)
            ? $payload['notification']['data']
            : [];
        $username  = trim((string) ($data['username']  ?? ''));
        $userId    = trim((string) ($data['userId']    ?? ''));
        $eiasToken = trim((string) ($data['eiasToken'] ?? ''));
        $topic     = (string) ($payload['notification']['notificationId']
            ?? $payload['metadata']['topic']
            ?? 'MARKETPLACE_ACCOUNT_DELETION');

        $this->log->info('eBay account deletion notification received', [
            'username' => $username,
            'userId'   => $userId,
            'topic'    => $topic,
        ]);

        $logId = $this->logEvent($username, $userId, $eiasToken, 'received', '', $rawBody);

        if ($username === '' && $userId === '') {
            $this->updateEvent($logId, 'ignored', 'no identifiers');
            // 204 — delivered; nothing to delete
            $this->respond(204);
        }

        try {
            $purged = $this->purgeUserData($username, $userId);
            $detail = sprintf(
                'messages=%d conversation_messages=%d conversations=%d orders=%d',
                $purged['messages'],
                $purged['conversation_messages'],
                $purged['conversations'],
                $purged['orders'],
            );
            $this->updateEvent($logId, 'purged', $detail);
        } catch (\Throwable $e) {
            // $this->log->error('Account deletion purge failed', [
            //     'username' => $username,
            //     'userId'   => $userId,
            //     'error'    => $e->getMessage(),
            // ]);
            $this->updateEvent($logId, 'failed', $e->getMessage());
            $this->json(500, ['error' => 'Deletion failed; will retry.']);
        }

        // eBay SDKs acknowledge with 204 No Content
        $this->respond(204);
    }

    // =========================================================================
    // Admin: generate verification token
    // =========================================================================

    public function generateTokenAction(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        // Permissions: Action.php only.

        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/account-deletion');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/account-deletion');
            return;
        }

        $channel = $this->ebayChannel();
        if ($channel === null) {
            $this->notify->error('No eBay channel found. Add a channel first.');
            redirect_to('storefeeder/admin/channels/add');
            return;
        }

        $token = self::generateVerificationToken();

        $settings = $channel->settings;
        $settings['notification_verification_token'] = $token;

        // Also default the endpoint URL if empty.
        if (trim((string) ($settings['notification_endpoint_url'] ?? '')) === '') {
            try {
                $settings['notification_endpoint_url'] = $this->url->to(
                    'storefeeder/ebay/account-deletion',
                    [],
                    absolute: true
                );
            } catch (\Throwable $e) {
                // leave blank
            }
        }

        $vault = $this->vaultForChannel($channel);
        $repo = new ChannelRepository($this->db);
        $repo->update((int) $channel->id, ['settings' => $settings], $vault);

        // Show once on next page load (don't leave secrets in the URL).
        $this->session->set('account_deletion_new_token', $token);

        $this->notify->success(
            'Verification token generated and saved to the channel. Copy it into the eBay Developer Portal.'
        );
        redirect_to('storefeeder/admin/account-deletion');
    }

    /**
     * eBay requires 32–80 chars matching [A-Za-z0-9_-].
     */
    public static function generateVerificationToken(int $length = 64): string
    {
        $length = max(32, min(80, $length));

        // URL-safe alphabet matching eBay's allowed set.
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
        $max      = strlen($alphabet) - 1;
        $token    = '';

        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, $max)];
        }

        return $token;
    }

    // =========================================================================
    // Admin status UI
    // =========================================================================

    public function indexAction(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        // Permissions: Action.php only.

        // Clear channel cache so freshly saved token is visible.
        $this->ebayChannel     = null;
        $this->ebayCredentials = null;

        $channel  = $this->ebayChannel();
        $endpoint = $this->endpointUrl();
        $tokenSet = $this->token() !== '';
        $sandbox  = $this->sandboxAllowed();
        $newToken = (string) $this->session->pull('account_deletion_new_token', '');

        $creds = [];
        try {
            $creds = $this->ebayCredentials();
        } catch (\Throwable $e) {
            // shown in UI below
        }
        $appOk = trim((string) ($creds['client_id'] ?? '')) !== ''
            && trim((string) ($creds['client_secret'] ?? '')) !== '';

        $events = [];
        try {
            $tenantId = $this->resolveAuditTenantId();
            if ($tenantId > 0) {
                $events = $this->db->query(
                    "SELECT * FROM `#__sf_account_deletion_log`
                      WHERE `tenant_id` = ?
                      ORDER BY `id` DESC LIMIT 50",
                    [$tenantId]
                )->rows;
            } else {
                $events = $this->db->query(
                    "SELECT * FROM `#__sf_account_deletion_log`
                      ORDER BY `id` DESC LIMIT 50"
                )->rows;
            }
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        $testChallenge = '';
        $testResponse  = '';
        if ($this->request->isPost() && $this->request->post('do_test', 'string', '') === '1') {
            try {
                $this->request->checkToken();
                $testChallenge = trim((string) $this->request->post('challenge_code', 'string', ''));
                if ($testChallenge !== '' && $tokenSet && $endpoint !== '') {
                    $testResponse = hash('sha256', $testChallenge . $this->token() . $endpoint);
                }
            } catch (\RuntimeException $e) {
                $this->notify->error('Session expired. Please reload.');
            }
        }

        echo $this->view->inline(function () use (
            $channel, $endpoint, $tokenSet, $sandbox, $appOk, $events,
            $testChallenge, $testResponse, $newToken
        ) {
            $this->view->assign('title', 'Account deletion');
            $this->view->block('right_sidebar', 'Channels');

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">eBay account deletion</h1>';
            echo '<a href="' . $this->view->e($this->url->to('storefeeder/admin/channels')) . '"'
               . ' class="btn btn-outline-secondary">'
               . '<i class="bi bi-arrow-left me-1"></i>Channels</a>';
            echo '</div>';

            echo '<p class="text-muted">Marketplace Account Deletion webhook — settings come from your eBay channel record.</p>';

            // Config checklist
            echo '<div class="card mb-4"><div class="card-body">';
            echo '<h2 class="h6 mb-3">Configuration (from channel)</h2>';
            echo '<dl class="row mb-0 small">';

            echo '<dt class="col-sm-4">eBay channel</dt><dd class="col-sm-8">';
            if ($channel !== null) {
                echo $this->view->e($channel->label)
                   . ' <code class="small">' . $this->view->e($channel->name) . '</code>'
                   . ' — ' . $this->view->e($channel->status);
                echo ' <a href="' . $this->view->e($this->url->to('storefeeder/admin/channels/edit', ['id' => $channel->id])) . '">Edit</a>';
            } else {
                echo '<span class="text-danger">No eBay channel found. <a href="'
                   . $this->view->e($this->url->to('storefeeder/admin/channels/add'))
                   . '">Add one</a>.</span>';
            }
            echo '</dd>';

            echo '<dt class="col-sm-4">Endpoint URL</dt><dd class="col-sm-8">';
            if ($endpoint !== '') {
                echo '<code>' . $this->view->e($endpoint) . '</code>';
                echo '<div class="form-text">Channel setting <code>notification_endpoint_url</code> — must match Developer Portal exactly.</div>';
            } else {
                echo '<span class="text-danger">Not set on channel — edit channel → Account-deletion endpoint URL</span>';
            }
            echo '</dd>';

            echo '<dt class="col-sm-4">Verification token</dt><dd class="col-sm-8">';
            if ($tokenSet) {
                echo '<span class="badge text-bg-success">Configured</span>';
            } else {
                echo '<span class="badge text-bg-danger">Missing</span>';
            }
            if ($channel !== null) {
                echo ' <form method="post" action="'
                   . $this->view->e($this->url->to('storefeeder/admin/account-deletion/generate-token'))
                   . '" class="d-inline"'
                   . ' onsubmit="return confirm(\'Generate a new token and save it to the channel? You must also update the eBay Developer Portal.\');">';
                echo $this->request->csrfField();
                echo '<button type="submit" class="btn btn-sm btn-outline-primary ms-1">'
                   . '<i class="bi bi-key me-1"></i>'
                   . ($tokenSet ? 'Regenerate' : 'Generate token')
                   . '</button>';
                echo '</form>';
            }
            echo '<div class="form-text">eBay requires 32–80 characters: A–Z a–z 0–9 _ -</div>';
            echo '</dd>';

            echo '<dt class="col-sm-4">App credentials</dt><dd class="col-sm-8">'
               . ($appOk
                    ? '<span class="badge text-bg-success">From channel</span> <span class="text-muted">(Client ID / Secret)</span>'
                    : '<span class="badge text-bg-danger">Missing</span> — save Client ID &amp; Secret on the channel')
               . '</dd>';

            echo '<dt class="col-sm-4">Environment</dt><dd class="col-sm-8">'
               . ($sandbox
                    ? '<span class="badge text-bg-warning">Sandbox</span>'
                    : '<span class="badge text-bg-success">Production</span>')
               . '<div class="form-text">From channel “Sandbox mode”. Must match SBX vs PRD app or you get HTTP 412.</div>'
               . '</dd>';

            echo '<dt class="col-sm-4">Public webhook</dt><dd class="col-sm-8"><code>'
               . $this->view->e($this->url->to('storefeeder/ebay/account-deletion'))
               . '</code></dd>';

            echo '</dl>';
            echo '</div></div>';

            // Newly generated token (shown once)
            if ($newToken !== '') {
                echo '<div class="alert alert-warning">';
                echo '<strong>New verification token</strong> — copy this into the eBay Developer Portal now. '
                   . 'It will not be shown again in full.';
                echo '<div class="input-group mt-2">';
                echo '<input type="text" class="form-control font-monospace" id="new-verification-token" '
                   . 'value="' . $this->view->e($newToken) . '" readonly>';
                echo '<button type="button" class="btn btn-outline-secondary" onclick="'
                   . 'navigator.clipboard.writeText(document.getElementById(\'new-verification-token\').value);'
                   . 'this.textContent=\'Copied\';'
                   . '">Copy</button>';
                echo '</div></div>';
            }

            // Challenge tester
            echo '<div class="card mb-4"><div class="card-body">';
            echo '<h2 class="h6 mb-3">Challenge response tester</h2>';
            echo '<p class="text-muted small">Paste a challenge_code to preview the hash eBay expects (does not call eBay).</p>';
            echo '<form method="post" class="row g-2 align-items-end">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="do_test" value="1">';
            echo '<div class="col-md-6">'
               . '<label class="form-label small">challenge_code</label>'
               . '<input type="text" name="challenge_code" class="form-control"'
               . ' value="' . $this->view->e($testChallenge) . '" required>'
               . '</div>';
            echo '<div class="col-md-3">'
               . '<button type="submit" class="btn btn-outline-primary">Compute</button>'
               . '</div>';
            echo '</form>';
            if ($testResponse !== '') {
                echo '<div class="mt-3"><label class="form-label small">challengeResponse</label>'
                   . '<pre class="bg-light p-2 rounded small mb-0">' . $this->view->e($testResponse) . '</pre></div>';
            }
            echo '</div></div>';

            // Event log
            echo '<h2 class="h6 mb-3">Recent deletion events</h2>';
            if ($events === []) {
                echo '<div class="alert alert-info">No deletion notifications logged yet.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm table-striped align-middle">';
            echo '<thead><tr>'
               . '<th>When</th><th>Username</th><th>User ID</th>'
               . '<th>Status</th><th>Detail</th>'
               . '</tr></thead><tbody>';
            foreach ($events as $ev) {
                $badge = match ((string) $ev['status']) {
                    'purged'  => 'text-bg-success',
                    'failed'  => 'text-bg-danger',
                    'ignored' => 'text-bg-secondary',
                    default   => 'text-bg-info',
                };
                echo '<tr>';
                echo '<td class="text-muted small text-nowrap">'
                   . $this->view->e((string) $ev['created_at']) . '</td>';
                echo '<td>' . $this->view->e((string) $ev['username']) . '</td>';
                echo '<td><code class="small">' . $this->view->e((string) $ev['user_id']) . '</code></td>';
                echo '<td><span class="badge ' . $badge . '">'
                   . $this->view->e((string) $ev['status']) . '</span></td>';
                echo '<td class="small">' . $this->view->e((string) $ev['detail']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    // =========================================================================
    // Signature verification
    // =========================================================================

    private function verifySignature(string $rawBody): bool
    {
        $header = $this->request->header('x-ebay-signature')
            ?? $this->request->header('X-EBAY-SIGNATURE');

        if ($header === null || $header === '') {
            // Only allow missing signatures in explicit sandbox mode.
            if ($this->sandboxAllowed()) {
                $this->log->warning('Account deletion: missing signature (allowed in sandbox)');
                return true;
            }
            $this->log->warning('Account deletion: missing X-EBAY-SIGNATURE header');
            return false;
        }

        $decoded = json_decode(base64_decode($header, true) ?: '', true);
        if (!is_array($decoded) || empty($decoded['kid']) || empty($decoded['signature'])) {
            $this->log->warning('Account deletion: malformed signature header');
            return false;
        }

        $kid       = (string) $decoded['kid'];
        $signature = (string) $decoded['signature'];

        try {
            $publicKey = $this->fetchPublicKeyPem($kid);
        } catch (\Throwable $e) {
            $this->log->error('Account deletion: could not fetch public key', [
                'kid'       => $kid,
                'sandbox'   => $this->sandboxAllowed(),
                'error'     => $e->getMessage(),
            ]);
            return false;
        }

        $binarySig = base64_decode($signature, true);
        if ($binarySig === false || $binarySig === '') {
            $this->log->warning('Account deletion: signature not valid base64');
            return false;
        }

        // Match eBay official PHP SDK:
        // openssl_verify(json_encode($message), base64_decode(sig), $key, OPENSSL_ALGO_SHA1)
        $parsed = json_decode($rawBody, true);
        if (!is_array($parsed)) {
            $this->log->warning('Account deletion: body is not JSON');
            return false;
        }

        $candidates = [
            // Official SDK path (default json_encode flags)
            json_encode($parsed),
            // Raw request body
            $rawBody,
            // Common alternate encodings
            json_encode($parsed, JSON_UNESCAPED_SLASHES),
            json_encode($parsed, JSON_UNESCAPED_UNICODE),
            json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        foreach ($candidates as $payload) {
            if (!is_string($payload) || $payload === '') {
                continue;
            }
            $ok = openssl_verify($payload, $binarySig, $publicKey, OPENSSL_ALGO_SHA1);
            if ($ok === 1) {
                return true;
            }
            // Also try SHA-256 in case eBay rotates digest
            $ok256 = openssl_verify($payload, $binarySig, $publicKey, OPENSSL_ALGO_SHA256);
            if ($ok256 === 1) {
                return true;
            }
        }

        $this->log->warning('Account deletion: signature verification failed', [
            'kid'            => $kid,
            'sandbox'        => $this->sandboxAllowed(),
            'openssl_error'  => openssl_error_string() ?: '',
            'body_len'       => strlen($rawBody),
        ]);
        return false;
    }

    /**
     * Fetch and format the public key PEM the same way as eBay's PHP SDK.
     */
    private function fetchPublicKeyPem(string $kid): string
    {
        $cacheKey = 'ebay_pubkey_pem_' . md5($kid . ($this->sandboxAllowed() ? 'sbx' : 'prd'));

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $ok);
            if ($ok && is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $sandbox = $this->sandboxAllowed();
        $base = $sandbox
            ? 'https://api.sandbox.ebay.com/commerce/notification/v1'
            : 'https://api.ebay.com/commerce/notification/v1';

        $url = $base . '/public_key/' . rawurlencode($kid);
        $token = $this->accessToken();

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Public key fetch cURL error: {$err}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || $code < 200 || $code >= 300) {
            $snippet = is_array($decoded)
                ? (string) ($decoded['errors'][0]['message'] ?? substr((string) $body, 0, 200))
                : substr((string) $body, 0, 200);
            throw new \RuntimeException(
                "Public key fetch failed (HTTP {$code}) from "
                . ($sandbox ? 'sandbox' : 'production') . ": {$snippet}"
            );
        }

        $key = (string) ($decoded['key'] ?? '');
        if ($key === '') {
            throw new \RuntimeException('Public key missing in response.');
        }

        $pem = $this->formatPublicKey($key);

        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $pem, 3600);
        }

        return $pem;
    }

    /**
     * Format key exactly like eBay/event-notification-php-sdk Validator.
     */
    private function formatPublicKey(string $key): string
    {
        $key = trim($key);

        // Official pattern: key already wrapped in PEM headers.
        if (preg_match('/^-----BEGIN PUBLIC KEY-----(.+)-----END PUBLIC KEY-----$/s', $key, $matches)) {
            $body = preg_replace('/\s+/', '', $matches[1]) ?? '';
            return "-----BEGIN PUBLIC KEY-----\n"
                 . implode("\n", str_split($body, 64))
                 . "\n-----END PUBLIC KEY-----";
        }

        // Bare base64 body
        $body = preg_replace('/\s+/', '', $key) ?? '';
        return "-----BEGIN PUBLIC KEY-----\n"
             . implode("\n", str_split($body, 64))
             . "\n-----END PUBLIC KEY-----";
    }

    private function accessToken(): string
    {
        $creds = $this->ebayCredentials();
        $clientId     = trim((string) ($creds['client_id']     ?? ''));
        $clientSecret = trim((string) ($creds['client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException(
                'eBay channel is missing Client ID / Client Secret.'
            );
        }

        $sandbox  = $this->sandboxAllowed();
        $tokenUrl = $sandbox
            ? 'https://api.sandbox.ebay.com/identity/v1/oauth2/token'
            : 'https://api.ebay.com/identity/v1/oauth2/token';

        $auth = base64_encode($clientId . ':' . $clientSecret);

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials&scope=' . rawurlencode(
                'https://api.ebay.com/oauth/api_scope'
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Authorization: Basic ' . $auth,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('App token request failed.');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || $code < 200 || $code >= 300) {
            throw new \RuntimeException("App token request failed (HTTP {$code}).");
        }

        $token = (string) ($decoded['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('App token missing in response.');
        }

        return $token;
    }

    // =========================================================================
    // Data purge
    // =========================================================================

    /**
     * @return array{messages: int, conversation_messages: int, conversations: int, orders: int}
     */
    private function purgeUserData(string $username, string $userId): array
    {
        $counts = [
            'messages'               => 0,
            'conversation_messages'  => 0,
            'conversations'          => 0,
            'orders'                 => 0,
        ];

        if ($username === '' && $userId === '') {
            return $counts;
        }

        return $this->db->transaction(function ($db) use ($username, $userId, $counts) {
            $identifiers = array_values(array_filter([$username, $userId], static fn($v) => $v !== ''));

            foreach ($identifiers as $id) {
                $counts['messages'] += $db->query(
                    "DELETE FROM `#__sf_messages`
                      WHERE `sender` = ? OR `recipient` = ?",
                    [$id, $id]
                )->affected;

                $counts['conversation_messages'] += $db->query(
                    "DELETE FROM `#__sf_conversation_messages`
                      WHERE `sender` = ? OR `recipient` = ?",
                    [$id, $id]
                )->affected;

                $counts['conversations'] += $db->query(
                    "DELETE FROM `#__sf_conversations`
                      WHERE `latest_sender` = ?",
                    [$id]
                )->affected;

                // Scrub PII from orders (keep order row for accounting, clear buyer fields)
                $counts['orders'] += $db->query(
                    "UPDATE `#__sf_orders`
                        SET `buyer_username` = '',
                            `buyer_email` = '',
                            `shipping_name` = '',
                            `shipping_address` = JSON_OBJECT()
                      WHERE `buyer_username` = ?",
                    [$id]
                )->affected;
            }

            // Drop conversations that no longer have any messages
            $db->query(
                "DELETE c FROM `#__sf_conversations` c
                  LEFT JOIN `#__sf_conversation_messages` m
                    ON m.`channel_id` = c.`channel_id`
                   AND m.`conversation_id` = c.`external_id`
                 WHERE m.`id` IS NULL"
            );

            $this->log->info('Account deletion: purge complete', [
                'username' => $username,
                'userId'   => $userId,
                'counts'   => $counts,
            ]);

            return $counts;
        });
    }

    // =========================================================================
    // Audit log
    // =========================================================================

    private function logEvent(
        string $username,
        string $userId,
        string $eiasToken,
        string $status,
        string $detail,
        string $rawBody,
    ): int {
        $tenantId = $this->resolveAuditTenantId();
        if ($tenantId <= 0) {
            $this->log->error('Account deletion: could not write audit log', [
                'error' => 'No valid tenant_id for sf_account_deletion_log',
            ]);
            return 0;
        }

        try {
            return (int) $this->db->insert('sf_account_deletion_log', [
                'tenant_id'    => $tenantId,
                'username'     => mb_substr($username, 0, 128),
                'user_id'      => mb_substr($userId, 0, 128),
                'eias_token'   => mb_substr($eiasToken, 0, 255),
                'status'       => mb_substr($status, 0, 32),
                'detail'       => mb_substr($detail, 0, 500),
                'payload_hash' => hash('sha256', $rawBody),
            ]);
        } catch (\Throwable $e) {
            $this->log->error('Account deletion: could not write audit log', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return 0;
        }
    }

    /**
     * Public eBay webhooks have no session tenant. Prefer the notification
     * channel's tenant, then TenantContext, then the first active tenant.
     */
    private function resolveAuditTenantId(): int
    {
        $channel = $this->ebayChannel();
        if ($channel !== null && $channel->tenantId > 0) {
            return $channel->tenantId;
        }

        if (TenantContext::get()->has()) {
            return (int) TenantContext::get()->id();
        }

        try {
            $row = $this->db->query(
                "SELECT `id` FROM `#__sf_tenants`
                  WHERE `status` = 'active'
                  ORDER BY `id` ASC
                  LIMIT 1"
            )->row;
            return (int) ($row['id'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function updateEvent(int $id, string $status, string $detail): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            $this->db->update('sf_account_deletion_log', [
                'status' => mb_substr($status, 0, 32),
                'detail' => mb_substr($detail, 0, 500),
            ], ['id' => $id]);
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    // =========================================================================
    // Channel-backed config helpers
    // =========================================================================

    private function ebayChannel(): ?Channel
    {
        if ($this->ebayChannel !== null) {
            return $this->ebayChannel;
        }

        $repo = new ChannelRepository($this->db);
        $route = trim($this->request->currentRoute(), '/');
        if (str_starts_with($route, 'storefeeder/ebay/')) {
            $endpoint = '';
            try {
                $endpoint = $this->url->to('storefeeder/ebay/account-deletion', [], absolute: true);
            } catch (\Throwable) {
            }
            return $this->ebayChannel = $repo->findEbayForWebhook($endpoint);
        }
        return $this->ebayChannel = $repo->findEbayForNotifications();
    }

    private function vaultForChannel(Channel $channel): CredentialsVault
    {
        $master = (string) $this->config->get('storefeeder.credentials_key', '');
        $tid    = CredentialsVault::peekEnc2Tenant($channel->credentials);
        if ($tid <= 0) {
            $tid = $channel->tenantId;
        }
        return CredentialsVault::forTenant($master, $tid > 0 ? $tid : null);
    }

    /**
     * @return array<string, string>
     */
    private function ebayCredentials(): array
    {
        if ($this->ebayCredentials !== null) {
            return $this->ebayCredentials;
        }

        $channel = $this->ebayChannel();
        if ($channel === null) {
            throw new \RuntimeException('No eBay channel found in the database.');
        }

        $sandbox   = !empty($channel->settings['sandbox']);
        $useCustom = !empty($channel->settings['custom_ebay_app']);
        $creds     = [];
        try {
            $vault = $this->vaultForChannel($channel);
            $repo  = new ChannelRepository($this->db);
            $opened = $repo->credentialsFor($channel, $vault);
            $creds  = is_array($opened) ? $opened : [];
        } catch (\Throwable $e) {
            if ($useCustom || !EbayPlatformApp::isConfigured($sandbox)) {
                throw $e;
            }
        }
        $merged = EbayPlatformApp::merge($creds, $sandbox, $useCustom);

        $out = [];
        foreach ($merged as $k => $v) {
            $out[(string) $k] = (string) $v;
        }

        return $this->ebayCredentials = $out;
    }

    private function channelSetting(string $key, string $default = ''): string
    {
        $channel = $this->ebayChannel();
        if ($channel === null) {
            return $default;
        }
        $val = $channel->settings[$key] ?? $default;
        return is_scalar($val) ? trim((string) $val) : $default;
    }

    private function token(): string
    {
        return $this->channelSetting('notification_verification_token');
    }

    private function endpointUrl(): string
    {
        $configured = $this->channelSetting('notification_endpoint_url');
        if ($configured !== '') {
            return $configured;
        }

        // Fallback: absolute URL to this app's webhook route
        try {
            return $this->url->to('storefeeder/ebay/account-deletion', [], absolute: true);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function sandboxAllowed(): bool
    {
        $channel = $this->ebayChannel();
        if ($channel === null) {
            return false;
        }
        return !empty($channel->settings['sandbox']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(int $code, array $payload): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function respond(int $code): never
    {
        http_response_code($code);
        exit;
    }
}
