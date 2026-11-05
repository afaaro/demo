<?php
declare(strict_types=1);

use System\Engine\Controller;
use System\Library\Paginator;
use System\Services\PermissionCatalog;
use System\Services\User;
use System\Services\UserAuthAudit;
use System\Services\UserGroup;
use System\Services\UserMailService;
use System\Services\UserSessionService;

class UserAdminUsers extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            $this->session->set('redirect_after_login', $this->url->to('user/admin/users'));
            redirect_to('user/login');
            return;
        }
        // Permissions: Action.php only (user.admin.users.*).
    }

    public function indexAction(): void
    {
        $page    = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage = 20;

        $total = (int) $this->db->query("SELECT COUNT(*) FROM `#__users`")->value;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $users = $this->db->query(
            "SELECT u.*,
                    GROUP_CONCAT(g.name SEPARATOR ', ') AS `groups`
               FROM `#__users` u
               LEFT JOIN `#__user_group_members` m ON u.id = m.user_id
               LEFT JOIN `#__user_groups` g ON m.group_id = g.id
              GROUP BY u.id
              ORDER BY u.created_at DESC
              LIMIT {$perPage} OFFSET {$offset}"
        )->rows;

        $this->renderList($users, $page, $perPage, $total, $totalPages, '');
    }

    public function searchAction(): void
    {
        $q = trim((string) $this->request->get('q', 'string', ''));

        if (strlen($q) < 2) {
            redirect_to('user/admin/users');
            return;
        }

        $users = $this->db->query(
            "SELECT u.*,
                    GROUP_CONCAT(g.name SEPARATOR ', ') AS `groups`
               FROM `#__users` u
               LEFT JOIN `#__user_group_members` m ON u.id = m.user_id
               LEFT JOIN `#__user_groups` g ON m.group_id = g.id
              WHERE u.username LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?
              GROUP BY u.id
              ORDER BY u.created_at DESC
              LIMIT 50",
            ["%{$q}%", "%{$q}%", "%{$q}%"]
        )->rows;

        $this->renderList($users, 1, 50, count($users), 1, $q);
    }

    /**
     * Shared renderer for index + search.
     *
     * @param array<int, array<string, mixed>> $users
     */
    private function renderList(
        array $users,
        int $page,
        int $perPage,
        int $total,
        int $totalPages,
        string $q
    ): void {
        $sessions = new UserSessionService($this->db, $this->session);
        $sessions->prune();
        $onlineMap = $sessions->onlineUserIdMap();
        $onlineUsers = $sessions->onlineUsers();

        echo $this->view->inline(function () use (
            $users, $page, $perPage, $total, $totalPages, $q, $onlineMap, $onlineUsers
        ) {
            $this->view->assign('title', $q !== '' ? "Search: {$q}" : 'User Management');
            $this->view->block('right_sidebar', 'Users');

            // ---- Header -----------------------------------------------------
            $pendingCount = (int) $this->db->query(
                "SELECT COUNT(*) FROM `#__users` WHERE `status` = 'pending'"
            )->value;
            $onlineCount = count($onlineUsers);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">' . ($q !== '' ? 'Search: ' . $this->view->e($q) : 'Users') . '</h1>';
            echo '<div class="d-flex flex-wrap gap-2 align-items-center">';
            echo '<span class="badge text-bg-' . ($onlineCount > 0 ? 'success' : 'secondary') . '">'
               . '<i class="bi bi-circle-fill me-1" style="font-size:.55rem"></i>'
               . $onlineCount . ' online</span>';
            echo '<a href="' . $this->view->e($this->url->to('user/admin/users/pending')) . '"'
               . ' class="btn btn-outline-warning">'
               . '<i class="bi bi-hourglass-split me-1"></i>Pending'
               . ($pendingCount > 0 ? ' <span class="badge text-bg-warning">' . $pendingCount . '</span>' : '')
               . '</a>';
            echo '<a href="' . $this->view->e($this->url->to('user/admin/users/add')) . '"'
               . ' class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add user</a>';
            echo '</div></div>';

            if ($onlineUsers !== []) {
                echo '<div class="alert alert-success py-2 small mb-3">'
                   . '<strong>Online now</strong> (active in last '
                   . (int) (UserSessionService::ONLINE_WINDOW / 60) . ' min): ';
                $bits = [];
                foreach ($onlineUsers as $ou) {
                    $label = (string) $ou['username'];
                    if ($ou['display_name'] !== '') {
                        $label .= ' (' . $ou['display_name'] . ')';
                    }
                    $bits[] = $this->view->e($label);
                }
                echo implode(', ', $bits) . '</div>';
            }

            // ---- Search -----------------------------------------------------
            echo '<form method="get"'
               . ' action="' . $this->view->e($this->url->to('user/admin/users/search')) . '"'
               . ' class="mb-3">';
            echo '<div class="input-group">';
            echo '<input type="search" name="q" class="form-control"'
               . ' placeholder="Search username, email or name…"'
               . ' value="' . $this->view->e($q) . '">';
            echo '<button type="submit" class="btn btn-outline-secondary">Search</button>';
            if ($q !== '') {
                echo '<a class="btn btn-outline-secondary" href="'
                   . $this->view->e($this->url->to('user/admin/users')) . '">Clear</a>';
            }
            echo '</div>';
            echo '</form>';

            // ---- Empty ------------------------------------------------------
            if (empty($users)) {
                echo '<div class="alert alert-info">'
                   . ($q !== '' ? 'No users matched your search.' : 'No users yet.')
                   . '</div>';
                return;
            }

            // ---- Table ------------------------------------------------------
            echo '<div class="table-responsive"><table class="table table-striped align-middle">';
            echo '<thead><tr>'
               . '<th>Username</th><th>Email</th><th>Groups</th>'
               . '<th class="text-center">Online</th>'
               . '<th class="text-center">Status</th>'
               . '<th>Last seen</th><th>Created</th><th></th>'
               . '</tr></thead><tbody>';

            foreach ($users as $u) {
                $uid    = (int) $u['id'];
                $status = (string) ($u['status'] ?? 'active');
                $isOnline = isset($onlineMap[$uid])
                    || UserSessionService::isOnlineTimestamp(
                        isset($u['last_seen_at']) ? (string) $u['last_seen_at'] : null
                    );
                $lastSeen = (string) ($u['last_seen_at'] ?? $u['last_login_at'] ?? '');

                echo '<tr>';
                echo '<td>' . $this->view->e((string) $u['username']) . '<br>'
                   . '<span class="text-muted small">'
                   . $this->view->e((string) ($u['display_name'] ?? '')) . '</span></td>';
                echo '<td>' . $this->view->e((string) $u['email']) . '</td>';
                echo '<td class="text-muted small">'
                   . $this->view->e((string) ($u['groups'] ?? '—')) . '</td>';

                echo '<td class="text-center">';
                if ($isOnline) {
                    echo '<span class="badge text-bg-success" title="Active session">'
                       . '<i class="bi bi-circle-fill me-1" style="font-size:.55rem"></i>Online</span>';
                } else {
                    echo '<span class="text-muted small">Offline</span>';
                }
                echo '</td>';

                echo '<td class="text-center">';
                echo match ($status) {
                    'active'    => '<span class="badge text-bg-success">Active</span>',
                    'pending'   => '<span class="badge text-bg-warning">Pending</span>',
                    'suspended' => '<span class="badge text-bg-danger">Suspended</span>',
                    default     => '<span class="badge text-bg-secondary">'
                                 . $this->view->e($status) . '</span>',
                };
                echo '</td>';

                echo '<td class="text-muted small">'
                   . $this->view->e($lastSeen !== '' ? $lastSeen : '—') . '</td>';
                echo '<td class="text-muted small">'
                   . $this->view->e((string) ($u['created_at'] ?? '')) . '</td>';

                echo '<td class="text-end text-nowrap">';
                if ($status === 'pending') {
                    echo '<form method="post" action="'
                       . $this->view->e($this->url->to('user/admin/users/approve'))
                       . '" class="d-inline">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="id" value="' . $uid . '">';
                    echo '<button type="submit" class="btn btn-sm btn-success">Approve</button></form> ';
                    echo '<form method="post" action="'
                       . $this->view->e($this->url->to('user/admin/users/reject'))
                       . '" class="d-inline" onsubmit="return confirm(\'Reject and delete this registration?\');">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="id" value="' . $uid . '">';
                    echo '<button type="submit" class="btn btn-sm btn-outline-danger">Reject</button></form> ';
                }
                echo '<a class="btn btn-sm btn-outline-secondary" href="'
                   . $this->view->e($this->url->to('user/admin/users/edit', ['id' => $uid])) . '">'
                   . 'Edit</a> ';
                echo '<form method="post"'
                   . ' action="' . $this->view->e($this->url->to('user/admin/users/delete')) . '"'
                   . ' class="d-inline"'
                   . ' onsubmit="return confirm(\'Delete this user?\');">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="id" value="' . $uid . '">';
                echo '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
                echo '</form>';
                echo '</td></tr>';
            }

            echo '</tbody></table></div>';

            if ($q === '') {
                echo Paginator::make($total, $page, $perPage)->render(
                    fn (int $p) => $this->url->to('user/admin/users', ['page' => $p]),
                    ['theme' => 'bootstrap']
                );
            }
        });
    }

    // =========================================================================
    // Add
    // =========================================================================

    public function addAction(): void
    {
        $groups = $this->db->query(
            "SELECT * FROM `#__user_groups` ORDER BY name"
        )->rows;

        $old = [
            'username'       => '',
            'email'          => '',
            'display_name'   => '',
            'status'         => 'active',
            'email_verified' => 0,
            'groups'         => [],
        ];

        if ($this->request->isPost()) {
            $old['username']       = trim((string) $this->request->post('username',       'string', ''));
            $old['email']          = trim((string) $this->request->post('email',          'string', ''));
            $old['display_name']   = trim((string) $this->request->post('display_name',   'string', ''));
            $old['status']         = (string) $this->request->post('status',              'string', 'active');
            $old['email_verified'] = (int)    $this->request->post('email_verified',      'int',    0);
            $old['groups']         = (array)  $this->request->post('groups',              'raw',    []);

            $password     = (string) $this->request->post('password',                     'raw', '');
            $passwordConf = (string) $this->request->post('password_confirm',             'raw', '');

            $errors = [];

            if (strlen($old['username']) < 3) {
                $errors[] = 'Username must be at least 3 characters.';
            }
            if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email address.';
            }
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }
            if ($password !== $passwordConf) {
                $errors[] = 'Passwords do not match.';
            }

            if ($errors === []) {
                $exists = $this->db->query(
                    "SELECT id FROM `#__users` WHERE username = ? OR email = ? LIMIT 1",
                    [$old['username'], $old['email']]
                )->row;

                if ($exists !== []) {
                    $errors[] = 'Username or email already in use.';
                }
            }

            if ($errors === []) {
                $now = date('Y-m-d H:i:s');
                $hash = password_hash($password, PASSWORD_DEFAULT);

                if ($hash === false) {
                    $errors[] = 'Failed to hash password.';
                } else {
                    $this->db->transaction(function ($db) use ($old, $hash, $now) {
                        $email = strtolower($old['email']);
                        $userId = $db->insert('users', [
                            'username'         => strtolower($old['username']),
                            'email'            => $email,
                            'email_canonical'  => \System\Services\User::canonicalEmail($email),
                            'display_name'     => $old['display_name'],
                            'password_hash'    => $hash,
                            'status'           => $old['status'],
                            'email_verified'   => $old['email_verified'] ? 1 : 0,
                            'created_at'       => $now,
                            'updated_at'       => $now,
                        ]);

                        foreach (array_unique(array_map('intval', $old['groups'])) as $gid) {
                            if ($gid > 0) {
                                $db->insert('user_group_members', [
                                    'user_id'  => $userId,
                                    'group_id' => $gid,
                                ]);
                            }
                        }
                    });

                    $this->notify->success('User created successfully.');
                    redirect_to('user/admin/users');
                    return;
                }
            }

            foreach ($errors as $msg) {
                $this->notify->error($msg);
            }
        }

        echo $this->view->inline(function () use ($groups, $old) {
            $this->view->assign('title', 'Add User');
            $this->view->block('right_sidebar', 'Users');

            $action = $this->url->to('user/admin/users/add');

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">Add User</h1>';
            echo '<a href="' . $this->view->e($this->url->to('user/admin/users')) . '"'
               . ' class="btn btn-outline-secondary">'
               . '<i class="bi bi-arrow-left me-1"></i>Back</a>';
            echo '</div>';

            echo '<form method="post" action="' . $this->view->e($action) . '">';
            echo $this->request->csrfField();

            echo '<div class="row g-3">';
            echo '<div class="col-md-6">';
            echo $this->form->field([
                'name'     => 'username',
                'label'    => 'Username',
                'type'     => 'text',
                'value'    => (string) $old['username'],
                'required' => true,
                'help'     => '3-64 characters: a-z, 0-9, underscore.',
            ]);
            echo '</div>';
            echo '<div class="col-md-6">';
            echo $this->form->field([
                'name'     => 'email',
                'label'    => 'Email',
                'type'     => 'email',
                'value'    => (string) $old['email'],
                'required' => true,
            ]);
            echo '</div>';
            echo '</div>';

            echo $this->form->field([
                'name'  => 'display_name',
                'label' => 'Display name',
                'type'  => 'text',
                'value' => (string) $old['display_name'],
            ]);

            echo '<div class="row g-3">';
            echo '<div class="col-md-6">';
            echo $this->form->field([
                'name'         => 'password',
                'label'        => 'Password',
                'type'         => 'password',
                'autocomplete' => 'new-password',
                'required'     => true,
                'help'         => 'Minimum 8 characters.',
            ]);
            echo '</div>';
            echo '<div class="col-md-6">';
            echo $this->form->field([
                'name'         => 'password_confirm',
                'label'        => 'Confirm password',
                'type'         => 'password',
                'autocomplete' => 'new-password',
                'required'     => true,
            ]);
            echo '</div>';
            echo '</div>';

            echo '<div class="row g-3">';
            echo '<div class="col-md-6">';
            echo '<label class="form-label">Status</label>';
            echo '<select name="status" class="form-select">';
            foreach (['active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended'] as $v => $l) {
                $sel = (string) $old['status'] === $v ? ' selected' : '';
                echo '<option value="' . $this->view->e($v) . '"' . $sel . '>'
                   . $this->view->e($l) . '</option>';
            }
            echo '</select>';
            echo '</div>';
            echo '<div class="col-md-6 d-flex align-items-end">';
            echo '<div class="form-check">';
            echo '<input type="checkbox" class="form-check-input" id="email_verified"'
               . ' name="email_verified" value="1"'
               . (!empty($old['email_verified']) ? ' checked' : '') . '>';
            echo '<label class="form-check-label" for="email_verified">Email verified</label>';
            echo '</div>';
            echo '</div>';
            echo '</div>';

            echo '<hr class="my-4">';
            echo '<h2 class="h6 mb-3">Groups</h2>';

            if (empty($groups)) {
                echo '<p class="text-muted">No groups available.</p>';
            } else {
                $selected = array_map('intval', (array) $old['groups']);

                echo '<div class="row g-2">';
                foreach ($groups as $g) {
                    $gid = (int) $g['id'];
                    $is  = in_array($gid, $selected, true);

                    echo '<div class="col-md-4">';
                    echo '<div class="form-check">';
                    echo '<input type="checkbox" class="form-check-input"'
                       . ' id="group-' . $gid . '"'
                       . ' name="groups[]"'
                       . ' value="' . $gid . '"'
                       . ($is ? ' checked' : '') . '>';
                    echo '<label class="form-check-label" for="group-' . $gid . '">'
                       . $this->view->e((string) $g['name']) . '</label>';
                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';
            }

            echo '<hr class="my-4">';
            echo '<button type="submit" class="btn btn-primary">Create user</button> ';
            echo '<a href="' . $this->view->e($this->url->to('user/admin/users')) . '"'
               . ' class="btn btn-outline-secondary">Cancel</a>';

            echo '</form>';
        });
    }

    // =========================================================================
    // Edit — unchanged from your version, except it stays inline
    // =========================================================================

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);

        $user = $this->db->query(
            "SELECT * FROM `#__users` WHERE `id` = ? LIMIT 1",
            [$id]
        )->row;

        if ($user === []) {
            $this->notify->error('User not found.');
            redirect_to('user/admin/users');
            return;
        }

        // Read-only: pull groups once. On POST we may reload below.
        $groups = $this->db->query(
            "SELECT g.*,
                    CASE WHEN m.group_id IS NOT NULL THEN 1 ELSE 0 END AS selected
            FROM `#__user_groups` g
            LEFT JOIN `#__user_group_members` m
                    ON g.id = m.group_id AND m.user_id = ?
            ORDER BY g.name",
            [$id]
        )->rows;

        if ($this->request->isPost()) {
            $displayName  = trim((string) $this->request->post('display_name',    'string', ''));
            $email        = trim((string) $this->request->post('email',           'string', ''));
            $password     = (string) $this->request->post('password',             'raw',    '');
            $passwordConf = (string) $this->request->post('password_confirm',     'raw',    '');
            $groupIds     = (array)  $this->request->post('groups',               'raw',    []);

            $errors = [];

            if ($this->request->verifyToken() === false) $errors[] = 'Invalid token.';
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email address.';
            }

            $changePassword = ($password !== '' || $passwordConf !== '');
            if ($changePassword) {
                if (strlen($password) < 8) {
                    $errors[] = 'New password must be at least 8 characters.';
                }
                if ($password !== $passwordConf) {
                    $errors[] = 'Password confirmation does not match.';
                }
            }

            $selectedInts = array_map('intval', $groupIds);

            $permsInput = (array) $this->request->post('direct_permissions', 'raw', []);
            $manual = (string) $this->request->post('direct_permissions_manual', 'raw', '');
            $fromTextarea = preg_split('/[\s,]+/', $manual, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $permsInput = array_merge($permsInput, $fromTextarea);
            try {
                $directPermissions = UserGroup::normalisePermissions(
                    array_map('strval', $permsInput)
                );
            } catch (\InvalidArgumentException $e) {
                $directPermissions = [];
                $errors[] = $e->getMessage();
            }

            if ($id === $this->auth->id()) {
                $adminIds = UserGroup::idsWithLevel(User::USER_LEVEL_ADMIN);
                $superIds = UserGroup::idsWithLevel(User::USER_LEVEL_SUPER_ADMIN);
                $keepsAdmin = $adminIds !== [] && array_intersect($adminIds, $selectedInts) !== [];
                $keepsSuper = $superIds !== [] && array_intersect($superIds, $selectedInts) !== [];

                if ($this->auth->userLevel() <= User::USER_LEVEL_ADMIN && !$keepsAdmin && !$keepsSuper) {
                    $errors[] = 'You cannot remove your own administrator level.';
                }
                if ($this->auth->isSuperAdmin() && !$keepsSuper
                    && UserGroup::countUsersWithLevel(User::USER_LEVEL_SUPER_ADMIN, $id) <= 0) {
                    $errors[] = 'You cannot remove the last Super Administrator.';
                }
            }

            if ($errors === []) {
                $emailNorm = strtolower($email);
                $update = [
                    'display_name'    => $displayName,
                    'email'           => $emailNorm,
                    'email_canonical' => \System\Services\User::canonicalEmail($emailNorm),
                    'updated_at'      => date('Y-m-d H:i:s'),
                ];

                if ($changePassword) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($hash === false) {
                        $this->notify->error('Failed to hash password.');
                        // fall through to re-render
                    } else {
                        $update['password_hash'] = $hash;
                    }
                }

                $update['direct_permissions'] = json_encode($directPermissions, JSON_UNESCAPED_SLASHES);

                $this->db->transaction(function ($db) use ($id, $update, $groupIds) {
                    $db->update('users', $update, ['id' => $id]);

                    $db->query('DELETE FROM `#__user_group_members` WHERE `user_id` = ?', [$id]);

                    foreach (array_unique(array_map('intval', $groupIds)) as $groupId) {
                        if ($groupId > 0) {
                            $db->query(
                                'INSERT INTO `#__user_group_members` (`user_id`, `group_id`) VALUES (?, ?)',
                                [$id, $groupId]
                            );
                        }
                    }
                });

                User::bumpAuthRevision($id);
                (new UserAuthAudit($this->db))->log(
                    'user.groups_updated',
                    'Updated groups and direct permissions',
                    $this->auth->id(),
                    $id,
                    null,
                    null,
                    ['groups' => array_values(array_unique(array_map('intval', $groupIds))), 'direct' => $directPermissions],
                );

                $this->notify->success('User updated successfully.');
                redirect_to('user/admin/users/edit', ['id' => $id]);
                return;
            }

            foreach ($errors as $msg) {
                $this->notify->error($msg);
            }

            // Refresh $user and $groups so the form shows the latest state.
            $user = $this->db->query(
                "SELECT * FROM `#__users` WHERE `id` = ? LIMIT 1",
                [$id]
            )->row;

            $groups = $this->db->query(
                "SELECT g.*,
                        CASE WHEN m.group_id IS NOT NULL THEN 1 ELSE 0 END AS selected
                FROM `#__user_groups` g
                LEFT JOIN `#__user_group_members` m
                        ON g.id = m.group_id AND m.user_id = ?
                ORDER BY g.name",
                [$id]
            )->rows;
        }

        $directCurrent = [];
        if (!empty($user['direct_permissions'])) {
            $decoded = json_decode((string) $user['direct_permissions'], true);
            if (is_array($decoded)) {
                $directCurrent = array_values(array_filter($decoded, 'is_string'));
            }
        }
        $catalog = PermissionCatalog::scan();
        $loaded = User::find($id);
        $effective = $loaded !== null ? $loaded->permissions : [];
        $auditRows = (new UserAuthAudit($this->db))->forUser($id, 15);

        echo $this->view->inline(function () use ($user, $groups, $id, $directCurrent, $catalog, $effective, $auditRows) {
            $this->view->assign('title', 'Edit User: ' . $user['username']);
            $this->view->block('right_sidebar', 'Users');

            $action = $this->url->to('user/admin/users/edit', ['id' => $id]);

            // ---- Header -----------------------------------------------------
            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">Edit User: ' . $this->view->e($user['username']) . '</h1>';
            echo '<a href="' . $this->view->e($this->url->to('user/admin/users')) . '"'
            . ' class="btn btn-outline-secondary">'
            . '<i class="bi bi-arrow-left me-1"></i>Back</a>';
            echo '</div>';

            // ---- Form -------------------------------------------------------
            echo '<form method="post" action="' . $this->view->e($action) . '">';
            echo $this->request->csrfField();

            // Display name + email row
            echo '<div class="row g-3">';
            echo '<div class="col-md-6">';
            echo $this->form->field([
                'name'  => 'display_name',
                'label' => 'Display name',
                'type'  => 'text',
                'value' => (string) ($user['display_name'] ?? ''),
            ]);
            echo '</div>';
            echo '<div class="col-md-6">';
            echo $this->form->field([
                'name'     => 'email',
                'label'    => 'Email',
                'type'     => 'email',
                'value'    => (string) ($user['email'] ?? ''),
                'required' => true,
            ]);
            echo '</div>';
            echo '</div>';

            // ---- Password section -------------------------------------------
            echo '<hr class="my-4">';
            echo '<h2 class="h6 mb-3">Password</h2>';
            echo '<p class="text-muted small">Leave both fields blank to keep the current password.</p>';

            echo '<div class="row g-3">';
            echo '<div class="col-md-6">';
            echo $this->form->field([
                'name'         => 'password',
                'label'        => 'New password',
                'type'         => 'password',
                'autocomplete' => 'new-password',
                'help'         => 'Minimum 8 characters.',
            ]);
            echo '</div>';
            echo '<div class="col-md-6">';
            echo $this->form->field([
                'name'         => 'password_confirm',
                'label'        => 'Confirm new password',
                'type'         => 'password',
                'autocomplete' => 'new-password',
            ]);
            echo '</div>';
            echo '</div>';

            // ---- Groups -----------------------------------------------------
            echo '<hr class="my-4">';
            echo '<h2 class="h6 mb-3">Groups</h2>';

            if (empty($groups)) {
                echo '<p class="text-muted">No groups available.</p>';
            } else {
                echo '<div class="row g-2">';
                foreach ($groups as $g) {
                    $gid      = (int) $g['id'];
                    $selected = !empty($g['selected']);

                    echo '<div class="col-md-4">';
                    echo '<div class="form-check">';
                    echo '<input type="checkbox"'
                    . ' class="form-check-input"'
                    . ' id="group-' . $gid . '"'
                    . ' name="groups[]"'
                    . ' value="' . $gid . '"'
                    . ($selected ? ' checked' : '')
                    . '>';
                    echo '<label class="form-check-label" for="group-' . $gid . '">'
                    . $this->view->e((string) $g['name'])
                    . '</label>';
                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';
            }

            echo '<hr class="my-4">';
            echo '<h2 class="h6 mb-3">Direct permissions</h2>';
            echo '<p class="text-muted small">Added on top of group permissions. Does not make the user a Super Administrator.</p>';
            $manualDirect = array_values(array_filter(
                $directCurrent,
                static fn(string $p): bool => !isset($catalog[$p])
            ));
            echo '<textarea name="direct_permissions_manual" class="form-control form-control-sm mb-3" rows="3" placeholder="news.view&#10;forum.moderate">'
               . $this->view->e(implode("\n", $manualDirect))
               . '</textarea>';

            $grouped = [];
            foreach ($catalog as $perm => $label) {
                $module = explode('.', $perm, 2)[0];
                $grouped[$module][$perm] = $label;
            }
            echo '<div class="accordion mb-3" id="direct-perm-acc">';
            $i = 0;
            foreach ($grouped as $module => $perms) {
                $i++;
                echo '<div class="accordion-item">';
                echo '<h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#dp-' . $i . '">'
                   . $this->view->e((string) $module) . '</button></h3>';
                echo '<div id="dp-' . $i . '" class="accordion-collapse collapse" data-bs-parent="#direct-perm-acc"><div class="accordion-body"><div class="row g-1">';
                foreach ($perms as $perm => $label) {
                    $checked = in_array($perm, $directCurrent, true);
                    echo '<div class="col-md-6 col-lg-4"><div class="form-check">';
                    echo '<input class="form-check-input" type="checkbox" name="direct_permissions[]" value="'
                       . $this->view->e($perm) . '" id="dp-' . md5($perm) . '"'
                       . ($checked ? ' checked' : '') . '>';
                    echo '<label class="form-check-label small" for="dp-' . md5($perm) . '">'
                       . $this->view->e($perm) . '</label></div></div>';
                }
                echo '</div></div></div></div>';
            }
            echo '</div>';

            if ($effective !== []) {
                echo '<p class="small text-muted mb-1">Effective (groups + direct)</p>';
                echo '<p class="small"><code>' . $this->view->e(implode(', ', array_slice($effective, 0, 40)))
                   . (count($effective) > 40 ? ' …' : '') . '</code></p>';
            }

            if ($auditRows !== []) {
                echo '<h2 class="h6 mt-4">Recent changes</h2>';
                echo '<ul class="list-group mb-3">';
                foreach ($auditRows as $row) {
                    echo '<li class="list-group-item small">'
                       . $this->view->e((string) ($row['created_at'] ?? ''))
                       . ' — ' . $this->view->e((string) ($row['summary'] ?? ''))
                       . '</li>';
                }
                echo '</ul>';
            }

            // ---- Actions ----------------------------------------------------
            echo '<hr class="my-4">';
            echo '<button type="submit" class="btn btn-primary">Save changes</button> ';
            echo '<a href="' . $this->view->e($this->url->to('user/admin/users')) . '"'
            . ' class="btn btn-outline-secondary">Cancel</a>';

            echo '</form>';
        });
    }

    // =========================================================================
    // Pending approvals
    // =========================================================================

    public function pendingAction(): void
    {
        $users = $this->db->query(
            "SELECT u.*,
                    GROUP_CONCAT(g.name SEPARATOR ', ') AS `groups`
               FROM `#__users` u
               LEFT JOIN `#__user_group_members` m ON u.id = m.user_id
               LEFT JOIN `#__user_groups` g ON m.group_id = g.id
              WHERE u.`status` = 'pending'
              GROUP BY u.id
              ORDER BY u.created_at ASC"
        )->rows;

        echo $this->view->inline(function () use ($users) {
            $this->view->assign('title', 'Pending registrations');
            $this->view->block('right_sidebar', 'Users');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">Pending registrations</h1>';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('user/admin/users')) . '">All users</a>';
            echo '</div>';

            if ($users === []) {
                echo '<div class="alert alert-success">No pending registrations.</div>';
                return;
            }

            $bulkUrl = $e($this->url->to('user/admin/users/bulk-approve'));
            $approveUrl = $e($this->url->to('user/admin/users/approve'));
            $rejectUrl = $e($this->url->to('user/admin/users/reject'));

            echo '<form method="post" action="' . $bulkUrl . '" id="pending-bulk">';
            echo $this->request->csrfField();

            echo '<div class="d-flex flex-wrap gap-2 mb-3">';
            echo '<button type="submit" class="btn btn-success btn-sm"'
               . ' onclick="return confirm(\'Approve selected accounts?\');">Approve selected</button>';
            echo '<span class="small text-muted align-self-center">' . count($users) . ' waiting</span>';
            echo '</div>';

            echo '<div class="table-responsive"><table class="table table-striped align-middle">';
            echo '<thead><tr>'
               . '<th style="width:2rem"><input type="checkbox" class="form-check-input" id="pending-all"></th>'
               . '<th>Username</th><th>Email</th><th>Created</th><th></th>'
               . '</tr></thead><tbody>';

            foreach ($users as $u) {
                $uid = (int) $u['id'];
                echo '<tr>';
                echo '<td><input type="checkbox" class="form-check-input pending-row" name="ids[]" value="'
                   . $uid . '"></td>';
                echo '<td>' . $e((string) $u['username']) . '<br><span class="text-muted small">'
                   . $e((string) ($u['display_name'] ?? '')) . '</span></td>';
                echo '<td>' . $e((string) $u['email']) . '</td>';
                echo '<td class="text-muted small">' . $e((string) ($u['created_at'] ?? '')) . '</td>';
                echo '<td class="text-end text-nowrap">';
                echo '<button type="submit" class="btn btn-sm btn-success" name="id" value="' . $uid . '"'
                   . ' formaction="' . $approveUrl . '">Approve</button> ';
                echo '<button type="submit" class="btn btn-sm btn-outline-danger" name="id" value="' . $uid . '"'
                   . ' formaction="' . $rejectUrl . '"'
                   . ' onclick="return confirm(\'Reject and delete this registration?\');">Reject</button> ';
                echo '<a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($this->url->to('user/admin/users/edit', ['id' => $uid])) . '">Edit</a>';
                echo '</td></tr>';
            }

            echo '</tbody></table></div></form>';

            echo <<<'JS'
<script>
(function () {
  const all = document.getElementById('pending-all');
  if (!all) return;
  all.addEventListener('change', function () {
    document.querySelectorAll('.pending-row').forEach(function (cb) {
      cb.checked = all.checked;
    });
  });
})();
</script>
JS;
        });
    }

    public function approveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('user/admin/users/pending');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('user/admin/users/pending');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $n = $this->approveIds([$id]);
        $this->notify->success($n > 0 ? 'User approved and notified.' : 'User not found or not pending.');
        redirect_to('user/admin/users/pending');
    }

    public function bulkApproveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('user/admin/users/pending');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('user/admin/users/pending');
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->request->post('ids', 'raw', [])),
            static fn(int $id) => $id > 0
        )));
        if ($ids === []) {
            $this->notify->error('Select at least one pending user.');
            redirect_to('user/admin/users/pending');
            return;
        }

        $n = $this->approveIds($ids);
        $this->notify->success("Approved {$n} user(s).");
        redirect_to('user/admin/users/pending');
    }

    public function rejectAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('user/admin/users/pending');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('user/admin/users/pending');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        if ($id <= 0 || $id === $this->auth->id()) {
            $this->notify->error('Cannot reject this user.');
            redirect_to('user/admin/users/pending');
            return;
        }

        $row = $this->db->query(
            "SELECT `id`, `email`, `display_name`, `username`, `status`
               FROM `#__users` WHERE `id` = ? LIMIT 1",
            [$id]
        )->row;

        if ($row === [] || (string) ($row['status'] ?? '') !== 'pending') {
            $this->notify->error('User not found or not pending.');
            redirect_to('user/admin/users/pending');
            return;
        }

        $mail = new UserMailService($this->mail, $this->url);
        $mail->sendRejected(
            (string) $row['email'],
            (string) (($row['display_name'] ?? '') ?: $row['username'])
        );

        $this->db->query('DELETE FROM `#__users` WHERE `id` = ? AND `status` = ?', [$id, 'pending']);
        $this->notify->success('Registration rejected and removed.');
        redirect_to('user/admin/users/pending');
    }

    /** @param list<int> $ids */
    private function approveIds(array $ids): int
    {
        $mail = new UserMailService($this->mail, $this->url);
        $n = 0;
        foreach ($ids as $id) {
            if ($id <= 0) {
                continue;
            }
            $row = $this->db->query(
                "SELECT `id` FROM `#__users` WHERE `id` = ? AND `status` = 'pending' LIMIT 1",
                [$id]
            )->row;
            if ($row === []) {
                continue;
            }
            $this->db->update('users', [
                'status'         => 'active',
                'email_verified' => 1,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
            $user = User::find($id);
            if ($user !== null) {
                $mail->sendApproved($user);
            }
            $n++;
        }
        return $n;
    }

    // =========================================================================
    // Status + delete
    // =========================================================================

    public function updateStatusAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('user/admin/users');
            return;
        }

        $id     = (int) $this->request->post('id', 'int', 0);
        $status = (string) $this->request->post('status', 'string', '');

        if (!in_array($status, ['active', 'pending', 'suspended'], true)) {
            $this->notify->error('Invalid status.');
        } else {
            $this->db->update('users', [
                'status'     => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
            $this->notify->success('Status updated.');
        }

        redirect_to('user/admin/users');
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('user/admin/users');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);

        if ($id === $this->auth->id()) {
            $this->notify->error('You cannot delete your own account.');
            redirect_to('user/admin/users');
            return;
        }

        $this->db->transaction(function ($db) use ($id) {
            try {
                $db->query('DELETE FROM `#__user_sessions` WHERE `user_id` = ?', [$id]);
            } catch (\Throwable) {
                // table may not exist yet
            }
            $db->query('DELETE FROM `#__user_group_members` WHERE `user_id` = ?', [$id]);
            $db->query('DELETE FROM `#__users`             WHERE `id`      = ?', [$id]);
        });

        $this->notify->success('User deleted.');
        redirect_to('user/admin/users');
    }
}