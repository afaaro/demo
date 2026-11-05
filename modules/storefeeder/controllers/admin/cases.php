<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\Channel;
use Module\StoreFeeder\Services\ChannelClientFactory;
use Module\StoreFeeder\Services\ChannelRepository;
use Module\StoreFeeder\Services\CredentialsVault;
use Module\StoreFeeder\Services\SupportCaseRepository;
use Module\StoreFeeder\Services\SupportCaseSyncService;
use Module\StoreFeeder\Services\TenantContext;
use System\Engine\Controller;
use System\Library\Paginator;

class StoreFeederAdminCases extends Controller
{
    private ChannelRepository $channels;
    private CredentialsVault $vault;
    private ChannelClientFactory $factory;
    private SupportCaseRepository $cases;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->channels = new ChannelRepository($this->db);
        $this->vault = CredentialsVault::forTenant(
            (string) $this->config->get('storefeeder.credentials_key', '')
        );
        $this->factory = new ChannelClientFactory($this->vault, $this->channels);
        $this->cases = new SupportCaseRepository($this->db);
    }

    public function indexAction(): void
    {
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $status = trim((string) $this->request->get('status', 'string', ''));
        $kind = trim((string) $this->request->get('kind', 'string', ''));
        $page = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $channels = array_values(array_filter(
            $this->channels->all(),
            fn(Channel $c) => in_array($c->type, ['ebay', 'amazon'], true)
                && $c->enabled
                && $c->isConnected()
        ));
        $channel = $this->pickChannel($channels, $channelId);

        $rows = [];
        $total = 0;
        $openCount = 0;
        $lastSync = null;
        $dueAlerts = [];

        if ($channel !== null) {
            $rows = $this->cases->paginate((int) $channel->id, $perPage, $offset, $status, $kind);
            $total = $this->cases->count((int) $channel->id, $status, $kind);
            $openCount = $this->cases->countOpen((int) $channel->id);
            $dueAlerts = $this->cases->respondByAlerts((int) $channel->id, 48, 25);
            $tid = TenantContext::get()->has() ? (int) TenantContext::get()->id() : 0;
            if ($tid > 0) {
                $lastSync = $this->db->query(
                    "SELECT MAX(`synced_at`) FROM `#__sf_support_cases`
                      WHERE `channel_id` = ? AND `tenant_id` = ?",
                    [$channel->id, $tid]
                )->value ?: null;
            }
        }

        echo $this->view->inline(function () use (
            $channels, $channel, $rows, $total, $openCount, $lastSync, $dueAlerts,
            $page, $perPage, $status, $kind
        ) {
            $this->view->assign('title', 'Support Cases');
            $this->view->block('right_sidebar', 'Support Cases');
            $e = fn(string $s): string => $this->view->e($s);

            $isAmazon = $channel !== null && $channel->type === 'amazon';
            $pageTitle = $isAmazon ? 'Amazon Returns & A-to-Z' : 'eBay Support Cases';
            $syncConfirm = $isAmazon
                ? 'Sync MFN returns report and A-to-Z / SAFE-T finance events from Amazon? This can take up to two minutes.'
                : 'Sync cases and inquiries from eBay?';

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">' . $e($pageTitle) . '</h1>';
            echo '<div class="d-flex flex-wrap gap-2">';
            if ($channel !== null && $channel->isConnected()) {
                $confirmJs = json_encode(
                    $syncConfirm,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
                ) ?: '"Sync?"';
                echo '<form method="post" action="'
                   . $e($this->url->to('storefeeder/admin/cases/sync')) . '"'
                   . ' class="d-inline" onsubmit="return confirm(' . $confirmJs . ');">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="channel_id" value="' . (int) $channel->id . '">';
                echo '<button type="submit" class="btn btn-outline-primary">'
                   . '<i class="bi bi-arrow-clockwise me-1"></i>Sync</button>';
                echo '</form>';
            }
            echo '</div></div>';

            if ($channels === []) {
                echo '<div class="alert alert-info">Add and connect an eBay or Amazon channel to sync support cases / returns.</div>';
                return;
            }

            if ($isAmazon) {
                echo '<div class="alert alert-info py-2">'
                   . 'Amazon has no Claims API. Returns come from the MFN returns report; '
                   . 'A-to-Z / chargebacks / SAFE-T from Finances events. '
                   . 'Resolve claims in Seller Central; message the buyer from the order when needed.'
                   . '</div>';
            }

            echo '<form method="get" class="row g-2 align-items-end mb-3">';
            echo '<div class="col-auto"><label class="form-label small mb-1">Channel</label>';
            echo '<select name="channel_id" class="form-select form-select-sm" onchange="this.form.submit()">';
            foreach ($channels as $c) {
                $sel = ($channel !== null && (int) $c->id === (int) $channel->id) ? ' selected' : '';
                $label = $c->label !== '' ? $c->label : $c->name;
                $label .= ' (' . $c->type . ')';
                echo '<option value="' . (int) $c->id . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></div>';

            echo '<div class="col-auto"><label class="form-label small mb-1">Kind</label>';
            echo '<select name="kind" class="form-select form-select-sm" onchange="this.form.submit()">';
            $kindOptions = $isAmazon
                ? ['' => 'All', 'return' => 'Returns', 'atoz' => 'A-to-Z / Chargeback', 'safet' => 'SAFE-T']
                : ['' => 'All', 'case' => 'Cases', 'inquiry' => 'Inquiries'];
            foreach ($kindOptions as $val => $label) {
                $sel = $kind === $val ? ' selected' : '';
                echo '<option value="' . $e((string) $val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></div>';

            echo '<div class="col-auto"><label class="form-label small mb-1">Status</label>';
            echo '<input type="text" name="status" class="form-control form-control-sm" value="'
               . $e($status) . '" placeholder="e.g. OPEN" style="width:10rem">';
            echo '</div>';
            echo '<div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button></div>';
            echo '</form>';

            if ($channel === null) {
                echo '<div class="alert alert-info">Select a channel.</div>';
                return;
            }

            if ($lastSync === null) {
                echo '<div class="alert alert-info">No cases stored yet. Click <strong>Sync</strong> to pull from eBay Post-Order.</div>';
                echo '<p class="small text-muted mb-0">Auto-sync: <code>php bin/console.php sf:sync-cases 90</code></p>';
                return;
            }

            if ($dueAlerts !== []) {
                $now = time();
                $overdue = 0;
                foreach ($dueAlerts as $a) {
                    $ts = strtotime((string) ($a['seller_response_due'] ?? ''));
                    if ($ts !== false && $ts < $now) {
                        $overdue++;
                    }
                }
                $soon = count($dueAlerts) - $overdue;
                echo '<div class="alert alert-warning">';
                echo '<strong>Respond-by alerts:</strong> ';
                if ($overdue > 0) {
                    echo (int) $overdue . ' overdue';
                    if ($soon > 0) {
                        echo ', ';
                    }
                }
                if ($soon > 0) {
                    echo (int) $soon . ' due within 48h';
                }
                echo '. <ul class="mb-0 mt-2 small">';
                foreach (array_slice($dueAlerts, 0, 8) as $a) {
                    $aUrl = $this->url->to('storefeeder/admin/cases/view', ['id' => (int) $a['id']]);
                    $due = (string) ($a['seller_response_due'] ?? '');
                    $dueTs = strtotime($due);
                    $tag = ($dueTs !== false && $dueTs < $now) ? 'OVERDUE' : 'DUE SOON';
                    echo '<li><a href="' . $e($aUrl) . '">'
                       . $e((string) $a['kind']) . ' #' . $e((string) $a['external_id'])
                       . '</a> — ' . $e($tag) . ' ' . $e($due)
                       . ($a['buyer_login'] !== '' ? ' · ' . $e((string) $a['buyer_login']) : '')
                       . '</li>';
                }
                echo '</ul></div>';
            }

            echo '<p class="text-muted small mb-3">'
               . (int) $total . ' shown · ' . (int) $openCount . ' open · Last synced: '
               . $e((string) $lastSync)
               . ' · Auto-sync: <code>php bin/console.php sf:sync-cases 90</code></p>';

            if ($rows === []) {
                echo '<div class="alert alert-info">No support cases match this filter.</div>';
                return;
            }

            echo '<div class="table-responsive border rounded bg-white">';
            echo '<table class="table table-sm table-hover align-middle mb-0">';
            echo '<thead class="table-light"><tr>'
               . '<th>ID</th><th>Kind</th><th>Type</th><th>Status</th><th>Buyer</th>'
               . '<th>Item</th><th>Claim</th><th>Respond by</th><th>Updated</th><th></th>'
               . '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $viewUrl = $this->url->to('storefeeder/admin/cases/view', [
                    'id' => (int) $row['id'],
                ]);
                $st = strtoupper((string) ($row['status'] ?? ''));
                $badge = match (true) {
                    in_array($st, ['CLOSED', 'CS_CLOSED', 'CLOSED_WITH_ESCALATION'], true) => 'text-bg-secondary',
                    str_contains($st, 'WAITING_SELLER') => 'text-bg-danger',
                    str_contains($st, 'OPEN') || str_contains($st, 'WAITING') => 'text-bg-warning',
                    default => 'text-bg-primary',
                };
                $claim = '';
                if ($row['claim_amount'] !== null && $row['claim_amount'] !== '') {
                    $claim = number_format((float) $row['claim_amount'], 2)
                        . ' ' . (string) ($row['claim_currency'] ?? '');
                }

                echo '<tr>';
                echo '<td class="font-monospace small">' . $e((string) $row['external_id']) . '</td>';
                echo '<td><span class="badge text-bg-light">' . $e((string) $row['kind']) . '</span></td>';
                echo '<td class="small">' . $e((string) ($row['case_type'] ?: '—')) . '</td>';
                echo '<td><span class="badge ' . $badge . '">' . $e($st !== '' ? $st : '—') . '</span></td>';
                echo '<td>' . $e((string) ($row['buyer_login'] ?: '—')) . '</td>';
                echo '<td class="small">' . $e(mb_substr((string) ($row['item_title'] ?: $row['item_id']), 0, 60)) . '</td>';
                echo '<td class="small text-nowrap">' . ($claim !== '' ? $e($claim) : '—') . '</td>';
                $due = (string) ($row['seller_response_due'] ?? '');
                $dueTs = $due !== '' ? strtotime($due) : false;
                $dueClass = 'text-muted';
                if ($dueTs !== false) {
                    if ($dueTs < time()) {
                        $dueClass = 'text-danger fw-semibold';
                    } elseif ($dueTs < time() + 172800) {
                        $dueClass = 'text-warning-emphasis fw-semibold';
                    }
                }
                echo '<td class="small text-nowrap ' . $dueClass . '">'
                   . ($due !== '' ? $e($due) : '—') . '</td>';
                echo '<td class="small text-muted text-nowrap">'
                   . $e((string) ($row['last_modified_date'] ?? $row['creation_date'] ?? '')) . '</td>';
                echo '<td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($viewUrl) . '">View</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';

            echo Paginator::make($total, $page, $perPage)->render(
                fn (int $p) => $this->url->to('storefeeder/admin/cases', [
                    'channel_id' => (int) $channel->id,
                    'status'     => $status,
                    'kind'       => $kind,
                    'page'       => $p,
                ]),
                ['theme' => 'bootstrap-sm']
            );
        });
    }

    public function syncAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/cases');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/cases');
            return;
        }

        $channelId = (int) $this->request->post('channel_id', 'int', 0);
        $channel = $this->channels->find($channelId);
        if ($channel === null
            || !in_array($channel->type, ['ebay', 'amazon'], true)
            || !$channel->isConnected()
        ) {
            $this->notify->error('Connected eBay or Amazon channel required.');
            redirect_to('storefeeder/admin/cases');
            return;
        }

        @set_time_limit(180);
        try {
            $sync = new SupportCaseSyncService($this->db, $this->factory, $this->cases);
            $lookback = $channel->type === 'amazon' ? 60 : 90;
            $result = $sync->syncChannel($channel, $lookback);
            if ($channel->type === 'amazon') {
                $this->notify->success(sprintf(
                    'Synced Amazon returns/claims: %d created, %d updated (%d returns, %d A-to-Z/chargeback, %d SAFE-T)%s.',
                    $result['created'],
                    $result['updated'],
                    $result['returns'] ?? $result['cases'],
                    $result['atoz'] ?? 0,
                    $result['safet'] ?? 0,
                    ($result['errors'] ?? 0) > 0 ? ', ' . $result['errors'] . ' error(s)' : ''
                ));
            } else {
                $this->notify->success(sprintf(
                    'Synced support cases: %d created, %d updated (%d cases, %d inquiries, %d details)%s.',
                    $result['created'],
                    $result['updated'],
                    $result['cases'],
                    $result['inquiries'],
                    $result['details'] ?? 0,
                    ($result['errors'] ?? 0) > 0 ? ', ' . $result['errors'] . ' error(s)' : ''
                ));
            }
        } catch (\Throwable $e) {
            $this->notify->error('Case sync failed: ' . $e->getMessage());
        }

        redirect_to('storefeeder/admin/cases', ['channel_id' => $channelId]);
    }

    public function viewAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $row = $this->cases->findById($id);
        if ($row === null) {
            $this->notify->error('Case not found.');
            redirect_to('storefeeder/admin/cases');
            return;
        }

        $channel = $this->channels->find((int) $row['channel_id']);
        if ($channel === null) {
            $this->notify->error('Channel not found.');
            redirect_to('storefeeder/admin/cases');
            return;
        }

        $payload = [];
        foreach (['detail_json', 'summary_json'] as $key) {
            $raw = $row[$key] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && $decoded !== []) {
                    $payload = $decoded;
                    break;
                }
            } elseif (is_array($raw) && $raw !== []) {
                $payload = $raw;
                break;
            }
        }

        // Prefer live payload fields when mapped columns were empty / outdated.
        if ((string) ($row['status'] ?? '') === '' && isset($payload['caseStatusEnum'])) {
            $row['status'] = (string) $payload['caseStatusEnum'];
        }
        if ((string) ($row['buyer_login'] ?? '') === '' && isset($payload['buyer'])) {
            $row['buyer_login'] = is_scalar($payload['buyer'])
                ? (string) $payload['buyer']
                : '';
        }
        if (empty($row['seller_response_due']) && isset($payload['respondByDate'])) {
            $due = $payload['respondByDate'];
            $dueVal = is_array($due) ? ($due['value'] ?? '') : $due;
            if (is_string($dueVal) && $dueVal !== '') {
                $ts = strtotime($dueVal);
                $row['seller_response_due'] = $ts !== false ? date('Y-m-d H:i:s', $ts) : $dueVal;
            }
        }
        $seller = '';
        if (isset($payload['seller'])) {
            $seller = is_scalar($payload['seller']) ? (string) $payload['seller'] : '';
        }

        $statusUpper = strtoupper((string) ($row['status'] ?? ''));
        $kind = strtolower((string) ($row['kind'] ?? 'case'));
        $isAmazon = $channel->type === 'amazon';
        $closed = in_array(
            $statusUpper,
            [
                'CLOSED', 'CS_CLOSED', 'OTHER', 'CLOSED_WITH_ESCALATION',
                'APPROVED', 'COMPLETED', 'REFUNDED', 'DENIED', 'CANCELLED', 'CANCELED',
                'POSTED', 'REIMBURSED', 'RECEIVED',
            ],
            true
        );
        $isEscalated = !$isAmazon && $kind === 'case';
        $isInquiry = !$isAmazon && $kind === 'inquiry';
        $canAct = !$isAmazon && $channel->isConnected() && !$closed && ($isEscalated || $isInquiry);
        $canReply = !$isAmazon && $channel->isConnected()
            && !$closed
            && (
                $isInquiry
                || trim((string) ($row['buyer_login'] ?? '')) !== ''
            );
        $replyMode = $isInquiry ? 'inquiry' : 'buyer';
        $replyHint = $isInquiry
            ? 'Reply is posted on the eBay inquiry thread (Post-Order). Use Inquiry actions for tracking, refund, or escalate.'
            : 'Optional: message the buyer about the listing (Commerce Message). Use Case actions for official Post-Order responses.';

        $history = [];
        foreach ([
            $payload['actionHistory'] ?? null,
            $payload['activityHistories'] ?? null,
            $payload['history'] ?? null,
        ] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            foreach ($candidate as $entry) {
                if (is_array($entry)) {
                    $history[] = $entry;
                }
            }
            if ($history !== []) {
                break;
            }
        }

        echo $this->view->inline(function () use (
            $row, $channel, $seller, $canReply, $replyMode, $replyHint,
            $closed, $canAct, $isEscalated, $isInquiry, $history, $isAmazon, $kind
        ) {
            $this->view->assign('title', 'Support Case #' . (string) $row['external_id']);
            $this->view->block('right_sidebar', 'Support Cases');
            $e = fn(string $s): string => $this->view->e($s);

            $back = $this->url->to('storefeeder/admin/cases', ['channel_id' => (int) $channel->id]);
            $kindLabel = match ($kind) {
                'inquiry' => 'Inquiry',
                'return'  => 'Return',
                'atoz'    => 'A-to-Z / Chargeback',
                'safet'   => 'SAFE-T',
                default   => 'Case',
            };
            $status = strtoupper((string) ($row['status'] ?? ''));
            $statusBadge = match (true) {
                in_array($status, ['CLOSED', 'CS_CLOSED', 'POSTED', 'REIMBURSED', 'APPROVED', 'COMPLETED'], true)
                    => 'text-bg-secondary',
                str_contains($status, 'WAITING_SELLER') || $status === 'FLAGGED_ON_RETURN' => 'text-bg-danger',
                str_contains($status, 'OPEN') || str_contains($status, 'WAITING') || str_contains($status, 'PENDING')
                    => 'text-bg-warning',
                default => 'text-bg-primary',
            };
            $claim = '';
            if ($row['claim_amount'] !== null && $row['claim_amount'] !== '') {
                $claim = number_format((float) $row['claim_amount'], 2)
                    . ' ' . (string) ($row['claim_currency'] ?? '');
            }
            $itemId = trim((string) ($row['item_id'] ?? ''));
            $orderId = trim((string) ($row['order_id'] ?? ''));
            $itemUrl = '';
            if ($isAmazon && $itemId !== '') {
                $itemUrl = 'https://www.amazon.co.uk/dp/' . rawurlencode($itemId);
            } elseif ($itemId !== '') {
                $itemUrl = 'https://www.ebay.co.uk/itm/' . rawurlencode($itemId);
            }

            echo '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">';
            echo '<div>';
            echo '<div class="text-muted small"><a href="' . $e($back) . '" class="text-decoration-none">'
               . '&laquo; Support Cases</a></div>';
            echo '<h1 class="h3 mb-1">' . $e($kindLabel) . ' '
               . $e((string) $row['external_id']) . '</h1>';
            if ($status !== '') {
                echo '<span class="badge ' . $statusBadge . '">' . $e($status) . '</span> ';
            }
            if ((string) ($row['case_type'] ?? '') !== '') {
                echo '<span class="badge text-bg-light border">' . $e((string) $row['case_type']) . '</span>';
            }
            echo '</div>';
            echo '<div class="d-flex gap-2">';
            if ($channel->isConnected()) {
                echo '<form method="post" action="'
                   . $e($this->url->to('storefeeder/admin/cases/refresh')) . '" class="d-inline">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
                echo '<button type="submit" class="btn btn-outline-primary btn-sm">'
                   . '<i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>';
                echo '</form>';
            }
            echo '<a class="btn btn-outline-secondary btn-sm" href="' . $e($back) . '">Back</a>';
            echo '</div></div>';

            if ($isAmazon) {
                echo '<div class="alert alert-info py-2">';
                echo 'Amazon SP-API is <strong>read-only</strong> for returns and A-to-Z — resolve claims in Seller Central.';
                if ($orderId !== '') {
                    $msgUrl = $this->url->to('storefeeder/admin/messages/conversation', [
                        'channel_id' => (int) $channel->id,
                        'id'         => $orderId,
                        'type'       => 'AMAZON_ORDER',
                    ]);
                    $ordUrl = $this->url->to('storefeeder/admin/orders', [
                        'channel_id' => (int) $channel->id,
                        'q'          => $orderId,
                    ]);
                    echo ' <a href="' . $e($msgUrl) . '">Message buyer</a>';
                    echo ' · <a href="' . $e($ordUrl) . '">Find order</a>';
                }
                echo '</div>';
            }

            echo '<div class="row g-3">';

            echo '<div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Parties</h2>';
            echo '<dl class="row mb-0 small">';
            $parties = [
                'Channel' => $channel->label !== '' ? $channel->label : $channel->name,
                'Buyer'   => (string) ($row['buyer_login'] ?? ''),
                'Seller'  => $seller,
            ];
            foreach ($parties as $label => $value) {
                if (trim($value) === '') {
                    continue;
                }
                echo '<dt class="col-4 text-muted">' . $e($label) . '</dt>';
                echo '<dd class="col-8">' . $e($value) . '</dd>';
            }
            echo '</dl></div></div></div>';

            echo '<div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Claim</h2>';
            echo '<dl class="row mb-0 small">';
            if ($claim !== '') {
                echo '<dt class="col-4 text-muted">Amount</dt><dd class="col-8 fw-semibold">' . $e($claim) . '</dd>';
            }
            $due = (string) ($row['seller_response_due'] ?? '');
            if ($due !== '') {
                echo '<dt class="col-4 text-muted">Respond by</dt><dd class="col-8">' . $e($due) . '</dd>';
            }
            if ($claim === '' && $due === '') {
                echo '<dd class="col-12 text-muted mb-0">No claim details.</dd>';
            }
            echo '</dl></div></div></div>';

            echo '<div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Order / item</h2>';
            echo '<dl class="row mb-0 small">';
            if ($itemId !== '') {
                echo '<dt class="col-4 text-muted">Item</dt><dd class="col-8">';
                if ($itemUrl !== '') {
                    echo '<a href="' . $e($itemUrl) . '" target="_blank" rel="noopener">' . $e($itemId) . '</a>';
                } else {
                    echo $e($itemId);
                }
                $title = trim((string) ($row['item_title'] ?? ''));
                if ($title !== '') {
                    echo '<div class="text-muted">' . $e($title) . '</div>';
                }
                echo '</dd>';
            }
            foreach ([
                'Transaction' => (string) ($row['transaction_id'] ?? ''),
                'Order'       => (string) ($row['order_id'] ?? ''),
            ] as $label => $value) {
                if (trim($value) === '') {
                    continue;
                }
                echo '<dt class="col-4 text-muted">' . $e($label) . '</dt>';
                echo '<dd class="col-8 font-monospace">' . $e($value) . '</dd>';
            }
            echo '</dl></div></div></div>';

            echo '<div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">Timeline</h2>';
            echo '<dl class="row mb-0 small">';
            foreach ([
                'Created' => (string) ($row['creation_date'] ?? ''),
                'Updated' => (string) ($row['last_modified_date'] ?? ''),
                'Synced'  => (string) ($row['synced_at'] ?? ''),
            ] as $label => $value) {
                if (trim($value) === '') {
                    continue;
                }
                echo '<dt class="col-4 text-muted">' . $e($label) . '</dt>';
                echo '<dd class="col-8">' . $e($value) . '</dd>';
            }
            echo '</dl></div></div></div>';

            if ($isInquiry) {
                echo '<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body">';
                echo '<h2 class="h6 text-uppercase text-muted mb-3">Inquiry actions</h2>';
                if ($closed) {
                    echo '<p class="small text-muted mb-0">This inquiry is closed. Seller actions are not available.</p>';
                } elseif (!$canAct) {
                    echo '<p class="small text-muted mb-0">Connect the eBay channel to run inquiry actions.</p>';
                } else {
                    echo '<p class="small text-muted mb-3">'
                       . 'These call eBay Post-Order inquiry endpoints (tracking, voluntary refund, escalate to case).</p>';
                    echo '<div class="row g-3">';

                    echo '<div class="col-md-6"><div class="border rounded p-3 h-100">';
                    echo '<h3 class="h6 mb-2">Provide shipment info</h3>';
                    echo '<form method="post" action="'
                       . $e($this->url->to('storefeeder/admin/cases/action')) . '">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
                    echo '<input type="hidden" name="case_action" value="shipment">';
                    echo '<div class="mb-2"><label class="form-label small mb-1">Carrier</label>'
                       . '<input type="text" class="form-control form-control-sm" name="carrier" required'
                       . ' placeholder="e.g. Royal Mail"></div>';
                    echo '<div class="mb-2"><label class="form-label small mb-1">Tracking number</label>'
                       . '<input type="text" class="form-control form-control-sm" name="tracking" required></div>';
                    echo '<div class="mb-2"><label class="form-label small mb-1">Comments (optional)</label>'
                       . '<textarea class="form-control form-control-sm" name="comments" rows="2"'
                       . ' maxlength="1000"></textarea></div>';
                    echo '<button type="submit" class="btn btn-outline-primary btn-sm">'
                       . '<i class="bi bi-truck me-1"></i>Submit tracking</button>';
                    echo '</form></div></div>';

                    echo '<div class="col-md-6"><div class="border rounded p-3 h-100">';
                    echo '<h3 class="h6 mb-2">Issue voluntary refund</h3>';
                    echo '<form method="post" action="'
                       . $e($this->url->to('storefeeder/admin/cases/action')) . '"'
                       . ' onsubmit="return confirm(\'Issue a voluntary refund for this inquiry on eBay?\');">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
                    echo '<input type="hidden" name="case_action" value="refund">';
                    echo '<div class="mb-2"><label class="form-label small mb-1">Comments (optional)</label>'
                       . '<textarea class="form-control form-control-sm" name="comments" rows="2"'
                       . ' maxlength="1000"></textarea></div>';
                    echo '<button type="submit" class="btn btn-outline-danger btn-sm">'
                       . '<i class="bi bi-cash-coin me-1"></i>Issue refund</button>';
                    echo '</form></div></div>';

                    echo '<div class="col-md-6"><div class="border rounded p-3 h-100">';
                    echo '<h3 class="h6 mb-2">Escalate to case</h3>';
                    echo '<form method="post" action="'
                       . $e($this->url->to('storefeeder/admin/cases/action')) . '"'
                       . ' onsubmit="return confirm(\'Escalate this inquiry to an eBay support case?\');">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
                    echo '<input type="hidden" name="case_action" value="escalate">';
                    echo '<div class="mb-2"><label class="form-label small mb-1">Reason</label>';
                    echo '<select class="form-select form-select-sm" name="escalate_reason" required>';
                    foreach ([
                        'BUYER_UNHAPPY' => 'Buyer unhappy',
                        'TROUBLE_COMMUNICATING_WITH_BUYER' => 'Trouble communicating with buyer',
                        'OTHER_SELLER_INITIATE' => 'Other (seller initiate)',
                    ] as $val => $label) {
                        echo '<option value="' . $e($val) . '">' . $e($label) . '</option>';
                    }
                    echo '</select></div>';
                    echo '<div class="mb-2"><label class="form-label small mb-1">Comments (optional)</label>'
                       . '<textarea class="form-control form-control-sm" name="comments" rows="2"'
                       . ' maxlength="1000"></textarea></div>';
                    echo '<button type="submit" class="btn btn-outline-warning btn-sm">'
                       . '<i class="bi bi-arrow-up-right-circle me-1"></i>Escalate</button>';
                    echo '</form></div></div>';

                    echo '</div>';
                }
                echo '</div></div></div>';
            }

            if ($isEscalated) {
                echo '<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body">';
                echo '<h2 class="h6 text-uppercase text-muted mb-3">Case actions</h2>';
                if ($closed) {
                    echo '<p class="small text-muted mb-0">This case is closed. Seller actions are not available.</p>';
                } elseif (!$canAct) {
                    echo '<p class="small text-muted mb-0">Connect the eBay channel to run case actions.</p>';
                } else {
                    echo '<p class="small text-muted mb-3">'
                       . 'These call eBay Post-Order case management (official response to the escalated case).</p>';
                    echo '<div class="row g-3">';

                    echo '<div class="col-md-6"><div class="border rounded p-3 h-100">';
                    echo '<h3 class="h6 mb-2">Provide shipment info</h3>';
                    echo '<form method="post" action="'
                       . $e($this->url->to('storefeeder/admin/cases/action')) . '">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
                    echo '<input type="hidden" name="case_action" value="shipment">';
                    echo '<div class="mb-2"><label class="form-label small mb-1">Carrier</label>'
                       . '<input type="text" class="form-control form-control-sm" name="carrier" required'
                       . ' placeholder="e.g. Royal Mail"></div>';
                    echo '<div class="mb-2"><label class="form-label small mb-1">Tracking number</label>'
                       . '<input type="text" class="form-control form-control-sm" name="tracking" required></div>';
                    echo '<button type="submit" class="btn btn-outline-primary btn-sm">'
                       . '<i class="bi bi-truck me-1"></i>Submit tracking</button>';
                    echo '</form></div></div>';

                    echo '<div class="col-md-6"><div class="border rounded p-3 h-100">';
                    echo '<h3 class="h6 mb-2">Issue refund</h3>';
                    echo '<form method="post" action="'
                       . $e($this->url->to('storefeeder/admin/cases/action')) . '"'
                       . ' onsubmit="return confirm(\'Issue a full refund for this case on eBay?\');">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
                    echo '<input type="hidden" name="case_action" value="refund">';
                    echo '<div class="mb-2"><label class="form-label small mb-1">Comments (optional)</label>'
                       . '<textarea class="form-control form-control-sm" name="comments" rows="2"'
                       . ' maxlength="1000"></textarea></div>';
                    echo '<button type="submit" class="btn btn-outline-danger btn-sm">'
                       . '<i class="bi bi-cash-coin me-1"></i>Issue refund</button>';
                    echo '</form></div></div>';

                    echo '<div class="col-md-6"><div class="border rounded p-3 h-100">';
                    echo '<h3 class="h6 mb-2">Appeal decision</h3>';
                    echo '<form method="post" action="'
                       . $e($this->url->to('storefeeder/admin/cases/action')) . '">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
                    echo '<input type="hidden" name="case_action" value="appeal">';
                    echo '<div class="mb-2"><label class="form-label small mb-1">Appeal comments</label>'
                       . '<textarea class="form-control form-control-sm" name="comments" rows="3"'
                       . ' maxlength="1000" required></textarea></div>';
                    echo '<button type="submit" class="btn btn-outline-warning btn-sm">'
                       . '<i class="bi bi-gavel me-1"></i>Submit appeal</button>';
                    echo '</form></div></div>';

                    echo '<div class="col-md-6"><div class="border rounded p-3 h-100">';
                    echo '<h3 class="h6 mb-2">Provide return address</h3>';
                    echo '<form method="post" action="'
                       . $e($this->url->to('storefeeder/admin/cases/action')) . '">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
                    echo '<input type="hidden" name="case_action" value="return_address">';
                    echo '<div class="row g-2 mb-2">';
                    echo '<div class="col-6"><input type="text" class="form-control form-control-sm"'
                       . ' name="first_name" placeholder="First name"></div>';
                    echo '<div class="col-6"><input type="text" class="form-control form-control-sm"'
                       . ' name="last_name" placeholder="Last name"></div>';
                    echo '<div class="col-12"><input type="text" class="form-control form-control-sm"'
                       . ' name="address_line1" placeholder="Address line 1" required></div>';
                    echo '<div class="col-12"><input type="text" class="form-control form-control-sm"'
                       . ' name="address_line2" placeholder="Address line 2"></div>';
                    echo '<div class="col-6"><input type="text" class="form-control form-control-sm"'
                       . ' name="city" placeholder="City" required></div>';
                    echo '<div class="col-6"><input type="text" class="form-control form-control-sm"'
                       . ' name="postal_code" placeholder="Postcode" required></div>';
                    echo '<div class="col-6"><input type="text" class="form-control form-control-sm"'
                       . ' name="state" placeholder="County / state"></div>';
                    echo '<div class="col-6"><input type="text" class="form-control form-control-sm"'
                       . ' name="country" value="GB" placeholder="Country code" required></div>';
                    echo '<div class="col-12"><input type="text" class="form-control form-control-sm"'
                       . ' name="rma" placeholder="RMA (optional)"></div>';
                    echo '</div>';
                    echo '<button type="submit" class="btn btn-outline-secondary btn-sm">'
                       . '<i class="bi bi-geo-alt me-1"></i>Submit address</button>';
                    echo '</form></div></div>';

                    echo '</div>'; // row actions
                }
                echo '</div></div></div>';
            }

            if ($history !== []) {
                echo '<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body">';
                echo '<h2 class="h6 text-uppercase text-muted mb-3">Activity</h2>';
                echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
                echo '<thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Notes</th></tr></thead><tbody>';
                foreach ($history as $entry) {
                    $when = '';
                    foreach (['date', 'actionDate', 'creationDate', 'timestamp'] as $dk) {
                        $raw = $entry[$dk] ?? null;
                        if (is_array($raw)) {
                            $when = (string) ($raw['value'] ?? $raw['formattedValue'] ?? '');
                        } elseif (is_scalar($raw)) {
                            $when = (string) $raw;
                        }
                        if ($when !== '') {
                            break;
                        }
                    }
                    $actor = (string) ($entry['actor'] ?? $entry['party'] ?? $entry['userRole'] ?? '');
                    $action = (string) ($entry['action'] ?? $entry['activityType'] ?? $entry['type'] ?? '');
                    $notes = '';
                    foreach (['description', 'comments', 'note', 'message'] as $nk) {
                        $raw = $entry[$nk] ?? null;
                        if (is_array($raw)) {
                            $notes = (string) ($raw['content'] ?? '');
                        } elseif (is_scalar($raw)) {
                            $notes = (string) $raw;
                        }
                        if ($notes !== '') {
                            break;
                        }
                    }
                    echo '<tr>';
                    echo '<td class="text-nowrap small">' . $e($when) . '</td>';
                    echo '<td class="small">' . $e($actor) . '</td>';
                    echo '<td class="small">' . $e($action) . '</td>';
                    echo '<td class="small text-muted">' . $e($notes) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div></div></div></div>';
            }

            echo '<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body">';
            echo '<h2 class="h6 text-uppercase text-muted mb-3">'
               . ($replyMode === 'inquiry' ? 'Reply' : 'Contact buyer') . '</h2>';
            if ($closed) {
                echo '<p class="small text-muted mb-0">This case is closed. Messaging is not available.</p>';
            } elseif (!$canReply) {
                echo '<p class="small text-muted mb-0">'
                   . ($replyMode === 'inquiry'
                        ? 'Connect the channel to reply.'
                        : 'Connect the channel and ensure a buyer username is present to message.')
                   . '</p>';
            } else {
                echo '<p class="small text-muted mb-3">' . $e($replyHint) . '</p>';
                echo '<form method="post" action="'
                   . $e($this->url->to('storefeeder/admin/cases/reply')) . '">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
                echo '<textarea class="form-control mb-2" name="message" rows="4" maxlength="2000" required'
                   . ' placeholder="Write your message…"></textarea>';
                echo '<div class="d-flex justify-content-between align-items-center">';
                echo '<span class="small text-muted">'
                   . ($replyMode === 'inquiry' ? 'Max 1000 characters (inquiry).' : 'Max 2000 characters.')
                   . '</span>';
                echo '<button type="submit" class="btn btn-primary btn-sm">'
                   . '<i class="bi bi-send me-1"></i>'
                   . ($replyMode === 'inquiry' ? 'Send reply' : 'Message buyer')
                   . '</button>';
                echo '</div></form>';
            }
            echo '</div></div></div>';

            echo '</div>'; // row
        });
    }

    public function actionAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/cases');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/cases');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $caseAction = strtolower(trim((string) $this->request->post('case_action', 'string', '')));
        $row = $this->cases->findById($id);
        if ($row === null) {
            $this->notify->error('Case not found.');
            redirect_to('storefeeder/admin/cases');
            return;
        }

        $kind = strtolower((string) ($row['kind'] ?? 'case'));
        if (!in_array($kind, ['case', 'inquiry'], true)) {
            $this->notify->error('Unsupported case kind.');
            redirect_to('storefeeder/admin/cases/view', ['id' => $id]);
            return;
        }

        $channel = $this->channels->find((int) $row['channel_id']);
        if ($channel === null || !$channel->isConnected() || $channel->type !== 'ebay') {
            $this->notify->error('Connected eBay channel required.');
            redirect_to('storefeeder/admin/cases/view', ['id' => $id]);
            return;
        }

        $status = strtoupper((string) ($row['status'] ?? ''));
        if (in_array($status, ['CLOSED', 'CS_CLOSED', 'OTHER', 'CLOSED_WITH_ESCALATION'], true)) {
            $this->notify->error('Cannot act on a closed case.');
            redirect_to('storefeeder/admin/cases/view', ['id' => $id]);
            return;
        }

        $externalId = (string) $row['external_id'];
        $comments = trim((string) $this->request->post('comments', 'string', ''));
        $carrier = (string) $this->request->post('carrier', 'string', '');
        $tracking = (string) $this->request->post('tracking', 'string', '');

        try {
            $client = $this->factory->make($channel);

            if ($kind === 'inquiry') {
                match ($caseAction) {
                    'shipment' => (function () use ($client, $externalId, $carrier, $tracking, $comments): void {
                        if (!method_exists($client, 'provideSupportInquiryShipmentInfo')) {
                            throw new \RuntimeException('Inquiry shipment info is not supported.');
                        }
                        $client->provideSupportInquiryShipmentInfo($externalId, $carrier, $tracking, $comments);
                    })(),
                    'refund' => (function () use ($client, $externalId, $comments): void {
                        if (!method_exists($client, 'issueSupportInquiryRefund')) {
                            throw new \RuntimeException('Inquiry refund is not supported.');
                        }
                        $client->issueSupportInquiryRefund($externalId, $comments);
                    })(),
                    'escalate' => (function () use ($client, $externalId, $comments): void {
                        if (!method_exists($client, 'escalateSupportInquiry')) {
                            throw new \RuntimeException('Inquiry escalate is not supported.');
                        }
                        $client->escalateSupportInquiry(
                            $externalId,
                            (string) $this->request->post('escalate_reason', 'string', ''),
                            $comments,
                        );
                    })(),
                    default => throw new \InvalidArgumentException('Unknown inquiry action.'),
                };
                $labels = [
                    'shipment' => 'Shipment info submitted on the inquiry.',
                    'refund'   => 'Voluntary refund issued on the inquiry.',
                    'escalate' => 'Inquiry escalated to an eBay case.',
                ];
            } else {
                match ($caseAction) {
                    'shipment' => (function () use ($client, $externalId, $carrier, $tracking): void {
                        if (!method_exists($client, 'provideSupportCaseShipmentInfo')) {
                            throw new \RuntimeException('Shipment info is not supported.');
                        }
                        $client->provideSupportCaseShipmentInfo($externalId, $carrier, $tracking);
                    })(),
                    'refund' => (function () use ($client, $externalId, $comments): void {
                        if (!method_exists($client, 'issueSupportCaseRefund')) {
                            throw new \RuntimeException('Case refund is not supported.');
                        }
                        $client->issueSupportCaseRefund($externalId, $comments);
                    })(),
                    'appeal' => (function () use ($client, $externalId, $comments): void {
                        if (!method_exists($client, 'appealSupportCase')) {
                            throw new \RuntimeException('Case appeal is not supported.');
                        }
                        $client->appealSupportCase($externalId, $comments);
                    })(),
                    'return_address' => (function () use ($client, $externalId): void {
                        if (!method_exists($client, 'provideSupportCaseReturnAddress')) {
                            throw new \RuntimeException('Return address is not supported.');
                        }
                        $client->provideSupportCaseReturnAddress($externalId, [
                            'firstName'       => (string) $this->request->post('first_name', 'string', ''),
                            'lastName'        => (string) $this->request->post('last_name', 'string', ''),
                            'RMA'             => (string) $this->request->post('rma', 'string', ''),
                            'addressLine1'    => (string) $this->request->post('address_line1', 'string', ''),
                            'addressLine2'    => (string) $this->request->post('address_line2', 'string', ''),
                            'city'            => (string) $this->request->post('city', 'string', ''),
                            'stateOrProvince' => (string) $this->request->post('state', 'string', ''),
                            'postalCode'      => (string) $this->request->post('postal_code', 'string', ''),
                            'country'         => strtoupper((string) $this->request->post('country', 'string', 'GB')),
                        ]);
                    })(),
                    default => throw new \InvalidArgumentException('Unknown case action.'),
                };
                $labels = [
                    'shipment'       => 'Shipment info submitted to eBay.',
                    'refund'         => 'Refund issued on eBay.',
                    'appeal'         => 'Appeal submitted to eBay.',
                    'return_address' => 'Return address submitted to eBay.',
                ];
            }

            $this->notify->success($labels[$caseAction] ?? 'Action completed.');

            try {
                $sync = new SupportCaseSyncService($this->db, $this->factory, $this->cases);
                $sync->refreshDetail($channel, $kind, $externalId);
            } catch (\Throwable) {
                // Non-fatal — action already succeeded.
            }
        } catch (\Throwable $e) {
            $this->notify->error('Case action failed: ' . $e->getMessage());
        }

        redirect_to('storefeeder/admin/cases/view', ['id' => $id]);
    }

    public function replyAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/cases');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/cases');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $message = trim((string) $this->request->post('message', 'string', ''));
        $row = $this->cases->findById($id);
        if ($row === null) {
            $this->notify->error('Case not found.');
            redirect_to('storefeeder/admin/cases');
            return;
        }
        $channel = $this->channels->find((int) $row['channel_id']);
        if ($channel === null || !$channel->isConnected() || $channel->type !== 'ebay') {
            $this->notify->error('Connected eBay channel required.');
            redirect_to('storefeeder/admin/cases/view', ['id' => $id]);
            return;
        }
        if ($message === '') {
            $this->notify->error('Message cannot be empty.');
            redirect_to('storefeeder/admin/cases/view', ['id' => $id]);
            return;
        }

        $status = strtoupper((string) ($row['status'] ?? ''));
        if (in_array($status, ['CLOSED', 'CS_CLOSED', 'OTHER', 'CLOSED_WITH_ESCALATION'], true)) {
            $this->notify->error('Cannot reply to a closed case.');
            redirect_to('storefeeder/admin/cases/view', ['id' => $id]);
            return;
        }

        try {
            $client = $this->factory->make($channel);
            $kind = strtolower((string) ($row['kind'] ?? 'case'));
            if ($kind === 'inquiry') {
                if (!method_exists($client, 'replySupportInquiry')) {
                    throw new \RuntimeException('Inquiry reply is not supported.');
                }
                $client->replySupportInquiry((string) $row['external_id'], $message);
                $this->notify->success('Reply sent on the eBay inquiry.');
            } else {
                $buyer = trim((string) ($row['buyer_login'] ?? ''));
                $itemId = trim((string) ($row['item_id'] ?? ''));
                if ($buyer === '') {
                    throw new \RuntimeException('Buyer username missing — cannot message.');
                }
                if (!method_exists($client, 'contactBuyerAboutItem')) {
                    throw new \RuntimeException('Buyer messaging is not supported.');
                }
                $client->contactBuyerAboutItem($buyer, $itemId, $message);
                $this->notify->success('Message sent to the buyer on eBay.');
            }

            // Refresh local detail after a successful reply.
            try {
                $sync = new SupportCaseSyncService($this->db, $this->factory, $this->cases);
                $sync->refreshDetail($channel, (string) $row['kind'], (string) $row['external_id']);
            } catch (\Throwable) {
                // Non-fatal — reply already succeeded.
            }
        } catch (\Throwable $e) {
            $this->notify->error('Reply failed: ' . $e->getMessage());
        }

        redirect_to('storefeeder/admin/cases/view', ['id' => $id]);
    }

    public function refreshAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/cases');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('storefeeder/admin/cases');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $row = $this->cases->findById($id);
        if ($row === null) {
            $this->notify->error('Case not found.');
            redirect_to('storefeeder/admin/cases');
            return;
        }
        $channel = $this->channels->find((int) $row['channel_id']);
        if ($channel === null || !$channel->isConnected()) {
            $this->notify->error('Channel not connected.');
            redirect_to('storefeeder/admin/cases/view', ['id' => $id]);
            return;
        }

        @set_time_limit(180);
        try {
            $sync = new SupportCaseSyncService($this->db, $this->factory, $this->cases);
            $sync->refreshDetail($channel, (string) $row['kind'], (string) $row['external_id']);
            $this->notify->success(
                $channel->type === 'amazon'
                    ? 'Refreshed from Amazon returns/claims pull.'
                    : 'Case refreshed from eBay.'
            );
        } catch (\Throwable $e) {
            $this->notify->error('Refresh failed: ' . $e->getMessage());
        }

        redirect_to('storefeeder/admin/cases/view', ['id' => $id]);
    }

    /** @param array<int, Channel> $channels */
    private function pickChannel(array $channels, int $channelId): ?Channel
    {
        if ($channelId > 0) {
            foreach ($channels as $c) {
                if ((int) $c->id === $channelId) {
                    return $c;
                }
            }
        }
        return $channels[0] ?? null;
    }
}
