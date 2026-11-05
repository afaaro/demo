<?php
declare(strict_types=1);

namespace System\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Engine\Registry;
use System\Library\Database;
use System\Library\Session;
use Throwable;

/**
 * User — merged value object + repository (single-class design).
 *
 * An instance represents one row. Static factories hydrate instances;
 * instance methods read the loaded row. The session-backed "current user"
 * API lives here too, exposed through static methods so the router,
 * controllers, and layouts can call it uniformly.
 *
 * A single database + session context is set once at bootstrap via
 * User::boot(). All static methods use that shared context.
 *
 * Registry keys `user` and `auth` both resolve to the current session user
 * (or an empty User when nobody is signed in). $this->auth / $view->auth
 * are that instance — instance can()/canAny()/canAll() apply to it.
 */
final class User
{
    /** Default group id for newly created users (members). */
    private const DEFAULT_GROUP_ID = 1;

    public const USER_LEVEL_PUBLIC      = 0;
    public const USER_LEVEL_MEMBER      = -101;
    public const USER_LEVEL_ADMIN       = -102;
    public const USER_LEVEL_SUPER_ADMIN = -103;

    private const SESSION_AUTH_REVISION = 'auth_revision';

    // =========================================================================
    // Loaded row (mutable so hydrate() can populate)
    // =========================================================================

    public ?int    $id            = null;
    public string  $email         = '';
    public string  $username      = '';
    public string  $passwordHash  = '';
    public string  $displayName   = '';
    public string  $status        = 'active';
    public bool    $emailVerified = false;
    public ?string $createdAt     = null;
    public ?string $updatedAt     = null;
    public ?string $lastLoginAt   = null;

    /** @var array<int, UserGroup> */
    public array $groups = [];

    /** @var array<int, string> */
    public array $permissions = [];

    /** @var array<int, string> */
    public array $directPermissions = [];

    public int $authRevision = 1;

    // =========================================================================
    // Static context — set once at bootstrap
    // =========================================================================

    private static ?Database $db      = null;
    private static ?Session  $session = null;

    /** Cached "current" user for the request. */
    private static ?User $current  = null;
    private static bool  $resolved = false;

    public static function setContext(Database $db, Session $session): void
    {
        self::$db      = $db;
        self::$session = $session;
    }

    public static function boot(Registry $registry): void
    {
        $db = $registry->get('db');
        $session = $registry->get('session');
        UserGroup::setContext($db);
        self::setContext($db, $session);
        $current = static fn(): self => self::current() ?? new self();
        $registry->factory('user', $current);
        $registry->factory('auth', $current);
    }

    private static function db(): Database
    {
        return self::$db
            ?? throw new RuntimeException('User: database context not set.');
    }

    private static function session(): Session
    {
        return self::$session
            ?? throw new RuntimeException('User: session context not set.');
    }

    // =========================================================================
    // Instance methods — operate on loaded state
    // =========================================================================

    public function can(string $permission): bool
    {
        return UserGroup::permissionMatches($this->permissions, $permission);
    }

