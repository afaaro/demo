<?php
declare(strict_types=1);

use Module\Store\Services\Channel;
use Module\Store\Services\ChannelClientFactory;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\ChannelSettings;
use Module\Store\Services\Channels\Marketplace\Amazon\AmazonPlatformApp;
use Module\Store\Services\Channels\Marketplace\Ebay\EbayPlatformApp;
use Module\Store\Services\CredentialsVault;
use Module\Store\Services\OrderSyncService;
use Module\Store\Services\SyncLogRepository;
use Module\Store\Services\TenantContext;
use Module\Store\Services\TenantRepository;
use Module\Store\Services\TenantResolver;
use Module\Store\Services\TenantTokenRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreAdminChannels extends Controller
{
    private ChannelRepository    $channels;
    private CredentialsVault     $vault;
    private ChannelClientFactory $factory;
    private TenantResolver       $tenantResolver;
    private TenantRepository     $tenants;

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
        $this->tenants  = new TenantRepository($this->db);
        $this->tenantResolver = new TenantResolver(
            $this->db,
            $this->request,
            $this->session,
            $this->auth,
            $this->tenants,
            new TenantTokenRepository($this->db),
        );

        $db = $this->db;
    }

    // =========================================================================
    // Integration Manager (Store-style)
    // =========================================================================

    public function indexAction(): void
    {
        // Ensure a tenant is bound before any create/connect actions from this page.
        if (!TenantContext::get()->has()) {
            $this->tenantResolver->forceDefaultContext();
        }

        $isPlatform = $this->tenantResolver->isPlatformOwner();
        // Platform: overview of every tenant’s channels. Tenant users: own only.
        $channels = $isPlatform
            ? $this->channels->allAcrossTenants()
            : $this->channels->all();
        $isSecure = $this->vault->isSecure();
        $types    = $this->factory->availableTypes();
        $catalog  = $this->integrationCatalog($types);

        // Group existing channels by category.
        $grouped = [];
        foreach ($catalog as $catKey => $cat) {
            $grouped[$catKey] = [];
        }
        $grouped['other'] = [];

        foreach ($channels as $c) {
            $typeCfg = $types[strtolower($c->type)] ?? [];
            $catKey  = (string) ($typeCfg['category'] ?? 'other');
            if (!isset($grouped[$catKey])) {
                $catKey = 'other';
            }
            $grouped[$catKey][] = $c;
        }

        $tenantCtx = TenantContext::get();
        $tenantChoices = [];
        if ($isPlatform) {
            foreach ($this->tenants->all('active') as $t) {
                $label = (string) $t['name'];
                if ($this->tenants->isPlatformHome($t)) {
                    $label .= ' (platform home)';
                }
                $tenantChoices[] = ['id' => (int) $t['id'], 'label' => $label];
            }
        }

        echo $this->view->inline(function () use (
            $channels, $isSecure, $catalog, $grouped, $types, $tenantCtx, $isPlatform, $tenantChoices
        ) {
            $this->view->assign('title', 'Integration Manager');
            $this->view->block('right_sidebar', 'Integrations');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo $this->integrationDetailsStyles();
            echo '<div class="sf-im-page">';
            echo '<div class="sf-im-head">';
            echo '<h1>Integration Manager</h1>';
            echo '<button type="button" class="sf-im-new" data-bs-toggle="modal" data-bs-target="#newIntegrationModal">'
               . '<i class="bi bi-plus-lg"></i>New integration</button>';
            echo '</div>';

            if ($isPlatform) {
                echo '<div class="sf-im-tenant">';
                echo '<form method="post" action="' . $e($this->url->to('store/admin/channels/select-tenant'))
                   . '" class="sf-im-tenant-row">';
                echo $this->request->csrfField();
                echo '<label for="sf-im-tenant">Tenant for new integrations</label>';
                echo '<select name="tenant_id" id="sf-im-tenant">';
                foreach ($tenantChoices as $opt) {
                    $sel = ((int) $opt['id'] === (int) ($tenantCtx->id() ?? 0)) ? ' selected' : '';
                    echo '<option value="' . (int) $opt['id'] . '"' . $sel . '>' . $e($opt['label']) . '</option>';
                }
                echo '</select>';
                echo '<button type="submit" class="sf-im-use">Use</button>';
                echo '</form>';
                echo '<p class="sf-im-tenant-help">The table lists <strong>all</strong> tenants&rsquo; channels. '
                   . 'Choose a tenant, click <strong>Use</strong>, then <strong>New integration</strong> '
                   . '&mdash; that channel is saved to the selected tenant. Client owners only see their own.</p>';
                echo '</div>';
            } elseif ($tenantCtx->has()) {
                echo '<div class="sf-im-note">'
                   . 'Your organisation: <strong>' . $e($tenantCtx->name()) . '</strong>. '
                   . 'Channels you connect stay private to this tenant.</div>';
            }

            if (!$isSecure) {
                echo '<div class="alert alert-warning">';
                echo '<strong>Credentials are not encrypted.</strong> '
                   . 'Set <code>store.credentials_key</code> in config to enable encryption.';
                echo '</div>';
            }

            $this->renderNewIntegrationModal($catalog);

            $any = false;
            foreach ($catalog as $catKey => $cat) {
                $rows = $grouped[$catKey] ?? [];
                if ($rows === []) {
                    continue;
                }
                $any = true;
                $this->renderIntegrationSection(
                    (string) $cat['label'],
                    (string) $cat['icon'],
                    $rows,
                    $types
                );
            }
            if (($grouped['other'] ?? []) !== []) {
                $any = true;
                $this->renderIntegrationSection('Other', 'puzzle', $grouped['other'], $types);
            }

            if (!$any) {
                echo '<div class="sf-im-empty">'
                   . 'No integrations yet. Click <strong>New integration</strong> to connect a marketplace or webstore.'
                   . '</div>';
            }
            echo '</div>';
        });
    }

    public function selectTenantAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }
        try {
            $this->request->checkToken();
            if (!$this->tenantResolver->isPlatformOwner()) {
                throw new \System\Engine\ForbiddenException('Platform owner required.');
            }
            $tid = (int) $this->request->post('tenant_id', 'int', 0);
            if (!$this->tenantResolver->selectTenantForUser($tid)) {
                throw new \RuntimeException('Unable to switch to that tenant.');
            }
            $this->rebuildVaultForCurrentTenant();
            $name = TenantContext::get()->name();
            $this->notify->success(
                'New integrations will be saved to: '
                . ($name !== '' ? $name : '#' . $tid)
            );
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/channels');
    }

    public function toggleAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/channels');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $channel = $this->requireChannel($id);

        $enabled = !(bool) $channel->enabled;
        $this->channels->update($id, ['enabled' => $enabled], $this->vault);
        $this->notify->success(
            ($enabled ? 'Enabled' : 'Disabled') . " integration '{$channel->label}'."
        );
        redirect_to('store/admin/channels');
    }

    // =========================================================================
    // OAuth — connect
    // =========================================================================

    public function connectAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);

        $channel = $this->requireChannel($id);

        $type = strtolower($channel->type);
        if ($type === 'ebay'
            && !EbayPlatformApp::isConfigured(!empty($channel->settings['sandbox']))
        ) {
            $hasLocal = false;
            try {
                $local = $this->channels->credentialsFor(
                    $channel,
                    $this->vaultForTenant((int) $channel->tenantId)
                );
                $hasLocal = trim((string) ($local['client_id'] ?? '')) !== ''
                    && trim((string) ($local['client_secret'] ?? '')) !== ''
                    && trim((string) ($local['ru_name'] ?? '')) !== '';
            } catch (\Throwable) {
                $hasLocal = false;
            }
            if (!$hasLocal) {
                $this->notify->error(
                    'eBay Connect needs the platform app keys. '
                    . 'Set store.ebay (client_id, client_secret, ru_name) in modules/store/config/ebay.php, '
                    . 'or enter App ID / Cert ID / RuName on this channel.'
                );
                redirect_to('store/admin/channels/edit', ['id' => $id]);
                return;
            }
        }

        if ($type === 'amazon' && !AmazonPlatformApp::isConfigured()) {
            $hasLocal = false;
            try {
                $local = $this->channels->credentialsFor(
                    $channel,
                    $this->vaultForTenant((int) $channel->tenantId)
                );
                $hasLocal = trim((string) ($local['application_id'] ?? '')) !== ''
                    && trim((string) ($local['client_id'] ?? '')) !== ''
                    && trim((string) ($local['client_secret'] ?? '')) !== '';
            } catch (\Throwable) {
                $hasLocal = false;
            }
            if (!$hasLocal) {
                $this->notify->error(
                    'Amazon Link account needs the platform app keys. '
                    . 'Set store.amazon (application_id, client_id, client_secret) in modules/store/config/amazon.php, '
                    . 'or enter Application ID / LWA keys on this channel.'
                );
                redirect_to('store/admin/channels/edit', ['id' => $id]);
                return;
            }
        }

        $client = $this->factory->make($channel);

        $state = $id . ':' . bin2hex(random_bytes(16));
        $this->channels->saveOAuthState($id, $state);
        $this->session->set('channel_oauth_id',    $id);
        $this->session->set('channel_oauth_state', $state);

        try {
            $consent = $client->consentUrl($state);
        } catch (\Throwable $e) {
            $this->notify->error('Could not start consent: ' . $e->getMessage());
            redirect_to('store/admin/channels/edit', ['id' => $id]);
            return;
        }
        if ($consent === null) {
            $this->notify->error('This channel type does not use OAuth. Use the credentials form instead.');
            redirect_to('store/admin/channels/edit', ['id' => $id]);
            return;
        }

        redirect_external($consent);
    }

    // =========================================================================
    // OAuth — callback
    // =========================================================================

    public function callbackAction(): void
    {
        $code  = (string) $this->request->get('code',  'string', '');
        if ($code === '') {
            $code = (string) $this->request->get('spapi_oauth_code', 'string', '');
        }
        $state = (string) $this->request->get('state', 'string', '');
        $oauthError = (string) $this->request->get('error', 'string', '');
        $oauthErrorDesc = (string) $this->request->get('error_description', 'string', '');

        $this->log->error('OAuth callback: ' . json_encode([
            'code'  => $code !== '' ? 'present' : 'missing',
            'state' => $state !== '' ? $state : '(missing)',
            'error' => $oauthError !== '' ? $oauthError : null,
        ]));

        $channelId = (int) $this->session->get('channel_oauth_id', 0);
        $this->session->remove('channel_oauth_id');
        $this->session->remove('channel_oauth_state');

        if ($channelId <= 0 && $state !== '') {
            [$stateChannelId] = array_pad(explode(':', $state, 2), 2, '');
            $channelId = (int) $stateChannelId;
        }

        if ($channelId <= 0) {
            $this->notify->error('OAuth callback failed: cannot identify channel.');
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $channel = $this->requireChannel($channelId);
        } catch (NotFoundException) {
            $this->notify->error('OAuth callback failed: channel not found.');
            redirect_to('store/admin/channels');
            return;
        }

        if ($oauthError !== '') {
            $message = $oauthErrorDesc !== '' ? $oauthErrorDesc : $oauthError;
            $this->channels->update($channelId, [
                'status'     => 'error',
                'last_error' => 'Authorization denied: ' . $message,
            ], $this->vault);
            $this->notify->error('Authorization was not completed: ' . $message);
            redirect_to('store/admin/channels/edit', ['id' => $channelId]);
            return;
        }

        if ($code === '') {
            $this->channels->update($channelId, [
                'status'     => 'error',
                'last_error' => 'No authorization code returned.',
            ], $this->vault);
            $hint = strtolower($channel->type) === 'amazon'
                ? 'Register the callback URL in the Amazon developer console and try Link account again.'
                : 'Check RuName / redirect URL in the eBay app.';
            $this->notify->error('No authorization code returned. ' . $hint);
            redirect_to('store/admin/channels/edit', ['id' => $channelId]);
            return;
        }

        try {
            $client = $this->factory->make($channel);
            $client->handleCallback($code);
            $this->notify->success('Channel connected. You can Test connection or open Messages.');
        } catch (\Throwable $e) {
            $this->channels->update($channelId, [
                'status'     => 'error',
                'last_error' => $e->getMessage(),
            ], $this->vault);

            $this->notify->error('Connection failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/channels/edit', ['id' => $channelId]);
    }

    // =========================================================================
    // Add
    // =========================================================================

    public function addAction(): void
    {
        $types       = $this->factory->availableTypes();
        $preselect   = strtolower(trim((string) $this->request->get('type', 'string', '')));
        $defaultType = isset($types[$preselect]) ? $preselect : (array_key_first($types) ?: 'ebay');

        $old = [
            'id'          => 0,
            'tenant_id'   => (int) (TenantContext::get()->id() ?? 1),
            'name'        => '',
            'label'       => '',
            'type'        => $defaultType,
            'enabled'     => 1,
            'status'      => 'disconnected',
            'credentials' => [],
            'settings'    => [],
        ];

        if ($this->request->isPost()) {
            try {
                $old['name']    = strtolower(trim((string) $this->request->post('name', 'string', '')));
                $old['label']   = trim((string) $this->request->post('label', 'string', ''));
                $old['type']    = trim((string) $this->request->post('type', 'string', $defaultType));
                $old['enabled'] = (int) $this->request->post('enabled', 'int', 0);
                $old['tenant_id'] = $this->resolvePostedTenantId(
                    (int) ($old['tenant_id'] ?? (TenantContext::get()->id() ?? 0))
                );

                $old['credentials'] = $this->readCredentials($old['type']);
                $old['settings']    = $this->readSettings($old['type']);

                $vault = $this->vaultForTenant((int) $old['tenant_id']);
                $channel = $this->channels->create([
                    'tenant_id'   => (int) $old['tenant_id'],
                    'name'        => $old['name'],
                    'label'       => $old['label'] !== '' ? $old['label'] : $old['name'],
                    'type'        => $old['type'],
                    'credentials' => $old['credentials'],
                    'settings'    => $old['settings'],
                    'enabled'     => (bool) $old['enabled'],
                ], $vault);
                // Stay in the tenant we just created for.
                if ($this->tenantResolver->isPlatformOwner()) {
                    $this->tenantResolver->selectTenantForUser((int) $old['tenant_id']);
                }

                if ($channel->id !== null && $this->typeSupportsAttributes($channel->type)) {
                    (new \Module\Store\Services\ChannelAttributeRepository($this->db))
                        ->replaceDefs(
                            (int) $channel->id,
                            \Module\Store\Services\EbayAspectResolver::defaultChannelRowsForType(
                                (string) $channel->type
                            )
                        );
                }

                $this->notify->success("Integration '{$channel->label}' created.");
                redirect_to('store/admin/channels/edit', ['id' => $channel->id]);
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        $typeLabel = (string) (($types[$old['type']]['label'] ?? null) ?: ucfirst($old['type']));

        echo $this->view->inline(function () use ($old, $types, $typeLabel, $preselect) {
            $this->view->assign('title', 'New integration');
            echo $this->integrationDetailsStyles();
            echo '<div class="mb-3"><a href="' . $this->view->e($this->url->to('store/admin/channels')) . '"'
               . ' class="text-decoration-none small">&larr; Integration Manager</a></div>';
            echo '<h1 class="h3 mb-4">New integration'
               . ($preselect !== '' ? ' — ' . $this->view->e($typeLabel) : '')
               . '</h1>';
            echo $this->channelForm(
                $old,
                $this->url->to('store/admin/channels/add'),
                $types,
                false,
                $preselect !== ''
            );
        });
    }

    // =========================================================================
    // Edit
    // =========================================================================

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);

        $channel = $this->requireChannel($id);

        $vault = $this->vaultForTenant((int) $channel->tenantId);
        $credentials = [];
        try {
            $credentials = $this->channels->credentialsFor($channel, $vault);
        } catch (\Throwable $e) {
            $this->notify->error('Could not decrypt credentials: ' . $e->getMessage());
        }

        $old = [
            'id'          => $channel->id,
            'tenant_id'   => (int) $channel->tenantId,
            'name'        => $channel->name,
            'label'       => $channel->label,
            'type'        => $channel->type,
            'enabled'     => $channel->enabled ? 1 : 0,
            'status'      => $channel->status,
            'credentials' => $credentials,
            'settings'    => $channel->settings,
        ];

        if ($this->request->isPost()) {
            try {
                $old['label']   = trim((string) $this->request->post('label', 'string', ''));
                $old['enabled'] = (int) $this->request->post('enabled', 'int', 0);
                $old['tenant_id'] = $this->resolvePostedTenantId((int) $channel->tenantId);
                $newType        = trim((string) $this->request->post('type', 'string', $channel->type));

                // Merge so cached business-policy lists survive Save.
                $mergedSettings = array_merge(
                    is_array($channel->settings) ? $channel->settings : [],
                    $this->readSettings($channel->type)
                );
                // General Attributes live only in `#__store_channel_attributes` (not settings JSON).
                $attrType = $newType !== '' ? $newType : $channel->type;
                $generalAttrsPost = null;
                if ($this->typeSupportsAttributes($attrType)) {
                    $generalAttrsPost = $this->readGeneralAttributesPost();
                }
                unset($mergedSettings['general_attributes']);
                $update = [
                    'tenant_id'=> (int) $old['tenant_id'],
                    'label'    => $old['label'] !== '' ? $old['label'] : $channel->name,
                    'settings' => $mergedSettings,
                    'enabled'  => (bool) $old['enabled'],
                ];

                $typeChanged = ($newType !== '' && $newType !== $channel->type);
                $saveVault = $this->vaultForTenant((int) $old['tenant_id']);

                if ($typeChanged) {
                    $update['type']        = $newType;
                    $update['credentials'] = $this->readCredentials($newType);
                    $update['status']      = 'disconnected';
                    $update['last_error']  = '';

                    $this->channels->clearTokens($id);

                    $old['type']        = $newType;
                    $old['credentials'] = $update['credentials'];
                    $old['status']      = 'disconnected';
                } else {
                    $newCredentials = $this->readCredentials($channel->type);
                    $hasCredentials = false;
                    foreach ($newCredentials as $v) {
                        if ($v !== '') { $hasCredentials = true; break; }
                    }
                    if ($hasCredentials) {
                        foreach ($newCredentials as $k => $v) {
                            if ($v === '' && isset($credentials[$k])) {
                                $newCredentials[$k] = $credentials[$k];
                            }
                        }
                        $update['credentials'] = $newCredentials;
                        $old['credentials']    = $newCredentials;
                    }
                }

                $this->channels->update($id, $update, $saveVault);
                if ($this->tenantResolver->isPlatformOwner()) {
                    $this->tenantResolver->selectTenantForUser((int) $old['tenant_id']);
                }
                if ($this->typeSupportsAttributes($attrType) && is_array($generalAttrsPost)) {
                    (new \Module\Store\Services\ChannelAttributeRepository($this->db))
                        ->replaceDefs($id, $generalAttrsPost);
                }
                $this->notify->success(
                    $typeChanged
                        ? 'Channel type changed. Re-enter credentials, then Connect again.'
                        : 'Channel updated.'
                );
                redirect_to('store/admin/channels/edit', ['id' => $id]);
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        // Reload channel so connection panel reflects latest tokens/status.
        $channel = ($this->tenantResolver->isPlatformOwner()
            ? $this->channels->findAny($id)
            : $this->channels->find($id)) ?? $channel;
        $types   = $this->factory->availableTypes();
        $typeLabel = (string) (($types[$channel->type]['label'] ?? null) ?: ucfirst($channel->type));

        echo $this->view->inline(function () use ($old, $id, $types, $channel, $typeLabel) {
            $this->view->assign('title', $old['label'] . ' — Integration Details');
            $this->view->block('right_sidebar', 'Integrations');

            echo $this->integrationDetailsStyles();
            echo '<div class="sf-integ-layout">';
            echo $this->renderIntegrationNav($channel, $typeLabel);
            echo '<div class="sf-integ-main">';
            echo '<h1 class="h4 mb-3">'
               . '<i class="bi bi-gear me-2 text-secondary"></i>'
               . $this->view->e($old['label']) . ' (' . $this->view->e($typeLabel) . ')'
               . ' — Integration Details</h1>';

            echo $this->channelForm(
                $old,
                $this->url->to('store/admin/channels/edit', ['id' => $id]),
                $types,
                isEdit: true,
                lockType: true,
                channel: $channel,
            );
            echo '</div></div>';
        });
    }

    // =========================================================================
    // Test connection
    // =========================================================================

    public function testAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/channels');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $channel = $this->requireChannel($id);
        } catch (NotFoundException) {
            $this->notify->error('Channel not found.');
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $client = $this->factory->make($channel);
            $result = $client->testConnection();

            if (!empty($result['ok'])) {
                if ($channel->status !== 'connected') {
                    $this->channels->update($id, [
                        'status'     => 'connected',
                        'last_error' => '',
                    ], $this->vault);
                } else {
                    $this->channels->update($id, ['last_error' => ''], $this->vault);
                }
                $msg = 'Connection test passed.';
                if (!empty($result['marketplaces']) && is_array($result['marketplaces'])) {
                    $msg .= ' Marketplaces: ' . implode(', ', array_map('strval', $result['marketplaces']));
                }
                if (!empty($result['warning'])) {
                    $msg .= ' ' . (string) $result['warning'];
                }
                if (!empty($result['shop'])) {
                    $msg .= ' Shop: ' . (string) $result['shop'];
                }
                $this->logSync($id, 'test_connection', 'ok', $msg, $result);
                $this->notify->success($msg);
            } else {
                $error = trim((string) ($result['error'] ?? 'Unknown error'));
                if ($error === '') {
                    $error = 'Unknown error';
                }
                $this->channels->update($id, [
                    'status'     => 'error',
                    'last_error' => $error,
                ], $this->vault);
                $this->logSync($id, 'test_connection', 'error', $error, $result);
                $this->notify->error('Connection test failed: ' . $error);
            }
        } catch (\Throwable $e) {
            $this->channels->update($id, [
                'status'     => 'error',
                'last_error' => $e->getMessage(),
            ], $this->vault);
            $this->logSync($id, 'test_connection', 'error', $e->getMessage());
            $this->notify->error('Connection test failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/channels/edit', ['id' => $id]);
    }

    // =========================================================================
    // Refresh eBay business policies
    // =========================================================================

    public function refreshPoliciesAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/channels');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $channel = $this->requireChannel($id);
        } catch (NotFoundException) {
            $this->notify->error('Channel not found.');
            redirect_to('store/admin/channels');
            return;
        }

        if ($channel->type !== 'ebay') {
            $this->notify->error('Business policies are only available for eBay.');
            redirect_to('store/admin/channels/edit', ['id' => $id]);
            return;
        }

        if (!$channel->isConnected()) {
            $this->notify->error('Connect the channel before refreshing policies.');
            redirect_to('store/admin/channels/edit', ['id' => $id]);
            return;
        }

        try {
            $client = $this->factory->make($channel);
            if (!method_exists($client, 'fetchBusinessPolicies')) {
                throw new \RuntimeException('This channel does not support business policies.');
            }

            /** @var array{fulfillment: array, payment: array, return: array, locations: array} $policies */
            $policies = $client->fetchBusinessPolicies();

            $settings = $channel->settings;
            $settings['business_policies_cache'] = [
                'fulfillment' => $policies['fulfillment'],
                'payment'     => $policies['payment'],
                'return'      => $policies['return'],
                'locations'   => $policies['locations'],
                'synced_at'   => date('Y-m-d H:i:s'),
                'marketplace' => (string) ($channel->settings['marketplace'] ?? 'EBAY_GB'),
            ];

            // Auto-select when only one option and nothing chosen yet.
            $map = [
                'fulfillment_policy_id' => 'fulfillment',
                'payment_policy_id'     => 'payment',
                'return_policy_id'      => 'return',
                'merchant_location_key' => 'locations',
            ];
            foreach ($map as $settingKey => $cacheKey) {
                $current = trim((string) ($settings[$settingKey] ?? ''));
                $list    = $policies[$cacheKey] ?? [];
                if ($current === '' && count($list) === 1) {
                    $settings[$settingKey] = (string) $list[0]['id'];
                }
            }

            $this->channels->update($id, ['settings' => $settings], $this->vault);

            $counts = sprintf(
                '%d fulfillment, %d payment, %d return, %d location(s)',
                count($policies['fulfillment']),
                count($policies['payment']),
                count($policies['return']),
                count($policies['locations']),
            );
            $this->notify->success('Business policies refreshed: ' . $counts . '.');
        } catch (\Throwable $e) {
            $this->notify->error('Policy refresh failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/channels/edit', ['id' => $id]);
    }

    // =========================================================================
    // Refresh eBay store categories
    // =========================================================================

    public function refreshStoreCategoriesAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/channels');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $channel = $this->requireChannel($id);
        } catch (NotFoundException) {
            $this->notify->error('Channel not found.');
            redirect_to('store/admin/channels');
            return;
        }

        if ($channel->type !== 'ebay') {
            $this->notify->error('Store categories are only available for eBay.');
            redirect_to('store/admin/channels/edit', ['id' => $id]);
            return;
        }

        if (!$channel->isConnected()) {
            $this->notify->error('Connect the channel before refreshing store categories.');
            redirect_to('store/admin/channels/edit', ['id' => $id]);
            return;
        }

        try {
            $client = $this->factory->make($channel);
            if (!method_exists($client, 'fetchStoreCategories')) {
                throw new \RuntimeException('This channel does not support store categories.');
            }

            /** @var array<int, array{id: string, name: string, path: string, level: int}> $cats */
            $cats = $client->fetchStoreCategories();

            $settings = $channel->settings;
            $settings['store_categories_cache'] = [
                'categories' => $cats,
                'synced_at'  => date('Y-m-d H:i:s'),
            ];

            $this->channels->update($id, ['settings' => $settings], $this->vault);
            $this->notify->success(
                'Store categories refreshed: ' . count($cats) . ' categor'
                . (count($cats) === 1 ? 'y' : 'ies') . '.'
            );
        } catch (\Throwable $e) {
            $this->notify->error('Store category refresh failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/channels/edit', ['id' => $id]);
    }

    // =========================================================================
    // Import listings from eBay Trading API → local products
    // =========================================================================

    public function importListingsAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/channels');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $channel = $this->requireChannel($id);
        } catch (NotFoundException) {
            $this->notify->error('Channel not found.');
            redirect_to('store/admin/channels');
            return;
        }
        if ($channel->type !== 'ebay') {
            $this->notify->error('Listing import is only available for eBay channels.');
            redirect_to('store/admin/channels/edit', ['id' => $id]);
            return;
        }

        try {
            // No stock publisher — import should not push qty back to eBay.
            $inventory = new \Module\Store\Services\InventoryService($this->db);
            $importer = new \Module\Store\Services\EbayListingImportService(
                $this->factory,
                new \Module\Store\Services\ProductRepository($this->db),
                new \Module\Store\Services\VariantRepository($this->db),
                new \Module\Store\Services\ListingRepository($this->db),
                $inventory,
                BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store',
                new \Module\Store\Services\ChannelAttributeRepository($this->db),
            );
            $result = $importer->importChannel($channel);
            $msg = sprintf(
                'eBay listings imported: %d fetched across %d page(s) — %d created, %d updated, %d linked, %d skipped, %d error(s).',
                $result['fetched'],
                $result['pages'],
                $result['created'],
                $result['updated'],
                $result['linked'],
                $result['skipped'],
                $result['errors'],
            );
            if ($result['error_samples'] !== []) {
                $msg .= ' First errors: ' . implode(' | ', $result['error_samples']);
            }
            if ($result['errors'] > 0) {
                $this->notify->error($msg);
            } else {
                $this->notify->success($msg);
            }
        } catch (\Throwable $e) {
            $this->notify->error('Listing import failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/channels/edit', ['id' => $id]);
    }

    // =========================================================================
    // Refresh listings (status + optional relist)
    // =========================================================================

    public function refreshListingsAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/channels');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $channel = $this->requireChannel($id);
        } catch (NotFoundException) {
            $this->notify->error('Channel not found.');
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $listings = new \Module\Store\Services\ListingRepository($this->db);
            $inventory = new \Module\Store\Services\InventoryService($this->db);
            $templates = new \Module\Store\Services\ListingTemplateRepository($this->db);
            $sync = new \Module\Store\Services\ListingSyncService(
                $this->db,
                $this->channels,
                $this->factory,
                $listings,
                new \Module\Store\Services\VariantRepository($this->db),
                new \Module\Store\Services\ProductRepository($this->db),
                $inventory,
                $templates,
            );
            $refresh = new \Module\Store\Services\ListingRefreshService(
                $this->factory,
                $listings,
                $sync,
                $inventory,
            );
            $result = $refresh->refreshChannel($channel);
            $this->notify->success(sprintf(
                'Listings refreshed: %d checked, %d active, %d ended, %d relisted, %d qty synced, %d error(s).',
                $result['checked'],
                $result['active'],
                $result['ended'],
                $result['relisted'],
                $result['synced_qty'],
                $result['errors'],
            ));
        } catch (\Throwable $e) {
            $this->notify->error('Listing refresh failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/channels/edit', ['id' => $id]);
    }

    // =========================================================================
    // eBay category suggestions (JSON)
    // =========================================================================

    public function categorySuggestAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $id = (int) $this->request->get('id', 'int', 0);
        $q  = trim((string) $this->request->get('q', 'string', ''));

        try {
            $channel = $this->requireChannel($id);
        } catch (NotFoundException) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Channel not found.', 'results' => []]);
            exit;
        }
        if ($channel->type !== 'ebay') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Only eBay supports category search.', 'results' => []]);
            exit;
        }
        if (!$channel->isConnected()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Connect the channel first.', 'results' => []]);
            exit;
        }
        if (mb_strlen($q) < 2) {
            echo json_encode(['ok' => true, 'results' => []]);
            exit;
        }

        try {
            $client = $this->factory->make($channel);
            if (!method_exists($client, 'suggestCategories')) {
                throw new \RuntimeException('Category search is not supported.');
            }
            $results = $client->suggestCategories($q, 20);
            echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(502);
            echo json_encode([
                'ok'      => false,
                'error'   => $e->getMessage(),
                'results' => [],
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    public function categoryAspectsAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $id = (int) $this->request->get('id', 'int', 0);
        $categoryId = trim((string) $this->request->get('category_id', 'string', ''));

        try {
            $channel = $this->requireChannel($id);
        } catch (NotFoundException) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Channel not found.', 'aspects' => []]);
            exit;
        }
        if ($channel->type !== 'ebay') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Only eBay supports Item Specifics.', 'aspects' => []]);
            exit;
        }
        if (!$channel->isConnected()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Connect the channel first.', 'aspects' => []]);
            exit;
        }
        if ($categoryId === '') {
            $categoryId = trim((string) ($channel->settings['default_category_id'] ?? ''));
        }
        if ($categoryId === '') {
            http_response_code(400);
            echo json_encode([
                'ok'      => false,
                'error'   => 'Set an eBay Default Category first.',
                'aspects' => [],
            ]);
            exit;
        }

        try {
            $client = $this->factory->make($channel);
            if (!method_exists($client, 'getItemAspectsForCategory')) {
                throw new \RuntimeException('Item Specifics lookup is not supported.');
            }
            $aspects = $client->getItemAspectsForCategory($categoryId);

            $settings = is_array($channel->settings) ? $channel->settings : [];
            $settings['category_aspects_cache'] = [
                'category_id'   => $categoryId,
                'category_name' => (string) ($channel->settings['default_category_name'] ?? ''),
                'fetched_at'    => date('c'),
                'aspects'       => $aspects,
            ];
            $this->channels->update($id, ['settings' => $settings], $this->vault);

            $required = 0;
            foreach ($aspects as $a) {
                if (!empty($a['required'])) {
                    $required++;
                }
            }

            echo json_encode([
                'ok'          => true,
                'category_id' => $categoryId,
                'required'    => $required,
                'total'       => count($aspects),
                'aspects'     => $aspects,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(502);
            echo json_encode([
                'ok'      => false,
                'error'   => $e->getMessage(),
                'aspects' => [],
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // =========================================================================
    // Disconnect
    // =========================================================================

    public function disconnectAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/channels');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $channel = $this->requireChannel($id);
        } catch (NotFoundException) {
            $this->notify->error('Channel not found.');
            redirect_to('store/admin/channels');
            return;
        }

        $this->channels->clearTokens($id);
        $this->channels->update($id, [
            'status'     => 'disconnected',
            'last_error' => '',
        ], $this->vault);

        $this->notify->success('Channel disconnected. OAuth tokens cleared.');
        redirect_to('store/admin/channels/edit', ['id' => $id]);
    }

    // =========================================================================
    // Sync orders (one channel / all enabled)
    // =========================================================================

    public function syncAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/channels');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $channel = $this->requireChannel($id);
        } catch (NotFoundException) {
            $this->notify->error('Channel not found.');
            redirect_to('store/admin/channels');
            return;
        }

        if (!$channel->isConnected()) {
            $this->notify->error('Channel is not connected.');
            redirect_to('store/admin/channels/edit', ['id' => $id]);
            return;
        }

        try {
            $sync   = new OrderSyncService($this->db, $this->channels, $this->factory);
            $result = $sync->syncChannel($channel, 30);
            $msg = "Synced {$result['created']} new order(s), {$result['updated']} updated.";
            if (!empty($result['skipped'])) {
                $msg .= " {$result['skipped']} skipped by integration settings.";
            }
            $status = 'ok';
            if (!ChannelSettings::bool($channel, 'import_orders', true)) {
                $msg = 'Import Orders is off for this integration — nothing synced.';
                $status = 'skipped';
            }
            $this->logSync($id, 'sync_orders', $status, $msg, $result);
            $this->notify->success($msg);
        } catch (\Throwable $e) {
            $this->channels->update($id, [
                'status'     => 'error',
                'last_error' => $e->getMessage(),
            ], $this->vault);
            $this->logSync($id, 'sync_orders', 'error', $e->getMessage());
            $this->notify->error('Sync failed: ' . $e->getMessage());
        }

        redirect_to('store/admin/channels/edit', ['id' => $id]);
    }

    public function syncAllAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }

        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/channels');
            return;
        }

        $sync    = new OrderSyncService($this->db, $this->channels, $this->factory);
        $created = 0;
        $updated = 0;
        $failed  = 0;

        foreach ($this->channels->enabled() as $channel) {
            if (!$channel->isConnected()) {
                continue;
            }
            if ($this->factory->isCourier($channel)) {
                continue;
            }
            $cid = (int) $channel->id;
            try {
                $result   = $sync->syncChannel($channel, 30);
                $created += $result['created'];
                $updated += $result['updated'];
                $this->logSync(
                    $cid,
                    'sync_orders',
                    'ok',
                    "Synced {$result['created']} new, {$result['updated']} updated.",
                    $result
                );
            } catch (\Throwable $e) {
                $failed++;
                $this->channels->update($cid, [
                    'status'     => 'error',
                    'last_error' => $e->getMessage(),
                ], $this->vault);
                $this->logSync($cid, 'sync_orders', 'error', $e->getMessage());
            }
        }

        if ($failed > 0) {
            $this->notify->warning(
                "Synced {$created} new, {$updated} updated. {$failed} channel(s) failed."
            );
        } else {
            $this->notify->success(
                "Synced {$created} new order(s), {$updated} updated across all channels."
            );
        }

        redirect_to('store/admin/channels');
    }

    // =========================================================================
    // Delete
    // =========================================================================

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        try {
            $channel = $this->requireChannel($id);
            $this->channels->delete($id);
            $this->notify->success("Channel '{$channel->label}' deleted.");
        } catch (NotFoundException) {
            $this->notify->error('Channel not found.');
        }

        redirect_to('store/admin/channels');
    }

    // =========================================================================
    // Form / Integration Details
    // =========================================================================

    private function integrationDetailsStyles(): string
    {
        return <<<'CSS'
<style>
.sf-integ-layout{display:flex;gap:1.25rem;align-items:flex-start}
.sf-integ-nav{width:220px;flex-shrink:0;background:#f7f8fa;border:1px solid #e3e6eb;border-radius:.35rem;padding:.5rem 0;position:sticky;top:1rem}
.sf-integ-nav a{display:block;padding:.45rem .9rem;color:#333;text-decoration:none;font-size:.9rem;border-left:3px solid transparent}
.sf-integ-nav a:hover{background:#eef1f5}
.sf-integ-nav a.active{background:#e8eef8;border-left-color:#1e4d8c;font-weight:600;color:#1e4d8c}
.sf-integ-nav a.disabled{color:#999;pointer-events:none}
.sf-integ-nav .nav-back{font-weight:600;border-bottom:1px solid #e3e6eb;margin-bottom:.25rem;padding-bottom:.65rem}
.sf-integ-main{flex:1;min-width:0}
.sf-panel{border:1px solid #d9dee7;border-radius:.35rem;margin-bottom:1rem;overflow:hidden;background:#fff}
.sf-panel-h{background:#1e4d8c;color:#fff;padding:.55rem 1rem;font-weight:600;font-size:.95rem}
.sf-panel-b{padding:1rem 1.1rem}
.sf-panel-sub{background:#f3f5f8;border:1px solid #e0e4ea;border-radius:.25rem;padding:.85rem 1rem;margin-bottom:.85rem}
.sf-panel-sub:last-child{margin-bottom:0}
.sf-panel-sub h3{font-size:.9rem;font-weight:700;margin:0 0 .75rem;color:#333}
.sf-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.35rem 0;border-bottom:1px solid #f0f2f5}
.sf-toggle-row:last-child{border-bottom:0}
.sf-toggle-row label{margin:0;font-size:.9rem;color:#333}
.sf-field-row{display:grid;grid-template-columns:minmax(140px,220px) minmax(160px,1fr);gap:.75rem;align-items:center;padding:.4rem 0;border-bottom:1px solid #f0f2f5}
.sf-field-row:last-child{border-bottom:0}
.sf-field-row label{margin:0;font-size:.9rem}
.sf-form-switch .form-check-input{width:2.6rem;height:1.35rem;cursor:pointer}
.sf-form-switch .form-check-input:checked{background-color:#e67e22;border-color:#e67e22}
.sf-provider-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 2rem}
.sf-link-ok{color:#198754;font-weight:600}
.sf-link-bad{color:#dc3545;font-weight:600}
.sf-im-page{color:#1f2937}
.sf-im-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem}
.sf-im-head h1{margin:0;font-size:1.35rem;font-weight:650;letter-spacing:-.02em;line-height:1.2}
.sf-im-new{display:inline-flex;align-items:center;gap:.4rem;background:#16a34a;border:0;color:#fff;border-radius:.55rem;padding:.5rem .95rem;font-weight:600;font-size:.875rem;white-space:nowrap}
.sf-im-new:hover{background:#15803d;color:#fff}
.sf-im-tenant,.sf-im-note,.sf-im-empty{background:#fff;border:1px solid #e5e7eb;border-radius:.7rem;padding:.85rem 1rem;margin-bottom:1rem}
.sf-im-tenant-row{display:flex;align-items:center;gap:.75rem;flex-wrap:nowrap}
.sf-im-tenant-row label{margin:0;font-size:.8rem;font-weight:600;color:#374151;white-space:nowrap}
.sf-im-tenant-row select{flex:1;min-width:0;max-width:28rem;height:2.25rem;border:1px solid #d1d5db;border-radius:.45rem;padding:.25rem .7rem;font-size:.875rem;background:#fff}
.sf-im-use{flex:0 0 auto;height:2.25rem;padding:0 1.15rem;border:0;border-radius:.45rem;background:#2563eb;color:#fff;font-weight:600;font-size:.875rem}
.sf-im-use:hover{background:#1d4ed8}
.sf-im-tenant-help{margin:.55rem 0 0;font-size:.8rem;color:#6b7280;line-height:1.45}
.sf-im-note,.sf-im-empty{font-size:.875rem;color:#4b5563}
.sf-im-card{background:#fff;border:1px solid #e5e7eb;border-radius:.7rem;overflow:hidden;margin-bottom:1rem;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.sf-im-card-h{display:flex;align-items:center;gap:.5rem;padding:.7rem 1rem;border-bottom:1px solid #eef0f3;background:#fff;font-weight:650}
.sf-im-card-h .sf-im-count{display:inline-flex;align-items:center;justify-content:center;min-width:1.4rem;height:1.4rem;padding:0 .4rem;border-radius:999px;background:#f3f4f6;color:#374151;font-size:.72rem;font-weight:700}
.sf-im-table{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
.sf-im-table thead th{background:#4b5563;color:#fff;font-weight:500;font-size:.72rem;letter-spacing:.02em;text-align:left;padding:.65rem .8rem;border:0;white-space:nowrap;vertical-align:middle}
.sf-im-table tbody td{padding:.75rem .8rem;border-top:1px solid #f1f5f9;font-size:.84rem;vertical-align:middle}
.sf-im-table tbody tr:hover{background:#f8fafc}
.sf-im-table .c-integ{width:16%}
.sf-im-table .c-name{width:20%}
.sf-im-table .c-tenant{width:16%}
.sf-im-table .c-active{width:9%;text-align:center}
.sf-im-table .c-status{width:11%}
.sf-im-table .c-sync{width:13%}
.sf-im-table .c-actions{width:15%;text-align:right}
.sf-im-title{display:block;font-weight:650;color:#111827;line-height:1.25;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sf-im-sub{display:block;margin-top:.15rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.72rem;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sf-im-badge{display:inline-flex;align-items:center;margin-top:.35rem;padding:.12rem .45rem;border-radius:.3rem;font-size:.68rem;font-weight:700;letter-spacing:.02em;text-transform:uppercase}
.sf-im-badge.is-live{background:#dcfce7;color:#166534}
.sf-im-badge.is-sand{background:#fef3c7;color:#92400e}
.sf-im-active{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;margin:0}
.sf-im-active span{font-size:.75rem;font-weight:600;color:#6b7280;min-width:1.4rem;text-align:left}
.sf-im-pill{display:inline-flex;align-items:center;padding:.2rem .55rem;border-radius:999px;font-size:.72rem;font-weight:700}
.sf-im-pill.is-ok{background:#dcfce7;color:#166534}
.sf-im-pill.is-err{background:#fee2e2;color:#991b1b}
.sf-im-pill.is-off{background:#e5e7eb;color:#374151}
.sf-im-sync{display:block;font-variant-numeric:tabular-nums;white-space:nowrap;color:#4b5563;font-size:.8rem}
.sf-im-actions{display:inline-flex;align-items:center;justify-content:flex-end;gap:.35rem;flex-wrap:nowrap}
.sf-im-actions a,.sf-im-actions button{display:inline-flex;align-items:center;height:1.85rem;padding:0 .55rem;border-radius:.4rem;border:1px solid #d1d5db;background:#fff;color:#374151;font-size:.75rem;font-weight:600;text-decoration:none;white-space:nowrap}
.sf-im-actions a:hover,.sf-im-actions button:hover{background:#f3f4f6;color:#111827}
.sf-im-actions .is-connect{border-color:#86efac;color:#166534}
.sf-im-actions .is-danger{border-color:#fecaca;color:#b91c1c}
.sf-im-actions form{display:inline;margin:0}
.sf-im-err{margin-top:.3rem;color:#b91c1c;font-size:.75rem;line-height:1.3}
@media (max-width:1100px){
  .sf-im-table{table-layout:auto}
  .sf-im-tenant-row{flex-wrap:wrap}
  .sf-im-tenant-row select{max-width:none;min-width:16rem}
}
@media (max-width:900px){
  .sf-integ-layout{flex-direction:column}
  .sf-integ-nav{width:100%;position:static}
  .sf-provider-grid{grid-template-columns:1fr}
  .sf-field-row{grid-template-columns:1fr}
}
</style>
CSS;
    }

    private function renderIntegrationNav(Channel $channel, string $typeLabel): string
    {
        $type = $channel->type;
        $listUrl = $this->url->to('store/admin/channels');
        $editUrl = $this->url->to('store/admin/channels/edit', ['id' => $channel->id]);

        $items = [
            ['href' => $listUrl, 'label' => $typeLabel, 'class' => 'nav-back', 'icon' => 'arrow-left'],
            ['href' => $editUrl . '#profile', 'label' => 'Channel Preferences', 'active' => true],
        ];

        if ($type === 'ebay') {
            $items = array_merge($items, [
                ['href' => $editUrl . '#link-account', 'label' => 'eBay Account'],
                ['href' => $this->url->to('store/admin/listing-templates', ['channel_id' => $channel->id]), 'label' => 'eBay Listing Templates'],
                ['href' => $editUrl . '#store-categories', 'label' => 'eBay Store Categories'],
                ['href' => $editUrl . '#business-policies', 'label' => 'eBay Business Policies'],
                ['href' => $editUrl . '#ebay-category', 'label' => 'eBay Default Category'],
                ['href' => $this->url->to('store/admin/cases', ['channel_id' => $channel->id]), 'label' => 'Support Cases'],
            ]);
        } elseif ($type === 'amazon') {
            $items = array_merge($items, [
                ['href' => $editUrl . '#link-account', 'label' => 'Amazon Account'],
                ['href' => $editUrl . '#provider', 'label' => 'Amazon Settings'],
                ['href' => $this->url->to('store/admin/listing-templates', ['channel_id' => $channel->id]), 'label' => 'Listing Templates'],
                ['href' => $this->url->to('store/admin/cases', ['channel_id' => $channel->id]), 'label' => 'Returns & A-to-Z'],
            ]);
        } elseif ($type === 'bnq') {
            $items = array_merge($items, [
                ['href' => $editUrl . '#link-account', 'label' => 'B&Q Account'],
                ['href' => $editUrl . '#provider', 'label' => 'B&Q Settings'],
                ['href' => $this->url->to('store/admin/cases', ['channel_id' => $channel->id]), 'label' => 'Incidents'],
            ]);
        } elseif ($type === 'shopify') {
            $items = array_merge($items, [
                ['href' => $editUrl . '#link-account', 'label' => 'Shopify Store'],
                ['href' => $editUrl . '#provider', 'label' => 'Shopify Settings'],
            ]);
        } elseif (in_array($type, ['evri', 'royalmail', 'parcelforce', 'dpd'], true)) {
            $courierLabel = match ($type) {
                'royalmail'   => 'Royal Mail',
                'parcelforce' => 'ParcelForce',
                'dpd'         => 'DPD',
                default       => 'Evri',
            };
            $items = array_merge($items, [
                ['href' => $editUrl . '#link-account', 'label' => $courierLabel . ' Account'],
                ['href' => $editUrl . '#provider', 'label' => 'Parcel Settings'],
            ]);
        }

        $typeCfg = $this->factory->availableTypes()[strtolower($type)] ?? [];
        $isCourier = (($typeCfg['category'] ?? '') === 'courier');
        if ($this->typeSupportsAttributes($type)) {
            $items[] = ['href' => $editUrl . '#general-attributes', 'label' => 'General Attributes'];
            $items[] = [
                'href' => $this->url->to('store/admin/channels/product-attributes', ['id' => $channel->id]),
                'label' => 'View Product Attributes',
            ];
        }
        if (!$isCourier) {
            $items[] = ['href' => $editUrl . '#channel-settings', 'label' => 'Channel Details'];
        }
        $items[] = ['href' => $editUrl . '#sync-log', 'label' => 'Sync Log'];
        $items[] = ['href' => $editUrl . '#tokens', 'label' => 'Token Refresh Details'];

        ob_start();
        echo '<nav class="sf-integ-nav" aria-label="Integration">';
        foreach ($items as $item) {
            $cls = trim(
                ($item['class'] ?? '')
                . (!empty($item['active']) ? ' active' : '')
                . (!empty($item['disabled']) ? ' disabled' : '')
            );
            $label = $this->view->e((string) $item['label']);
            if (!empty($item['icon'])) {
                $label = '<i class="bi bi-' . $this->view->e((string) $item['icon']) . ' me-1"></i>' . $label;
            }
            if (!empty($item['disabled'])) {
                $label .= ' <span class="small text-muted">(soon)</span>';
            }
            echo '<a href="' . $this->view->e((string) $item['href']) . '"'
               . ($cls !== '' ? ' class="' . $this->view->e($cls) . '"' : '')
               . '>' . $label . '</a>';
        }
        echo '</nav>';
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, array> $types
     */
    private function renderLinkAccountPanel(
        Channel $channel,
        array $types,
        bool $useExternalForms = false,
    ): string {
        $typeConfig = $types[$channel->type] ?? [];
        $usesOauth  = !empty($typeConfig['uses_oauth']);
        $typeLabel  = (string) ($typeConfig['label'] ?? ucfirst($channel->type));

        $status = $channel->status;
        $now    = time();

        $accessLabel = '—';
        if ($channel->accessExpiresAt) {
            $ts = strtotime($channel->accessExpiresAt);
            if ($ts !== false) {
                $accessLabel = $channel->accessExpiresAt . ($ts > $now ? ' (valid)' : ' (expired)');
            }
        }

        $refreshLabel = '—';
        $refreshExpired = false;
        if ($channel->refreshExpiresAt) {
            $ts = strtotime($channel->refreshExpiresAt);
            if ($ts !== false) {
                $refreshExpired = $ts <= $now;
                $refreshLabel = $channel->refreshExpiresAt
                    . ($refreshExpired ? ' (expired — reconnect required)' : ' (valid)');
            }
        }

        $hasTokens = $channel->accessToken !== '' || $channel->refreshToken !== '';
        $linked    = $status === 'connected' && !$refreshExpired;
        $testDisabled = (!$hasTokens && $usesOauth) ? ' disabled' : '';

        ob_start();
        ?>
        <div class="sf-panel" id="link-account">
            <div class="sf-panel-h">Link your <?= $this->view->e($typeLabel) ?> account</div>
            <div class="sf-panel-b">
                <?php if ($status === 'error' && $channel->lastError !== ''): ?>
                    <div class="alert alert-danger py-2"><?= $this->view->e($channel->lastError) ?></div>
                <?php endif; ?>

                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div>
                        <?php if ($linked): ?>
                            <div class="sf-link-ok">
                                <i class="bi bi-check-circle-fill me-1"></i>
                                Channel linked to <?= $this->view->e($typeLabel) ?>
                            </div>
                        <?php else: ?>
                            <div class="sf-link-bad">
                                <i class="bi bi-x-circle-fill me-1"></i>
                                Channel not linked to <?= $this->view->e($typeLabel) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($channel->type === 'ebay' && EbayPlatformApp::managesChannelOAuth(!empty($channel->settings['sandbox']))): ?>
                            <div class="small text-muted mt-1">
                                Click <strong>Link account</strong> to open eBay consent — no App ID / Cert ID needed on this channel.
                            </div>
                        <?php endif; ?>
                        <?php if ($channel->type === 'amazon' && AmazonPlatformApp::managesChannelOAuth()): ?>
                            <div class="small text-muted mt-1">
                                Click <strong>Link account</strong> to open Amazon Seller Central consent — no LWA keys needed on this channel.
                            </div>
                        <?php elseif ($channel->type === 'amazon'): ?>
                            <div class="small text-muted mt-1">
                                Click <strong>Link account</strong> to authorize Seller Central. Set <code>store.amazon</code>
                                (application_id, client_id, client_secret) so tenants do not paste LWA keys.
                            </div>
                        <?php endif; ?>
                        <?php if ($channel->lastSyncedAt): ?>
                            <div class="small text-muted mt-1">
                                Last synced: <?= $this->view->e((string) $channel->lastSyncedAt) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($usesOauth): ?>
                            <a href="<?= $this->view->e($this->url->to('store/admin/channels/connect', ['id' => $channel->id])) ?>"
                               class="btn btn-primary btn-sm"
                               <?= $linked ? 'onclick="return confirm(\'Re-authorize this channel?\');"' : '' ?>>
                                <?= $linked || $refreshExpired ? 'Reconnect' : 'Link account' ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($useExternalForms): ?>
                            <button type="submit" form="sf-test-form" class="btn btn-outline-primary btn-sm"<?= $testDisabled ?>>
                                Test Connection
                            </button>
                            <?php if ($hasTokens || $status === 'connected' || $status === 'error'): ?>
                                <button type="submit" form="sf-disconnect-form" class="btn btn-outline-danger btn-sm">
                                    Disconnect
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="post" action="<?= $this->view->e($this->url->to('store/admin/channels/test', ['id' => $channel->id])) ?>" class="d-inline">
                                <?= $this->request->csrfField() ?>
                                <button type="submit" class="btn btn-outline-primary btn-sm"<?= $testDisabled ?>>
                                    Test Connection
                                </button>
                            </form>
                            <?php if ($hasTokens || $status === 'connected' || $status === 'error'): ?>
                                <form method="post"
                                      action="<?= $this->view->e($this->url->to('store/admin/channels/disconnect', ['id' => $channel->id])) ?>"
                                      class="d-inline"
                                      onsubmit="return confirm('Disconnect and clear tokens?');">
                                    <?= $this->request->csrfField() ?>
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Disconnect</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sf-field-row" id="tokens">
                    <label>Access token</label>
                    <div class="small text-muted"><?= $this->view->e($accessLabel) ?></div>
                </div>
                <?php if ($usesOauth): ?>
                <div class="sf-field-row">
                    <label>Refresh token</label>
                    <div class="small text-muted"><?= $this->view->e($refreshLabel) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($channel->type === 'ebay'): ?>
                    <div class="sf-field-row">
                        <label>Environment</label>
                        <div>
                            <?php if (!empty($channel->settings['sandbox'])): ?>
                                <span class="badge text-bg-warning">Sandbox</span>
                            <?php else: ?>
                                <span class="badge text-bg-success">Production</span>
                            <?php endif; ?>
                            <span class="small text-muted ms-1">(change under eBay Specific Settings)</span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a href="<?= $this->view->e($this->url->to('store/admin/account-deletion')) ?>"
                           class="btn btn-outline-secondary btn-sm">Account deletion</a>
                        <a href="<?= $this->view->e($this->url->to('store/admin/messages/conversations', ['channel_id' => $channel->id])) ?>"
                           class="btn btn-outline-secondary btn-sm">Messages</a>
                        <a href="<?= $this->view->e($this->url->to('store/admin/cases', ['channel_id' => $channel->id])) ?>"
                           class="btn btn-outline-secondary btn-sm">Support Cases</a>
                    </div>
                <?php elseif ($channel->type === 'amazon'): ?>
                    <div class="mt-3">
                        <a href="<?= $this->view->e($this->url->to('store/admin/messages/conversations', ['channel_id' => $channel->id])) ?>"
                           class="btn btn-outline-secondary btn-sm">Messages</a>
                        <a href="<?= $this->view->e($this->url->to('store/admin/cases', ['channel_id' => $channel->id])) ?>"
                           class="btn btn-outline-secondary btn-sm">Returns &amp; A-to-Z</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Inject cached store category paths into select field defs.
     *
     * @param array<string, mixed> $settingsDefs
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function withStoreCategoryOptions(array $settingsDefs, array $values): array
    {
        $cache = is_array($values['store_categories_cache'] ?? null)
            ? $values['store_categories_cache']
            : [];
        $list = is_array($cache['categories'] ?? null) ? $cache['categories'] : [];

        $options = ['' => '— None —'];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = trim((string) ($row['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $indent = str_repeat('— ', max(0, ((int) ($row['level'] ?? 1)) - 1));
            $label  = $indent . (string) ($row['name'] ?? $path);
            $options[$path] = $label . ' (' . $path . ')';
        }

        foreach (['store_category_1', 'store_category_2'] as $field) {
            if (!isset($settingsDefs[$field]) || !is_array($settingsDefs[$field])) {
                continue;
            }
            $fieldOptions = $options;
            $current = trim((string) ($values[$field] ?? ''));
            if ($current !== '' && !isset($fieldOptions[$current])) {
                $fieldOptions[$current] = $current . ' (cached path)';
            }
            $settingsDefs[$field]['options'] = $fieldOptions;
            $settingsDefs[$field]['type'] = 'select';
        }

        return $settingsDefs;
    }

    /**
     * @param array<string, mixed> $settingsDefs
     * @param array<string, mixed> $values
     */
    private function renderStoreCategoriesPanel(array $settingsDefs, array $values): string
    {
        $cache = is_array($values['store_categories_cache'] ?? null)
            ? $values['store_categories_cache']
            : [];
        $syncedAt = (string) ($cache['synced_at'] ?? '');
        $count = is_array($cache['categories'] ?? null) ? count($cache['categories']) : 0;

        ob_start();
        echo '<div class="sf-panel" id="store-categories">';
        echo '<div class="sf-panel-h">eBay Store Categories</div>';
        echo '<div class="sf-panel-b">';
        echo '<p class="small text-muted mb-3">Optional custom store categories for new listings '
           . '(sent as <code>storeCategoryNames</code> on the offer). Requires an eBay Store.</p>';

        echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">';
        echo '<div class="small text-muted">';
        if ($syncedAt !== '') {
            echo 'Last refreshed: ' . $this->view->e($syncedAt) . ' · ' . $count . ' categor'
               . ($count === 1 ? 'y' : 'ies');
        } else {
            echo 'Not refreshed yet — click <strong>Refresh from eBay</strong>.';
        }
        echo '</div>';
        echo '<button type="submit" class="btn btn-outline-primary btn-sm" form="sf-refresh-store-cats-form">'
           . '<i class="bi bi-arrow-clockwise me-1"></i>Refresh from eBay</button>';
        echo '</div>';

        if ($count === 0) {
            echo '<div class="alert alert-info py-2 mb-3">'
               . 'No store categories cached. Connect with <code>sell.stores</code> scope, then refresh. '
               . 'If you do not have an eBay Store, leave these as None.'
               . '</div>';
        }

        echo $this->renderSettingFields($settingsDefs, $values, 'store_categories');
        echo '</div></div>';
        return (string) ob_get_clean();
    }

    /**
     * Inject cached eBay policy options into select field defs.
     *
     * @param array<string, mixed> $settingsDefs
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function withBusinessPolicyOptions(array $settingsDefs, array $values): array
    {
        $cache = is_array($values['business_policies_cache'] ?? null)
            ? $values['business_policies_cache']
            : [];

        $map = [
            'fulfillment_policy_id' => 'fulfillment',
            'payment_policy_id'     => 'payment',
            'return_policy_id'      => 'return',
            'merchant_location_key' => 'locations',
        ];

        foreach ($map as $field => $cacheKey) {
            if (!isset($settingsDefs[$field]) || !is_array($settingsDefs[$field])) {
                continue;
            }
            $options = ['' => '— Select —'];
            $list = is_array($cache[$cacheKey] ?? null) ? $cache[$cacheKey] : [];
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? $id));
                $options[$id] = $name !== '' ? $name : $id;
            }

            // Keep a currently saved ID visible even if cache is stale/missing.
            $current = trim((string) ($values[$field] ?? ''));
            if ($current !== '' && !isset($options[$current])) {
                $options[$current] = $current . ' (cached id)';
            }

            $settingsDefs[$field]['options'] = $options;
            $settingsDefs[$field]['type'] = 'select';
        }

        return $settingsDefs;
    }

    /**
     * @param array<string, mixed> $settingsDefs
     * @param array<string, mixed> $values
     */
    private function renderBusinessPoliciesPanel(
        array $settingsDefs,
        array $values,
        int $channelId,
    ): string {
        $cache = is_array($values['business_policies_cache'] ?? null)
            ? $values['business_policies_cache']
            : [];
        $syncedAt = (string) ($cache['synced_at'] ?? '');
        $hasCache = $cache !== [] && (
            !empty($cache['fulfillment'])
            || !empty($cache['payment'])
            || !empty($cache['return'])
            || !empty($cache['locations'])
        );

        ob_start();
        echo '<div class="sf-panel" id="business-policies">';
        echo '<div class="sf-panel-h">eBay Business Policies</div>';
        echo '<div class="sf-panel-b">';
        echo '<p class="small text-muted mb-3">Policies and inventory locations from your eBay seller account. '
           . 'Required to publish listings. Refresh after changing policies on eBay.</p>';

        echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">';
        echo '<div class="small text-muted">';
        if ($syncedAt !== '') {
            echo 'Last refreshed: ' . $this->view->e($syncedAt);
            if (!empty($cache['marketplace'])) {
                echo ' · ' . $this->view->e((string) $cache['marketplace']);
            }
        } else {
            echo 'Not refreshed yet — click <strong>Refresh from eBay</strong>.';
        }
        echo '</div>';
        echo '<button type="submit" class="btn btn-outline-primary btn-sm" form="sf-refresh-policies-form">'
           . '<i class="bi bi-arrow-clockwise me-1"></i>Refresh from eBay</button>';
        echo '</div>';

        if (!$hasCache) {
            echo '<div class="alert alert-info py-2 mb-3">'
               . 'No policies cached. Connect the channel, then refresh.'
               . '</div>';
        }

        echo $this->renderSettingFields($settingsDefs, $values, 'business_policies');

        // Manual fallback if API list is empty but user knows the IDs.
        echo '<details class="mt-3"><summary class="small text-muted" style="cursor:pointer">'
           . 'Enter policy IDs manually</summary><div class="mt-2">';
        foreach (['fulfillment_policy_id', 'payment_policy_id', 'return_policy_id', 'merchant_location_key'] as $field) {
            if (!isset($settingsDefs[$field])) {
                continue;
            }
            $label = (string) ($settingsDefs[$field]['label'] ?? $field);
            $val = (string) ($values[$field] ?? '');
            echo '<div class="sf-field-row"><label for="manual-' . $this->view->e($field) . '">'
               . $this->view->e($label) . ' (ID)</label>';
            echo '<input type="text" class="form-control form-control-sm" '
               . 'id="manual-' . $this->view->e($field) . '" '
               . 'value="' . $this->view->e($val) . '" '
               . 'onchange="var s=document.getElementById(\'setting-' . $this->view->e($field)
               . '\'); if(s){ if(![...s.options].some(o=>o.value===this.value)){'
               . 's.add(new Option(this.value,this.value,true,true)); } s.value=this.value; }">';
            echo '</div>';
        }
        echo '</div></details>';

        echo '</div></div>';
        return (string) ob_get_clean();
    }

    /**
     * Searchable eBay default category picker (Taxonomy suggestions).
     *
     * @param array<string, mixed> $values
     */
    private function renderEbayCategoryPicker(array $values, int $channelId): string
    {
        $catId   = trim((string) ($values['default_category_id'] ?? ''));
        $catName = trim((string) ($values['default_category_name'] ?? ''));
        $suggestUrl = $this->url->to('store/admin/channels/category-suggest', ['id' => $channelId]);

        ob_start();
        ?>
        <div class="sf-panel" id="ebay-category">
            <div class="sf-panel-h">eBay Default Category</div>
            <div class="sf-panel-b">
                <p class="small text-muted mb-3">
                    Used when publishing listings. Search eBay’s category tree for your marketplace, then pick a leaf category.
                </p>

                <div class="sf-field-row">
                    <label>Selected</label>
                    <div>
                        <?php if ($catId !== ''): ?>
                            <strong id="sf-cat-selected-label"><?= $this->view->e($catName !== '' ? $catName : ('Category ' . $catId)) ?></strong>
                            <span class="text-muted small"> · ID <code id="sf-cat-selected-id"><?= $this->view->e($catId) ?></code></span>
                        <?php else: ?>
                            <span class="text-muted" id="sf-cat-selected-label">None selected</span>
                            <code id="sf-cat-selected-id" class="d-none"></code>
                        <?php endif; ?>
                    </div>
                </div>

                <input type="hidden" name="default_category_id" id="default_category_id" value="<?= $this->view->e($catId) ?>">
                <input type="hidden" name="default_category_name" id="default_category_name" value="<?= $this->view->e($catName) ?>">

                <div class="mb-2">
                    <label class="form-label small mb-1" for="sf-cat-search">Search categories</label>
                    <input type="search" id="sf-cat-search" class="form-control form-control-sm"
                           placeholder="e.g. office chair, phone case…" autocomplete="off">
                    <div class="form-text">Type at least 2 characters. Results come from eBay Taxonomy.</div>
                </div>

                <div id="sf-cat-status" class="small text-muted mb-2"></div>
                <div id="sf-cat-results" class="list-group list-group-flush border rounded mb-2" style="max-height:16rem;overflow:auto;display:none"></div>

                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="sf-cat-clear">Clear selection</button>
                </div>
            </div>
        </div>
        <script>
        (function () {
          const suggestUrl = <?= json_encode($suggestUrl) ?>;
          const search = document.getElementById('sf-cat-search');
          const results = document.getElementById('sf-cat-results');
          const status = document.getElementById('sf-cat-status');
          const idInput = document.getElementById('default_category_id');
          const nameInput = document.getElementById('default_category_name');
          const labelEl = document.getElementById('sf-cat-selected-label');
          const idEl = document.getElementById('sf-cat-selected-id');
          const clearBtn = document.getElementById('sf-cat-clear');
          if (!search || !results || !idInput) return;

          let timer = null;
          let seq = 0;

          function setSelected(id, name, path) {
            idInput.value = id || '';
            nameInput.value = path || name || '';
            if (!id) {
              labelEl.textContent = 'None selected';
              labelEl.classList.add('text-muted');
              idEl.textContent = '';
              idEl.classList.add('d-none');
              return;
            }
            labelEl.textContent = path || name || ('Category ' + id);
            labelEl.classList.remove('text-muted');
            idEl.textContent = id;
            idEl.classList.remove('d-none');
          }

          function render(items) {
            results.innerHTML = '';
            if (!items.length) {
              results.style.display = 'none';
              return;
            }
            results.style.display = 'block';
            items.forEach(function (item) {
              const btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'list-group-item list-group-item-action py-2';
              btn.innerHTML = '<div class="fw-semibold">' + escapeHtml(item.name || item.id) + '</div>'
                + '<div class="small text-muted">' + escapeHtml(item.path || '') + ' · ID '
                + escapeHtml(item.id) + '</div>';
              btn.addEventListener('click', function () {
                setSelected(item.id, item.name, item.path || item.name);
                status.textContent = 'Selected. Click Save to keep this category.';
                results.style.display = 'none';
                search.value = '';
              });
              results.appendChild(btn);
            });
          }

          function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
              return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
            });
          }

          function runSearch(q) {
            const my = ++seq;
            status.textContent = 'Searching…';
            fetch(suggestUrl + (suggestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q), {
              headers: { 'Accept': 'application/json' },
              credentials: 'same-origin'
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                if (my !== seq) return;
                if (!res.j || !res.j.ok) {
                  status.textContent = (res.j && res.j.error) ? res.j.error : 'Search failed.';
                  results.style.display = 'none';
                  return;
                }
                const items = Array.isArray(res.j.results) ? res.j.results : [];
                status.textContent = items.length ? (items.length + ' result(s)') : 'No categories found.';
                render(items);
              })
              .catch(function () {
                if (my !== seq) return;
                status.textContent = 'Search failed.';
                results.style.display = 'none';
              });
          }

          search.addEventListener('input', function () {
            const q = search.value.trim();
            clearTimeout(timer);
            if (q.length < 2) {
              status.textContent = '';
              results.style.display = 'none';
              return;
            }
            timer = setTimeout(function () { runSearch(q); }, 300);
          });

          clearBtn && clearBtn.addEventListener('click', function () {
            setSelected('', '', '');
            status.textContent = 'Cleared. Save to apply.';
            results.style.display = 'none';
          });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $settingsDefs
     * @param array<string, mixed> $values
     */
    private function renderSettingFields(array $settingsDefs, array $values, string $section): string
    {
        ob_start();
        foreach ($settingsDefs as $fieldName => $fieldDef) {
            if (($fieldDef['section'] ?? '') !== $section) {
                continue;
            }
            // Rendered by dedicated picker UI.
            if ($section === 'ebay_category') {
                continue;
            }
            echo $this->renderOneSetting((string) $fieldName, $fieldDef, $values);
        }
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $fieldDef
     * @param array<string, mixed> $values
     */
    private function renderOneSetting(string $fieldName, array $fieldDef, array $values): string
    {
        $label   = (string) ($fieldDef['label'] ?? $fieldName);
        $ftype   = (string) ($fieldDef['type'] ?? 'text');
        $default = $fieldDef['default'] ?? null;
        $checked = array_key_exists($fieldName, $values)
            ? !empty($values[$fieldName])
            : (bool) $default;
        $current = (string) ($values[$fieldName] ?? (is_scalar($default) ? (string) $default : ''));
        $id      = 'setting-' . $fieldName;

        ob_start();
        if ($ftype === 'checkbox') {
            ?>
            <div class="sf-toggle-row">
                <label for="<?= $this->view->e($id) ?>"><?= $this->view->e($label) ?></label>
                <div class="form-check form-switch sf-form-switch m-0">
                    <input type="hidden" name="<?= $this->view->e($fieldName) ?>" value="0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="<?= $this->view->e($fieldName) ?>" value="1"
                           id="<?= $this->view->e($id) ?>"
                           <?= $checked ? 'checked' : '' ?>>
                </div>
            </div>
            <?php
        } elseif ($ftype === 'select') {
            ?>
            <div class="sf-field-row">
                <label for="<?= $this->view->e($id) ?>"><?= $this->view->e($label) ?></label>
                <select name="<?= $this->view->e($fieldName) ?>" id="<?= $this->view->e($id) ?>" class="form-select form-select-sm">
                    <?php foreach (($fieldDef['options'] ?? []) as $optVal => $optLabel): ?>
                        <option value="<?= $this->view->e((string) $optVal) ?>"
                            <?= $current === (string) $optVal ? 'selected' : '' ?>>
                            <?= $this->view->e((string) $optLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php
        } else {
            ?>
            <div class="sf-field-row">
                <label for="<?= $this->view->e($id) ?>"><?= $this->view->e($label) ?></label>
                <input type="<?= $this->view->e($ftype) ?>"
                       name="<?= $this->view->e($fieldName) ?>"
                       id="<?= $this->view->e($id) ?>"
                       class="form-control form-control-sm"
                       value="<?= $this->view->e($current) ?>">
            </div>
            <?php
            if (!empty($fieldDef['help'])) {
                echo '<div class="form-text small mb-2">' . $this->view->e((string) $fieldDef['help']) . '</div>';
            }
        }
        return (string) ob_get_clean();
    }

    /**
     * Split provider settings into two columns for Store-style layout.
     *
     * @param array<string, mixed> $settingsDefs
     * @param array<string, mixed> $values
     */
    private function renderProviderSettingsGrid(
        array $settingsDefs,
        array $values,
        string $heading = 'Integration settings',
    ): string {
        $fields = [];
        foreach ($settingsDefs as $name => $def) {
            if (($def['section'] ?? '') === 'provider') {
                $fields[$name] = $def;
            }
        }
        if ($fields === []) {
            return '';
        }

        $keys = array_keys($fields);
        $mid  = (int) ceil(count($keys) / 2);
        $left = array_slice($keys, 0, $mid, true);
        $right = array_slice($keys, $mid, null, true);

        ob_start();
        echo '<div class="sf-panel" id="provider">';
        echo '<div class="sf-panel-h">' . $this->view->e($heading) . '</div>';
        echo '<div class="sf-panel-b"><div class="sf-provider-grid">';
        echo '<div>';
        foreach ($left as $name) {
            echo $this->renderOneSetting((string) $name, $fields[$name], $values);
        }
        echo '</div><div>';
        foreach ($right as $name) {
            echo $this->renderOneSetting((string) $name, $fields[$name], $values);
        }
        echo '</div></div></div></div>';
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, array> $types
     */
    private function channelForm(
        array $old,
        string $action,
        array $types,
        bool $isEdit = false,
        bool $lockType = false,
        ?Channel $channel = null,
    ): string {
        if (!isset($types[$old['type']])) {
            $types[$old['type']] = [
                'label'       => ucfirst($old['type']),
                'uses_oauth'  => false,
                'credentials' => [],
                'settings'    => [],
            ];
        }

        if ($isEdit && $channel !== null) {
            return $this->integrationDetailsForm($old, $action, $types, $channel);
        }

        return $this->simpleChannelForm($old, $action, $types, $isEdit, $lockType);
    }

    /**
     * @param array<string, array> $types
     */
    private function integrationDetailsForm(
        array $old,
        string $action,
        array $types,
        Channel $channel,
    ): string {
        $typeConfig = $types[$old['type']];
        $typeLabel  = (string) ($typeConfig['label'] ?? ucfirst($old['type']));
        $settings   = is_array($typeConfig['settings'] ?? null) ? $typeConfig['settings'] : [];
        $credFields = is_array($typeConfig['credentials'] ?? null) ? $typeConfig['credentials'] : [];
        $cid        = (int) $channel->id;

        ob_start();

        // Sibling action forms — buttons inside the save form use form="…"
        echo '<form id="sf-test-form" method="post" class="d-none" action="'
           . $this->view->e($this->url->to('store/admin/channels/test', ['id' => $cid])) . '">'
           . $this->request->csrfField() . '</form>';
        echo '<form id="sf-disconnect-form" method="post" class="d-none" action="'
           . $this->view->e($this->url->to('store/admin/channels/disconnect', ['id' => $cid])) . '"'
           . ' onsubmit="return confirm(\'Disconnect and clear tokens?\');">'
           . $this->request->csrfField() . '</form>';
        echo '<form id="sf-sync-form" method="post" class="d-none" action="'
           . $this->view->e($this->url->to('store/admin/channels/sync', ['id' => $cid])) . '"'
           . ' onsubmit="return confirm(\'Sync orders from this channel now?\');">'
           . $this->request->csrfField() . '</form>';
        echo '<form id="sf-refresh-policies-form" method="post" class="d-none" action="'
           . $this->view->e($this->url->to('store/admin/channels/refresh-policies', ['id' => $cid])) . '">'
           . $this->request->csrfField() . '</form>';
        echo '<form id="sf-refresh-store-cats-form" method="post" class="d-none" action="'
           . $this->view->e($this->url->to('store/admin/channels/refresh-store-categories', ['id' => $cid])) . '">'
           . $this->request->csrfField() . '</form>';
        echo '<form id="sf-refresh-listings-form" method="post" class="d-none" action="'
           . $this->view->e($this->url->to('store/admin/channels/refresh-listings', ['id' => $cid])) . '"'
           . ' onsubmit="return confirm(\'Refresh listing status from eBay (and relist ended if enabled)?\');">'
           . $this->request->csrfField() . '</form>';
        if (($old['type'] ?? '') === 'ebay') {
            echo '<form id="sf-import-listings-form" method="post" class="d-none" action="'
               . $this->view->e($this->url->to('store/admin/channels/import-listings', ['id' => $cid])) . '"'
               . ' onsubmit="return confirm(\'Import all active eBay listings into Products? Existing SKUs will be updated and linked.\');">'
               . $this->request->csrfField() . '</form>';
        }

        $settings = $this->withBusinessPolicyOptions($settings, $old['settings'] ?? []);
        $settings = $this->withStoreCategoryOptions($settings, $old['settings'] ?? []);

        echo $this->form->open([
            'action' => $action,
            'method' => 'POST',
            'attrs'  => ['id' => 'sf-integ-save'],
        ]);
        echo '<input type="hidden" name="type" value="' . $this->view->e($old['type']) . '">';

        // 1) Integration Profile
        echo '<div class="sf-panel" id="profile">';
        echo '<div class="sf-panel-h">Integration Profile</div>';
        echo '<div class="sf-panel-b">';
        echo '<div class="sf-field-row"><label>Integration Type</label>';
        echo '<div><strong>' . $this->view->e($typeLabel) . '</strong>'
           . ' <span class="text-muted small">(' . $this->view->e($old['name']) . ')</span></div></div>';
        echo '<div class="sf-field-row"><label for="label">Profile name</label>';
        echo '<input type="text" name="label" id="label" class="form-control form-control-sm" value="'
           . $this->view->e($old['label']) . '"></div>';
        echo $this->renderTenantFieldRow($old);
        echo '<div class="sf-toggle-row"><label for="enabled">Active</label>';
        echo '<div class="form-check form-switch sf-form-switch m-0">';
        echo '<input type="hidden" name="enabled" value="0">';
        echo '<input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1" id="enabled"'
           . (!empty($old['enabled']) ? ' checked' : '') . '>';
        echo '</div></div></div></div>';

        // 2) Link account (actions via form= attribute)
        echo $this->renderLinkAccountPanel($channel, $types, useExternalForms: true);

        // 3) Provider settings
        $providerHeading = match ($old['type']) {
            'ebay'        => 'eBay Specific Settings',
            'amazon'      => 'Amazon Specific Settings',
            'bnq'         => 'B&Q Specific Settings',
            'shopify'     => 'Shopify Specific Settings',
            'evri'        => 'Evri Parcel Settings',
            'royalmail'   => 'Royal Mail Parcel Settings',
            'parcelforce' => 'ParcelForce Parcel Settings',
            'dpd'         => 'DPD Parcel Settings',
            default       => 'Integration settings',
        };
        echo $this->renderProviderSettingsGrid($settings, $old['settings'] ?? [], $providerHeading);

        if ($old['type'] === 'shopify') {
            echo '<div class="sf-panel" id="shopify-messaging">';
            echo '<div class="sf-panel-h">Buyer messaging</div>';
            echo '<div class="sf-panel-b">';
            echo '<div class="alert alert-secondary mb-0 small">'
               . '<strong>Unavailable in Store.</strong> Shopify Admin API does not expose seller↔buyer '
               . 'messaging send/list for this integration. Conversation sync returns an empty list; '
               . 'use Shopify Inbox or customer email outside Store.</div>';
            echo '</div></div>';
        }

        // 3b) eBay Business Policies + store categories + marketplace category
        if ($old['type'] === 'ebay') {
            echo $this->renderBusinessPoliciesPanel($settings, $old['settings'] ?? [], $cid);
            echo $this->renderStoreCategoriesPanel($settings, $old['settings'] ?? []);
            echo $this->renderEbayCategoryPicker($old['settings'] ?? [], $cid);
        }
        if ($this->typeSupportsAttributes((string) $old['type'])) {
            $attrSettings = is_array($old['settings'] ?? null) ? $old['settings'] : [];
            $defs = (new \Module\Store\Services\ChannelAttributeRepository($this->db))
                ->ensureDefs($cid, (string) $old['type']);
            $attrSettings['general_attributes'] = $defs;
            echo $this->renderGeneralAttributesPanel($attrSettings, $cid, (string) $old['type']);
        }

        $isCourier = (($typeConfig['category'] ?? '') === 'courier');

        // 4) Channel Settings (sales channels only)
        if (!$isCourier) {
            echo '<div class="sf-panel" id="channel-settings">';
            echo '<div class="sf-panel-h">Channel Settings</div>';
            echo '<div class="sf-panel-b">';
            echo '<p class="small text-muted mb-3">This system is the master inventory source. '
               . 'Order, stock and fee behaviour for this integration is controlled below.</p>';

            echo '<div class="sf-panel-sub"><h3>Order Processing</h3>';
            echo $this->renderSettingFields($settings, $old['settings'] ?? [], 'order_processing');
            echo '</div>';

            echo '<div class="sf-panel-sub"><h3>Stock Management</h3>';
            echo $this->renderSettingFields($settings, $old['settings'] ?? [], 'stock_management');
            echo '<div class="d-flex flex-wrap justify-content-end gap-2 mt-2">'
               . '<button type="submit" class="btn btn-outline-primary btn-sm" form="sf-sync-form">'
               . 'Download orders now</button>'
               . '<button type="submit" class="btn btn-outline-secondary btn-sm" form="sf-refresh-listings-form">'
               . 'Refresh listings now</button>';
            if (($old['type'] ?? '') === 'ebay') {
                echo '<button type="submit" class="btn btn-outline-success btn-sm" form="sf-import-listings-form">'
                   . 'Import listings → Products</button>';
            }
            echo '</div></div>';

            echo '<div class="sf-panel-sub"><h3>Misc</h3>';
            echo $this->renderSettingFields($settings, $old['settings'] ?? [], 'misc');
            echo '</div></div></div>';
        } elseif (in_array($old['type'], ['evri', 'royalmail', 'parcelforce', 'dpd'], true)) {
            $courierName = match ($old['type']) {
                'royalmail'   => 'Royal Mail',
                'parcelforce' => 'ParcelForce',
                'dpd'         => 'DPD',
                default       => 'Evri',
            };
            echo '<div class="sf-panel" id="courier-help">';
            echo '<div class="sf-panel-h">How ' . $this->view->e($courierName) . ' labels work</div>';
            echo '<div class="sf-panel-b">'
               . '<p class="small text-muted mb-0">Save credentials and parcel settings, then <strong>Test Connection</strong>. '
               . 'On an open order, choose this courier when marking shipped — '
               . 'we create the parcel, download the PDF, and notify the sales channel with the tracking number.</p>'
               . '</div></div>';
        }

        // 5) Credentials & advanced
        echo '<div class="sf-panel" id="advanced">';
        echo '<div class="sf-panel-h">Credentials &amp; advanced</div>';
        echo '<div class="sf-panel-b">';
        $sandbox = !empty($old['settings']['sandbox']);
        $typeKey = (string) ($old['type'] ?? '');
        $customApp = $typeKey === 'amazon'
            ? !empty($old['settings']['custom_amazon_app'])
            : !empty($old['settings']['custom_ebay_app']);
        $platformManaged = ($typeKey === 'ebay' && EbayPlatformApp::managesChannelOAuth($sandbox))
            || ($typeKey === 'amazon' && AmazonPlatformApp::managesChannelOAuth());
        $platformConfig = $typeKey === 'amazon' ? 'store.amazon' : 'store.ebay';
        $platformLabel = $typeKey === 'amazon' ? 'Amazon' : 'eBay';
        $customLabel = $typeKey === 'amazon' ? 'Use custom Amazon app' : 'Use custom eBay app';
        $visibleCreds = $this->visibleCredentialFields($credFields, $typeKey, $sandbox);
        if ($platformManaged && !$customApp && $visibleCreds === []) {
            echo '<p class="small text-muted mb-3">' . $this->view->e($platformLabel)
               . ' app credentials are managed by the platform '
               . '(<code>' . $this->view->e($platformConfig) . '</code>'
               . ($typeKey === 'ebay' && $sandbox ? '.sandbox' : '')
               . '). Use <strong>Link account</strong> above for seller consent. '
               . 'Enable <em>' . $this->view->e($customLabel) . '</em> below only if this channel needs its own app keys.</p>';
            if ($this->tenantResolver->isPlatformOwner()) {
                echo '<details class="mb-3"><summary class="small text-muted" style="cursor:pointer">'
                   . 'Custom app fields (only used when “' . $this->view->e($customLabel) . '” is on)</summary>';
                echo '<p class="small text-muted mt-2">Turn on the checkbox in advanced settings, then save these keys.</p>';
                foreach ($credFields as $fieldName => $fieldDef) {
                    $this->renderCredentialInput((string) $fieldName, $fieldDef, $old['credentials'] ?? []);
                }
                echo '</details>';
            }
        } elseif ($platformManaged && $customApp) {
            echo '<p class="small text-muted">This channel uses a <strong>custom</strong> '
               . $this->view->e($platformLabel) . ' app. '
               . 'Passwords left blank keep the stored value.</p>';
            foreach ($credFields as $fieldName => $fieldDef) {
                $this->renderCredentialInput((string) $fieldName, $fieldDef, $old['credentials'] ?? []);
            }
        } else {
            echo '<p class="small text-muted">App credentials from the developer portal. '
               . 'Passwords left blank keep the stored value.</p>';
            foreach ($visibleCreds as $fieldName => $fieldDef) {
                $this->renderCredentialInput((string) $fieldName, $fieldDef, $old['credentials'] ?? []);
            }
        }
        echo $this->renderSettingFields($settings, $old['settings'] ?? [], 'advanced');
        echo '</div></div>';

        echo '<div class="d-flex flex-wrap gap-2 mb-4">';
        echo '<button type="submit" class="btn btn-primary">Save</button>';
        echo '<a href="' . $this->view->e($this->url->to('store/admin/channels'))
           . '" class="btn btn-outline-secondary">Back to Integrations</a>';
        echo '</div>';
        echo $this->form->close();

        echo $this->renderSyncLogPanel($cid);

        return (string) ob_get_clean();
    }

    private function renderSyncLogPanel(int $channelId): string
    {
        $rows = [];
        try {
            $rows = (new SyncLogRepository($this->db))->forChannel($channelId, 40);
        } catch (\Throwable $e) {
            error_log('Sync log load #' . $channelId . ': ' . $e->getMessage());
        }

        ob_start();
        echo '<div class="sf-panel" id="sync-log">';
        echo '<div class="sf-panel-h">Sync Log</div>';
        echo '<div class="sf-panel-b">';
        echo '<p class="small text-muted mb-3">Recent connection tests and order syncs for this integration.</p>';
        if ($rows === []) {
            echo '<div class="alert alert-info mb-0">No sync activity logged yet. '
               . 'Run <strong>Test Connection</strong> or <strong>Download orders now</strong>.</div>';
            echo '</div></div>';
            return (string) ob_get_clean();
        }

        echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
        echo '<thead><tr><th>When</th><th>Action</th><th>Status</th><th>Summary</th><th>User</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $badge = match ((string) $r['status']) {
                'ok'      => 'text-bg-success',
                'error'   => 'text-bg-danger',
                'skipped' => 'text-bg-secondary',
                default   => 'text-bg-light',
            };
            echo '<tr>';
            echo '<td class="small text-nowrap">' . $this->view->e((string) $r['created_at']) . '</td>';
            echo '<td class="small"><code>' . $this->view->e((string) $r['action']) . '</code></td>';
            echo '<td><span class="badge ' . $badge . '">' . $this->view->e((string) $r['status']) . '</span></td>';
            echo '<td class="small">' . $this->view->e((string) $r['summary']) . '</td>';
            echo '<td class="small text-muted">' . $this->view->e((string) ($r['user_label'] !== '' ? $r['user_label'] : '—')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div></div>';
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $details */
    private function logSync(
        int $channelId,
        string $action,
        string $status,
        string $summary = '',
        array $details = [],
    ): void {
        try {
            (new SyncLogRepository($this->db))->log(
                $channelId,
                $action,
                $status,
                $summary,
                $details,
                $this->auth->id(),
                $this->currentSyncUserLabel(),
            );
        } catch (\Throwable $e) {
            error_log('Sync log #' . $channelId . ': ' . $e->getMessage());
        }
    }

    private function currentSyncUserLabel(): string
    {
        $user = $this->auth->user();
        if ($user === null) {
            return 'System';
        }
        $label = method_exists($user, 'displayLabel') ? (string) $user->displayLabel() : '';
        if ($label === '' && isset($user->username)) {
            $label = (string) $user->username;
        }
        if ($label === '') {
            $label = 'User #' . (int) ($this->auth->id() ?? 0);
        }
        return $label;
    }

    /**
     * Compact create form (new integration).
     *
     * @param array<string, array> $types
     */
    private function simpleChannelForm(
        array $old,
        string $action,
        array $types,
        bool $isEdit = false,
        bool $lockType = false,
    ): string {
        ob_start();
        echo $this->form->open(['action' => $action, 'method' => 'POST']);
        echo '<div class="sf-panel"><div class="sf-panel-h">Integration Profile</div><div class="sf-panel-b">';

        if ($isEdit) {
            echo '<div class="mb-3"><label class="form-label">Name</label>'
               . '<input type="text" class="form-control" value="' . $this->view->e($old['name']) . '" readonly></div>';
        } else {
            echo $this->form->field([
                'name' => 'name', 'label' => 'Name (internal key)', 'type' => 'text',
                'value' => $old['name'], 'required' => true,
                'help' => 'Lowercase slug, e.g. ebay-main.',
            ]);
        }

        echo $this->form->field([
            'name' => 'label', 'label' => 'Profile name', 'type' => 'text',
            'value' => $old['label'],
            'help' => 'Shown in Integration Manager, e.g. "Dusky eBay".',
        ]);

        echo $this->renderTenantFieldSimple($old);

        echo '<div class="mb-3"><label class="form-label">Type</label>';
        if ($lockType && !$isEdit) {
            echo '<input type="hidden" name="type" value="' . $this->view->e($old['type']) . '">';
            echo '<input type="text" class="form-control" value="'
               . $this->view->e((string) ($types[$old['type']]['label'] ?? $old['type'])) . '" readonly>';
        } else {
            echo '<select name="type" class="form-select">';
            foreach ($types as $code => $config) {
                echo '<option value="' . $this->view->e($code) . '"'
                   . ($old['type'] === $code ? ' selected' : '') . '>'
                   . $this->view->e((string) ($config['label'] ?? $code)) . '</option>';
            }
            echo '</select>';
        }
        echo '</div>';

        echo '<div class="sf-toggle-row mb-2"><label for="enabled">Active</label>'
           . '<div class="form-check form-switch sf-form-switch m-0">'
           . '<input type="hidden" name="enabled" value="0">'
           . '<input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1" id="enabled"'
           . (!empty($old['enabled']) ? ' checked' : '') . '></div></div>';
        echo '</div></div>';

        echo '<div class="sf-panel"><div class="sf-panel-h">Credentials</div><div class="sf-panel-b">';
        echo '<p class="text-muted small">App credentials from the developer portal.</p>';
        foreach ($types as $code => $config) {
            echo '<div class="cred-group" data-type="' . $this->view->e($code) . '">';
            $credFields = is_array($config['credentials'] ?? null) ? $config['credentials'] : [];
            $sandbox = !empty($old['settings']['sandbox']);
            $visible = $this->visibleCredentialFields($credFields, (string) $code, $sandbox);
            if ($code === 'ebay' && EbayPlatformApp::managesChannelOAuth($sandbox) && $visible === []) {
                echo '<p class="text-muted small mb-0">Save this integration, then use <strong>Link account</strong> '
                   . 'to open eBay consent. App ID / Cert ID are managed by the platform.</p>';
            } elseif ($code === 'amazon' && AmazonPlatformApp::managesChannelOAuth() && $visible === []) {
                echo '<p class="text-muted small mb-0">Save this integration, then use <strong>Link account</strong> '
                   . 'to open Amazon Seller Central consent. LWA keys are managed by the platform.</p>';
            } elseif ($code === 'amazon') {
                echo '<p class="text-muted small mb-0">Save this integration, then use <strong>Link account</strong> '
                   . 'to authorize Seller Central. Application ID + LWA keys can live in <code>store.amazon</code>.</p>';
            } elseif ($visible === []) {
                echo '<p class="text-muted small">No credentials for this type yet.</p>';
            }
            foreach ($visible as $fieldName => $fieldDef) {
                $ftype = (string) ($fieldDef['type'] ?? 'text');
                $value = $ftype === 'password' ? '' : (string) ($old['credentials'][$fieldName] ?? '');
                echo $this->form->field([
                    'name' => (string) $fieldName,
                    'label' => (string) ($fieldDef['label'] ?? $fieldName),
                    'type' => $ftype,
                    'value' => $value,
                ]);
            }
            echo '</div>';
        }
        echo '</div></div>';

        // Minimal settings on create (provider + advanced only)
        echo '<div class="sf-panel"><div class="sf-panel-h">Settings</div><div class="sf-panel-b">';
        foreach ($types as $code => $config) {
            echo '<div class="cred-group" data-type="' . $this->view->e($code) . '">';
            $settings = $config['settings'] ?? [];
            $shown = 0;
            foreach ($settings as $fieldName => $fieldDef) {
                $section = (string) ($fieldDef['section'] ?? 'provider');
                if (!in_array($section, ['provider', 'advanced'], true)) {
                    continue;
                }
                echo $this->renderOneSetting((string) $fieldName, $fieldDef, $old['settings'] ?? []);
                $shown++;
            }
            if ($shown === 0) {
                echo '<p class="text-muted small mb-0">Defaults will be applied. Fine-tune after connect.</p>';
            }
            echo '</div>';
        }
        echo '</div></div>';

        echo '<button type="submit" class="btn btn-primary">Save</button> ';
        echo '<a href="' . $this->view->e($this->url->to('store/admin/channels'))
           . '" class="btn btn-outline-secondary">Cancel</a>';
        ?>
        <script>
        (function () {
            const typeSelect = document.querySelector('select[name="type"]');
            function syncGroups() {
                const type = typeSelect ? typeSelect.value : <?= json_encode($old['type']) ?>;
                document.querySelectorAll('.cred-group').forEach(function (el) {
                    el.classList.toggle('d-none', el.dataset.type !== type);
                });
            }
            typeSelect && typeSelect.addEventListener('change', syncGroups);
            syncGroups();
        })();
        </script>
        <?php
        echo $this->form->close();
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, array> $availableTypes
     * @return array<string, array{label: string, icon: string, providers: array<string, array{label: string, available: bool, blurb?: string}>}>
     */
    private function integrationCatalog(array $availableTypes): array
    {
        $file = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'integrations.php';
        $catalog = is_file($file) ? include $file : [];
        if (!is_array($catalog)) {
            $catalog = [];
        }

        // Mark providers available when a real channel client exists.
        foreach ($catalog as $catKey => &$cat) {
            if (!isset($cat['providers']) || !is_array($cat['providers'])) {
                continue;
            }
            foreach ($cat['providers'] as $type => &$provider) {
                if (isset($availableTypes[$type])) {
                    $provider['available'] = true;
                    if (empty($provider['label']) && !empty($availableTypes[$type]['label'])) {
                        $provider['label'] = (string) $availableTypes[$type]['label'];
                    }
                }
            }
            unset($provider);
        }
        unset($cat);

        return $catalog;
    }

    /**
     * @param array<int, Channel> $channels
     * @param array<string, array> $types
     */
    private function formatLastSynced(?string $at): string
    {
        if ($at === null || trim($at) === '') {
            return '—';
        }
        $ts = strtotime($at);
        return $ts === false ? $at : date('d M Y H:i', $ts);
    }

    private function renderIntegrationSection(string $label, string $icon, array $channels, array $types): void
    {
        $showTenant = $this->tenantResolver->isPlatformOwner();
        echo '<div class="sf-im-card">';
        echo '<div class="sf-im-card-h">'
           . '<i class="bi bi-' . $this->view->e($icon) . '"></i>'
           . '<span>' . $this->view->e($label) . '</span>'
           . '<span class="sf-im-count">' . count($channels) . '</span>'
           . '</div>';
        echo '<div class="table-responsive"><table class="sf-im-table">';
        echo '<thead><tr>'
           . '<th class="c-integ">Integration</th>'
           . '<th class="c-name">Profile name</th>'
           . ($showTenant ? '<th class="c-tenant">Tenant</th>' : '')
           . '<th class="c-active">Active</th>'
           . '<th class="c-status">Status</th>'
           . '<th class="c-sync">Last synced</th>'
           . '<th class="c-actions">Actions</th>'
           . '</tr></thead><tbody>';

        foreach ($channels as $c) {
            $typeLabel = (string) (($types[strtolower($c->type)]['label'] ?? null) ?: $c->type);
            echo '<tr>';
            echo '<td class="c-integ"><span class="sf-im-title">' . $this->view->e($typeLabel) . '</span>'
               . '<span class="sf-im-sub">' . $this->view->e($c->type) . '</span>';
            if (strtolower($c->type) === 'ebay') {
                echo !empty($c->settings['sandbox'])
                    ? '<span class="sf-im-badge is-sand">Sandbox</span>'
                    : '<span class="sf-im-badge is-live">Production</span>';
            }
            echo '</td>';
            echo '<td class="c-name"><span class="sf-im-title">' . $this->view->e($c->label) . '</span>'
               . '<span class="sf-im-sub">' . $this->view->e($c->name) . '</span>';
            if ($c->status === 'error' && $c->lastError !== '') {
                echo '<div class="sf-im-err">'
                   . $this->view->e(mb_substr($c->lastError, 0, 100))
                   . (mb_strlen($c->lastError) > 100 ? '…' : '')
                   . '</div>';
            }
            echo '</td>';

            if ($showTenant) {
                $t = $this->tenants->find((int) $c->tenantId);
                $tLabel = $t !== null ? (string) $t['name'] : ('#' . (int) $c->tenantId);
                echo '<td class="c-tenant"><span class="sf-im-title">' . $this->view->e($tLabel) . '</span>'
                   . '<span class="sf-im-sub">#' . (int) $c->tenantId . '</span></td>';
            }

            $toggleId = 'sf-active-' . (int) $c->id;
            echo '<td class="c-active">';
            echo '<form method="post" action="'
               . $this->view->e($this->url->to('store/admin/channels/toggle', ['id' => $c->id]))
               . '" class="sf-im-active">';
            echo $this->request->csrfField();
            echo '<div class="form-check form-switch sf-form-switch m-0">';
            echo '<input class="form-check-input" type="checkbox" role="switch" id="'
               . $this->view->e($toggleId) . '"'
               . ($c->enabled ? ' checked' : '')
               . ' title="' . ($c->enabled ? 'Active — click to disable' : 'Inactive — click to enable') . '"'
               . ' onchange="this.form.submit()">';
            echo '</div>';
            echo '<span>' . ($c->enabled ? 'On' : 'Off') . '</span>';
            echo '</form></td>';

            $statusClass = match ($c->status) {
                'connected' => 'is-ok',
                'error'     => 'is-err',
                default     => 'is-off',
            };
            $statusLabel = match ($c->status) {
                'connected' => 'Connected',
                'error'     => 'Error',
                default     => 'Disconnected',
            };
            echo '<td class="c-status"><span class="sf-im-pill ' . $statusClass . '">'
               . $this->view->e($statusLabel) . '</span></td>';
            echo '<td class="c-sync"><span class="sf-im-sync">'
               . $this->view->e($this->formatLastSynced($c->lastSyncedAt ?? null))
               . '</span></td>';
            echo '<td class="c-actions"><div class="sf-im-actions">';
            echo '<a href="' . $this->view->e($this->url->to('store/admin/channels/edit', ['id' => $c->id])) . '">Edit</a>';
            if (!empty($types[strtolower($c->type)]['uses_oauth'])) {
                echo '<a class="is-connect" href="'
                   . $this->view->e($this->url->to('store/admin/channels/connect', ['id' => $c->id]))
                   . '">Connect</a>';
            }
            echo '<form method="post"'
               . ' action="' . $this->view->e($this->url->to('store/admin/channels/delete', ['id' => $c->id])) . '"'
               . ' onsubmit="return confirm(\'Delete this integration?\');">';
            echo $this->request->csrfField();
            echo '<button type="submit" class="is-danger">Delete</button>';
            echo '</form></div></td></tr>';
        }

        echo '</tbody></table></div></div>';
    }

    /**
     * @param array<string, array{label: string, icon: string, providers: array<string, array{label: string, available: bool, blurb?: string}>}> $catalog
     */
    private function renderNewIntegrationModal(array $catalog): void
    {
        echo '<div class="modal fade" id="newIntegrationModal" tabindex="-1" aria-labelledby="newIntegrationModalLabel" aria-hidden="true">';
        echo '<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">';
        echo '<div class="modal-header">';
        echo '<h2 class="modal-title h5" id="newIntegrationModalLabel">New integration</h2>';
        echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
        echo '</div>';
        echo '<div class="modal-body">';
        echo '<p class="text-muted">Please select an integration type…</p>';

        echo '<div class="row g-3 mb-4" id="integ-cat-grid">';
        foreach ($catalog as $catKey => $cat) {
            echo '<div class="col-6 col-md-3">';
            echo '<button type="button" class="btn btn-outline-secondary w-100 h-100 py-4 integ-cat-btn" data-cat="'
               . $this->view->e((string) $catKey) . '">';
            echo '<i class="bi bi-' . $this->view->e((string) $cat['icon']) . ' fs-2 d-block mb-2"></i>';
            echo '<span class="fw-semibold text-uppercase small">' . $this->view->e((string) $cat['label']) . '</span>';
            echo '</button></div>';
        }
        echo '</div>';

        foreach ($catalog as $catKey => $cat) {
            echo '<div class="integ-providers d-none" data-cat="' . $this->view->e((string) $catKey) . '">';
            echo '<button type="button" class="btn btn-link btn-sm px-0 mb-3 integ-back">&larr; Back to types</button>';
            echo '<div class="list-group">';
            foreach ($cat['providers'] as $type => $provider) {
                $available = !empty($provider['available']);
                $label = (string) ($provider['label'] ?? $type);
                $blurb = (string) ($provider['blurb'] ?? '');
                if ($available) {
                    $href = $this->url->to('store/admin/channels/add', ['type' => $type]);
                    echo '<a href="' . $this->view->e($href) . '" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">';
                    echo '<span><strong>' . $this->view->e($label) . '</strong>'
                       . ($blurb !== '' ? '<br><small class="text-muted">' . $this->view->e($blurb) . '</small>' : '')
                       . '</span>';
                    echo '<i class="bi bi-chevron-right"></i></a>';
                } else {
                    echo '<div class="list-group-item d-flex justify-content-between align-items-center opacity-75">';
                    echo '<span><strong>' . $this->view->e($label) . '</strong>'
                       . ($blurb !== '' ? '<br><small class="text-muted">' . $this->view->e($blurb) . '</small>' : '')
                       . '</span>';
                    echo '<span class="badge text-bg-secondary">Coming soon</span></div>';
                }
            }
            echo '</div></div>';
        }

        echo '</div>';
        echo '<div class="modal-footer">';
        echo '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>';
        echo '</div></div></div></div>';

        echo <<<'HTML'
<script>
(function () {
  const modal = document.getElementById('newIntegrationModal');
  if (!modal) return;
  const catGrid = document.getElementById('integ-cat-grid');
  function showCats() {
    catGrid.classList.remove('d-none');
    modal.querySelectorAll('.integ-providers').forEach(el => el.classList.add('d-none'));
  }
  modal.querySelectorAll('.integ-cat-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const cat = btn.getAttribute('data-cat');
      catGrid.classList.add('d-none');
      modal.querySelectorAll('.integ-providers').forEach(el => {
        el.classList.toggle('d-none', el.getAttribute('data-cat') !== cat);
      });
    });
  });
  modal.querySelectorAll('.integ-back').forEach(btn => {
    btn.addEventListener('click', showCats);
  });
  modal.addEventListener('hidden.bs.modal', showCats);
})();
</script>
HTML;
    }

    /**
     * @return array<string, string>
     */
    private function readCredentials(string $type): array
    {
        $types = $this->factory->availableTypes();
        $fields = $types[$type]['credentials'] ?? [];

        $out = [];
        foreach (array_keys($fields) as $fieldName) {
            $fieldType = $fields[$fieldName]['type'] ?? 'text';
            $out[$fieldName] = trim((string) $this->request->post(
                (string) $fieldName,
                $fieldType === 'password' ? 'raw' : 'string',
                ''
            ));
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function readSettings(string $type): array
    {
        $types  = $this->factory->availableTypes();
        $fields = $types[$type]['settings'] ?? [];

        $out = [];
        foreach ($fields as $fieldName => $fieldDef) {
            $fieldType = $fieldDef['type'] ?? 'text';
            if ($fieldType === 'checkbox') {
                $out[$fieldName] = !empty($this->request->post((string) $fieldName, 'int', 0));
            } else {
                $out[$fieldName] = trim((string) $this->request->post(
                    (string) $fieldName, 'string', ''
                ));
            }
        }
        return $out;
    }

    private function typeSupportsAttributes(string $type): bool
    {
        $typeCfg = $this->factory->availableTypes()[strtolower($type)] ?? [];
        $category = (string) ($typeCfg['category'] ?? '');
        // Couriers have no listing attributes; marketplace + webstore do.
        return $category !== '' && $category !== 'courier';
    }

    /** @return list<array{name: string, source: string, value: string}> */
    private function readGeneralAttributesPost(): array
    {
        $names = (array) $this->request->post('ga_name', 'raw', []);
        $sources = (array) $this->request->post('ga_source', 'raw', []);
        $values = (array) $this->request->post('ga_value', 'raw', []);
        $rows = [];
        $count = max(count($names), count($sources), count($values));
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'name'   => trim((string) ($names[$i] ?? '')),
                'source' => trim((string) ($sources[$i] ?? 'fixed')),
                'value'  => trim((string) ($values[$i] ?? '')),
            ];
        }
        return \Module\Store\Services\EbayAspectResolver::normalizeChannelRows([
            'general_attributes' => $rows,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function renderGeneralAttributesPanel(
        array $values,
        int $channelId,
        string $marketplace = 'ebay',
    ): string {
        $rows = \Module\Store\Services\EbayAspectResolver::normalizeChannelRows($values);
        if ($rows === []) {
            $rows = \Module\Store\Services\EbayAspectResolver::defaultChannelRowsForType($marketplace);
        }
        $sources = \Module\Store\Services\EbayAspectResolver::SOURCES;
        $e = fn(string $s): string => $this->view->e($s);
        $aspectsUrl = $this->url->to('store/admin/channels/category-aspects', ['id' => $channelId]);
        $catId = trim((string) ($values['default_category_id'] ?? ''));
        $catName = trim((string) ($values['default_category_name'] ?? ''));
        $cache = is_array($values['category_aspects_cache'] ?? null)
            ? $values['category_aspects_cache']
            : [];
        $cachedAspects = is_array($cache['aspects'] ?? null) ? $cache['aspects'] : [];
        $isEbay = $marketplace === 'ebay';
        $isAmazon = $marketplace === 'amazon';

        ob_start();
        echo '<div class="sf-panel" id="general-attributes">';
        echo '<div class="sf-panel-h">General Attributes</div>';
        echo '<div class="sf-panel-b">';
        if ($isEbay) {
            echo '<p class="small text-muted mb-3">Default eBay Item Specifics for this channel. '
               . 'Stored per channel so B&amp;Q / TikTok / other accounts stay independent. '
               . 'Load required names from your Default Category, then map to a product field or fixed value.</p>';

            echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-3">';
            echo '<button type="button" class="btn btn-sm btn-outline-primary" id="sf-ga-load-aspects"'
               . ($catId === '' ? ' disabled title="Set eBay Default Category first"' : '') . '>'
               . '<i class="bi bi-cloud-download me-1"></i>Load from default category</button>';
            if ($catId !== '') {
                echo '<span class="small text-muted">Category: <strong>'
                   . $e($catName !== '' ? $catName : $catId) . '</strong></span>';
            } else {
                echo '<span class="small text-muted">Set an eBay Default Category above first.</span>';
            }
            echo '<span class="small text-muted" id="sf-ga-aspects-status"></span>';
            echo '</div>';
        } elseif ($isAmazon) {
            echo '<p class="small text-muted mb-3">Stored in <code>#__store_channel_attributes</code> for this Amazon account. '
               . 'Map SP-API attribute names (e.g. <code>brand</code>, <code>ean</code>) to a product field or fixed value. '
               . 'Per-SKU overrides go on the product → Channel Attributes tab. '
               . 'Pair with Default product type under Amazon Settings.</p>';
            $productType = trim((string) ($values['product_type'] ?? ''));
            if ($productType !== '') {
                echo '<p class="small mb-3">Product type: <code>' . $e($productType) . '</code></p>';
            }
        } else {
            echo '<p class="small text-muted mb-3">Stored in <code>#__store_channel_attributes</code> for this integration. '
               . 'Product-level overrides are edited on each product → Channel Attributes '
               . '(<code>#__store_product_channel_attributes</code>).</p>';
        }

        // Datalists for known aspect values from last taxonomy fetch
        foreach ($cachedAspects as $i => $aspect) {
            if (!is_array($aspect)) {
                continue;
            }
            $vals = is_array($aspect['values'] ?? null) ? $aspect['values'] : [];
            if ($vals === []) {
                continue;
            }
            $listId = 'sf-ga-vals-' . (int) $i;
            echo '<datalist id="' . $e($listId) . '" data-aspect-name="'
               . $e((string) ($aspect['name'] ?? '')) . '">';
            foreach ($vals as $v) {
                echo '<option value="' . $e((string) $v) . '">';
            }
            echo '</datalist>';
        }

        $attrLabel = $isEbay ? 'Item Specific' : 'Attribute';
        echo '<div class="table-responsive"><table class="table table-sm align-middle mb-2" id="sf-ga-table">';
        echo '<thead><tr><th>' . $e($attrLabel) . '</th><th>Mapping</th><th>Fixed value</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo $this->generalAttributeRowHtml($row, $sources, $isEbay ? $cachedAspects : []);
        }
        echo '</tbody></table></div>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="sf-ga-add">'
           . '<i class="bi bi-plus-lg me-1"></i>Add ' . $e($attrLabel) . '</button>';
        echo '</div></div>';

        $sourceOpts = '';
        foreach ($sources as $code => $label) {
            $sourceOpts .= '<option value="' . $e($code) . '">' . $e($label) . '</option>';
        }
        $autoMap = json_encode([
            'Brand' => 'product_brand',
            'Manufacturer' => 'product_brand',
            'MPN' => 'product_mpn',
            'Manufacturer Part Number' => 'product_mpn',
            'EAN' => 'product_ean',
            'UPC' => 'product_upc',
        ], JSON_UNESCAPED_UNICODE);
        echo '<script>
(function () {
  const tbody = document.querySelector("#sf-ga-table tbody");
  const addBtn = document.getElementById("sf-ga-add");
  const loadBtn = document.getElementById("sf-ga-load-aspects");
  const status = document.getElementById("sf-ga-aspects-status");
  if (!tbody || !addBtn) return;
  const sourceOpts = ' . json_encode($sourceOpts) . ';
  const aspectsUrl = ' . json_encode($aspectsUrl) . ';
  const autoMap = ' . $autoMap . ';

  function existingNames() {
    const set = new Set();
    tbody.querySelectorAll(\'input[name="ga_name[]"]\').forEach(function (inp) {
      const n = (inp.value || "").trim().toLowerCase();
      if (n) set.add(n);
    });
    return set;
  }
  function bindRemove(btn) {
    btn.addEventListener("click", function () {
      const tr = btn.closest("tr");
      if (tr && tbody.querySelectorAll("tr").length > 1) tr.remove();
    });
  }
  function addRow(name, source, value, required) {
    const tr = document.createElement("tr");
    if (required) tr.classList.add("table-warning");
    const req = required
      ? \' <span class="badge text-bg-danger">Required</span>\'
      : "";
    tr.innerHTML = \'<td><div class="d-flex align-items-center gap-1"><input type="text" class="form-control form-control-sm" name="ga_name[]" maxlength="64" value="\'
      + escapeHtml(name || "") + \'" placeholder="e.g. Material">\' + req + \'</div></td>\'
      + \'<td><select class="form-select form-select-sm" name="ga_source[]">\' + sourceOpts + \'</select></td>\'
      + \'<td><input type="text" class="form-control form-control-sm" name="ga_value[]" maxlength="255" value="\'
      + escapeHtml(value || "") + \'" placeholder="Used when mapping is Fixed value" list=""></td>\'
      + \'<td><button type="button" class="btn btn-sm btn-outline-danger sf-ga-remove">&times;</button></td>\';
    tbody.appendChild(tr);
    const sel = tr.querySelector(\'select[name="ga_source[]"]\');
    if (sel && source) sel.value = source;
    bindRemove(tr.querySelector(".sf-ga-remove"));
    return tr;
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"\']/g, function (c) {
      return ({ "&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;","\'":"&#39;" })[c];
    });
  }
  tbody.querySelectorAll(".sf-ga-remove").forEach(bindRemove);
  addBtn.addEventListener("click", function () { addRow("", "fixed", "", false); });

  if (loadBtn) {
    loadBtn.addEventListener("click", function () {
      loadBtn.disabled = true;
      if (status) status.textContent = "Loading Item Specifics from eBay…";
      fetch(aspectsUrl, { credentials: "same-origin", headers: { "Accept": "application/json" } })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.j || !res.j.ok) {
            throw new Error((res.j && res.j.error) || "Failed to load aspects.");
          }
          const have = existingNames();
          let added = 0;
          (res.j.aspects || []).forEach(function (a) {
            const name = (a.name || "").trim();
            if (!name) return;
            if (have.has(name.toLowerCase())) return;
            const mapped = autoMap[name] || "fixed";
            addRow(name, mapped, "", !!a.required);
            have.add(name.toLowerCase());
            added++;
          });
          if (status) {
            status.textContent = "Loaded " + (res.j.total || 0)
              + " specifics (" + (res.j.required || 0) + " required). Added " + added
              + " new row(s). Save to keep.";
          }
        })
        .catch(function (err) {
          if (status) status.textContent = err.message || "Load failed.";
        })
        .finally(function () { loadBtn.disabled = false; });
    });
  }
})();
</script>';
        return (string) ob_get_clean();
    }

    /**
     * @param array{name: string, source: string, value: string} $row
     * @param array<string, string> $sources
     * @param list<array<string, mixed>> $cachedAspects
     */
    private function generalAttributeRowHtml(array $row, array $sources, array $cachedAspects): string
    {
        $e = fn(string $s): string => $this->view->e($s);
        $required = false;
        $listId = '';
        foreach ($cachedAspects as $i => $aspect) {
            if (!is_array($aspect)) {
                continue;
            }
            if (strcasecmp((string) ($aspect['name'] ?? ''), $row['name']) === 0) {
                $required = !empty($aspect['required']);
                if (!empty($aspect['values']) && is_array($aspect['values'])) {
                    $listId = 'sf-ga-vals-' . (int) $i;
                }
                break;
            }
        }
        $html = '<tr' . ($required ? ' class="table-warning"' : '') . '>';
        $html .= '<td><div class="d-flex align-items-center gap-1"><input type="text" class="form-control form-control-sm" name="ga_name[]" maxlength="64" value="'
              . $e($row['name']) . '" placeholder="e.g. Brand">';
        if ($required) {
            $html .= ' <span class="badge text-bg-danger">Required</span>';
        }
        $html .= '</div></td>';
        $html .= '<td><select class="form-select form-select-sm" name="ga_source[]">';
        foreach ($sources as $code => $label) {
            $sel = $row['source'] === $code ? ' selected' : '';
            $html .= '<option value="' . $e($code) . '"' . $sel . '>' . $e($label) . '</option>';
        }
        $html .= '</select></td>';
        $html .= '<td><input type="text" class="form-control form-control-sm" name="ga_value[]" maxlength="255" value="'
              . $e($row['value']) . '" placeholder="Used when mapping is Fixed value"'
              . ($listId !== '' ? ' list="' . $e($listId) . '"' : '') . '></td>';
        $html .= '<td><button type="button" class="btn btn-sm btn-outline-danger sf-ga-remove">&times;</button></td>';
        $html .= '</tr>';
        return $html;
    }

    public function productAttributesAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $channel = $this->requireChannel($id);
        if (!$this->typeSupportsAttributes($channel->type)) {
            $this->notify->warning('Product attributes are not available for this channel type.');
            redirect_to('store/admin/channels/edit', ['id' => $id]);
            return;
        }

        $products = new \Module\Store\Services\ProductRepository($this->db);
        $attrRepo = new \Module\Store\Services\ChannelAttributeRepository($this->db);
        $rows = $products->all(limit: 100, offset: 0);
        $types = $this->factory->availableTypes();
        $typeLabel = (string) (($types[$channel->type]['label'] ?? null) ?: ucfirst($channel->type));
        $editHash = '#sf-sec-channel-attrs';

        echo $this->view->inline(function () use (
            $channel, $rows, $typeLabel, $id, $editHash, $attrRepo
        ) {
            $this->view->assign('title', 'Product Attributes — ' . $channel->label);
            $this->view->block('right_sidebar', 'Integrations');
            $e = fn(string $s): string => $this->view->e($s);

            echo $this->integrationDetailsStyles();
            echo '<div class="sf-integ-layout">';
            echo $this->renderIntegrationNav($channel, $typeLabel);
            echo '<div class="sf-integ-main">';
            echo '<h1 class="h4 mb-3">View Product Attributes</h1>';
            echo '<p class="text-muted small mb-3">Resolved attributes for <strong>'
               . $e($channel->label !== '' ? $channel->label : $channel->name)
               . '</strong> from this channel\'s General Attributes plus each product\'s '
               . 'per-channel overrides. Edit a product to change values.</p>';

            echo '<div class="table-responsive border rounded bg-white">';
            echo '<table class="table table-sm table-hover align-middle mb-0">';
            echo '<thead class="table-light"><tr>'
               . '<th>SKU</th><th>Name</th><th>Brand</th><th>MPN</th><th>Resolved Attributes</th><th></th>'
               . '</tr></thead><tbody>';

            if ($rows === []) {
                echo '<tr><td colspan="6" class="text-muted text-center py-4">No products yet.</td></tr>';
            }

            foreach ($rows as $product) {
                $aspects = $attrRepo->resolveForProduct($channel, $product);
                $bits = [];
                foreach ($aspects as $name => $vals) {
                    $bits[] = $name . ': ' . implode(' | ', $vals);
                }
                $editUrl = $this->url->to('store/admin/products/edit', ['id' => (int) $product->id]);
                echo '<tr>';
                echo '<td class="font-monospace small">' . $e($product->sku) . '</td>';
                echo '<td>' . $e($product->name) . '</td>';
                echo '<td>' . $e($product->brand !== '' ? $product->brand : '—') . '</td>';
                echo '<td>' . $e($product->mpn !== '' ? $product->mpn : '—') . '</td>';
                echo '<td class="small">' . ($bits !== [] ? $e(implode('; ', $bits)) : '<span class="text-muted">—</span>') . '</td>';
                echo '<td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($editUrl . $editHash) . '">Edit</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
            echo '<div class="mt-3"><a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/channels/edit', ['id' => $id]))
               . '">Back to integration</a></div>';
            echo '</div></div>';
        });
    }

    /**
     * Credential fields shown on the form. Platform-managed eBay keys are
     * hidden when store.ebay is configured (tenants: always; platform: in override).
     *
     * @param array<string, array<string, mixed>> $credFields
     * @return array<string, array<string, mixed>>
     */
    private function visibleCredentialFields(array $credFields, string $type, bool $sandbox): array
    {
        $managed = ($type === 'ebay' && EbayPlatformApp::managesChannelOAuth($sandbox))
            || ($type === 'amazon' && AmazonPlatformApp::managesChannelOAuth());
        if (!$managed) {
            return $credFields;
        }
        // Tenants never see platform-managed keys. Platform edits them via <details>.
        if (!$this->tenantResolver->isPlatformOwner()) {
            $out = [];
            foreach ($credFields as $name => $def) {
                if (empty($def['platform_managed'])) {
                    $out[$name] = $def;
                }
            }
            return $out;
        }
        // Platform edit form uses empty visible set + optional override block.
        return [];
    }

    /**
     * @param array<string, mixed> $fieldDef
     * @param array<string, mixed> $credentials
     */
    private function renderCredentialInput(string $fieldName, array $fieldDef, array $credentials): void
    {
        $label = (string) ($fieldDef['label'] ?? $fieldName);
        $ftype = (string) ($fieldDef['type'] ?? 'text');
        $value = $ftype === 'password' ? '' : (string) ($credentials[$fieldName] ?? '');
        $help  = ($ftype === 'password' && ($credentials[$fieldName] ?? '') !== '')
            ? 'Leave blank to keep the current value.'
            : (string) ($fieldDef['help'] ?? '');
        echo '<div class="sf-field-row"><label for="cred-' . $this->view->e($fieldName) . '">'
           . $this->view->e($label) . '</label>';
        echo '<input type="' . $this->view->e($ftype) . '" name="' . $this->view->e($fieldName)
           . '" id="cred-' . $this->view->e($fieldName) . '" class="form-control form-control-sm" value="'
           . $this->view->e($value) . '"></div>';
        if ($help !== '') {
            echo '<div class="form-text small mb-2">' . $this->view->e($help) . '</div>';
        }
    }

    /**
     * Load a channel for the current user. Platform may open any tenant's
     * channel (and switches context to that tenant for vault/OAuth).
     */
    private function requireChannel(int $id): Channel
    {
        if ($id <= 0) {
            throw new NotFoundException('Channel not found.');
        }

        if ($this->tenantResolver->isPlatformOwner()) {
            $channel = $this->channels->findAny($id);
            if ($channel === null) {
                throw new NotFoundException('Channel not found.');
            }
            $tid = (int) $channel->tenantId;
            if ($tid > 0) {
                $this->tenantResolver->selectTenantForUser($tid);
                $this->rebuildVaultForCurrentTenant();
            }
            return $channel;
        }

        $channel = $this->channels->find($id);
        if ($channel === null) {
            throw new NotFoundException('Channel not found.');
        }
        return $channel;
    }

    /**
     * Platform may post tenant_id; tenant owners always keep their context tenant.
     */
    private function resolvePostedTenantId(int $fallback): int
    {
        if (!$this->tenantResolver->isPlatformOwner()) {
            $ctx = (int) (TenantContext::get()->id() ?? 0);
            return $ctx > 0 ? $ctx : max(1, $fallback);
        }
        $posted = (int) $this->request->post('tenant_id', 'int', 0);
        if ($posted <= 0) {
            $posted = $fallback > 0 ? $fallback : (int) (TenantContext::get()->id() ?? 1);
        }
        $t = $this->tenants->find($posted);
        if ($t === null || ($t['status'] ?? '') !== 'active') {
            throw new \InvalidArgumentException('Selected tenant is missing or suspended.');
        }
        return $posted;
    }

    private function vaultForTenant(int $tenantId): CredentialsVault
    {
        $tenantId = max(1, $tenantId);
        return CredentialsVault::forTenant(
            (string) $this->config->get('store.credentials_key', ''),
            $tenantId
        );
    }

    private function rebuildVaultForCurrentTenant(): void
    {
        $tid = (int) (TenantContext::get()->id() ?? 1);
        $this->vault = $this->vaultForTenant($tid);
        $this->factory = new ChannelClientFactory($this->vault, $this->channels);
    }

    /** @param array<string, mixed> $old */
    private function renderTenantFieldSimple(array $old): string
    {
        if (!$this->tenantResolver->isPlatformOwner()) {
            $tid = (int) ($old['tenant_id'] ?? (TenantContext::get()->id() ?? 0));
            return '<input type="hidden" name="tenant_id" value="' . $tid . '">';
        }
        ob_start();
        echo '<div class="mb-3"><label class="form-label" for="tenant_id">Tenant</label>';
        echo '<select name="tenant_id" id="tenant_id" class="form-select" required>';
        $selected = (int) ($old['tenant_id'] ?? (TenantContext::get()->id() ?? 0));
        foreach ($this->tenants->all('active') as $t) {
            $sel = ((int) $t['id'] === $selected) ? ' selected' : '';
            $label = (string) $t['name'];
            if ($this->tenants->isPlatformHome($t)) {
                $label .= ' (platform home)';
            }
            echo '<option value="' . (int) $t['id'] . '"' . $sel . '>'
               . $this->view->e($label) . '</option>';
        }
        echo '</select>';
        echo '<div class="form-text">This integration belongs to the selected tenant only.</div></div>';
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $old */
    private function renderTenantFieldRow(array $old): string
    {
        if (!$this->tenantResolver->isPlatformOwner()) {
            $tid = (int) ($old['tenant_id'] ?? (TenantContext::get()->id() ?? 0));
            return '<input type="hidden" name="tenant_id" value="' . $tid . '">';
        }
        ob_start();
        echo '<div class="sf-field-row"><label for="tenant_id">Tenant</label>';
        echo '<select name="tenant_id" id="tenant_id" class="form-select form-select-sm" required>';
        $selected = (int) ($old['tenant_id'] ?? (TenantContext::get()->id() ?? 0));
        foreach ($this->tenants->all('active') as $t) {
            $sel = ((int) $t['id'] === $selected) ? ' selected' : '';
            $label = (string) $t['name'];
            if ($this->tenants->isPlatformHome($t)) {
                $label .= ' (platform home)';
            }
            echo '<option value="' . (int) $t['id'] . '"' . $sel . '>'
               . $this->view->e($label) . '</option>';
        }
        echo '</select></div>';
        return (string) ob_get_clean();
    }
}