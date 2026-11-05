<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\TenantContext;
use Module\StoreFeeder\Services\TenantRepository;
use Module\StoreFeeder\Services\TenantResolver;
use Module\StoreFeeder\Services\TenantTokenRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Services\User;

class StoreFeederAdminTenants extends Controller
{
    private TenantRepository $tenants;
    private TenantTokenRepository $tokens;
    private TenantResolver $resolver;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->tenants = new TenantRepository($this->db);
        $this->tokens = new TenantTokenRepository($this->db);
        $this->resolver = new TenantResolver(
            $this->db,
            $this->request,
            $this->session,
            $this->auth,
            $this->tenants,
            $this->tokens,
        );
    }

    public function indexAction(): void
    {
        $isPlatform = $this->resolver->isPlatformOwner();
        if ($isPlatform) {
            $this->tenants->ensurePlatformHome();
        }

        $memberships = $this->tenants->membershipsForUser((int) ($this->auth->id() ?? 0));
        $all = $isPlatform ? $this->tenants->all() : [];
        $ctx = TenantContext::get();
        $currentId = $ctx->id();

        $viewId = (int) $this->request->get('id', 'int', 0);
        // Opening Manage switches the working tenant so products/channels match.
        if ($isPlatform && $viewId > 0 && (int) $currentId !== $viewId) {
            if ($this->resolver->selectTenantForUser($viewId)) {
                $ctx = TenantContext::get();
                $currentId = $ctx->id();
            }
        }

        $ownersByTenant = [];
        if ($isPlatform) {
            foreach ($all as $t) {
                $ownersByTenant[(int) $t['id']] = $this->tenants->ownerForTenant((int) $t['id']);
            }
        }

        $tenantDetail = null;
        $tenantUsers = [];
        $tenantTokens = [];
        if ($viewId <= 0 && $currentId) {
            $viewId = $currentId;
        }
        if ($viewId > 0 && ($isPlatform || $this->userInTenant($viewId, $memberships))) {
            $tenantDetail = $this->tenants->find($viewId);
            if ($tenantDetail !== null) {
                $tenantUsers = $this->tenants->usersForTenant($viewId);
                $tenantTokens = $this->tokens->forTenant($viewId);
            }
        }

        $flashToken = (string) $this->session->get('_sf_new_token', '');
        if ($flashToken !== '') {
            $this->session->remove('_sf_new_token');
        }

        $switchOptions = [];
        if ($isPlatform) {
            foreach ($all as $t) {
                if (($t['status'] ?? '') !== 'active') {
                    continue;
                }
                $label = (string) $t['name'];
                if ($this->tenants->isPlatformHome($t)) {
                    $label .= ' — your platform home';
                }
                $switchOptions[] = [
                    'tenant_id'   => (int) $t['id'],
                    'tenant_name' => $label,
                    'role'        => 'platform',
                ];
            }
        } elseif (count($memberships) > 1) {
            $switchOptions = $memberships;
        }

        echo $this->view->inline(function () use (
            $isPlatform, $memberships, $all, $currentId, $tenantDetail,
            $tenantUsers, $tenantTokens, $flashToken, $ctx, $switchOptions, $ownersByTenant
        ) {
            $this->view->assign('title', $isPlatform ? 'Platform & tenants' : 'My organisation');
            $this->view->block('right_sidebar', 'Tenants');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-building me-1"></i>'
               . ($isPlatform ? 'Platform &amp; tenants' : 'My organisation') . '</h1>';
            if ($isPlatform) {
                echo '<p class="text-muted small mb-0">StoreFeeder products, channels, and orders belong to the tenant in '
                   . '<strong>Working in</strong> (top bar). Adding a member lets that person sign in to the client org '
                   . '&mdash; it does not change which tenant <em>you</em> are working in. '
                   . 'Use <strong>Manage</strong> or <strong>Use</strong> to enter a client tenant.</p>';
            } else {
                echo '<p class="text-muted small mb-0">Your organisation&rsquo;s StoreFeeder workspace (tokens &amp; members).</p>';
            }
            echo '</div>';
            if ($ctx->has()) {
                echo '<span class="badge text-bg-primary">Working in: ' . $e($ctx->name())
                   . ' (#' . (int) $ctx->id() . ')</span>';
            }
            echo '</div>';

            if ($flashToken !== '') {
                echo '<div class="alert alert-warning"><strong>Copy this token now</strong> — it will not be shown again.<br>'
                   . '<code class="user-select-all">' . $e($flashToken) . '</code></div>';
            }

            if ($isPlatform) {
                $ebayReady = \Module\StoreFeeder\Services\Channels\Ebay\EbayPlatformApp::isConfigured(false);
                if ($ebayReady) {
                    echo '<div class="alert alert-success py-2 small mb-3">eBay platform app is configured — '
                       . 'tenants can <strong>Link account</strong> (consent only), no App ID / Cert ID on the channel.</div>';
                } else {
                    echo '<div class="alert alert-warning py-2 small mb-3">eBay consent-only Connect is not ready. '
                       . 'Set <code>storefeeder.ebay</code> (<code>client_id</code>, <code>client_secret</code>, '
                       . '<code>ru_name</code>) in <code>system/config/storefeeder.php</code>. '
                       . 'RuName must point at <code>…/storefeeder/admin/channels/callback</code>.</div>';
                }
            }

            if ($switchOptions !== []) {
                echo '<div class="card border-0 shadow-sm mb-4"><div class="card-body">';
                echo '<h2 class="h6">' . ($isPlatform ? 'Work in tenant' : 'Switch organisation') . '</h2>';
                echo '<p class="text-muted small mb-2">Products, channels, orders, and inventory are loaded from this tenant. '
                   . 'Pick Duzky (or another client) and click <strong>Use</strong> to see their data.</p>';
                echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/tenants/select'))
                   . '" class="row g-2 align-items-end">';
                echo $this->request->csrfField();
                echo '<div class="col-md-6"><select name="tenant_id" class="form-select form-select-sm">';
                foreach ($switchOptions as $m) {
                    $sel = ((int) $m['tenant_id'] === (int) $currentId) ? ' selected' : '';
                    $extra = isset($m['role']) && $m['role'] !== 'platform'
                        ? ' (' . $m['role'] . ')' : '';
                    echo '<option value="' . (int) $m['tenant_id'] . '"' . $sel . '>'
                       . $e($m['tenant_name'] . $extra) . '</option>';
                }
                echo '</select></div>';
                echo '<div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary w-100">Use</button></div>';
                echo '</form></div></div>';
            }

            if ($isPlatform) {
                echo '<div class="card border-0 shadow-sm mb-4"><div class="card-body">';
                echo '<h2 class="h6">Add client tenant</h2>';
                echo '<p class="text-muted small">Creates an isolated org and assigns its owner (must be an existing user).</p>';
                echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/tenants/create'))
                   . '" class="row g-2 align-items-end">';
                echo $this->request->csrfField();
                echo '<div class="col-md-2"><label class="form-label small">Slug</label>'
                   . '<input name="slug" class="form-control form-control-sm" required pattern="[a-z0-9-]+" placeholder="acme"></div>';
                echo '<div class="col-md-3"><label class="form-label small">Name</label>'
                   . '<input name="name" class="form-control form-control-sm" required placeholder="Acme Ltd"></div>';
                echo '<div class="col-md-4"><label class="form-label small">Owner (email or username)</label>'
                   . '<input name="owner_login" class="form-control form-control-sm" required '
                   . 'placeholder="owner@example.com" autocomplete="off"></div>';
                echo '<div class="col-md-2"><button type="submit" class="btn btn-sm btn-success w-100">Create</button></div>';
                echo '</form></div></div>';

                $missingOwners = 0;
                foreach ($all as $t) {
                    if (!$this->tenants->isPlatformHome($t) && ($ownersByTenant[(int) $t['id']] ?? null) === null) {
                        $missingOwners++;
                    }
                }
                if ($missingOwners > 0) {
                    echo '<div class="alert alert-warning mb-3">';
                    echo '<strong>' . $missingOwners . ' client tenant(s) have no owner.</strong> '
                       . 'Open <em>Manage</em> → <strong>Set owner</strong> (user must already exist). '
                       . 'Until then, nobody can log in to that tenant&rsquo;s StoreFeeder data.';
                    echo '</div>';
                }

                echo '<div class="table-responsive border rounded bg-white mb-4">';
                echo '<table class="table table-sm align-middle mb-0"><thead class="table-light"><tr>'
                   . '<th>ID</th><th>Slug</th><th>Name</th><th>Owner</th><th>Status</th><th></th></tr></thead><tbody>';
                foreach ($all as $t) {
                    $home = $this->tenants->isPlatformHome($t);
                    $owner = $ownersByTenant[(int) $t['id']] ?? null;
                    $ownerLabel = '—';
                    $ownerMissing = false;
                    if ($home) {
                        $ownerLabel = 'you (platform)';
                    } elseif ($owner !== null) {
                        $ownerLabel = ($owner['username'] !== '' ? $owner['username'] : 'User #' . $owner['user_id']);
                        if ($owner['email'] !== '') {
                            $ownerLabel .= ' · ' . $owner['email'];
                        }
                    } else {
                        $ownerMissing = true;
                    }
                    echo '<tr' . ($ownerMissing ? ' class="table-warning"' : '') . '>';
                    echo '<td>' . (int) $t['id'] . '</td>';
                    echo '<td><code>' . $e($t['slug']) . '</code></td>';
                    echo '<td>' . $e($t['name'])
                       . ($home ? ' <span class="badge text-bg-secondary">platform home</span>' : '')
                       . '</td>';
                    echo '<td class="small">'
                       . ($ownerMissing
                           ? '<span class="badge text-bg-warning text-dark">No owner — assign below</span>'
                           : $e($ownerLabel))
                       . '</td>';
                    echo '<td><span class="badge ' . ($t['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary') . '">'
                       . $e($t['status']) . '</span></td>';
                    echo '<td class="text-end">';
                    echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/tenants/select'))
                       . '" class="d-inline">' . $this->request->csrfField()
                       . '<input type="hidden" name="tenant_id" value="' . (int) $t['id'] . '">'
                       . '<input type="hidden" name="next" value="storefeeder/admin">'
                       . '<button class="btn btn-sm btn-primary"'
                       . ((int) $t['id'] === (int) $currentId ? ' disabled' : '') . '>'
                       . ((int) $t['id'] === (int) $currentId ? 'Current' : 'Use') . '</button></form> ';
                    echo '<a class="btn btn-sm btn-outline-primary" href="'
                       . $e($this->url->to('storefeeder/admin/tenants', ['id' => (int) $t['id']])) . '">Manage</a> ';
                    if (!$home) {
                        echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/tenants/status', ['id' => (int) $t['id']]))
                           . '" class="d-inline">' . $this->request->csrfField()
                           . '<input type="hidden" name="status" value="'
                           . ($t['status'] === 'active' ? 'suspended' : 'active') . '">'
                           . '<button class="btn btn-sm btn-outline-secondary">'
                           . ($t['status'] === 'active' ? 'Suspend' : 'Activate') . '</button></form>';
                    }
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }

            if ($tenantDetail !== null) {
                $tid = (int) $tenantDetail['id'];
                $isHome = $this->tenants->isPlatformHome($tenantDetail);
                $detailOwner = $isPlatform ? ($ownersByTenant[$tid] ?? $this->tenants->ownerForTenant($tid)) : null;
                if ($isPlatform && !$isHome && $detailOwner === null) {
                    echo '<div class="alert alert-warning">';
                    echo '<strong>' . $e($tenantDetail['name']) . ' has no owner yet.</strong> '
                       . 'Use <strong>Set owner</strong> on the right with an existing user&rsquo;s email or username. '
                       . 'That person will only see this tenant&rsquo;s products, channels, and orders.';
                    echo '</div>';
                }
                if ($isPlatform && (int) $currentId === $tid) {
                    echo '<div class="alert alert-info py-2 small">You are now working in <strong>'
                       . $e($tenantDetail['name']) . '</strong>. Products, channels, and orders in the sidebar '
                       . 'are this tenant&rsquo;s'
                       . ($isHome ? ' (platform home).' : '.')
                       . ' <a href="' . $e($this->url->to('storefeeder/admin/products')) . '">Products</a>'
                       . ' · <a href="' . $e($this->url->to('storefeeder/admin/channels')) . '">Integrations</a>'
                       . ' · <a href="' . $e($this->url->to('storefeeder/admin')) . '">Dashboard</a></div>';
                } elseif ($isPlatform && (int) $currentId !== $tid) {
                    echo '<div class="alert alert-warning py-2 small">You are viewing <strong>'
                       . $e($tenantDetail['name']) . '</strong> members, but still working in <strong>'
                       . $e($ctx->name()) . '</strong>. Switch with <strong>Use</strong> to see this tenant&rsquo;s '
                       . 'products and channels.</div>';
                }
                echo '<div class="row g-3">';
                echo '<div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">';
                echo '<h2 class="h6">API tokens — ' . $e($tenantDetail['name']) . '</h2>';
                echo '<p class="small text-muted">Send as <code>Authorization: Bearer sft_…</code> or <code>X-Tenant-Token</code>.</p>';
                echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/tenants/create-token', ['id' => $tid]))
                   . '" class="row g-2 align-items-end mb-3">';
                echo $this->request->csrfField();
                echo '<div class="col-md-6"><input name="name" class="form-control form-control-sm" placeholder="Token name" required></div>';
                echo '<div class="col-md-4"><button type="submit" class="btn btn-sm btn-primary w-100">Create token</button></div>';
                echo '</form>';
                if ($tenantTokens === []) {
                    echo '<p class="text-muted small mb-0">No tokens yet.</p>';
                } else {
                    echo '<table class="table table-sm mb-0"><thead><tr><th>Name</th><th>Prefix</th><th>Status</th><th></th></tr></thead><tbody>';
                    foreach ($tenantTokens as $tk) {
                        $revoked = !empty($tk['revoked_at']);
                        echo '<tr><td>' . $e($tk['name']) . '</td><td><code>'
                           . $e($tk['prefix'] . '…' . $tk['last4']) . '</code></td><td>'
                           . ($revoked ? '<span class="badge text-bg-secondary">revoked</span>' : '<span class="badge text-bg-success">active</span>')
                           . '</td><td class="text-end">';
                        if (!$revoked) {
                            echo '<form method="post" action="'
                               . $e($this->url->to('storefeeder/admin/tenants/revoke-token', ['id' => $tid]))
                               . '" class="d-inline">' . $this->request->csrfField()
                               . '<input type="hidden" name="token_id" value="' . (int) $tk['id'] . '">'
                               . '<button class="btn btn-sm btn-outline-danger">Revoke</button></form>';
                        }
                        echo '</td></tr>';
                    }
                    echo '</tbody></table>';
                }
                echo '</div></div></div>';

                echo '<div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">';
                echo '<h2 class="h6">Users</h2>';
                if ($isPlatform && !$isHome) {
                    echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/tenants/set-owner', ['id' => $tid]))
                       . '" class="row g-2 align-items-end mb-3">';
                    echo $this->request->csrfField();
                    echo '<div class="col-md-7"><label class="form-label small">Set owner (email or username)</label>'
                       . '<input name="owner_login" class="form-control form-control-sm" required '
                       . 'placeholder="owner@example.com"></div>';
                    echo '<div class="col-md-4"><button type="submit" class="btn btn-sm btn-warning w-100">Set owner</button></div>';
                    echo '</form>';
                }
                if ($isPlatform) {
                    echo '<form method="post" action="' . $e($this->url->to('storefeeder/admin/tenants/add-user', ['id' => $tid]))
                       . '" class="row g-2 align-items-end mb-3">';
                    echo $this->request->csrfField();
                    echo '<div class="col-md-5"><label class="form-label small">Add member</label>'
                       . '<input name="user_login" class="form-control form-control-sm" required '
                       . 'placeholder="email or username"></div>';
                    echo '<div class="col-md-3"><label class="form-label small">Role</label>'
                       . '<select name="role" class="form-select form-select-sm">'
                       . '<option value="staff">staff</option><option value="admin">admin</option>'
                       . '</select></div>';
                    echo '<div class="col-md-3"><button type="submit" class="btn btn-sm btn-primary w-100 mt-4">Add</button></div>';
                    echo '</form>';
                }
                echo '<table class="table table-sm mb-0"><thead><tr><th>User</th><th>Role</th>';
                if ($isPlatform) {
                    echo '<th></th>';
                }
                echo '</tr></thead><tbody>';
                foreach ($tenantUsers as $u) {
                    echo '<tr><td>' . $e(($u['username'] !== '' ? $u['username'] : 'User #' . $u['user_id']))
                       . '<div class="small text-muted">' . $e($u['email']) . '</div></td>'
                       . '<td>' . $e($u['role']) . '</td>';
                    if ($isPlatform) {
                        echo '<td class="text-end"><form method="post" action="'
                           . $e($this->url->to('storefeeder/admin/tenants/remove-user', ['id' => $tid]))
                           . '" class="d-inline" onsubmit="return confirm(\'Remove user from tenant?\');">'
                           . $this->request->csrfField()
                           . '<input type="hidden" name="user_id" value="' . (int) $u['user_id'] . '">'
                           . '<button class="btn btn-sm btn-outline-danger">Remove</button></form></td>';
                    }
                    echo '</tr>';
                }
                if ($tenantUsers === []) {
                    echo '<tr><td colspan="3" class="text-muted">No users assigned.</td></tr>';
                }
                echo '</tbody></table></div></div></div></div>';
            } elseif (!$isPlatform && $memberships === []) {
                echo '<div class="alert alert-warning mb-0">Your account is not assigned to a StoreFeeder tenant. '
                   . 'Ask the platform owner to assign you.</div>';
            }
        });
    }

    public function createAction(): void
    {
        $this->requirePlatform();
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/tenants');
            return;
        }
        try {
            $this->request->checkToken();
            $ownerLogin = trim((string) $this->request->post('owner_login', 'string', ''));
            $owner = $this->resolveUserByLogin($ownerLogin);
            $id = $this->tenants->create([
                'slug' => (string) $this->request->post('slug', 'string', ''),
                'name' => (string) $this->request->post('name', 'string', ''),
            ]);
            $this->tenants->setOwner($id, (int) $owner->id);
            $this->notify->success('Tenant created and owner assigned.');
            redirect_to('storefeeder/admin/tenants', ['id' => $id]);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
            redirect_to('storefeeder/admin/tenants');
        }
    }

    public function setOwnerAction(): void
    {
        $this->requirePlatform();
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/tenants');
            return;
        }
        try {
            $this->request->checkToken();
            $tid = (int) $this->request->get('id', 'int', 0);
            $row = $this->tenants->find($tid);
            if ($row === null) {
                throw new NotFoundException('Tenant not found.');
            }
            if ($this->tenants->isPlatformHome($row)) {
                throw new \RuntimeException('Platform home does not use a client owner.');
            }
            $owner = $this->resolveUserByLogin(
                trim((string) $this->request->post('owner_login', 'string', ''))
            );
            $this->tenants->setOwner($tid, (int) $owner->id);
            $this->notify->success('Tenant owner updated.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('storefeeder/admin/tenants', ['id' => (int) $this->request->get('id', 'int', 0)]);
    }

    public function statusAction(): void
    {
        $this->requirePlatform();
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/tenants');
            return;
        }
        try {
            $this->request->checkToken();
            $id = (int) $this->request->get('id', 'int', 0);
            $status = (string) $this->request->post('status', 'string', 'active');
            $row = $this->tenants->find($id);
            if ($row !== null && $this->tenants->isPlatformHome($row) && $status === 'suspended') {
                throw new \RuntimeException('Your platform home tenant cannot be suspended.');
            }
            $this->tenants->updateStatus($id, $status);
            $this->notify->success('Tenant status updated.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('storefeeder/admin/tenants');
    }

    public function selectAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/tenants');
            return;
        }
        $tid = (int) $this->request->post('tenant_id', 'int', 0);
        try {
            $this->request->checkToken();
            if (!$this->resolver->selectTenantForUser($tid)) {
                throw new \RuntimeException('You cannot access that tenant.');
            }
            $name = TenantContext::get()->name();
            $this->notify->success(
                'Now working in ' . ($name !== '' ? $name : ('tenant #' . $tid))
                . '. Products, channels, and orders are this tenant’s.'
            );
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        $next = trim((string) $this->request->post('next', 'string', ''));
        $next = \Module\StoreFeeder\Services\TenantBar::safeNext($next);
        if ($next === 'storefeeder/admin/tenants') {
            redirect_to('storefeeder/admin/tenants', ['id' => $tid]);
            return;
        }
        redirect_to($next);
    }

    public function createTokenAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/tenants');
            return;
        }
        try {
            $this->request->checkToken();
            $tid = (int) $this->request->get('id', 'int', 0);
            $this->assertCanManageTenant($tid);
            $created = $this->tokens->create(
                $tid,
                (string) $this->request->post('name', 'string', 'API token'),
                ['*'],
            );
            $this->session->set('_sf_new_token', $created['token']);
            $this->notify->success('Token created — copy it now.');
            redirect_to('storefeeder/admin/tenants', ['id' => $tid]);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
            redirect_to('storefeeder/admin/tenants');
        }
    }

    public function revokeTokenAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/tenants');
            return;
        }
        try {
            $this->request->checkToken();
            $tid = (int) $this->request->get('id', 'int', 0);
            $this->assertCanManageTenant($tid);
            $this->tokens->revoke($tid, (int) $this->request->post('token_id', 'int', 0));
            $this->notify->success('Token revoked.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('storefeeder/admin/tenants', ['id' => (int) $this->request->get('id', 'int', 0)]);
    }

    public function addUserAction(): void
    {
        $this->requirePlatform();
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/tenants');
            return;
        }
        try {
            $this->request->checkToken();
            $tid = (int) $this->request->get('id', 'int', 0);
            $login = trim((string) $this->request->post('user_login', 'string', ''));
            if ($login === '') {
                // Backward compatible with numeric user_id posts.
                $uid = (int) $this->request->post('user_id', 'int', 0);
                if ($uid <= 0) {
                    throw new \InvalidArgumentException('User login is required.');
                }
                $user = User::find($uid);
                if ($user === null) {
                    throw new \InvalidArgumentException('User not found.');
                }
            } else {
                $user = $this->resolveUserByLogin($login);
            }
            $role = (string) $this->request->post('role', 'string', 'staff');
            if ($role === 'owner') {
                $this->tenants->setOwner($tid, (int) $user->id);
            } else {
                $this->tenants->addUser($tid, (int) $user->id, $role);
            }
            $addedSelf = (int) $user->id === (int) ($this->auth->id() ?? 0);
            $this->resolver->selectTenantForUser($tid);
            $tenantName = (string) (($this->tenants->find($tid)['name'] ?? '') ?: ('tenant #' . $tid));
            if ($addedSelf) {
                $this->notify->success(
                    'You were added to ' . $tenantName . '. You are now working in that tenant — '
                    . 'open Products or Integrations to see its data.'
                );
            } else {
                $this->notify->success(
                    'User added to ' . $tenantName . '. They will see this tenant when they sign in. '
                    . 'You are now working in ' . $tenantName . ' (top bar).'
                );
            }
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('storefeeder/admin/tenants', ['id' => (int) $this->request->get('id', 'int', 0)]);
    }

    public function removeUserAction(): void
    {
        $this->requirePlatform();
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/tenants');
            return;
        }
        try {
            $this->request->checkToken();
            $tid = (int) $this->request->get('id', 'int', 0);
            $this->tenants->removeUser($tid, (int) $this->request->post('user_id', 'int', 0));
            $this->notify->success('User removed.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('storefeeder/admin/tenants', ['id' => (int) $this->request->get('id', 'int', 0)]);
    }

    private function resolveUserByLogin(string $login): User
    {
        $login = trim($login);
        if ($login === '') {
            throw new \InvalidArgumentException('Owner login (email or username) is required.');
        }
        $user = User::findByLogin($login);
        if ($user === null || $user->id === null) {
            throw new \InvalidArgumentException('No user found for "' . $login . '". Create the user account first.');
        }
        return $user;
    }

    private function requirePlatform(): void
    {
        if (!$this->resolver->isPlatformOwner()) {
            throw new \System\Engine\ForbiddenException('Platform owner required.');
        }
    }

    private function assertCanManageTenant(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new NotFoundException('Tenant not found.');
        }
        if ($this->resolver->isPlatformOwner()) {
            return;
        }
        $memberships = $this->tenants->membershipsForUser((int) ($this->auth->id() ?? 0));
        foreach ($memberships as $m) {
            if ((int) $m['tenant_id'] === $tenantId && in_array($m['role'], ['owner', 'admin'], true)) {
                return;
            }
        }
        throw new \System\Engine\ForbiddenException('Not allowed for this tenant.');
    }

    /** @param list<array<string, mixed>> $memberships */
    private function userInTenant(int $tenantId, array $memberships): bool
    {
        foreach ($memberships as $m) {
            if ((int) $m['tenant_id'] === $tenantId) {
                return true;
            }
        }
        return false;
    }
}