    /** @param array<int, string> $permissions */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if ($this->can($p)) return true;
        }
        return false;
    }

    /** @param array<int, string> $permissions */
    public function canAll(array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }
        foreach ($permissions as $p) {
            if (!$this->can($p)) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when the user may access a module: explicit module.view / module.*,
     * global *, or any concrete permission under that module prefix.
     */
    public function canModule(string $module): bool
    {
        $module = strtolower(trim($module));
        $module = preg_replace('/[^a-z0-9_]/', '', $module) ?? '';
        if ($module === '') {
            return false;
        }

        if ($this->can('*')
            || $this->can($module . '.*')
            || $this->can($module . '.view')
        ) {
            return true;
        }

        $prefix = $module . '.';
        foreach ($this->permissions as $perm) {
            if ($perm === $module || str_starts_with($perm, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function inGroup(string $name): bool
    {
        foreach ($this->groups as $group) {
            if ($group->name === $name) return true;
        }
        return false;
    }

    public function inGroupId(int $groupId): bool
    {
        if ($groupId <= 0) {
            return false;
        }
        foreach ($this->groups as $group) {
            if ($group->id === $groupId) {
                return true;
            }
        }
        return false;
    }

    /** Dedicated Super Administrator: any of this user's groups has level -103. */
    public function isSuperAdmin(): bool
    {
        return $this->userLevel() === self::USER_LEVEL_SUPER_ADMIN;
    }

    /**
     * PHP-Fusion user_level from group.level (most negative wins).
     * Guest 0; logged-in with no rank group is member (-101).
     */
    public function userLevel(): int
    {
        if ($this->id === null) {
            return self::USER_LEVEL_PUBLIC;
        }
        $best = 0;
        foreach ($this->groups as $group) {
            if ($group->level < 0 && ($best === 0 || $group->level < $best)) {
                $best = $group->level;
            }
        }
        return $best < 0 ? $best : self::USER_LEVEL_MEMBER;
    }

    /**
     * Row access (PHP-Fusion checkgroup). URL keys are a separate gate.
     */
    public function canAccess(int $required): bool
    {
        $required = (int) $required;
        if ($required === self::USER_LEVEL_PUBLIC) {
            return true;
        }
        if ($this->id === null) {
            return false;
        }
        if ($this->isSuperAdmin()) {
            return true;
        }
        if ($required < 0) {
            return $this->userLevel() <= $required;
        }
        return $this->inGroupId($required);
    }

    /** Admin toolbar / staff UI: any module permission or *. */
    public function canAccessAdminUi(): bool
    {
        if ($this->can('*')) {
            return true;
        }
        if (!function_exists('get_modules')) {
            return $this->permissions !== [];
        }
        foreach (array_keys(get_modules()) as $module) {
            if ($this->canModule((string) $module)) {
                return true;
            }
        }
        return false;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function displayLabel(): string
    {
        return $this->displayName !== '' ? $this->displayName : $this->username;
    }

    /** Normalize email for uniqueness (Gmail dots / plus-tags). */
    public static function canonicalEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $domain = rtrim($domain, '.');
        if ($domain === 'googlemail.com') {
            $domain = 'gmail.com';
        }
        if (str_contains($local, '+')) {
            $local = explode('+', $local, 2)[0];
        }
        if ($domain === 'gmail.com') {
            $local = str_replace('.', '', $local);
        }
        return $local . '@' . $domain;
    }

    /** Never includes password_hash. */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'email'          => $this->email,
            'username'       => $this->username,
            'display_name'   => $this->displayLabel(),
            'status'         => $this->status,
            'email_verified' => $this->emailVerified,
            'groups'         => array_map(fn(UserGroup $g) => $g->name, $this->groups),
            'permissions'    => $this->permissions,
            'user_level'     => $this->userLevel(),
            'is_super_admin' => $this->isSuperAdmin(),
            'created_at'     => $this->createdAt,
            'last_login_at'  => $this->lastLoginAt,
        ];
    }

    // =========================================================================
    // Current-user API (static)
    // =========================================================================

    public static function check(): bool
    {
        return self::current() !== null;
    }

    /**
     * Resolve and cache the currently authenticated user.
     *
     * Exception-safe: if the DB lookup throws, `$resolved` stays false so a
     * subsequent call in the same request will retry.
     */
    public static function current(): ?self
    {
        if (self::$resolved) {
            return self::$current;
        }

        $id = self::session()->get('user_id');
        if (!is_int($id) || $id <= 0) {
            self::$resolved = true;
            return null;
        }

        // Don't cache the "resolved" flag until after a successful lookup,
        // so a transient DB error doesn't log the user out for the request.
        $user = self::find($id);   // may throw

        self::$resolved = true;

        if ($user === null || !$user->isActive()) {
            self::session()->remove('user_id');
            self::session()->remove(self::SESSION_AUTH_REVISION);
            return null;
        }

        $sessionRev = self::session()->get(self::SESSION_AUTH_REVISION);
        if ($sessionRev === null) {
            self::session()->set(self::SESSION_AUTH_REVISION, $user->authRevision);
        } elseif ((int) $sessionRev !== $user->authRevision) {
            $forceLogout = true;
            try {
                $forceLogout = (bool) Registry::getInstance()
                    ->get('config')
                    ->get('user.force_logout_on_privilege_change', true);
            } catch (Throwable) {
                $forceLogout = true;
            }
            if ($forceLogout) {
                self::logout();
                return null;
            }
            self::session()->set(self::SESSION_AUTH_REVISION, $user->authRevision);
        }

        self::$current = $user;
        self::sessions()->touch((int) $user->id);

        return self::$current;
    }

    /** Alias for current() — themes call $auth->user(). */
    public static function user(): ?self
    {
        return self::current();
    }

    public static function id(): ?int
    {
        return self::current()?->id;
    }

    public function isAdmin(): bool
    {
        $level = $this->userLevel();
        return $level !== self::USER_LEVEL_PUBLIC && $level <= self::USER_LEVEL_ADMIN;
    }

    public static function isAdminCurrent(): bool
    {
        return self::current()?->isAdmin() ?? false;
    }

    public static function isSuperAdminCurrent(): bool
    {
        return self::current()?->isSuperAdmin() ?? false;
    }

    public static function userLevelCurrent(): int
    {
        return self::current()?->userLevel() ?? self::USER_LEVEL_PUBLIC;
    }

    public static function canAccessCurrent(int $required): bool
    {
        if ($required === self::USER_LEVEL_PUBLIC) {
            return true;
        }
        return self::current()?->canAccess($required) ?? false;
    }

    /**
     * SQL fragment + params for a row access column. Never compare the int yourself.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public static function accessSql(string $column): array
    {
        $parts = explode('.', str_replace('`', '', $column));
        $col = implode('.', array_map(static fn(string $p) => '`' . $p . '`', $parts));
        $user = self::current();
        if ($user === null) {
            return ['sql' => "{$col} = 0", 'params' => []];
        }
        if ($user->isSuperAdmin()) {
            return ['sql' => '1=1', 'params' => []];
        }

        $parts = ["{$col} = 0"];
        $params = [];
        $level = $user->userLevel();
        if ($level < 0) {
            $parts[] = "({$col} < 0 AND {$col} >= ?)";
            $params[] = $level;
        }

        $ids = [];
        foreach ($user->groups as $g) {
            if ($g->id !== null && $g->id > 0) {
                $ids[] = (int) $g->id;
            }
        }
        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $parts[] = "({$col} > 0 AND {$col} IN ({$placeholders}))";
            $params = array_merge($params, $ids);
        }

        return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
    }

    /**
     * Dropdown for access / access_role / post_role.
     *
     * @return list<array{value:int,label:string}>
     */
    public static function accessOptions(): array
    {
        $out = [
            ['value' => self::USER_LEVEL_PUBLIC,      'label' => 'Public'],
            ['value' => self::USER_LEVEL_MEMBER,      'label' => 'Member'],
            ['value' => self::USER_LEVEL_ADMIN,       'label' => 'Administrator'],
            ['value' => self::USER_LEVEL_SUPER_ADMIN, 'label' => 'Super Administrator'],
        ];
        try {
            foreach (UserGroup::all() as $g) {
                if ($g->id === null || $g->level < 0) {
                    continue;
                }
                $label = $g->label !== '' ? $g->label : $g->name;
                $out[] = ['value' => (int) $g->id, 'label' => $label . ' (group)'];
            }
        } catch (Throwable) {
            // Groups table unavailable.
        }
        return $out;
    }

    public static function bumpAuthRevision(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            self::db()->query(
                'UPDATE `#__users` SET `auth_revision` = `auth_revision` + 1 WHERE `id` = ?',
                [$userId]
            );
        } catch (Throwable) {
            // Column may not exist until migrate.
        }
    }

    public static function bumpAuthRevisionForGroup(int $groupId): void
    {
        if ($groupId <= 0) {
            return;
        }
        try {
            self::db()->query(
                'UPDATE `#__users` u
                   JOIN `#__user_group_members` m ON m.`user_id` = u.`id`
                   SET u.`auth_revision` = u.`auth_revision` + 1
                 WHERE m.`group_id` = ?',
                [$groupId]
            );
        } catch (Throwable) {
            // Column may not exist until migrate.
        }
    }

    /** Staff UI gate — see instance canAccessAdminUi(). */
    public static function canAccessAdminUiCurrent(): bool
    {
        return self::current()?->canAccessAdminUi() ?? false;
    }

    public static function hasGroup(string $name): bool
    {
        return self::current()?->inGroup($name) ?? false;
    }

    public static function canCurrent(string $permission): bool
    {
        return self::current()?->can($permission) ?? false;
    }

    public static function canModuleCurrent(string $module): bool
    {
        return self::current()?->canModule($module) ?? false;
    }

    /** @param array<int, string> $permissions */
    public static function canAnyCurrent(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (self::canCurrent($p)) return true;
        }
        return false;
    }

    public static function login(self $user): void
    {
        // Drop any prior session row before regenerating the PHP session id.
        self::sessions()->end();

        self::session()->regenerate(true);
        self::session()->regenerateCsrf();
        self::session()->set('user_id', $user->id);
        self::session()->set(self::SESSION_AUTH_REVISION, $user->authRevision);

        self::$current  = $user;
        self::$resolved = true;

        if ($user->id !== null) {
            self::sessions()->start((int) $user->id);
        }
    }

    public static function logout(): void
    {
        self::sessions()->end();

        self::session()->remove('user_id');
        self::session()->remove(self::SESSION_AUTH_REVISION);
        self::session()->regenerate(true);
        self::session()->regenerateCsrf();

        self::$current  = null;
        self::$resolved = true;
    }

    private static function sessions(): UserSessionService
    {
        return new UserSessionService(self::db(), self::session());
    }

    // =========================================================================
    // Static lookups
    // =========================================================================

    public static function find(int $id): ?self
    {
        return self::queryOne('id', $id);
    }

    public static function findByEmail(string $email): ?self
    {
        return self::queryOne('email', strtolower(trim($email)));
    }

    public static function findByUsername(string $username): ?self
    {
        return self::queryOne('username', strtolower(trim($username)));
    }

    /**
     * Look up by either email or username, in a single query.
     */
    public static function findByLogin(string $login): ?self
    {
        $login = strtolower(trim($login));

        $row = self::db()->query(
            "SELECT * FROM `users` WHERE `email` = ? OR `username` = ? LIMIT 1",
            [$login, $login]
        )->row;

        return $row !== [] ? self::hydrate($row) : null;
    }

    /** @return array<int, self> */
    public static function all(int $limit = 100, int $offset = 0): array
    {
        $rows = self::db()->query(
            "SELECT * FROM `users` ORDER BY `id` ASC LIMIT ? OFFSET ?",
            [$limit, $offset]
        )->rows;

        if ($rows === []) return [];

        $ids    = array_map(fn($r) => (int) $r['id'], $rows);
        $byUser = UserGroup::forUsers($ids);

        return array_map(
            fn($row) => self::hydrate($row, $byUser[(int) $row['id']] ?? []),
            $rows
        );
    }

    public static function count(): int
    {
        return (int) self::db()->query("SELECT COUNT(*) FROM `users`")->value;
    }

    // =========================================================================
    // Static writes
    // =========================================================================

    /**
     * @param array{
     *     email: string,
     *     username: string,
     *     password: string,
     *     display_name?: string,
     *     status?: string,
     *     email_verified?: bool,
     *     groups?: array<int, int|string>
     * } $data
     */
    public static function create(array $data): self
    {
        $email    = strtolower(trim((string) ($data['email']    ?? '')));
        $username = strtolower(trim((string) ($data['username'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email.');
        }
        if (!preg_match('/^[a-z0-9_]{3,64}$/', $username)) {
            throw new InvalidArgumentException('Username must be 3-64 chars: a-z, 0-9, underscore.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        $db = self::db();
        $emailCanonical = self::canonicalEmail($email);
        if ($db->exists('users', ['email' => $email])) {
            throw new RuntimeException('Email is already registered.');
        }
        try {
            $dup = $db->query(
                "SELECT `id` FROM `#__users` WHERE `email_canonical` = ? LIMIT 1",
                [$emailCanonical]
            )->row;
            if ($dup !== []) {
                throw new RuntimeException('Email is already registered.');
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable) {
            // email_canonical column may not exist on older DBs.
        }
        if ($db->exists('users', ['username' => $username])) {
            throw new RuntimeException('Username is already taken.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('password_hash() failed.');
        }

        $now = date('Y-m-d H:i:s');

        return $db->transaction(function (Database $db) use ($email, $emailCanonical, $username, $hash, $data, $now) {
            $payload = [
                'email'          => $email,
                'username'       => $username,
                'password_hash'  => $hash,
                'display_name'   => (string) ($data['display_name'] ?? ''),
                'status'         => (string) ($data['status'] ?? 'pending'),
                'email_verified' => !empty($data['email_verified']) ? 1 : 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
            try {
                $payload['email_canonical'] = $emailCanonical;
                $id = $db->insert('users', $payload);
            } catch (\Throwable) {
                unset($payload['email_canonical']);
                $id = $db->insert('users', $payload);
            }

            foreach ($data['groups'] ?? [self::DEFAULT_GROUP_ID] as $group) {
                $groupId = is_int($group)
                    ? $group
                    : UserGroup::groupIdByName((string) $group);
                UserGroup::addUser($id, $groupId);
            }

            return self::find($id)
                ?? throw new RuntimeException('Failed to reload new user.');
        });
    }

    public function save(): void
    {
        if ($this->id === null) {
            throw new RuntimeException('Cannot save an unsaved User.');
        }

        $payload = [
            'email'          => $this->email,
            'username'       => $this->username,
            'password_hash'  => $this->passwordHash,
            'display_name'   => $this->displayName,
            'status'         => $this->status,
            'email_verified' => $this->emailVerified ? 1 : 0,
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        try {
            $payload['email_canonical'] = self::canonicalEmail($this->email);
            self::db()->update('users', $payload, ['id' => $this->id]);
        } catch (\Throwable) {
            unset($payload['email_canonical']);
            self::db()->update('users', $payload, ['id' => $this->id]);
        }

        // Groups / permissions may have been changed in another process
        // (e.g. an admin edited this user's role) — reload from DB so the
        // cached permission set stays accurate.
        $this->groups      = UserGroup::forUser($this->id);
        $this->permissions = self::flattenPermissions($this->groups, $this->directPermissions);

        // Refresh the current-user cache if we just saved ourselves.
        if (self::$current !== null && self::$current->id === $this->id) {
            self::$current = $this;
        }
    }

    public function setPassword(string $plain): void
    {
        if (strlen($plain) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('password_hash() failed.');
        }
        $this->setPasswordHash($hash);
    }

    public function setPasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
        if ($this->id !== null) {
            self::db()->update('users', [
                'password_hash' => $hash,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], ['id' => $this->id]);
        }
    }

    public function touchLastLogin(): void
    {
        if ($this->id === null) return;

        $now = date('Y-m-d H:i:s');
        $payload = [
            'last_login_at' => $now,
            'updated_at'    => $now,
        ];
        // last_seen_at added by user_sessions migration — set when present.
        try {
            $hasSeen = (int) self::db()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = 'last_seen_at'",
                [str_replace('#__', self::db()->getPrefix(), '#__users')]
            )->value;
            if ($hasSeen > 0) {
                $payload['last_seen_at'] = $now;
            }
        } catch (Throwable) {
            // ignore
        }

        self::db()->update('users', $payload, ['id' => $this->id]);

        $this->lastLoginAt = $now;
    }

    public function delete(): void
    {
        if ($this->id === null) return;

        $id = $this->id;

        self::db()->transaction(function (Database $db) use ($id) {
            try {
                $db->query('DELETE FROM `user_sessions` WHERE `user_id` = ?', [$id]);
            } catch (Throwable) {
                // table may not exist yet
            }
            $db->query('DELETE FROM `user_group_members` WHERE `user_id` = ?', [$id]);
            $db->query('DELETE FROM `users`             WHERE `id`      = ?', [$id]);
        });

        if (self::$current !== null && self::$current->id === $id) {
            self::$current  = null;
            self::$resolved = true;
        }

        $this->id = null;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private static function queryOne(string $column, mixed $value): ?self
    {
        $sql = "SELECT * FROM `users` WHERE `{$column}` = ? LIMIT 1";
        $row = self::db()->query($sql, [$value])->row;
        return $row !== [] ? self::hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row, ?array $groups = null): self
    {
        $id     = (int) ($row['id'] ?? 0);
        $groups ??= UserGroup::forUser($id);

        $user = new self();
        $user->id            = $id;
        $user->email         = (string) ($row['email']         ?? '');
        $user->username      = (string) ($row['username']      ?? '');
        $user->passwordHash  = (string) ($row['password_hash'] ?? '');
        $user->displayName   = (string) ($row['display_name']  ?? '');
        $user->status        = (string) ($row['status']        ?? 'active');
        $user->emailVerified = (bool)   ($row['email_verified'] ?? false);
        $user->createdAt     = $row['created_at']    ?? null;
        $user->updatedAt     = $row['updated_at']    ?? null;
        $user->lastLoginAt   = $row['last_login_at'] ?? null;
        $user->groups        = $groups;

        $direct = [];
        if (!empty($row['direct_permissions'])) {
            $decoded = json_decode((string) $row['direct_permissions'], true);
            if (is_array($decoded)) {
                try {
                    $direct = UserGroup::normalisePermissions($decoded);
                } catch (Throwable) {
                    $direct = array_values(array_filter($decoded, 'is_string'));
                }
            }
        }
        $user->directPermissions = $direct;
        $user->authRevision      = (int) ($row['auth_revision'] ?? 1);
        $user->permissions       = self::flattenPermissions($groups, $direct);

        return $user;
    }

    /**
     * Flatten groups ∪ direct grants into a de-duplicated permission list.
     *
     * @param array<int, UserGroup> $groups
     * @param array<int, string>    $direct
     * @return array<int, string>
     */
    private static function flattenPermissions(array $groups, array $direct = []): array
    {
        $perms = [];
        foreach ($groups as $g) {
            foreach ($g->permissions as $p) {
                $perms[$p] = true;
            }
        }
        foreach ($direct as $p) {
            $perms[$p] = true;
        }
        return array_keys($perms);
    }
}