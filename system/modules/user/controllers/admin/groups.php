<?php
declare(strict_types=1);

use System\Engine\Controller;
use System\Library\Dataset;
use System\Services\PermissionCatalog;
use System\Services\User;
use System\Services\UserAuthAudit;
use System\Services\UserGroup;

class UserAdminGroups extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            $this->session->set('redirect_after_login', 'user/admin/groups');
            redirect_to('user/login', ['next' => 'user/admin/groups']);
        }
        // Permissions: Action.php only (user.admin.groups.*).
    }

    // =========================================================================
    // List groups
    // =========================================================================

    public function indexAction(): void
    {
        $dataset = (new Dataset($this->registry))
            ->perPage(50)
            ->searchPlaceholder('Search name, label or description…')
            ->sortable(['name', 'label', 'member_count'])
            ->defaultSort('name', 'asc')
            ->columns([
                'name'          => 'Name',
                'label'         => 'Label',
                'description'   => 'Description',
                'member_count'  => 'Members',
                'actions'       => '',
            ])
            ->query(function ($db, array $state): array {
                [$whereSql, $params] = $this->groupsListWhere($state);
                $sortCol = $this->groupsListSortColumn($state['sort']);
                $dir = $state['dir'] === 'asc' ? 'ASC' : 'DESC';
                $orderExpr = $sortCol === 'member_count'
                    ? 'member_count'
                    : "g.`{$sortCol}`";

                $total = (int) ($db->query(
                    "SELECT COUNT(*) AS total FROM `#__user_groups` g{$whereSql}",
                    $params
                )->row['total'] ?? 0);

                $listParams = $params;
                $listParams[] = (int) $state['offset'];
                $listParams[] = (int) $state['per_page'];

                $rows = $db->query(
                    "SELECT g.*, COUNT(DISTINCT m.user_id) AS member_count
                       FROM `#__user_groups` g
                       LEFT JOIN `#__user_group_members` m ON m.group_id = g.id
                      {$whereSql}
                      GROUP BY g.id
                      ORDER BY {$orderExpr} {$dir}
                      LIMIT ?, ?",
                    $listParams
                )->rows;

                return [
                    'rows'  => is_array($rows) ? $rows : [],
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $value): string => $this->view->e((string) $value);

        $dataset->columns([
            'name' => function (array $g) use ($e): string {
                $html = '<code>' . $e((string) $g['name']) . '</code>';
                if (!empty($g['is_system'])) {
                    $html .= ' <span class="badge text-bg-primary">System</span>';
                }
                return $html;
            },
            'label' => fn(array $g): string => $e((string) ($g['label'] ?? '')),
            'description' => function (array $g) use ($e): string {
                return '<span class="text-muted small">' . $e((string) ($g['description'] ?? '')) . '</span>';
            },
            'member_count' => function (array $g): string {
                return '<span class="text-end d-block">' . (int) ($g['member_count'] ?? 0) . '</span>';
            },
            'actions' => function (array $g) use ($e): string {
                $id = (int) $g['id'];
                $isSystem = !empty($g['is_system']);
                $html = '<div class="text-end text-nowrap">';
                $html .= '<a href="' . $e($this->url->to('user/admin/groups/edit', ['id' => $id])) . '"'
                    . ' class="btn btn-sm btn-outline-secondary">Edit</a> ';
                $html .= '<form method="post" action="' . $e($this->url->to('user/admin/groups/clone')) . '"'
                    . ' class="d-inline"'
                    . ' onsubmit="return confirm(\'Clone this group (permissions only, no members)?\');">';
                $html .= $this->request->csrfField();
                $html .= '<input type="hidden" name="id" value="' . $id . '">';
                $html .= '<button type="submit" class="btn btn-sm btn-outline-primary">Clone</button>';
                $html .= '</form>';
                if (!$isSystem) {
                    $html .= ' <form method="post" action="' . $e($this->url->to('user/admin/groups/delete')) . '"'
                        . ' class="d-inline"'
                        . ' onsubmit="return confirm(\'Delete this group?\');">';
                    $html .= $this->request->csrfField();
                    $html .= '<input type="hidden" name="id" value="' . $id . '">';
                    $html .= '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
                    $html .= '</form>';
                }
                $html .= '</div>';
                return $html;
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e) {
            $this->view->assign('title', 'Group Management');
            $this->view->block('right_sidebar', 'Group Management');

            $defaultGroup = trim((string) $this->config->get('user.default_group', 'users'));
            echo '<div class="d-flex justify-content-between align-items-center mb-4">';
            echo '<div>';
            echo '<h1 class="h3 mb-0">Group Management</h1>';
            if ($defaultGroup !== '') {
                echo '<p class="text-muted small mb-0">New registrations join group '
                   . '<code>' . $e($defaultGroup) . '</code>'
                   . ' (config <code>user.default_group</code>)</p>';
            }
            echo '</div>';
            echo '<a href="' . $e($this->url->to('user/admin/groups/add')) . '"'
               . ' class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add group</a>';
            echo '</div>';

            echo $dataset->render();
        });
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function groupsListWhere(array $state): array
    {
        $clauses = [];
        $params = [];

        if ($state['q'] !== '') {
            $needle = $this->likeNeedle($state['q']);
            $clauses[] = '(g.name LIKE ? ESCAPE \'\\\\\' OR g.label LIKE ? ESCAPE \'\\\\\' OR g.description LIKE ? ESCAPE \'\\\\\')';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }

        if ($clauses === []) {
            return ['', []];
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function groupsListSortColumn(string $sort): string
    {
        $allowed = ['name', 'label', 'member_count'];
        return in_array($sort, $allowed, true) ? $sort : 'name';
    }

    private function likeNeedle(string $q): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        return '%' . $escaped . '%';
    }

    public function addAction(): void
    {
        $old = [
            'name'        => '',
            'label'       => '',
            'description' => '',
            'is_system'   => 0,
            'level'       => 0,
        ];

        if ($this->request->isPost()) {
            $old['name']        = trim((string) $this->request->post('name',        'string', ''));
            $old['label']       = trim((string) $this->request->post('label',       'string', ''));
            $old['description'] = trim((string) $this->request->post('description', 'string', ''));
            $old['is_system']   = (int)          $this->request->post('is_system',  'int',    0);
            $old['level']       = (int)          $this->request->post('level',      'int',    0);

            if (!preg_match('/^[a-z0-9_]{2,64}$/', $old['name'])) {
                $this->notify->error('Name must be 2–64 characters: lowercase letters, numbers, underscore.');
            } elseif ($this->groupNameExists($old['name'])) {
                $this->notify->error('A group with this name already exists.');
            } else {
                $this->db->insert('user_groups', [
                    'name'        => $old['name'],
                    'label'       => $old['label'] !== '' ? $old['label'] : $old['name'],
                    'description' => $old['description'],
                    'is_system'   => $old['is_system'] ? 1 : 0,
                    'level'       => $old['level'],
                    'permissions' => '[]',
                ]);

                $this->notify->success('Group created successfully.');
                redirect_to('user/admin/groups');
                return;
            }
        }

        echo $this->view->inline(function () use ($old) {
            $this->view->assign('title', 'Add Group');
            $this->view->block('right_sidebar', 'Add Group');

            echo '<h1 class="h3 mb-4">Add Group</h1>';

            echo $this->form->open([
                'action' => $this->url->to('user/admin/groups/add'),
                'method' => 'POST',
            ]);

            echo $this->form->field([
                'name'     => 'name',
                'label'    => 'Name',
                'type'     => 'text',
                'value'    => $old['name'],
                'required' => true,
                'help'     => 'Lowercase letters, numbers, underscore. Unique. 2–64 characters.',
            ]);

            echo $this->form->field([
                'name'  => 'label',
                'label' => 'Label',
                'type'  => 'text',
                'value' => $old['label'],
                'help'  => 'Human-readable name shown in the UI. Defaults to the name.',
            ]);

            echo $this->form->field([
                'name'  => 'description',
                'label' => 'Description',
                'type'  => 'textarea',
                'value' => $old['description'],
                'rows'  => 3,
            ]);

            echo $this->form->field([
                'name'    => 'is_system',
                'label'   => 'System group (cannot be deleted)',
                'type'    => 'checkbox',
                'value'   => 1,
                'checked' => (bool) $old['is_system'],
            ]);

            echo $this->form->field([
                'name'    => 'level',
                'label'   => 'Level',
                'type'    => 'select',
                'value'   => (string) (int) $old['level'],
                'options' => [
                    '0'     => 'Custom group (positive id for row access)',
                    '-101'  => 'Member (-101)',
                    '-102'  => 'Administrator (-102)',
                    '-103'  => 'Super Administrator (-103) — dedicated',
                ],
                'help'    => 'Rank for row access. Super Administrator is level -103 on the group, not a name.',
            ]);

            echo '<button type="submit" class="btn btn-primary">Create group</button>';
            echo ' ';
            echo '<a href="' . $this->view->e($this->url->to('user/admin/groups')) . '"'
               . ' class="btn btn-outline-secondary">Cancel</a>';

            echo $this->form->close();
        });
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);

        $group = $this->db->query(
            "SELECT * FROM #__user_groups WHERE id = ?",
            [$id]
        )->row;

        if ($group === []) {
            $this->notify->error('Group not found.');
            redirect_to('user/admin/groups');
            return;
        }

        $currentPerms = [];
        if (!empty($group['permissions'])) {
            $decoded = json_decode((string) $group['permissions'], true);
            if (is_array($decoded)) {
                $currentPerms = array_values(array_filter($decoded, 'is_string'));
            }
        }

        $isSuperGroup = (int) ($group['level'] ?? 0) === User::USER_LEVEL_SUPER_ADMIN;
        $nameLocked = $this->isLockedSystemGroupName((string) ($group['name'] ?? ''));
        $isGuestGroup = strcasecmp((string) ($group['name'] ?? ''), 'guest') === 0;

        if ($this->request->isPost()) {
            $name        = trim((string) $this->request->post('name',        'string', ''));
            $label       = trim((string) $this->request->post('label',       'string', ''));
            $description = trim((string) $this->request->post('description', 'string', ''));
            $isSystem    = (int)          $this->request->post('is_system',  'int',    0);
            $level       = (int)          $this->request->post('level',      'int',    0);

            $permsInput  = (array)        $this->request->post('permissions', 'raw', []);
            $manual = (string) $this->request->post('permissions_manual', 'raw', '');
            $fromTextarea = preg_split('/[\s,]+/', $manual, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $permsInput = array_merge($permsInput, $fromTextarea);

            $permissions = array_values(array_unique(array_filter(
                array_map('trim', array_map('strval', $permsInput)),
                fn($p) => $p !== '' && preg_match('/^(\*|[a-z0-9_]+(\.[a-z0-9_]+)*(\.\*)?)$/i', $p)
            )));

            if ($isSuperGroup) {
                $isSystem = 1;
                $level    = User::USER_LEVEL_SUPER_ADMIN;
            }
            if ($nameLocked) {
                $name = (string) $group['name'];
                $isSystem = 1;
            }

            if (!preg_match('/^[a-z0-9_]{2,64}$/', $name)) {
                $this->notify->error('Name must be 2–64 characters: lowercase letters, numbers, underscore.');
            } elseif ($this->groupNameExists($name, $id)) {
                $this->notify->error('A group with this name already exists.');
            } else {
                $beforePerms = $currentPerms;
                $beforeLevel = (int) ($group['level'] ?? 0);
                $permsChanged = $beforePerms !== $permissions || $beforeLevel !== $level;

                $this->db->update('user_groups', [
                    'name'        => $name,
                    'label'       => $label !== '' ? $label : $name,
                    'description' => $description,
                    'is_system'   => $isSystem ? 1 : 0,
                    'level'       => $level,
                    'permissions' => json_encode($permissions, JSON_UNESCAPED_SLASHES),
                ], ['id' => $id]);

                // Only invalidate sessions when privileges actually changed.
                if ($permsChanged) {
                    User::bumpAuthRevisionForGroup($id);
                }
                (new UserAuthAudit($this->db))->log(
                    'group.permissions_updated',
                    'Updated group ' . $name,
                    $this->auth->id(),
                    null,
                    $id,
                    ['permissions' => $beforePerms, 'level' => $beforeLevel],
                    ['permissions' => $permissions, 'level' => $level],
                );

                $this->notify->success('Group updated successfully.');
                redirect_to('user/admin/groups/edit', ['id' => $id]);
                return;
            }

            // Re-populate for the form below.
            $group['name']        = $name;
            $group['label']       = $label;
            $group['description'] = $description;
            $group['is_system']   = $isSystem;
            $group['level']       = $level;
            $currentPerms         = $permissions;
        }

        $members = $this->db->query(
            "SELECT u.id, u.username, u.email, u.status
               FROM #__user_group_members m
               JOIN #__users u ON m.user_id = u.id
              WHERE m.group_id = ?
              ORDER BY u.username",
            [$id]
        )->rows;

        $allActions = PermissionCatalog::scan();
        $auditRows = (new UserAuthAudit($this->db))->forGroup($id, 15);
        $isSuperGroup = (int) ($group['level'] ?? 0) === User::USER_LEVEL_SUPER_ADMIN;

        echo $this->view->inline(function () use ($group, $members, $currentPerms, $allActions, $id, $isSuperGroup, $auditRows, $nameLocked, $isGuestGroup) {
            $this->view->assign('title', 'Edit Group: ' . $group['name']);
            $this->view->block('right_sidebar', 'Edit Group');

            echo '<h1 class="h3 mb-4">Edit Group: '
               . $this->view->e($group['name'])
               . '</h1>';

            if ($isGuestGroup) {
                echo '<div class="alert alert-info">Guest is for anonymous visitors. Permissions apply when not logged in. Do not assign members.</div>';
            }

            echo $this->form->open([
                'action' => $this->url->to('user/admin/groups/edit', ['id' => $id]),
                'method' => 'POST',
            ]);

            echo $this->form->field([
                'name'     => 'name',
                'label'    => 'Name',
                'type'     => 'text',
                'value'    => $group['name'],
                'required' => true,
                'disabled' => $nameLocked,
                'help'     => $nameLocked
                    ? 'System group name is locked.'
                    : 'Lowercase letters, numbers, underscore. Unique. 2–64 characters.',
            ]);
            if ($nameLocked) {
                echo '<input type="hidden" name="name" value="' . $this->view->e((string) $group['name']) . '">';
            }

            echo $this->form->field([
                'name'  => 'label',
                'label' => 'Label',
                'type'  => 'text',
                'value' => $group['label'],
            ]);

            echo $this->form->field([
                'name'  => 'description',
                'label' => 'Description',
                'type'  => 'textarea',
                'value' => $group['description'] ?? '',
                'rows'  => 3,
            ]);

            echo $this->form->field([
                'name'     => 'is_system',
                'label'    => 'System group (cannot be deleted)',
                'type'     => 'checkbox',
                'value'    => 1,
                'checked'  => !empty($group['is_system']),
                'disabled' => $isSuperGroup || $nameLocked,
            ]);
            if ($isSuperGroup || $nameLocked) {
                echo '<input type="hidden" name="is_system" value="1">';
            }

            echo $this->form->field([
                'name'     => 'level',
                'label'    => 'Level',
                'type'     => 'select',
                'value'    => (string) (int) ($group['level'] ?? 0),
                'disabled' => $isSuperGroup,
                'help'     => $isSuperGroup
                    ? 'This group is Super Administrator because its level is -103.'
                    : 'Rank for row access. Super Administrator is -103 on any group.',
                'options'  => [
                    '0'    => 'Custom group (row access by group id)',
                    '-101' => 'Member (-101)',
                    '-102' => 'Administrator (-102)',
                    '-103' => 'Super Administrator (-103) — dedicated',
                ],
            ]);
            if ($isSuperGroup) {
                echo '<input type="hidden" name="level" value="-103">';
            }

            // ------------------------------------------------------------------
            // Permissions
            // ------------------------------------------------------------------
            echo '<fieldset class="mb-4">';
            echo '<legend class="h6 d-flex justify-content-between align-items-center">';
            echo '<span>Permissions</span>';
            echo '<span class="small">';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary me-1" id="perm-select-all">Select all</button>';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="perm-clear-all">Clear all</button>';
            echo '</span>';
            echo '</legend>';

            // Permission presets (check matching discovered actions + add wildcards to manual).
            $presets = $this->permissionPresets();
            echo '<div class="mb-3 d-flex flex-wrap gap-2 align-items-center">';
            echo '<span class="small text-muted me-1">Presets:</span>';
            foreach ($presets as $key => $preset) {
                echo '<button type="button" class="btn btn-sm btn-outline-primary perm-preset"'
                   . ' data-perms="' . $this->view->e(implode(',', $preset['perms'])) . '">'
                   . $this->view->e($preset['label']) . '</button>';
            }
            echo '</div>';

            // Free-form input.
            $manual = array_values(array_filter(
                $currentPerms,
                fn($p) => !isset($allActions[$p])
            ));

            echo '<div class="mb-3">';
            echo '<label for="perm-manual" class="form-label small text-muted">'
            . 'Additional permissions (one per line; wildcards allowed: <code>user.*</code>, <code>*</code>)'
            . '</label>';
            echo '<textarea name="permissions_manual" id="perm-manual"'
            . ' class="form-control form-control-sm" rows="3"'
            . ' placeholder="user.*&#10;news.edit">'
            . $this->view->e(implode("\n", $manual))
            . '</textarea>';
            echo '</div>';

            if (!empty($allActions)) {
                echo '<h6 class="small text-muted mb-2">Discovered actions</h6>';

                // Group by module.
                $grouped = [];
                foreach ($allActions as $perm => $label) {
                    $module = explode('.', $perm, 2)[0];
                    $grouped[$module][$perm] = $label;
                }

                $moduleIndex = 0;
                foreach ($grouped as $module => $perms) {
                    $moduleIndex++;
                    $moduleDomId = 'perm-module-' . $moduleIndex;
                    $ordered = [];
                    foreach ([$module . '.view', $module . '.*'] as $head) {
                        if (isset($perms[$head])) {
                            $ordered[$head] = $perms[$head];
                            unset($perms[$head]);
                        }
                    }
                    $perms = $ordered + $perms;

                    echo '<div class="mb-3 border rounded p-2">';

                    // Module header with its own select-all.
                    echo '<div class="d-flex justify-content-between align-items-center mb-2">';
                    echo '<div class="form-check">';
                    echo '<input class="form-check-input perm-module-toggle"'
                    . ' type="checkbox"'
                    . ' id="' . $moduleDomId . '"'
                    . ' data-target=".perm-module-' . $moduleIndex . '">';
                    echo '<label class="form-check-label fw-semibold small" for="' . $moduleDomId . '">'
                    . $this->view->e($module)
                    . '</label>';
                    echo '</div>';
                    echo '<button type="button"'
                    . ' class="btn btn-sm btn-link p-0 perm-module-select"'
                    . ' data-target=".perm-module-' . $moduleIndex . '">Select all</button>';
                    echo '</div>';

                    echo '<div class="row g-1">';

                    foreach ($perms as $perm => $label) {
                        $checked = in_array($perm, $currentPerms, true);
                        $domId   = 'perm-' . md5($perm);

                        echo '<div class="col-md-6 col-lg-4">';
                        echo '<div class="form-check">';
                        echo '<input class="form-check-input perm-item perm-module-' . $moduleIndex . '"'
                        . ' type="checkbox"'
                        . ' name="permissions[]"'
                        . ' id="' . $domId . '"'
                        . ' value="' . $this->view->e($perm) . '"'
                        . ($checked ? ' checked' : '') . '>';
                        echo '<label class="form-check-label small" for="' . $domId . '">'
                        . $this->view->e($perm)
                        . '</label>';
                        echo '</div></div>';
                    }

                    echo '</div>';  // row
                    echo '</div>';  // module wrapper
                }
            } else {
                echo '<p class="text-muted small">No actions discovered in any module.</p>';
            }

            echo '</fieldset>';

            echo '<button type="submit" class="btn btn-primary">Save changes</button>';
            echo ' ';
            echo '<a href="' . $this->view->e($this->url->to('user/admin/groups')) . '"'
               . ' class="btn btn-outline-secondary">Cancel</a>';

            echo $this->form->close();

            // ------------------------------------------------------------------
            // Members
            // ------------------------------------------------------------------
            echo '<h2 class="h5 mt-5">Members <span class="text-muted small">('
               . count($members) . ')</span></h2>';

            if ($isGuestGroup) {
                echo '<p class="text-muted">Guest represents anonymous visitors. Membership is not allowed.</p>';
            } else {
                echo '<form method="post" action="'
                   . $this->view->e($this->url->to('user/admin/groups/add-member'))
                   . '" class="row g-2 align-items-end mb-3">';
                echo $this->request->csrfField();
                echo '<input type="hidden" name="group_id" value="' . $id . '">';
                echo '<div class="col-md-6"><label class="form-label small">Add member</label>'
                   . '<input type="text" name="login" class="form-control" required'
                   . ' placeholder="Username or email" autocomplete="off"></div>';
                echo '<div class="col-auto"><button type="submit" class="btn btn-primary">Add to group</button></div>';
                echo '</form>';

                if (empty($members)) {
                    echo '<p class="text-muted">No members in this group.</p>';
                } else {
                    echo '<ul class="list-group">';
                    foreach ($members as $m) {
                        $mid = (int) $m['id'];
                        $status = (string) ($m['status'] ?? '');
                        echo '<li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">';
                        echo '<span>';
                        echo $this->view->e((string) $m['username']);
                        echo ' <span class="text-muted small">&lt;'
                           . $this->view->e((string) $m['email']) . '&gt;</span>';
                        if ($status !== '' && $status !== 'active') {
                            echo ' <span class="badge text-bg-secondary">' . $this->view->e($status) . '</span>';
                        }
                        echo '</span>';
                        echo '<span class="text-nowrap">';
                        echo '<a href="' . $this->view->e($this->url->to('user/admin/users/edit', ['id' => $mid])) . '"'
                           . ' class="btn btn-sm btn-outline-secondary">Edit user</a> ';
                        echo '<form method="post" action="'
                           . $this->view->e($this->url->to('user/admin/groups/remove-member'))
                           . '" class="d-inline"'
                           . ' onsubmit="return confirm(\'Remove this user from the group?\');">';
                        echo $this->request->csrfField();
                        echo '<input type="hidden" name="group_id" value="' . $id . '">';
                        echo '<input type="hidden" name="user_id" value="' . $mid . '">';
                        echo '<button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>';
                        echo '</form>';
                        echo '</span>';
                        echo '</li>';
                    }
                    echo '</ul>';
                }
            }

            if ($auditRows !== []) {
                echo '<h2 class="h5 mt-5">Recent changes</h2>';
                echo '<ul class="list-group mb-4">';
                foreach ($auditRows as $row) {
                    echo '<li class="list-group-item small">'
                       . $this->view->e((string) ($row['created_at'] ?? ''))
                       . ' — ' . $this->view->e((string) ($row['summary'] ?? $row['event'] ?? ''))
                       . '</li>';
                }
                echo '</ul>';
            }

            $script = '<script>
                (function () {
                    const items      = () => Array.from(document.querySelectorAll("input.perm-item"));
                    const moduleTogs = () => Array.from(document.querySelectorAll("input.perm-module-toggle"));
                    const manualEl   = document.getElementById("perm-manual");

                    function refreshModuleToggles() {
                        moduleTogs().forEach(function (toggle) {
                            const sel = toggle.dataset.target;
                            const kids = Array.from(document.querySelectorAll(sel));
                            if (kids.length === 0) return;

                            const checked = kids.filter(k => k.checked).length;
                            toggle.checked       = checked === kids.length;
                            toggle.indeterminate = checked > 0 && checked < kids.length;
                        });
                    }

                    document.addEventListener("change", function (e) {
                        const t = e.target;

                        if (t.matches && t.matches("input.perm-module-toggle")) {
                            const sel = t.dataset.target;
                            document.querySelectorAll(sel).forEach(function (cb) {
                                cb.checked = t.checked;
                            });
                            refreshModuleToggles();
                        }

                        if (t.matches && t.matches("input.perm-item")) {
                            refreshModuleToggles();
                        }
                    });

                    document.addEventListener("click", function (e) {
                        const btn = e.target.closest && e.target.closest(".perm-module-select");
                        if (!btn) return;
                        e.preventDefault();

                        const sel  = btn.dataset.target;
                        const kids = document.querySelectorAll(sel);
                        const allChecked = Array.from(kids).every(k => k.checked);
                        kids.forEach(function (cb) { cb.checked = !allChecked; });
                        refreshModuleToggles();
                    });

                    const allBtn   = document.getElementById("perm-select-all");
                    const clearBtn = document.getElementById("perm-clear-all");

                    if (allBtn) {
                        allBtn.addEventListener("click", function () {
                            items().forEach(cb => cb.checked = true);
                            refreshModuleToggles();
                        });
                    }

                    if (clearBtn) {
                        clearBtn.addEventListener("click", function () {
                            items().forEach(cb => cb.checked = false);
                            refreshModuleToggles();
                        });
                    }

                    // Presets: check matching boxes + merge wildcards into manual textarea.
                    document.querySelectorAll(".perm-preset").forEach(function (btn) {
                        btn.addEventListener("click", function () {
                            const list = (btn.getAttribute("data-perms") || "").split(",").filter(Boolean);
                            const known = new Set(items().map(cb => cb.value));
                            const manualExtra = [];
                            list.forEach(function (p) {
                                if (known.has(p)) {
                                    const cb = document.querySelector(\'input.perm-item[value="\' + CSS.escape(p) + \'"]\');
                                    if (cb) cb.checked = true;
                                } else {
                                    manualExtra.push(p);
                                }
                            });
                            if (manualEl && manualExtra.length) {
                                const existing = manualEl.value.trim();
                                const lines = existing ? existing.split(/\\r?\\n/) : [];
                                manualExtra.forEach(function (p) {
                                    if (lines.indexOf(p) === -1) lines.push(p);
                                });
                                manualEl.value = lines.join("\\n");
                            }
                            refreshModuleToggles();
                        });
                    });

                    refreshModuleToggles();
                })();
            </script>';
            $this->view->block('scripts', $script);
        });
    }

    // =========================================================================
    // Clone / members / delete
    // =========================================================================

    public function cloneAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('user/admin/groups');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('user/admin/groups');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $group = $this->db->query(
            "SELECT * FROM #__user_groups WHERE id = ?",
            [$id]
        )->row;

        if ($group === []) {
            $this->notify->error('Group not found.');
            redirect_to('user/admin/groups');
            return;
        }

        $base = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $group['name'])) ?: 'group';
        $base = mb_substr($base, 0, 50);
        $newName = $base . '_copy';
        $n = 2;
        while ($this->groupNameExists($newName)) {
            $newName = $base . '_copy' . $n;
            $n++;
            if ($n > 99) {
                $this->notify->error('Could not find a free name for the clone.');
                redirect_to('user/admin/groups');
                return;
            }
        }

        $newId = (int) $this->db->insert('user_groups', [
            'name'        => $newName,
            'label'       => trim((string) ($group['label'] ?? $group['name'])) . ' (copy)',
            'description' => (string) ($group['description'] ?? ''),
            'is_system'   => 0,
            'permissions' => (string) ($group['permissions'] ?? '[]'),
        ]);

        $this->notify->success("Cloned as '{$newName}'. Members were not copied.");
        redirect_to('user/admin/groups/edit', ['id' => $newId]);
    }

    public function addMemberAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('user/admin/groups');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('user/admin/groups');
            return;
        }

        $groupId = (int) $this->request->post('group_id', 'int', 0);
        $login = trim((string) $this->request->post('login', 'string', ''));

        $group = $this->db->query(
            "SELECT id, name FROM #__user_groups WHERE id = ? LIMIT 1",
            [$groupId]
        )->row;
        if ($group === []) {
            $this->notify->error('Group not found.');
            redirect_to('user/admin/groups');
            return;
        }

        if (strcasecmp((string) ($group['name'] ?? ''), 'guest') === 0) {
            $this->notify->error('Guests are anonymous visitors. Membership is not allowed.');
            redirect_to('user/admin/groups/edit', ['id' => $groupId]);
            return;
        }

        if ($login === '') {
            $this->notify->error('Enter a username or email.');
            redirect_to('user/admin/groups/edit', ['id' => $groupId]);
            return;
        }

        $user = \System\Services\User::findByLogin($login);
        if ($user === null || $user->id === null) {
            $this->notify->error('User not found.');
            redirect_to('user/admin/groups/edit', ['id' => $groupId]);
            return;
        }

        $exists = $this->db->query(
            "SELECT 1 FROM #__user_group_members WHERE user_id = ? AND group_id = ? LIMIT 1",
            [(int) $user->id, $groupId]
        )->row;
        if ($exists !== []) {
            $this->notify->warning('User is already in this group.');
            redirect_to('user/admin/groups/edit', ['id' => $groupId]);
            return;
        }

        UserGroup::addUser((int) $user->id, $groupId);
        User::bumpAuthRevision((int) $user->id);
        (new UserAuthAudit($this->db))->log(
            'group.member_added',
            'Added ' . $user->username . ' to group',
            $this->auth->id(),
            (int) $user->id,
            $groupId,
        );
        $this->notify->success('Added ' . $user->username . ' to the group.');
        redirect_to('user/admin/groups/edit', ['id' => $groupId]);
    }

    public function removeMemberAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('user/admin/groups');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('user/admin/groups');
            return;
        }

        $groupId = (int) $this->request->post('group_id', 'int', 0);
        $userId  = (int) $this->request->post('user_id', 'int', 0);

        $group = $this->db->query(
            "SELECT id, name, level FROM #__user_groups WHERE id = ? LIMIT 1",
            [$groupId]
        )->row;
        if ($group === [] || $userId <= 0) {
            $this->notify->error('Invalid request.');
            redirect_to('user/admin/groups');
            return;
        }

        if (strcasecmp((string) ($group['name'] ?? ''), 'guest') === 0) {
            $this->notify->error('Guests are anonymous visitors. Membership is not allowed.');
            redirect_to('user/admin/groups/edit', ['id' => $groupId]);
            return;
        }

        $groupLevel = (int) ($group['level'] ?? 0);
        if ($userId === (int) $this->auth->id()
            && ($groupLevel === User::USER_LEVEL_SUPER_ADMIN || $groupLevel === User::USER_LEVEL_ADMIN)
            && UserGroup::countUsersWithLevel($groupLevel, $userId) <= 0) {
            $this->notify->error('You cannot remove the last person with this access level.');
            redirect_to('user/admin/groups/edit', ['id' => $groupId]);
            return;
        }

        UserGroup::removeUser($userId, $groupId);
        User::bumpAuthRevision($userId);
        (new UserAuthAudit($this->db))->log(
            'group.member_removed',
            'Removed user from group',
            $this->auth->id(),
            $userId,
            $groupId,
        );
        $this->notify->success('Member removed from the group.');
        redirect_to('user/admin/groups/edit', ['id' => $groupId]);
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('user/admin/groups');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);

        $group = $this->db->query(
            "SELECT * FROM #__user_groups WHERE id = ?",
            [$id]
        )->row;

        if ($group === []) {
            $this->notify->error('Group not found.');
            redirect_to('user/admin/groups');
            return;
        }

        if (!empty($group['is_system'])) {
            $this->notify->error('System groups cannot be deleted.');
            redirect_to('user/admin/groups');
            return;
        }

        User::bumpAuthRevisionForGroup($id);
        $this->db->transaction(function ($db) use ($id) {
            $db->query('DELETE FROM #__user_group_members WHERE group_id = ?', [$id]);
            $db->query('DELETE FROM #__user_groups        WHERE id       = ?', [$id]);
        });

        $this->notify->success('Group deleted successfully.');
        redirect_to('user/admin/groups');
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Quick permission packs for the edit UI.
     *
     * @return array<string, array{label:string, perms:list<string>}>
     */
    private function permissionPresets(): array
    {
        return [
            'moderator' => [
                'label' => 'Moderator',
                'perms' => [
                    'user.admin.users.index',
                    'user.admin.users.search',
                    'user.admin.users.edit',
                    'user.admin.users.status',
                    'user.admin.users.pending',
                    'user.admin.users.approve',
                    'user.admin.users.bulkApprove',
                    'user.admin.users.reject',
                    'user.admin.dashboard.index',
                    'user.admin.stats.index',
                ],
            ],
            'sf_ops' => [
                'label' => 'Store ops',
                'perms' => [
                    'store.*',
                ],
            ],
            'sf_orders' => [
                'label' => 'Orders only',
                'perms' => [
                    'store.admin.orders.index',
                    'store.admin.orders.view',
                    'store.admin.orders.bulk',
                    'store.admin.orders.ship',
                    'store.admin.orders.label',
                    'store.admin.orders.invoice',
                    'store.admin.pickwaves.*',
                ],
            ],
            'read_users' => [
                'label' => 'Users read-only',
                'perms' => [
                    'user.admin.users.index',
                    'user.admin.users.search',
                    'user.admin.dashboard.index',
                    'user.admin.stats.index',
                ],
            ],
        ];
    }

    private function groupNameExists(string $name, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            $row = $this->db->query(
                "SELECT id FROM #__user_groups WHERE name = ? LIMIT 1",
                [$name]
            )->row;
            return $row !== [];
        }

        $row = $this->db->query(
            "SELECT id FROM #__user_groups WHERE name = ? AND id != ? LIMIT 1",
            [$name, $exceptId]
        )->row;
        return $row !== [];
    }

    private function isLockedSystemGroupName(string $name): bool
    {
        return in_array(strtolower(trim($name)), ['guest', 'authenticated', 'admin', 'sadmin'], true);
    }

    /**
     * Scan every module's controllers/ for *Action methods and return
     * a flat map of ['module.controller.action' => 'Label'].
     *
     * Class name derivation: given a file at
     *   system/modules/user/controllers/admin/groups.php
     * the expected class is `UserAdminGroups` (module + path + basename).
     *
     * @return array<string, string>
     */
    protected function availablePermissions(): array
    {
        return PermissionCatalog::scan();
    }
}