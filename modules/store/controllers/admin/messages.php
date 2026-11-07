<?php
declare(strict_types=1);

use Module\Store\Services\Channel;
use Module\Store\Services\ChannelClientFactory;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\Channels\NotSupportedException;
use Module\Store\Services\CredentialsVault;
use Module\Store\Services\MessageSyncService;
use Module\Store\Services\OrderRepository;
use Module\Store\Services\ReturnRepository;
use Module\Store\Services\SupportCaseRepository;
use Module\Store\Services\TenantContext;
use Module\Store\Services\TenantRepository;
use Module\Store\Services\TenantResolver;
use Module\Store\Services\TenantTokenRepository;
use System\Engine\Controller;
use System\Library\Paginator;

class StoreAdminMessages extends Controller
{
    private ChannelRepository    $channels;
    private CredentialsVault     $vault;
    private ChannelClientFactory $factory;
    private TenantResolver       $tenantResolver;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->channels = new ChannelRepository($this->db);
        $this->vault    = CredentialsVault::forTenant(
            (string) $this->config->get('store.credentials_key', '')
        );
        $this->factory  = new ChannelClientFactory($this->vault, $this->channels);
        $tenants = new TenantRepository($this->db);
        $this->tenantResolver = new TenantResolver(
            $this->db,
            $this->request,
            $this->session,
            $this->auth,
            $tenants,
            new TenantTokenRepository($this->db),
        );
        // Permissions: Action.php only.
    }

    // =========================================================================
    // Entry — Conversations-first
    // =========================================================================

    public function indexAction(): void
    {
        $params = [];
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        if ($channelId > 0) {
            $params['channel_id'] = $channelId;
        }
        redirect_to('store/admin/messages/conversations', $params);
    }

    // =========================================================================
    // Mail Inbox / Sent — secondary (Trading GetMyMessages)
    // =========================================================================

    public function mailAction(): void
    {
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $folderId  = (int) $this->request->get('folder',     'int', 0);
        $page      = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage   = 25;
        $offset    = ($page - 1) * $perPage;

        if (!in_array($folderId, [0, 1], true)) {
            $folderId = 0;
        }

        $channels = $this->channels->all();

        $channel = null;
        if ($channelId > 0) {
            $channel = $this->channels->find($channelId);
        }
        if ($channel === null) {
            foreach ($channels as $c) {
                if ($c->isConnected()) {
                    $channel = $c;
                    break;
                }
            }
        }

        $messages = [];
        $total    = 0;
        $unread   = 0;
        $lastSync = null;

        if ($channel !== null) {
            $tid = TenantContext::get()->has() ? (int) TenantContext::get()->id() : 0;
            if ($tid > 0) {
                $messages = $this->db->query(
                    "SELECT * FROM `#__store_messages`
                      WHERE `channel_id` = ? AND `folder` = ? AND `tenant_id` = ?
                      ORDER BY `receive_date` DESC, `id` DESC
                      LIMIT ? OFFSET ?",
                    [$channel->id, $folderId, $tid, $perPage, $offset]
                )->rows;

                $total = (int) $this->db->query(
                    "SELECT COUNT(*) FROM `#__store_messages`
                      WHERE `channel_id` = ? AND `folder` = ? AND `tenant_id` = ?",
                    [$channel->id, $folderId, $tid]
                )->value;

                $unread = (int) $this->db->query(
                    "SELECT COUNT(*) FROM `#__store_messages`
                      WHERE `channel_id` = ? AND `folder` = ? AND `is_read` = 0 AND `tenant_id` = ?",
                    [$channel->id, $folderId, $tid]
                )->value;

                $lastSync = $this->db->query(
                    "SELECT MAX(`synced_at`) FROM `#__store_messages`
                      WHERE `channel_id` = ? AND `folder` = ? AND `tenant_id` = ?",
                    [$channel->id, $folderId, $tid]
                )->value ?: null;
            }
        }

        echo $this->view->inline(function () use (
            $channels, $channel, $folderId, $page, $perPage,
            $messages, $total, $unread, $lastSync
        ) {
            $mailTitle = $folderId === 1 ? 'Mail — Sent' : 'Mail — Inbox';
            $this->view->assign('title', $mailTitle);
            $this->view->block('right_sidebar', 'Messages');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo $this->messageListStyles();
            echo '<div class="sf-msg">';
            echo '<div class="sf-msg-head">';
            echo '<h1>' . $e($mailTitle) . '</h1>';
            echo '<div class="sf-msg-actions">';
            if ($channel !== null) {
                echo '<form method="post"'
                   . ' action="' . $e($this->url->to('store/admin/messages/sync')) . '"'
                   . ' onsubmit="return messageSyncSubmit(this, \'Fetch new mail from eBay?\');">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="channel_id" value="' . (int) $channel->id . '">';
                echo '<input type="hidden" name="folder" value="' . (int) $folderId . '">';
                echo '<button type="submit" class="sf-msg-btn"><i class="bi bi-arrow-clockwise"></i>Sync</button>';
                echo '</form>';
            }
            echo '<a href="' . $e($this->url->to('store/admin/channels')) . '" class="sf-msg-btn">'
               . '<i class="bi bi-plug"></i>Channels</a>';
            echo '</div></div>';

            $this->renderChannelSelector(
                $channels,
                $channel,
                'store/admin/messages/mail',
                ['folder' => $folderId]
            );

            if ($channel === null) {
                echo '<p class="sf-msg-empty">No connected channels. '
                   . 'Connect one on the <a href="'
                   . $e($this->url->to('store/admin/channels'))
                   . '">Channels</a> page.</p></div>';
                return;
            }

            $tabKey = $folderId === 1 ? 'sent' : 'inbox';
            $this->renderMessageTabs($channel, $tabKey, $folderId);

            if ($lastSync === null) {
                echo '<p class="sf-msg-empty">No mail stored yet. Click <strong>Sync</strong> to fetch from eBay My Messages.</p></div>';
                return;
            }

            if (empty($messages)) {
                echo '<p class="sf-msg-empty">No mail in this folder.</p></div>';
                return;
            }

            echo '<section class="sf-msg-card">';
            echo '<div class="sf-msg-meta">'
               . 'Page ' . (int) $page . ' · '
               . '<strong>' . number_format($total) . '</strong> total · '
               . '<strong>' . number_format($unread) . '</strong> unread · '
               . 'Last synced ' . $e($this->formatWhen((string) $lastSync))
               . '</div>';

            echo '<div class="table-responsive"><table class="sf-msg-table">';
            echo '<thead><tr>'
               . '<th class="c-when">Date</th><th class="c-from">From</th>'
               . '<th class="c-preview">Subject</th><th class="c-item">Item</th>'
               . '<th class="c-status">Status</th><th class="c-view"></th>'
               . '</tr></thead><tbody>';

            foreach ($messages as $m) {
                $msgId = (string) ($m['external_id'] ?? '');
                $subject = trim((string) ($m['subject'] ?? ''));
                $item = trim((string) ($m['item_title'] ?? ''));
                $isRead = !empty($m['is_read']);
                $viewUrl = $msgId !== ''
                    ? $this->url->to('store/admin/messages/view', [
                        'id'         => $msgId,
                        'channel_id' => $channel->id,
                    ])
                    : '';

                echo '<tr' . ($isRead ? '' : ' class="is-unread"') . '>';
                echo '<td class="c-when">' . $e($this->formatWhen((string) ($m['receive_date'] ?? ''))) . '</td>';
                echo '<td class="c-from">' . $e((string) ($m['sender'] ?? '')) . '</td>';
                echo '<td class="c-preview">';
                if ($viewUrl !== '') {
                    echo '<a class="sf-msg-preview" href="' . $e($viewUrl) . '" title="' . $e($subject) . '">'
                       . $e($subject !== '' ? $subject : '(no subject)') . '</a>';
                } else {
                    echo '<span class="sf-msg-preview">' . $e($subject !== '' ? $subject : '(no subject)') . '</span>';
                }
                echo '</td>';
                echo '<td class="c-item" title="' . $e($item) . '">'
                   . ($item !== '' ? $e($item) : '<span class="sf-msg-dash">—</span>') . '</td>';
                echo '<td class="c-status">';
                echo $isRead
                    ? '<span class="sf-msg-pill">Read</span>'
                    : '<span class="sf-msg-pill is-new">New</span>';
                if (!empty($m['is_replied'])) {
                    echo ' <span class="sf-msg-pill is-ok">Replied</span>';
                }
                echo '</td>';
                echo '<td class="c-view">';
                if ($viewUrl !== '') {
                    echo '<a href="' . $e($viewUrl) . '" class="sf-msg-btn">View</a>';
                }
                echo '</td></tr>';
            }

            echo '</tbody></table></div></section>';

            $this->renderPagination($channel, $folderId, $page, $perPage, $total);
            echo '</div>';

            echo <<<'HTML'
            <script>
            function messageSyncSubmit(form, message) {
                if (!confirm(message)) {
                    return false;
                }
                const btn = form.querySelector('button');
                if (btn) {
                    btn.disabled = true;
                    const original = btn.innerHTML;
                    btn.innerHTML = 'Syncing…';
                    window.addEventListener('beforeunload', function () {
                        btn.disabled = false;
                        btn.innerHTML = original;
                    }, { once: true });
                }
                return true;
            }
            </script>
            HTML;
        });
    }

    // =========================================================================
    // Single message — read from local DB, fall back to API if missing
    // =========================================================================

    public function viewAction(): void
    {
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $messageId = trim((string) $this->request->get('id', 'string', ''));

        if ($messageId === '') {
            $this->notify->error('Message ID missing.');
            redirect_to('store/admin/messages/mail');
            return;
        }

        $channel = $this->channels->find($channelId);
        if ($channel === null) {
            $this->notify->error('Channel not found.');
            redirect_to('store/admin/messages/mail');
            return;
        }

        $tid = TenantContext::get()->has() ? (int) TenantContext::get()->id() : 0;
        $message = $tid > 0
            ? $this->db->first('store_messages', [
                'channel_id'  => $channel->id,
                'external_id' => $messageId,
                'tenant_id'   => $tid,
            ])
            : [];

        if ($message === []) {
            $this->notify->error('Message not found locally. Run a sync first.');
            redirect_to('store/admin/messages/mail', ['channel_id' => $channelId]);
            return;
        }

        // Mark as read locally when opened (Trading API has no mark-read wired here).
        if (empty($message['is_read'])) {
            $this->db->update('store_messages', ['is_read' => 1], [
                'id'        => (int) $message['id'],
                'tenant_id' => $tid,
            ]);
            $message['is_read'] = 1;
        }

        $bodyMissing = trim((string) ($message['content'] ?? '')) === '';

        echo $this->view->inline(function () use ($channel, $message, $bodyMissing) {
            $this->view->assign('title', 'Message');
            $this->view->block('right_sidebar', 'Message');

            $backUrl = $this->url->to('store/admin/messages/mail', [
                'channel_id' => $channel->id,
                'folder'     => (int) ($message['folder'] ?? 0),
            ]);

            echo '<div class="d-flex justify-content-between align-items-center mb-4">';
            echo '<h1 class="h4 mb-0">'
               . $this->view->e((string) ($message['subject'] ?? '(no subject)'))
               . '</h1>';
            echo '<div class="d-flex gap-2">';

            if ($bodyMissing) {
                $fetchUrl = $this->url->to('store/admin/messages/fetch-body');
                echo '<form method="post" action="' . $this->view->e($fetchUrl) . '" class="d-inline">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="channel_id" value="' . (int) $channel->id . '">';
                echo '<input type="hidden" name="folder" value="' . (int) ($message['folder'] ?? 0) . '">';
                echo '<input type="hidden" name="id" value="'
                   . $this->view->e((string) $message['external_id']) . '">';
                echo '<button type="submit" class="btn btn-primary">'
                   . '<i class="bi bi-cloud-download me-1"></i>Fetch body</button>';
                echo '</form>';
            }

            echo '<a href="' . $this->view->e($backUrl) . '" class="btn btn-outline-secondary">'
               . '<i class="bi bi-arrow-left me-1"></i>Back</a>';
            echo '</div>';
            echo '</div>';

            // ---- Meta table -------------------------------------------------
            echo '<div class="card"><div class="card-body">';
            echo '<dl class="row mb-0">';

            $rows = [
                'From'     => $message['sender']        ?? '',
                'To'       => $message['recipient']     ?? '',
                'Received' => $message['receive_date']  ?? '',
                'Item'     => $message['item_title']    ?? '',
                'Type'     => $message['message_type']  ?? '',
                'Question' => $message['question_type'] ?? '',
            ];

            foreach ($rows as $label => $value) {
                if ((string) $value === '') {
                    continue;
                }
                echo '<dt class="col-sm-3">' . $this->view->e($label) . '</dt>';
                echo '<dd class="col-sm-9">' . $this->view->e((string) $value) . '</dd>';
            }

            echo '</dl>';
            echo '<hr>';

            if ($bodyMissing) {
                echo '<div class="alert alert-info mb-0">'
                   . 'The message body has not been synced yet. '
                   . 'Click <strong>Fetch body</strong> above to retrieve it from eBay.'
                   . '</div>';
            } else {
                echo $this->renderMessageBody((string) $message['content']);
            }

            echo '</div></div>';
        });
    }

    public function fetchBodyAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/messages/mail');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/messages/mail');
            return;
        }

        $channelId = (int) $this->request->post('channel_id', 'int', 0);
        $folderId  = (int) $this->request->post('folder',     'int', 0);
        $messageId = trim((string) $this->request->post('id', 'string', ''));

        if (!in_array($folderId, [0, 1], true)) {
            $folderId = 0;
        }

        if ($messageId === '') {
            $this->notify->error('Message ID missing.');
            redirect_to('store/admin/messages/mail', ['channel_id' => $channelId]);
            return;
        }

        $channel = $this->channels->find($channelId);
        if ($channel === null || !$channel->isConnected()) {
            $this->notify->error('Channel not found or not connected.');
            redirect_to('store/admin/messages/mail');
            return;
        }

        try {
            $sync = new MessageSyncService(
                $this->db,
                $this->channels,
                $this->factory,
            );

            $ok = $sync->fetchBody($channel, $folderId, $messageId);

            if ($ok) {
                $this->notify->success('Message body fetched.');
            } else {
                $this->notify->warning('eBay returned no body for this message.');
            }
        } catch (\Throwable $e) {
            $this->notify->error('Could not fetch body: ' . $e->getMessage());
        }

        redirect_to('store/admin/messages/view', [
            'id'         => $messageId,
            'channel_id' => $channelId,
        ]);
    }

    // =========================================================================
    // Sync — POST /store/admin/messages/sync
    // =========================================================================

    public function syncAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/messages/mail');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/messages/mail');
            return;
        }

        $this->runSync();
    }

    /**
     * Shared sync logic. Callers must already have validated POST + CSRF.
     */
    private function runSync(): void
    {
        $channelId = (int) $this->request->post('channel_id', 'int', 0);
        $folderId  = (int) $this->request->post('folder',     'int', 0);

        if (!in_array($folderId, [0, 1], true)) {
            $folderId = 0;
        }

        $channel = $this->channels->find($channelId);
        if ($channel === null || !$channel->isConnected()) {
            $this->notify->error('Channel not found or not connected.');
            redirect_to('store/admin/messages/mail');
            return;
        }

        @set_time_limit(120);

        try {
            $sync = new MessageSyncService(
                $this->db,
                $this->channels,
                $this->factory,
            );

            // Keep web sync short — host proxies kill long PHP requests.
            $result = $sync->syncChannel($channel, $folderId, 3);

            $msg = sprintf(
                'Synced %s: %d new, %d updated across %d page(s).',
                $channel->label,
                $result['created'],
                $result['updated'],
                $result['pages'],
            );

            if (!empty($result['truncated'])) {
                $this->notify->warning($msg . ' More messages exist — run sync again.');
            } else {
                $this->notify->success($msg);
            }
        } catch (\Throwable $e) {
            $this->notify->error('Sync failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/messages/mail', [
            'channel_id' => $channelId,
            'folder'     => $folderId,
        ]);
    }

    // =========================================================================
    // Debug — GET /store/admin/messages/debug
    // =========================================================================

    public function debugAction(): void
    {
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $folderId  = (int) $this->request->get('folder',     'int', 0);
        $page      = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage   = (int) $this->request->get('per_page', 'int', 25);
        $perPage   = max(1, min(100, $perPage));
        $messageId = trim((string) $this->request->get('message_id', 'string', ''));

        if (!in_array($folderId, [0, 1], true)) {
            $folderId = 0;
        }

        $channels = $this->channels->all();

        $channel = null;
        if ($channelId > 0) {
            $channel = $this->channels->find($channelId);
        }
        if ($channel === null) {
            foreach ($channels as $c) {
                if ($c->isConnected()) {
                    $channel = $c;
                    break;
                }
            }
        }

        echo $this->view->inline(function () use (
            $channels, $channel, $folderId, $page, $perPage, $messageId
        ) {
            $this->view->assign('title', 'Debug: eBay messages');
            $this->view->block('right_sidebar', 'Messages');

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">Debug: eBay GetMyMessages</h1>';
            echo '<a href="' . $this->view->e($this->url->to('store/admin/messages/mail'))
               . '" class="btn btn-outline-secondary">'
               . '<i class="bi bi-arrow-left me-1"></i>Back</a>';
            echo '</div>';

            // ---- Form -------------------------------------------------------
            echo '<form method="get"'
               . ' action="' . $this->view->e($this->url->to('store/admin/messages/debug')) . '"'
               . ' class="card card-body mb-4"><div class="row g-2 align-items-end">';

            echo '<div class="col-md-3">'
               . '<label class="form-label small">Channel</label>'
               . '<select name="channel_id" class="form-select form-select-sm">';
            echo '<option value="0">(first connected)</option>';
            foreach ($channels as $c) {
                $sel = ($channel !== null && $c->id === $channel->id) ? ' selected' : '';
                echo '<option value="' . (int) $c->id . '"' . $sel . '>'
                   . $this->view->e($c->label)
                   . ' — ' . $this->view->e($c->status)
                   . '</option>';
            }
            echo '</select></div>';

            echo '<div class="col-md-2">'
               . '<label class="form-label small">Folder</label>'
               . '<select name="folder" class="form-select form-select-sm">'
               . '<option value="0"' . ($folderId === 0 ? ' selected' : '') . '>Inbox (0)</option>'
               . '<option value="1"' . ($folderId === 1 ? ' selected' : '') . '>Sent (1)</option>'
               . '</select></div>';

            echo '<div class="col-md-2">'
               . '<label class="form-label small">Page</label>'
               . '<input type="number" name="page" min="1" value="' . $page . '"'
               . ' class="form-control form-control-sm"></div>';

            echo '<div class="col-md-2">'
               . '<label class="form-label small">Per page</label>'
               . '<input type="number" name="per_page" min="1" max="100" value="' . $perPage . '"'
               . ' class="form-control form-control-sm"></div>';

            echo '<div class="col-md-3">'
               . '<label class="form-label small">Message ID (optional)</label>'
               . '<input type="text" name="message_id" value="'
               . $this->view->e($messageId) . '"'
               . ' class="form-control form-control-sm"'
               . ' placeholder="for ReturnMessages detail level"></div>';

            echo '<div class="col-12 mt-2">'
               . '<button type="submit" class="btn btn-primary btn-sm">Fetch</button>'
               . '</div>';

            echo '</div></form>';

            if ($channel === null) {
                echo '<div class="alert alert-info">No channel selected / none connected.</div>';
                return;
            }

            if (!$channel->isConnected()) {
                echo '<div class="alert alert-warning">Channel <strong>'
                   . $this->view->e($channel->label)
                   . '</strong> is not connected (status: '
                   . $this->view->e($channel->status) . ').</div>';
                return;
            }

            $detailLevel = $messageId !== '' ? 'ReturnMessages' : 'ReturnHeaders';
            $messageIds  = $messageId !== '' ? [$messageId] : [];

            $raw     = null;
            $parsed  = null;
            $error   = null;
            $startedAt = microtime(true);

            try {
                $client = $this->factory->make($channel);

                $callPerPage = $messageId !== '' ? 1 : $perPage;
                $callPage    = $messageId !== '' ? 1 : $page;

                $parsed = $client->fetchMyMessages(
                    $folderId,
                    $detailLevel,
                    $messageIds,
                    $callPage,
                    $callPerPage,
                );

                if (method_exists($client, 'fetchMyMessagesRaw')) {
                    $raw = $client->fetchMyMessagesRaw(
                        $folderId,
                        $detailLevel,
                        $messageIds,
                        $callPage,
                        $callPerPage,
                    );
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $elapsed = number_format((microtime(true) - $startedAt) * 1000, 0);

            // ---- Request summary --------------------------------------------
            echo '<div class="card mb-4"><div class="card-body">';
            echo '<h2 class="h6 mb-3">Request</h2>';
            echo '<dl class="row mb-0 small">';
            echo '<dt class="col-sm-3">Channel</dt><dd class="col-sm-9">'
               . $this->view->e($channel->label)
               . ' (#' . (int) $channel->id . ', type=' . $this->view->e($channel->type) . ')</dd>';

            echo '<dt class="col-sm-3">Sandbox?</dt><dd class="col-sm-9">'
               . (!empty($channel->settings['sandbox']) ? 'yes' : 'no') . '</dd>';

            echo '<dt class="col-sm-3">Marketplace</dt><dd class="col-sm-9">'
               . $this->view->e((string) ($channel->settings['marketplace'] ?? 'EBAY_GB')) . '</dd>';

            echo '<dt class="col-sm-3">Folder</dt><dd class="col-sm-9">'
               . (int) $folderId . ' (' . ($folderId === 0 ? 'Inbox' : 'Sent') . ')</dd>';

            echo '<dt class="col-sm-3">DetailLevel</dt><dd class="col-sm-9">'
               . $this->view->e($detailLevel) . '</dd>';

            if ($messageId !== '') {
                echo '<dt class="col-sm-3">MessageIDs</dt><dd class="col-sm-9">'
                   . $this->view->e($messageId) . '</dd>';
            }

            echo '<dt class="col-sm-3">Page / size</dt><dd class="col-sm-9">'
               . $callPage . ' / ' . $callPerPage . '</dd>';

            echo '<dt class="col-sm-3">Elapsed</dt><dd class="col-sm-9">'
               . $elapsed . ' ms</dd>';
            echo '</dl>';
            echo '</div></div>';

            if ($error !== null) {
                echo '<div class="alert alert-danger"><strong>Error:</strong> '
                   . $this->view->e($error) . '</div>';
                return;
            }

            // ---- Raw XML ----------------------------------------------------
            if ($raw !== null) {
                echo '<div class="card mb-4"><div class="card-body">';
                echo '<h2 class="h6 mb-3">Raw XML response</h2>';
                echo '<pre style="max-height:400px;overflow:auto;background:#f6f8fa;'
                   . 'padding:1rem;border-radius:4px;font-size:12px;">'
                   . $this->view->e($raw) . '</pre>';
                echo '</div></div>';
            } else {
                echo '<div class="alert alert-info">'
                   . 'Raw XML unavailable — add a <code>fetchMyMessagesRaw()</code> method to '
                   . '<code>EbayChannelClient</code>.'
                   . '</div>';
            }

            // ---- Parsed result ----------------------------------------------
            echo '<div class="card mb-4"><div class="card-body">';
            echo '<h2 class="h6 mb-3">Parsed result</h2>';

            if (!is_array($parsed)) {
                echo '<p class="text-muted">No parsed result.</p>';
            } else {
                echo '<dl class="row mb-3 small">';
                foreach (['total_messages', 'new_messages', 'page', 'per_page'] as $k) {
                    if (array_key_exists($k, $parsed)) {
                        echo '<dt class="col-sm-3">' . $this->view->e($k) . '</dt>'
                           . '<dd class="col-sm-9">'
                           . $this->view->e((string) $parsed[$k]) . '</dd>';
                    }
                }
                echo '<dt class="col-sm-3">messages[]</dt><dd class="col-sm-9">'
                   . count($parsed['messages'] ?? []) . ' item(s)</dd>';
                echo '</dl>';

                if (!empty($parsed['messages'])) {
                    echo '<table class="table table-sm"><thead><tr>'
                       . '<th>message_id</th><th>sender</th><th>subject</th>'
                       . '<th>receive_date</th><th>content (length)</th>'
                       . '</tr></thead><tbody>';
                    foreach ($parsed['messages'] as $m) {
                        echo '<tr>';
                        echo '<td><code>'
                           . $this->view->e((string) ($m['message_id'] ?? '')) . '</code></td>';
                        echo '<td>' . $this->view->e((string) ($m['sender'] ?? '')) . '</td>';
                        echo '<td>' . $this->view->e((string) ($m['subject'] ?? '')) . '</td>';
                        echo '<td class="text-muted small">'
                           . $this->view->e((string) ($m['receive_date'] ?? '')) . '</td>';
                        echo '<td class="text-muted small">'
                           . strlen((string) ($m['content'] ?? '')) . ' chars</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<div class="alert alert-warning mb-0">'
                       . 'Parsed response has <strong>zero</strong> messages. '
                       . 'Check the raw XML above — if it has <code>&lt;Message&gt;</code> '
                       . 'nodes, the parser is the problem. If it has '
                       . '<code>&lt;Ack&gt;Success&lt;/Ack&gt;</code> with no messages, '
                       . 'eBay has no messages for this folder.'
                       . '</div>';
                }
            }
            echo '</div></div>';
        });
    }

    // =========================================================================
    // Render helpers
    // =========================================================================

    /**
     * @param array<int, Channel> $channels
     * @param array<string, scalar> $extra  Extra GET fields preserved on switch (e.g. folder).
     */
    private function renderChannelSelector(
        array $channels,
        ?Channel $current,
        string $route = 'store/admin/messages/conversations',
        array $extra = [],
    ): void {
        $usable = array_values(array_filter(
            $channels,
            fn(Channel $c) => $c->enabled && $c->isConnected()
        ));

        if (count($usable) <= 1) {
            return;
        }

        echo '<div class="mb-3">';
        echo '<form method="get" action="' . $this->view->e($this->url->to($route)) . '"'
           . ' class="row g-2 align-items-center">';

        foreach ($extra as $name => $value) {
            echo '<input type="hidden" name="' . $this->view->e((string) $name) . '" value="'
               . $this->view->e((string) $value) . '">';
        }

        echo '<div class="col-auto">';
        echo '<label class="col-form-label">Channel</label>';
        echo '</div>';

        echo '<div class="col-auto">';
        echo '<select name="channel_id" class="form-select" onchange="this.form.submit()">';
        foreach ($usable as $c) {
            $selected = ($current !== null && $c->id === $current->id) ? ' selected' : '';
            echo '<option value="' . (int) $c->id . '"' . $selected . '>'
               . $this->view->e($c->label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    /**
     * Tabs: Conversations first, then secondary Mail folders.
     */
    private function renderMessageTabs(Channel $channel, string $current, int $folderId): void
    {
        $tabs = [
            [
                'key'   => 'conversations',
                'label' => 'Conversations',
                'icon'  => 'chat-dots',
                'url'   => $this->url->to('store/admin/messages/conversations', [
                    'channel_id' => $channel->id,
                ]),
            ],
            [
                'key'   => 'inbox',
                'label' => 'Mail inbox',
                'icon'  => 'inbox',
                'url'   => $this->url->to('store/admin/messages/mail', [
                    'channel_id' => $channel->id,
                    'folder'     => 0,
                ]),
            ],
            [
                'key'   => 'sent',
                'label' => 'Mail sent',
                'icon'  => 'send',
                'url'   => $this->url->to('store/admin/messages/mail', [
                    'channel_id' => $channel->id,
                    'folder'     => 1,
                ]),
            ],
        ];

        echo '<nav class="sf-msg-tabs">';
        foreach ($tabs as $t) {
            $on = ($t['key'] === $current) ? ' is-on' : '';
            echo '<a class="' . $on . '" href="' . $this->view->e($t['url']) . '">'
               . '<i class="bi bi-' . $t['icon'] . '"></i>'
               . $this->view->e($t['label'])
               . '</a>';
        }
        echo '</nav>';
    }

    private function renderPagination(Channel $channel, int $folderId, int $page, int $perPage, int $total): void
    {
        echo Paginator::make($total, $page, $perPage)->render(
            fn (int $p) => $this->url->to('store/admin/messages/mail', [
                'channel_id' => $channel->id,
                'folder'     => $folderId,
                'page'       => $p,
            ]),
            ['theme' => 'bootstrap']
        );
    }

    public function conversationAction(): void
    {
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $convId    = trim((string) $this->request->get('id',   'string', ''));
        $convType  = trim((string) $this->request->get('type', 'string', 'FROM_MEMBERS'));

        if ($convId === '') {
            $this->notify->error('Conversation ID missing.');
            redirect_to('store/admin/messages/conversations', ['channel_id' => $channelId]);
            return;
        }

        $channel = $this->requireMessageChannel($channelId);
        if ($channel === null || !$channel->isConnected()) {
            $this->notify->error('Channel not found or not connected.');
            redirect_to('store/admin/messages/conversations');
            return;
        }

        if ($channel->type === 'amazon') {
            $convType = 'AMAZON_ORDER';
        } elseif ($channel->type === 'bnq') {
            $convType = 'BNQ_THREAD';
        } elseif (!in_array($convType, ['FROM_EBAY', 'FROM_MEMBERS', 'AMAZON_ORDER', 'BNQ_THREAD'], true)) {
            $convType = 'FROM_MEMBERS';
        }

        $repo            = new \Module\Store\Services\ConversationRepository($this->db);
        $messages        = $repo->messagesFor((int) $channel->id, $convId);
        $autoFetchError  = null;
        $autoFetched     = false;

        // Auto-fetch thread from eBay when the local cache is empty.
        if ($messages === []) {
            try {
                $sync = new \Module\Store\Services\ConversationSyncService(
                    $this->db,
                    $this->channels,
                    $this->factory,
                    $repo,
                );
                $sync->syncThread($channel, $convId, $convType);
                $messages    = $repo->messagesFor((int) $channel->id, $convId);
                $autoFetched = $messages !== [];
            } catch (\Throwable $e) {
                $autoFetchError = $e->getMessage();
                error_log('Auto-fetch conversation failed: ' . $autoFetchError);
            }
        }

        // Mark read locally immediately. eBay mark-read is best-effort and must
        // not add another round-trip before the page renders.
        $existing = $repo->find((int) $channel->id, $convId);
        if ($existing !== null && (int) ($existing['unread_count'] ?? 0) > 0) {
            $repo->markReadLocally((int) $channel->id, $convId);
        }

        $sellerUsername = (string) ($channel->settings['ebay_username'] ?? '');
        $buyerUsername  = $this->resolveConversationBuyer(
            $sellerUsername,
            $existing,
            $messages,
        );

        $orderRepo = new OrderRepository($this->db);
        $relatedOrders = $this->resolveConversationOrders(
            $orderRepo,
            $channel,
            $existing,
            $messages,
            $buyerUsername,
            $convId,
        );
        if ($buyerUsername === '' && $relatedOrders !== []) {
            $buyerUsername = trim((string) ($relatedOrders[0]['order']['buyer_username'] ?? ''));
        }

        $returnsByOrder = [];
        $returnRepo = new ReturnRepository($this->db);
        foreach ($relatedOrders as $row) {
            $oid = (int) ($row['order']['id'] ?? 0);
            if ($oid > 0) {
                $returnsByOrder[$oid] = $returnRepo->forOrder($oid);
            }
        }

        $cases = (new SupportCaseRepository($this->db))->forConversationContext(
            (int) $channel->id,
            $buyerUsername,
            (string) ($existing['reference_id'] ?? ''),
            (string) ($existing['reference_id'] ?? ''),
        );

        $refundHint = $this->conversationRefundHint($messages, $relatedOrders, $returnsByOrder, $cases);

        $orderSidebar = $this->renderConversationOrderSidebar(
            $channel,
            $buyerUsername,
            $existing,
            $relatedOrders,
            $returnsByOrder,
            $cases,
            $refundHint,
        );

        $amazonActions = [];
        if ($channel->type === 'amazon') {
            try {
                $client = $this->factory->make($channel);
                if (method_exists($client, 'messagingActions')) {
                    $amazonActions = $client->messagingActions($convId);
                }
            } catch (\Throwable $e) {
                error_log('Amazon messagingActions (conversation): ' . $e->getMessage());
            }
        }

        echo $this->view->inline(function () use (
            $channel, $convId, $convType, $messages, $sellerUsername,
            $autoFetchError, $autoFetched, $orderSidebar, $amazonActions
        ) {
            $this->view->assign('title', 'Conversation');
            $this->view->block('right_sidebar', 'Messages');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            $backUrl = $this->url->to('store/admin/messages/conversations', [
                'channel_id' => $channel->id,
            ]);

            echo $this->conversationStyles();
            echo '<div class="sf-conv">';
            echo '<div class="sf-conv-head">';
            echo '<div><h1>Conversation</h1><code>' . $e($convId) . '</code></div>';
            echo '<div class="sf-conv-actions">';
            echo '<form method="post"'
            . ' action="' . $e($this->url->to('store/admin/messages/conversation/refresh')) . '">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="channel_id" value="' . (int) $channel->id . '">';
            echo '<input type="hidden" name="id"         value="' . $e($convId) . '">';
            echo '<input type="hidden" name="type"       value="' . $e($convType) . '">';
            echo '<button type="submit" class="sf-conv-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</button>';
            echo '</form>';
            echo '<a href="' . $e($backUrl) . '" class="sf-conv-btn"><i class="bi bi-arrow-left"></i>Back</a>';
            echo '</div></div>';

            if ($autoFetched) {
                $loadedLabel = match ($channel->type) {
                    'amazon' => 'Loaded available Amazon message types for this order.',
                    'bnq'    => 'Thread loaded from B&Q Mirakl inbox.',
                    default  => 'Thread loaded from eBay.',
                };
                echo '<p class="sf-conv-note">' . $e($loadedLabel) . '</p>';
            }

            echo '<div class="sf-conv-layout">';
            echo '<div class="sf-conv-main">';

            if ($channel->type === 'amazon') {
                echo '<div class="sf-conv-banner">'
                   . 'Amazon Messaging API is <strong>send-only</strong> — buyer replies are not available via SP-API. '
                   . 'Prefer messaging from the <a href="'
                   . $e($this->url->to('store/admin/orders'))
                   . '">order</a> when possible.'
                   . '</div>';
            }

            echo '<section class="sf-conv-card">';
            if (empty($messages)) {
                if ($autoFetchError !== null) {
                    $fromLabel = match ($channel->type) {
                        'amazon' => ' from Amazon',
                        'bnq'    => ' from B&Q',
                        default  => ' from eBay',
                    };
                    echo '<p class="sf-conv-empty">Could not load messages' . $fromLabel . ': '
                    . $e($autoFetchError)
                    . ' Use <strong>Refresh</strong> to try again.</p>';
                } else {
                    echo '<p class="sf-conv-empty">No messages in this conversation yet.</p>';
                }
            } else {
                echo '<div class="sf-conv-thread">';
                foreach ($messages as $m) {
                    $sender  = (string) $m['sender'];
                    $body    = (string) $m['body'];
                    $created = (string) ($m['created_date'] ?? '');
                    $isMine = ($sellerUsername !== '' && $sender === $sellerUsername)
                        || ($channel->type === 'bnq' && strtoupper($sender) === 'SHOP');
                    $ts = $created !== '' ? strtotime($created) : false;
                    $displayDate = $ts !== false ? date('j M H:i', $ts) : $created;

                    echo '<article class="sf-conv-msg' . ($isMine ? ' is-mine' : '') . '">';
                    echo '<header><strong>' . $e($sender !== '' ? $sender : '—') . '</strong>'
                       . '<time>' . $e($displayDate) . '</time></header>';
                    echo $this->renderMessageBody($body);
                    echo '</article>';
                }
                echo '</div>';
            }

            echo '<div class="sf-conv-reply">';
            echo '<h2>' . ($channel->type === 'amazon' ? 'Message buyer' : 'Reply') . '</h2>';
            if ($channel->type === 'amazon' && $amazonActions === []) {
                echo '<p class="sf-conv-empty">No Messaging API actions available for this order right now.</p>';
            } else {
                echo '<form method="post" action="'
                . $e($this->url->to('store/admin/messages/conversation/reply')) . '">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="channel_id" value="' . (int) $channel->id . '">';
                echo '<input type="hidden" name="id"         value="' . $e($convId) . '">';
                echo '<input type="hidden" name="type"       value="' . $e($convType) . '">';

                if ($channel->type === 'amazon' && $amazonActions !== []) {
                    echo '<label class="sf-conv-label" for="amz_action">Message type</label>';
                    echo '<select name="action" id="amz_action" required>';
                    foreach ($amazonActions as $a) {
                        echo '<option value="' . $e((string) $a['name']) . '">'
                           . $e((string) $a['label']) . '</option>';
                    }
                    echo '</select>';
                }

                echo '<textarea id="sf-conv-body" name="body" rows="4" placeholder="Type your message…" maxlength="2000" required></textarea>';
                echo '<div class="sf-conv-reply-bar">';
                echo '<div class="sf-conv-emoji">';
                echo '<button type="button" class="sf-conv-btn" id="sf-conv-emoji-btn" title="Add emoji" aria-expanded="false" aria-controls="sf-conv-emoji-panel">😊</button>';
                echo '<div class="sf-conv-emoji-panel" id="sf-conv-emoji-panel" hidden>';
                foreach ([
                    '😊', '😄', '🙂', '😉', '😍', '😅', '😢', '😮',
                    '👍', '👎', '🙏', '👋', '🤝', '❤️', '✨', '🎉',
                    '✅', '❌', '❗', '❓', '📦', '🚚',
                ] as $emoji) {
                    echo '<button type="button" data-emoji="' . $emoji . '">' . $emoji . '</button>';
                }
                echo '</div></div>';
                echo '<span>Max 2000 characters'
                   . ($channel->type === 'amazon' ? '. Plain text only — no HTML or email addresses.' : '.')
                   . '</span>';
                echo '<button type="submit" class="sf-conv-btn is-primary">'
                . '<i class="bi bi-send"></i>'
                . ($channel->type === 'amazon' ? 'Send to buyer' : 'Send reply')
                . '</button>';
                echo '</div>';
                echo <<<'JS'
<script>
(function () {
  const ta = document.getElementById('sf-conv-body');
  const btn = document.getElementById('sf-conv-emoji-btn');
  const panel = document.getElementById('sf-conv-emoji-panel');
  if (!ta || !btn || !panel) return;
  function close() {
    panel.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
  }
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    const open = panel.hidden;
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  panel.addEventListener('click', function (e) {
    const pick = e.target.closest('[data-emoji]');
    if (!pick) return;
    const emoji = pick.getAttribute('data-emoji') || '';
    const max = parseInt(ta.getAttribute('maxlength') || '2000', 10);
    const start = ta.selectionStart ?? ta.value.length;
    const end = ta.selectionEnd ?? start;
    const next = ta.value.slice(0, start) + emoji + ta.value.slice(end);
    if (next.length > max) return;
    ta.value = next;
    const pos = start + emoji.length;
    ta.focus();
    ta.setSelectionRange(pos, pos);
  });
  document.addEventListener('click', function (e) {
    if (panel.hidden) return;
    if (panel.contains(e.target) || btn.contains(e.target)) return;
    close();
  });
})();
</script>
JS;
                echo '</form>';
            }
            echo '</div></section></div>';
            echo '<aside class="sf-conv-side">' . $orderSidebar . '</aside>';
            echo '</div></div>';
        });
    }

    public function replyAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/messages/conversations');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/messages/conversations');
            return;
        }

        $channelId = (int) $this->request->post('channel_id', 'int', 0);
        $convId    = trim((string) $this->request->post('id',   'string', ''));
        $convType  = trim((string) $this->request->post('type', 'string', 'FROM_MEMBERS'));
        $body      = trim((string) $this->request->post('body', 'string', ''));
        $action    = trim((string) $this->request->post('action', 'string', ''));

        $channel = $this->requireMessageChannel($channelId);
        if ($channel === null || !$channel->isConnected()) {
            $this->notify->error('Channel not found or not connected.');
            redirect_to('store/admin/messages/conversations');
            return;
        }

        if ($channel->type === 'amazon') {
            $convType = 'AMAZON_ORDER';
        } elseif ($channel->type === 'bnq') {
            $convType = 'BNQ_THREAD';
        } elseif (!in_array($convType, ['FROM_EBAY', 'FROM_MEMBERS', 'AMAZON_ORDER', 'BNQ_THREAD'], true)) {
            $convType = 'FROM_MEMBERS';
        }

        if ($convId === '' || $body === '') {
            $this->notify->error('Message body required.');
            redirect_to('store/admin/messages/conversation', [
                'channel_id' => $channelId,
                'id'         => $convId,
                'type'       => $convType,
            ]);
            return;
        }

        try {
            $client = $this->factory->make($channel);
            if (!method_exists($client, 'sendMessage')) {
                throw new \RuntimeException('This channel cannot send messages.');
            }
            if ($channel->type === 'amazon') {
                $sent = $client->sendMessage($convId, $convType, $body, $action);
            } else {
                $sent = $client->sendMessage($convId, $convType, $body);
            }
            $this->notify->success($channel->type === 'amazon' ? 'Message sent to buyer.' : 'Reply sent.');

            // Cache the outbound reply immediately, then refresh the thread.
            $repo = new \Module\Store\Services\ConversationRepository($this->db);
            $this->cacheOutboundReply($repo, $channel, $convId, $body, is_array($sent) ? $sent : []);
            $sync = new \Module\Store\Services\ConversationSyncService(
                $this->db,
                $this->channels,
                $this->factory,
                $repo,
            );

            try {
                $sync->syncThread($channel, $convId, $convType);
            } catch (\Throwable $e) {
                error_log('Post-reply thread sync failed: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $this->notify->error('Send failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/messages/conversation', [
            'channel_id' => $channelId,
            'id'         => $convId,
            'type'       => $convType,
        ]);
    }

    public function refreshConversationAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/messages/conversations');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/messages/conversations');
            return;
        }

        $channelId = (int) $this->request->post('channel_id', 'int', 0);
        $convId    = trim((string) $this->request->post('id',   'string', ''));
        $convType  = trim((string) $this->request->post('type', 'string', 'FROM_MEMBERS'));

        $channel = $this->requireMessageChannel($channelId);
        if ($channel === null || !$channel->isConnected()) {
            $this->notify->error('Channel not found or not connected.');
            redirect_to('store/admin/messages/conversations');
            return;
        }

        if ($channel->type === 'amazon') {
            $convType = 'AMAZON_ORDER';
        } elseif ($channel->type === 'bnq') {
            $convType = 'BNQ_THREAD';
        } elseif (!in_array($convType, ['FROM_EBAY', 'FROM_MEMBERS', 'AMAZON_ORDER', 'BNQ_THREAD'], true)) {
            $convType = 'FROM_MEMBERS';
        }

        try {
            $repo = new \Module\Store\Services\ConversationRepository($this->db);
            $sync = new \Module\Store\Services\ConversationSyncService(
                $this->db,
                $this->channels,
                $this->factory,
                $repo,
            );

            $result = $sync->syncThread($channel, $convId, $convType);
            $this->notify->success('Fetched ' . $result['messages'] . ' message(s).');
        } catch (\Throwable $e) {
            $this->notify->error('Refresh failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/messages/conversation', [
            'channel_id' => $channelId,
            'id'         => $convId,
            'type'       => $convType,
        ]);
    }

    public function conversationsAction(): void
    {
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $page      = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage   = 25;
        $offset    = ($page - 1) * $perPage;

        $channels = $this->channels->all();
        $channel  = $this->pickChannel($channels, $channelId);

        $repo = new \Module\Store\Services\ConversationRepository($this->db);

        $conversations = [];
        $total         = 0;
        $unreadTotal   = 0;
        $lastSync      = null;

        if ($channel !== null) {
            $conversations = $repo->paginate((int) $channel->id, $perPage, $offset);
            $total         = $repo->count((int) $channel->id);
            $unreadTotal   = $repo->countUnread((int) $channel->id);

            $tid = TenantContext::get()->has() ? (int) TenantContext::get()->id() : 0;
            if ($tid > 0) {
                $lastSync = $this->db->query(
                    "SELECT MAX(`synced_at`) FROM `#__store_conversations`
                      WHERE `channel_id` = ? AND `tenant_id` = ?",
                    [$channel->id, $tid]
                )->value ?: null;
            }
        }

        echo $this->view->inline(function () use (
            $channels, $channel, $page, $perPage, $offset,
            $conversations, $total, $unreadTotal, $lastSync
        ) {
            $this->view->assign('title', 'Messages');
            $this->view->block('right_sidebar', 'Messages');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo $this->messageListStyles();
            echo '<div class="sf-msg">';
            echo '<div class="sf-msg-head">';
            echo '<h1>Messages</h1>';
            echo '<div class="sf-msg-actions">';
            if ($channel !== null) {
                echo '<form method="post"'
                . ' action="' . $e($this->url->to('store/admin/messages/conversations/sync')) . '"'
                . ' onsubmit="return confirm(\'Sync conversations from eBay?\');">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="channel_id" value="' . (int) $channel->id . '">';
                echo '<button type="submit" class="sf-msg-btn"><i class="bi bi-arrow-clockwise"></i>Sync</button>';
                echo '</form>';
            }
            echo '<a href="' . $e($this->url->to('store/admin/channels')) . '" class="sf-msg-btn">'
               . '<i class="bi bi-plug"></i>Channels</a>';
            echo '</div></div>';

            $this->renderChannelSelector(
                $channels,
                $channel,
                'store/admin/messages/conversations'
            );

            if ($channel === null) {
                echo '<p class="sf-msg-empty">No connected channels.</p></div>';
                return;
            }

            $this->renderMessageTabs($channel, 'conversations', 0);

            if ($lastSync === null) {
                echo '<p class="sf-msg-empty">No conversations stored yet. Click <strong>Sync</strong> to fetch buyer threads from eBay.</p></div>';
                return;
            }

            if (empty($conversations)) {
                echo '<p class="sf-msg-empty">No conversations stored.</p></div>';
                return;
            }

            echo '<section class="sf-msg-card">';
            echo '<div class="sf-msg-meta">'
            . '<strong>' . number_format($total) . '</strong> total · '
            . '<strong>' . number_format($unreadTotal) . '</strong> unread · '
            . 'Last synced ' . $e($this->formatWhen((string) $lastSync))
            . '</div>';

            echo '<div class="table-responsive"><table class="sf-msg-table">';
            echo '<thead><tr>'
            . '<th class="c-preview">Latest message</th><th class="c-from">From</th>'
            . '<th class="c-unread">Unread</th>'
            . '<th class="c-when">When</th><th class="c-status">Status</th><th class="c-view"></th>'
            . '</tr></thead><tbody>';

            foreach ($conversations as $conv) {
                $convId = (string) $conv['external_id'];
                $type   = (string) $conv['conversation_type'];
                $preview = trim((string) $conv['latest_body']);
                $unread = (int) $conv['unread_count'];
                $status = strtoupper((string) $conv['status']);
                $pill = match ($status) {
                    'ACTIVE'              => ['is-ok', 'Active'],
                    'ARCHIVE', 'ARCHIVED' => ['', 'Archived'],
                    'DELETE', 'DELETED'   => ['is-bad', 'Deleted'],
                    default               => ['', (string) $conv['status']],
                };

                echo '<tr' . ($unread > 0 ? ' class="is-unread"' : '') . '>';
                echo '<td class="c-preview"><a class="sf-msg-preview" href="'
                   . $e($this->url->to('store/admin/messages/conversation', [
                       'channel_id' => $channel->id,
                       'id'         => $convId,
                       'type'       => $type,
                   ])) . '" title="' . $e($preview) . '">'
                   . $e($preview !== '' ? $preview : '(no message)') . '</a></td>';
                echo '<td class="c-from">' . $e((string) $conv['latest_sender']) . '</td>';
                echo '<td class="c-unread">';
                echo $unread > 0
                    ? '<span class="sf-msg-count">' . $unread . '</span>'
                    : '<span class="sf-msg-dash">—</span>';
                echo '</td>';
                echo '<td class="c-when">' . $e($this->formatWhen((string) ($conv['latest_date'] ?? ''))) . '</td>';
                echo '<td class="c-status"><span class="sf-msg-pill ' . $pill[0] . '">'
                   . $e($pill[1]) . '</span></td>';
                echo '<td class="c-view"><a class="sf-msg-btn" href="'
                   . $e($this->url->to('store/admin/messages/conversation', [
                       'channel_id' => $channel->id,
                       'id'         => $convId,
                       'type'       => $type,
                   ])) . '">View</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table></div></section>';

            echo Paginator::make($total, $page, $perPage)->render(
                fn (int $p) => $this->url->to('store/admin/messages/conversations', [
                    'channel_id' => $channel->id,
                    'page'       => $p,
                ]),
                ['theme' => 'bootstrap-sm', 'class' => 'mb-0']
            );
            echo '</div>';
        });
    }

    public function syncConversationsAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/messages/conversations');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/messages/conversations');
            return;
        }

        $channelId = (int) $this->request->post('channel_id', 'int', 0);
        $channel   = $this->requireMessageChannel($channelId);

        if ($channel === null || !$channel->isConnected()) {
            $this->notify->error('Channel not found or not connected.');
            redirect_to('store/admin/messages/conversations');
            return;
        }

        @set_time_limit(120);

        try {
            $repo = new \Module\Store\Services\ConversationRepository($this->db);
            $sync = new \Module\Store\Services\ConversationSyncService(
                $this->db,
                $this->channels,
                $this->factory,
                $repo,
            );

            // Headers only, 2 pages max (~100 threads) so the web request stays fast.
            // Open a thread (or Refresh) to load messages; Sync again for older pages.
            $syncType = match ($channel->type) {
                'amazon' => 'AMAZON_ORDER',
                'bnq'    => 'BNQ_THREAD',
                default  => 'FROM_MEMBERS',
            };
            $result = $sync->syncChannel($channel, $syncType, 0, 2);

            $msg = sprintf(
                'Synced conversations: %d new, %d updated across %d page(s). Open a thread to load messages.',
                $result['created'],
                $result['updated'],
                $result['pages'],
            );

            if (!empty($result['truncated'])) {
                $this->notify->warning($msg . ' More exist — run sync again.');
            } else {
                $this->notify->success($msg);
            }
        } catch (\Throwable $e) {
            $this->notify->error('Sync failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/messages/conversations', ['channel_id' => $channelId]);
    }

    /**
     * Buyer username for linking conversation → orders.
     * Skip courier/notification senders (e.g. duzky_uk, royalmail).
     *
     * @param array<string, mixed>|null $conversation
     * @param array<int, array<string, mixed>> $messages
     */
    private function resolveConversationBuyer(
        string $sellerUsername,
        ?array $conversation,
        array $messages,
    ): string {
        $sellerUsername = trim($sellerUsername);

        $candidates = [];
        if ($conversation !== null) {
            $candidates[] = trim((string) ($conversation['latest_sender'] ?? ''));
        }
        foreach ($messages as $m) {
            $candidates[] = trim((string) ($m['sender'] ?? ''));
            $candidates[] = trim((string) ($m['recipient'] ?? ''));
        }

        foreach ($candidates as $name) {
            if ($name === '') {
                continue;
            }
            if ($sellerUsername !== '' && strcasecmp($name, $sellerUsername) === 0) {
                continue;
            }
            if ($this->isSystemConversationSender($name)) {
                continue;
            }
            return $name;
        }

        return '';
    }

    private function isSystemConversationSender(string $name): bool
    {
        $n = strtolower(trim($name));
        if ($n === '') {
            return true;
        }
        foreach ([
            'duzky', 'royalmail', 'royal mail', 'evri', 'yodel', 'dpd', 'hermes',
            'inpost', 'parcelforce', 'ups', 'dhl', 'fedex', 'paypal', 'ebay',
            'inmoment', 'noreply', 'no-reply', 'mailer-daemon', 'notification',
            'tracking', 'delivery',
        ] as $needle) {
            if (str_contains($n, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed>|null $conversation
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}>
     */
    private function resolveConversationOrders(
        OrderRepository $orders,
        Channel $channel,
        ?array $conversation,
        array $messages,
        string $buyerUsername,
        string $convId,
    ): array {
        $channelId = (int) $channel->id;
        $found = [];

        $push = static function (?array $bundle) use (&$found): void {
            if ($bundle === null) {
                return;
            }
            $id = (int) ($bundle['order']['id'] ?? 0);
            if ($id <= 0 || isset($found[$id])) {
                return;
            }
            $found[$id] = $bundle;
        };

        $referenceId = trim((string) ($conversation['reference_id'] ?? ''));
        $referenceType = strtoupper(trim((string) ($conversation['reference_type'] ?? '')));

        if ($channel->type === 'amazon' && $convId !== '') {
            $push($orders->bundleByExternalId($channelId, $convId));
        }

        if ($referenceId !== '') {
            if (in_array($referenceType, ['ORDER', 'TRANSACTION', 'PURCHASE'], true)
                || str_contains(strtolower($referenceId), '-')
            ) {
                $push($orders->bundleByExternalId($channelId, $referenceId));
            }
            foreach ($orders->forListingItem($channelId, $referenceId, 5) as $bundle) {
                $push($bundle);
            }
        }

        $blob = $referenceId . "\n" . (string) ($conversation['latest_body'] ?? '');
        foreach ($messages as $m) {
            $blob .= "\n" . (string) ($m['body'] ?? '');
        }
        foreach ($this->extractTrackingCandidates($blob) as $tracking) {
            $push($orders->bundleByTracking($channelId, $tracking));
        }

        if ($buyerUsername !== '') {
            foreach ($orders->forBuyer($channelId, $buyerUsername, 5) as $bundle) {
                $push($bundle);
            }
        }

        return array_values($found);
    }

    /**
     * @return array<int, string>
     */
    private function extractTrackingCandidates(string $text): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = [];
        if (preg_match_all('/\b([A-Z]{2}\d{8,22}[A-Z]{2})\b/i', $text, $m)) {
            foreach ($m[1] as $code) {
                $out[strtoupper($code)] = strtoupper($code);
            }
        }
        if (preg_match_all('/\b(\d{12,22})\b/', $text, $m2)) {
            foreach ($m2[1] as $code) {
                $out[$code] = $code;
            }
        }
        return array_values($out);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}> $relatedOrders
     * @param array<int, array<int, array<string, mixed>>> $returnsByOrder
     * @param array<int, array<string, mixed>> $cases
     * @return array{needed: bool, reasons: array<int, string>}
     */
    private function conversationRefundHint(
        array $messages,
        array $relatedOrders,
        array $returnsByOrder,
        array $cases,
    ): array {
        $reasons = [];
        $blob = '';
        foreach ($messages as $m) {
            $blob .= ' ' . (string) ($m['body'] ?? '');
        }
        $blob = strtolower(html_entity_decode(strip_tags($blob), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach ([
            'refund' => 'Buyer mentioned a refund',
            'return' => 'Buyer mentioned a return',
            'not received' => 'Item not received mentioned',
            "haven't received" => 'Item not received mentioned',
            'did not receive' => 'Item not received mentioned',
            'damaged' => 'Damage mentioned',
            'wrong item' => 'Wrong item mentioned',
            'missing' => 'Missing item mentioned',
            'money back' => 'Refund mentioned',
        ] as $needle => $label) {
            if (str_contains($blob, $needle) && !in_array($label, $reasons, true)) {
                $reasons[] = $label;
            }
        }

        foreach ($returnsByOrder as $rows) {
            foreach ($rows as $ret) {
                $st = strtolower((string) ($ret['status'] ?? ''));
                if (in_array($st, ['open', 'approved', 'received'], true)) {
                    $reasons[] = 'Open return #' . (int) ($ret['id'] ?? 0)
                        . ($st !== '' ? ' (' . $st . ')' : '');
                }
            }
        }

        foreach ($cases as $case) {
            $st = strtoupper((string) ($case['status'] ?? ''));
            if ($st !== '' && !in_array($st, [
                'CLOSED', 'CS_CLOSED', 'CLOSED_WITH_ESCALATION', 'COMPLETED',
                'REFUNDED', 'DENIED', 'CANCELLED', 'CANCELED',
            ], true)) {
                $kind = (string) ($case['kind'] ?? 'case');
                $reasons[] = 'Open ' . $kind . ' ' . (string) ($case['external_id'] ?? '')
                    . ($st !== '' ? ' (' . $st . ')' : '');
            }
        }

        foreach ($relatedOrders as $row) {
            $st = strtoupper((string) ($row['order']['status'] ?? ''));
            if (in_array($st, ['CANCELLED', 'CANCELED', 'REFUNDED', 'RETURNED'], true)) {
                $reasons[] = 'Order status is ' . $st;
            }
        }

        $reasons = array_values(array_unique($reasons));
        return ['needed' => $reasons !== [], 'reasons' => $reasons];
    }

    /**
     * Conversation context panel: related order, tracking, returns, refund.
     *
     * @param array<string, mixed>|null $conversation
     * @param array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}> $relatedOrders
     * @param array<int, array<int, array<string, mixed>>> $returnsByOrder
     * @param array<int, array<string, mixed>> $cases
     * @param array{needed: bool, reasons: array<int, string>} $refundHint
     */
    private function renderConversationOrderSidebar(
        Channel $channel,
        string $buyerUsername,
        ?array $conversation,
        array $relatedOrders,
        array $returnsByOrder = [],
        array $cases = [],
        array $refundHint = ['needed' => false, 'reasons' => []],
    ): string {
        $referenceId   = trim((string) ($conversation['reference_id'] ?? ''));
        $referenceType = trim((string) ($conversation['reference_type'] ?? ''));

        ob_start();
        $orderWhen = static function (?string $at): string {
            if ($at === null || trim($at) === '') {
                return '—';
            }
            $ts = strtotime($at);
            return $ts === false ? $at : date('j M Y H:i', $ts);
        };
        ?>
        <div class="sf-conv-card">
            <div class="sf-conv-side-h"><h2>Order context</h2></div>
            <div class="sf-conv-side-b">

            <?php if (!empty($refundHint['needed'])): ?>
                <div class="sf-conv-warn">
                    <strong>May need a refund / return</strong>
                    <ul>
                        <?php foreach ($refundHint['reasons'] as $reason): ?>
                            <li><?= $this->view->e($reason) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($buyerUsername !== ''): ?>
                <p class="sf-conv-meta">Buyer <strong><?= $this->view->e($buyerUsername) ?></strong></p>
            <?php endif; ?>

            <?php if ($referenceId !== ''): ?>
                <p class="sf-conv-meta">
                    <?= $this->view->e($referenceType !== '' ? $referenceType : 'Reference') ?>
                    <code><?= $this->view->e($referenceId) ?></code>
                    <?php if (strtoupper($referenceType) === 'LISTING' || ctype_digit($referenceId)): ?>
                        <?php
                        $listingHost = match (strtoupper((string) ($channel->settings['marketplace'] ?? 'EBAY_GB'))) {
                            'EBAY_US' => 'www.ebay.com',
                            'EBAY_DE' => 'www.ebay.de',
                            'EBAY_AU' => 'www.ebay.com.au',
                            default   => 'www.ebay.co.uk',
                        };
                        ?>
                        <a target="_blank" rel="noopener"
                           href="https://<?= $listingHost ?>/itm/<?= $this->view->e(rawurlencode($referenceId)) ?>">
                            View listing
                        </a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($relatedOrders === []): ?>
                <p class="sf-conv-empty">
                    <?= $buyerUsername === ''
                        ? 'No matching order found from this thread yet.'
                        : 'No local orders for this buyer.' ?>
                </p>
                <a class="sf-conv-btn is-primary" href="<?= $this->view->e($this->url->to('store/admin/orders')) ?>">
                    Go to orders
                </a>
            <?php else: ?>
                <?php foreach ($relatedOrders as $row):
                    $order = $row['order'];
                    $items = $row['items'];
                    $oid = (int) $order['id'];
                    $status = strtoupper((string) ($order['status'] ?? ''));
                    $tracking = trim((string) ($order['tracking_number'] ?? ''));
                    $canShip = $channel->isConnected()
                        && !in_array($status, ['FULFILLED', 'CANCELLED', 'CANCELED', 'REFUNDED'], true)
                        && $tracking === '';
                    $viewUrl = $this->url->to('store/admin/orders/view', ['id' => $oid]);
                    $returnUrl = $this->url->to('store/admin/orders/view', ['id' => $oid]) . '#returns';
                    $tone = match ($status) {
                        'NOT_STARTED' => 'is-wait',
                        'IN_PROGRESS' => 'is-info',
                        'FULFILLED'   => 'is-ok',
                        'CANCELLED', 'CANCELED', 'REFUNDED' => 'is-bad',
                        default       => '',
                    };
                    $orderReturns = $returnsByOrder[$oid] ?? [];
                    ?>
                    <div class="sf-conv-order">
                        <div class="sf-conv-order-h">
                            <a href="<?= $this->view->e($viewUrl) ?>">
                                <?= $this->view->e((string) $order['external_order_id']) ?>
                            </a>
                            <span class="sf-conv-pill <?= $tone ?>"><?= $this->view->e((string) $order['status']) ?></span>
                        </div>
                        <p class="sf-conv-meta">
                            <?= $this->view->e(number_format((float) $order['total'], 2)) ?>
                            <?= $this->view->e((string) $order['currency']) ?>
                            · <?= $this->view->e($orderWhen((string) ($order['created_at'] ?? ''))) ?>
                        </p>
                        <?php if ($items !== []): ?>
                            <ul class="sf-conv-items">
                                <?php foreach (array_slice($items, 0, 3) as $item): ?>
                                    <li title="<?= $this->view->e((string) ($item['title'] !== '' ? $item['title'] : $item['external_sku'])) ?>">
                                        <?= (int) $item['qty'] ?>×
                                        <?= $this->view->e((string) ($item['title'] !== '' ? $item['title'] : $item['external_sku'])) ?>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (count($items) > 3): ?>
                                    <li class="is-more">+<?= count($items) - 3 ?> more</li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($tracking !== ''): ?>
                            <p class="sf-conv-meta">
                                Shipped
                                <?php if (!empty($order['carrier_code'])): ?>
                                    (<?= $this->view->e((string) $order['carrier_code']) ?>)
                                <?php endif; ?>
                                <code><?= $this->view->e($tracking) ?></code>
                            </p>
                        <?php endif; ?>

                        <?php if ($orderReturns !== []): ?>
                            <p class="sf-conv-meta"><strong>Returns</strong>
                                <?php foreach ($orderReturns as $ret): ?>
                                    <br>
                                    <a href="<?= $this->view->e($this->url->to('store/admin/returns/view', [
                                        'id' => (int) $ret['id'],
                                    ])) ?>">
                                        #<?= (int) $ret['id'] ?>
                                        <?= $this->view->e((string) $ret['status']) ?>
                                    </a>
                                    <?php if ((float) ($ret['refund_amount'] ?? 0) > 0): ?>
                                        · <?= $this->view->e(number_format((float) $ret['refund_amount'], 2)) ?>
                                        <?= $this->view->e((string) ($ret['currency'] ?? '')) ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>

                        <div class="sf-conv-order-actions">
                            <a href="<?= $this->view->e($viewUrl) ?>" class="sf-conv-btn">View order</a>
                            <a href="<?= $this->view->e($returnUrl) ?>" class="sf-conv-btn">Return / refund</a>
                            <?php if ($canShip): ?>
                                <a href="<?= $this->view->e($viewUrl) ?>#fulfillment" class="sf-conv-btn is-primary">Ship</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($cases !== []): ?>
                <h3>Support cases</h3>
                <ul class="sf-conv-cases">
                    <?php foreach ($cases as $case): ?>
                        <li>
                            <a href="<?= $this->view->e($this->url->to('store/admin/cases/view', [
                                'id' => (int) $case['id'],
                            ])) ?>">
                                <?= $this->view->e((string) ($case['kind'] ?? 'case')) ?>
                                <?= $this->view->e((string) ($case['external_id'] ?? '')) ?>
                            </a>
                            <span class="sf-conv-pill"><?= $this->view->e((string) ($case['status'] ?? '')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($buyerUsername !== ''): ?>
                <a class="sf-conv-more" href="<?= $this->view->e($this->url->to('store/admin/orders', [
                    'q' => $buyerUsername,
                    'channel_id' => (int) $channel->id,
                ])) ?>">All orders for buyer</a>
            <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Pick a channel by id, or fall back to the first connected and enabled one.
     *
     * @param array<int, Channel> $channels
     */
    private function pickChannel(array $channels, int $preferredId): ?Channel
    {
        if ($preferredId > 0) {
            $channel = $this->requireMessageChannel($preferredId);
            if ($channel !== null) {
                return $channel;
            }
        }

        foreach ($channels as $c) {
            if ($c->enabled && $c->isConnected()) {
                return $this->bindMessageChannel($c);
            }
        }

        return null;
    }

    /**
     * Load a messaging channel and bind TenantContext + vault to its tenant.
     * Platform owners may open another tenant's channel via findAny.
     */
    private function requireMessageChannel(int $channelId): ?Channel
    {
        if ($channelId <= 0) {
            return null;
        }

        $channel = $this->channels->find($channelId);
        if ($channel === null && $this->tenantResolver->isPlatformOwner()) {
            $channel = $this->channels->findAny($channelId);
        }
        if ($channel === null) {
            return null;
        }

        return $this->bindMessageChannel($channel);
    }

    private function bindMessageChannel(Channel $channel): Channel
    {
        $tid = (int) $channel->tenantId;
        if ($tid > 0) {
            $current = TenantContext::get()->has() ? (int) TenantContext::get()->id() : 0;
            if ($current !== $tid) {
                $this->tenantResolver->selectTenantForUser($tid);
            }
            $this->vault = CredentialsVault::forTenant(
                (string) $this->config->get('store.credentials_key', ''),
                $tid
            );
            $this->factory = new ChannelClientFactory($this->vault, $this->channels);
        }
        return $channel;
    }

    /**
     * Persist an outbound reply even if eBay thread refresh fails.
     *
     * @param array<string, mixed> $sent
     */
    private function cacheOutboundReply(
        \Module\Store\Services\ConversationRepository $repo,
        Channel $channel,
        string $conversationId,
        string $body,
        array $sent,
    ): void {
        $messageId = trim((string) ($sent['messageId'] ?? $sent['message_id'] ?? ''));
        if ($messageId === '') {
            $messageId = 'local-' . bin2hex(random_bytes(8));
        }

        $seller = trim((string) ($channel->settings['ebay_username'] ?? ''));
        try {
            $repo->upsertMessage((int) $channel->id, $conversationId, [
                'messageId'         => $messageId,
                'messageBody'       => $body,
                'senderUsername'    => $seller !== '' ? $seller : 'me',
                'recipientUsername' => '',
                'createdDate'       => gmdate('c'),
                'readStatus'        => true,
            ]);
        } catch (\Throwable $e) {
            error_log('Outbound reply cache failed: ' . $e->getMessage());
        }
    }

    private function formatWhen(?string $at): string
    {
        if ($at === null || trim($at) === '') {
            return '—';
        }
        $ts = strtotime($at);
        return $ts === false ? $at : date('d M H:i', $ts);
    }

    private function messageListStyles(): string
    {
        return <<<'CSS'
<style>
.sf-msg{color:#1f2937}
.sf-msg-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:.85rem;flex-wrap:wrap}
.sf-msg-head h1{margin:0;font-size:1.35rem;font-weight:650;letter-spacing:-.02em}
.sf-msg-actions{display:flex;gap:.45rem;align-items:center}
.sf-msg-btn{display:inline-flex;align-items:center;gap:.35rem;height:2.05rem;padding:0 .75rem;border:1px solid #d1d5db;border-radius:.45rem;background:#fff;color:#374151;font-size:.8rem;font-weight:600;text-decoration:none}
.sf-msg-btn:hover{background:#f3f4f6;color:#111827}
.sf-msg-tabs{display:flex;align-items:center;gap:.2rem;flex-wrap:wrap;margin:0 0 .85rem}
.sf-msg-tabs a{display:inline-flex;align-items:center;gap:.35rem;color:#6b7280;text-decoration:none;padding:.35rem .7rem;border-radius:999px;font-size:.84rem;font-weight:600}
.sf-msg-tabs a:hover{background:#f3f4f6;color:#111827}
.sf-msg-tabs a.is-on{background:#dbeafe;color:#1d4ed8}
.sf-msg-empty{margin:0;padding:1rem;background:#fff;border:1px solid #e5e7eb;border-radius:.7rem;color:#6b7280;font-size:.84rem}
.sf-msg-card{background:#fff;border:1px solid #e5e7eb;border-radius:.7rem;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.sf-msg-meta{padding:.65rem 1rem;border-bottom:1px solid #eef0f3;font-size:.8rem;color:#6b7280}
.sf-msg-table{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
.sf-msg-table thead th{background:#4b5563;color:#fff;font-weight:500;font-size:.72rem;letter-spacing:.02em;border:0;white-space:nowrap;padding:.6rem .75rem;text-align:left}
.sf-msg-table tbody td{border-top:1px solid #f1f5f9;padding:.6rem .75rem;font-size:.82rem;vertical-align:middle}
.sf-msg-table tbody tr:hover{background:#f8fafc}
.sf-msg-table tbody tr.is-unread{background:#f8fbff}
.sf-msg-table .c-preview{width:auto}
.sf-msg-table .c-from{width:12rem}
.sf-msg-table .c-item{width:16rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#4b5563}
.sf-msg-table .c-unread{width:5rem;text-align:center}
.sf-msg-table .c-when{width:7.2rem}
.sf-msg-table .c-status{width:7.5rem}
.sf-msg-table .c-view{width:4.8rem;text-align:right}
.sf-msg-pill.is-new{background:#dbeafe;color:#1d4ed8}
.sf-msg-preview{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#111827;text-decoration:none;font-weight:550}
.sf-msg-preview:hover{color:#2563eb}
.sf-msg-table tr.is-unread .sf-msg-preview{font-weight:700}
.sf-msg-count{display:inline-flex;align-items:center;justify-content:center;min-width:1.35rem;height:1.35rem;padding:0 .35rem;border-radius:999px;background:#2563eb;color:#fff;font-size:.7rem;font-weight:700}
.sf-msg-dash{color:#9ca3af}
.sf-msg-pill{display:inline-flex;align-items:center;padding:.12rem .45rem;border-radius:999px;background:#f3f4f6;color:#374151;font-size:.68rem;font-weight:700}
.sf-msg-pill.is-ok{background:#dcfce7;color:#166534}
.sf-msg-pill.is-bad{background:#fee2e2;color:#991b1b}
.sf-msg-pager{display:flex;gap:.3rem;margin-top:.75rem}
.sf-msg-pager a{min-width:1.8rem;height:1.8rem;display:inline-flex;align-items:center;justify-content:center;border-radius:.4rem;color:#374151;text-decoration:none;font-size:.8rem}
.sf-msg-pager a:hover{background:#f3f4f6}
.sf-msg-pager a.is-on{background:#111827;color:#fff}
</style>
CSS;
    }

    private function conversationStyles(): string
    {
        return <<<'CSS'
<style>
.sf-conv{color:#1f2937}
.sf-conv-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1rem;flex-wrap:wrap}
.sf-conv-head h1{margin:0;font-size:1.35rem;font-weight:650;letter-spacing:-.02em}
.sf-conv-head code{display:block;margin-top:.15rem;font-size:.78rem;color:#6b7280}
.sf-conv-actions{display:flex;gap:.45rem;align-items:center}
.sf-conv-btn{display:inline-flex;align-items:center;gap:.35rem;height:2.05rem;padding:0 .75rem;border:1px solid #d1d5db;border-radius:.45rem;background:#fff;color:#374151;font-size:.8rem;font-weight:600;text-decoration:none}
.sf-conv-btn:hover{background:#f3f4f6;color:#111827}
.sf-conv-btn.is-primary{background:#2563eb;border-color:#2563eb;color:#fff}
.sf-conv-btn.is-primary:hover{background:#1d4ed8;color:#fff}
.sf-conv-note,.sf-conv-empty{margin:0;padding:.85rem 1rem;color:#6b7280;font-size:.84rem}
.sf-conv-banner{background:#eff6ff;border:1px solid #bfdbfe;border-radius:.55rem;padding:.7rem .9rem;font-size:.82rem;margin-bottom:.75rem}
.sf-conv-layout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(18rem,.9fr);gap:.85rem;align-items:start}
.sf-conv-card{background:#fff;border:1px solid #e5e7eb;border-radius:.7rem;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.sf-conv-thread{max-height:58vh;overflow:auto;padding:.35rem 0}
.sf-conv-msg{padding:.75rem 1rem;border-bottom:1px solid #f1f5f9}
.sf-conv-msg:last-child{border-bottom:0}
.sf-conv-msg header{display:flex;align-items:baseline;justify-content:space-between;gap:.75rem;margin-bottom:.35rem}
.sf-conv-msg header strong{font-size:.8rem;color:#111827}
.sf-conv-msg header time{font-size:.72rem;color:#6b7280;font-variant-numeric:tabular-nums;white-space:nowrap}
.sf-conv-msg.is-mine{background:#f8fafc;border-left:3px solid #2563eb}
.sf-conv-msg .message-body{font-size:.88rem;line-height:1.45;color:#1f2937}
.sf-conv-reply{border-top:1px solid #eef0f3;padding:.85rem 1rem 1rem}
.sf-conv-reply h2{margin:0 0 .55rem;font-size:.92rem;font-weight:650}
.sf-conv-reply textarea,.sf-conv-reply select{width:100%;border:1px solid #d1d5db;border-radius:.45rem;padding:.55rem .7rem;font-size:.875rem;min-height:6.5rem}
.sf-conv-reply select{min-height:2.25rem;margin-bottom:.55rem}
.sf-conv-label{display:block;font-size:.75rem;font-weight:600;color:#6b7280;margin-bottom:.25rem}
.sf-conv-reply-bar{display:flex;align-items:center;gap:.75rem;margin-top:.55rem}
.sf-conv-reply-bar span{font-size:.75rem;color:#6b7280;margin-right:auto}
.sf-conv-emoji{position:relative}
.sf-conv-emoji-panel{position:absolute;left:0;bottom:calc(100% + .4rem);z-index:20;display:grid;grid-template-columns:repeat(8,1.85rem);gap:.15rem;padding:.45rem;background:#fff;border:1px solid #e5e7eb;border-radius:.55rem;box-shadow:0 8px 24px rgba(15,23,42,.12)}
.sf-conv-emoji-panel[hidden]{display:none}
.sf-conv-emoji-panel button{width:1.85rem;height:1.85rem;border:0;background:transparent;border-radius:.35rem;font-size:1.05rem;line-height:1;padding:0}
.sf-conv-emoji-panel button:hover{background:#f3f4f6}
.sf-conv-side{position:sticky;top:4.5rem}
.sf-conv-side-h{padding:.65rem 1rem;border-bottom:1px solid #eef0f3}
.sf-conv-side-h h2{margin:0;font-size:.92rem;font-weight:650}
.sf-conv-side-b{padding:.85rem 1rem 1rem}
.sf-conv-side-b h3{margin:1rem 0 .45rem;font-size:.8rem;font-weight:650}
.sf-conv-meta{margin:0 0 .65rem;font-size:.78rem;color:#6b7280;line-height:1.4}
.sf-conv-meta code{font-size:.74rem}
.sf-conv-warn{background:#fff7ed;border:1px solid #fdba74;border-radius:.45rem;padding:.6rem .75rem;font-size:.78rem;margin-bottom:.75rem}
.sf-conv-warn ul{margin:.35rem 0 0;padding-left:1.1rem}
.sf-conv-order{border:1px solid #e5e7eb;border-radius:.55rem;padding:.7rem .75rem;margin-bottom:.65rem}
.sf-conv-order-h{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.3rem}
.sf-conv-order-h a{font-weight:650;color:#111827;text-decoration:none;font-size:.84rem}
.sf-conv-order-h a:hover{color:#2563eb}
.sf-conv-items{list-style:none;margin:0 0 .55rem;padding:0}
.sf-conv-items li{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.78rem;color:#374151}
.sf-conv-items .is-more{color:#6b7280}
.sf-conv-order-actions{display:flex;flex-wrap:wrap;gap:.35rem}
.sf-conv-pill{display:inline-flex;align-items:center;padding:.12rem .45rem;border-radius:999px;background:#f3f4f6;color:#374151;font-size:.68rem;font-weight:700;white-space:nowrap}
.sf-conv-pill.is-ok{background:#dcfce7;color:#166534}
.sf-conv-pill.is-wait{background:#fef3c7;color:#92400e}
.sf-conv-pill.is-info{background:#dbeafe;color:#1d4ed8}
.sf-conv-pill.is-bad{background:#fee2e2;color:#991b1b}
.sf-conv-cases{list-style:none;margin:0;padding:0}
.sf-conv-cases li{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.4rem 0;border-bottom:1px solid #f1f5f9;font-size:.78rem}
.sf-conv-cases a{color:#1d4ed8;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sf-conv-more{display:inline-block;margin-top:.55rem;font-size:.78rem;font-weight:600;color:#2563eb;text-decoration:none}
@media (max-width:1100px){.sf-conv-layout{grid-template-columns:1fr}.sf-conv-side{position:static}}
</style>
CSS;
    }

    /**
     * eBay My Messages Content is usually HTML; plain text is escaped.
     */
    private function renderMessageBody(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        if (!$this->looksLikeHtml($content)) {
            return '<div class="message-body">'
                 . nl2br($this->view->e($content))
                 . '</div>';
        }

        // Sandboxed iframe so eBay markup/styles render without scripts.
        $srcdoc = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="message-body message-body-html">'
             . '<iframe class="message-body-frame w-100 border-0 bg-white"'
             . ' sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"'
             . ' referrerpolicy="no-referrer"'
             . ' loading="lazy"'
             . ' title="Message body"'
             . ' srcdoc="' . $srcdoc . '"'
             . ' style="min-height:320px;height:480px"'
             . ' onload="try{var d=this.contentDocument;if(d){if(d.body){'
             . 'this.style.height=Math.max(320,d.body.scrollHeight+24)+\'px\'}}}catch(e){}"'
             . '></iframe>'
             . '</div>';
    }

    private function looksLikeHtml(string $content): bool
    {
        return (bool) preg_match('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', $content);
    }
}