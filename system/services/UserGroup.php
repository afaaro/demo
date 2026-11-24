<?php
declare(strict_types=1);

namespace System\Services;

use System\Library\Database;
use InvalidArgumentException;
use RuntimeException;

final class UserGroup
{
    public ?int $id = null;
    public string $name = '';
    public string $label = '';
    public string $description = '';
    public bool $isSystem = false;

    /** PHP-Fusion level: 0 custom, -101 member, -102 admin, -103 super. */
    public int $level = 0;

    /** @var array<int, string> */
    public array $permissions = [];

    // =========================================================================
    // Static context
    // =========================================================================

    private static ?Database $db = null;

    public static function setContext(Database $db): void
    {
        self::$db = $db;
    }

    private static function db(): Database
    {
        return self::$db
            ?? throw new RuntimeException('UserGroup: database context not set.');
    }

    // =========================================================================
    // Instance methods
    // =========================================================================
    public function can(string $permission): bool
    {
        $permission = strtolower(trim($permission));

        if ($permission === '') {
            return false;
        }

        return self::permissionMatches($this->permissions, $permission);
    }

    /**
     * @param array<int, string> $permissions
     */
    public static function permissionMatches(
        array $permissions,
        string $permission
    ): bool {
        if (
            in_array('all', $permissions, true) ||
            in_array('*', $permissions, true)
        ) {
            return true;
        }

        if (in_array($permission, $permissions, true)) {
            return true;
        }

        $parts = explode('.', $permission);

        while (count($parts) > 1) {
            array_pop($parts);

            $wildcard = implode('.', $parts) . '.*';

            if (in_array($wildcard, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'label'       => $this->label,
            'description' => $this->description,
            'is_system'   => $this->isSystem,
            'level'       => $this->level,
            'permissions' => $this->permissions,
        ];
    }

    // =========================================================================
    // Static reads
    // =========================================================================

    public static function find(int $id): ?self
    {
        $row = self::db()->first('user_group', ['id' => $id]);

        return $row !== [] ? self::hydrate($row) : null;
    }

    public static function findByName(string $name): ?self
    {
        $row = self::db()->first('user_group', [
            'name' => strtolower(trim($name)),
        ]);

        return $row !== [] ? self::hydrate($row) : null;
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        $rows = self::db()->query(
            'SELECT g.*, p.`permission`
               FROM `#__user_group` g
               LEFT JOIN `#__user_permission` p
                 ON p.`user_group_id` = g.`id`
              ORDER BY g.`id` ASC'
        )->rows;

        return array_map(
            static fn(array $row): self => self::hydrate($row),
            $rows
        );
    }

    /** @return array<int, self> */
    public static function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = self::db()->query(
            'SELECT g.*, p.`permission`
               FROM `#__user_group` g
               JOIN `#__user_group_member` m
                 ON m.`group_id` = g.`id`
               LEFT JOIN `#__user_permission` p
                 ON p.`user_group_id` = g.`id`
              WHERE m.`user_id` = ?
              ORDER BY g.`name`',
            [$userId]
        )->rows;

        return array_map(
            static fn(array $row): self => self::hydrate($row),
            $rows
        );
    }

    /**
     * Batch: one query, returns [userId => [UserGroup, ...]].
     *
     * @param array<int, int> $userIds
     * @return array<int, array<int, self>>
     */
    public static function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));

        $rows = self::db()->query(
            "SELECT m.`user_id`, g.*, p.`permission`
               FROM `#__user_group` g
               JOIN `#__user_group_member` m
                 ON m.`group_id` = g.`id`
               LEFT JOIN `#__user_permission` p
                 ON p.`user_group_id` = g.`id`
              WHERE m.`user_id` IN ({$placeholders})
              ORDER BY g.`name`",
            array_values($userIds)
        )->rows;

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['user_id']][] = self::hydrate($row);
        }

        return $out;
    }

    public static function groupIdByName(string $name): int
    {
        $name = strtolower(trim($name));

        if ($name === '') {
            throw new InvalidArgumentException('Group name cannot be empty.');
        }

        $row = self::db()->first('user_group', [
            'name' => $name,
        ]);

        if ($row === []) {
            throw new InvalidArgumentException("Unknown group: {$name}");
        }

        return (int) $row['id'];
    }

    /** @return list<int> */
    public static function idsWithLevel(int $level): array
    {
        $rows = self::db()->query(
            'SELECT `id`
            FROM `#__user_group`
            WHERE `level` = ?',
            [$level]
        )->rows;

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public static function countUsersWithLevel(
        int $level,
        int $exceptUserId = 0
    ): int {
        return (int) self::db()->query(
            'SELECT COUNT(DISTINCT m.`user_id`)
            FROM `#__user_group_member` m
            JOIN `#__user_group` g
                ON g.`id` = m.`group_id`
            WHERE g.`level` = ?
                AND m.`user_id` <> ?',
            [$level, $exceptUserId]
        )->value;
    }

    // =========================================================================
    // Static writes
    // =========================================================================

    /**
     * @param array{
     *     name: string,
     *     label: string,
     *     description?: string,
     *     is_system?: bool,
     *     level?: int,
     *     permissions?: array<int, string>
     * } $data
     */
    public static function create(array $data): self
    {
        $name  = strtolower(trim((string) ($data['name'] ?? '')));
        $label = trim((string) ($data['label'] ?? ''));

        if (!preg_match('/^[a-z0-9_]{2,64}$/', $name)) {
            throw new InvalidArgumentException('Invalid group name.');
        }

        if ($label === '') {
            throw new InvalidArgumentException('Group label is required.');
        }

        if (self::findByName($name) !== null) {
            throw new RuntimeException("Group '{$name}' already exists.");
        }

        $permissions = self::normalisePermissions(
            $data['permissions'] ?? []
        );

        $db = self::db();

        $id = $db->transaction(function (Database $db) use (
            $name,
            $label,
            $data,
            $permissions
        ): int {
            $id = $db->insert('user_group', [
                'name'        => $name,
                'label'       => $label,
                'description' => (string) ($data['description'] ?? ''),
                'is_system'   => !empty($data['is_system']) ? 1 : 0,
                'level'       => (int) ($data['level'] ?? 0),
            ]);

            self::savePermissionRow($id, $permissions);

            return $id;
        });

        return self::find($id)
            ?? throw new RuntimeException('Failed to reload group.');
    }

    public function save(): void
    {
        if ($this->id === null) {
            throw new RuntimeException('Cannot save an unsaved UserGroup.');
        }

        $id = $this->id;
        $permissions = self::normalisePermissions($this->permissions);

        self::db()->transaction(function (Database $db) use ($id, $permissions): void {
            $db->update('user_group', [
                'label'       => $this->label,
                'description' => $this->description,
                'level'       => $this->level,
            ], ['id' => $id]);

            self::savePermissionRow($id, $permissions);
        });

        $this->permissions = $permissions;
    }

    public function setPermissions(array $permissions): void
    {
        $permissions = self::normalisePermissions($permissions);

        if ($this->id === null) {
            $this->permissions = $permissions;
            return;
        }

        self::savePermissionRow($this->id, $permissions);

        $this->permissions = $permissions;
    }

    public function delete(bool $force = false): void
    {
        if ($this->id === null) {
            return;
        }

        if ($this->isSystem && !$force) {
            throw new RuntimeException(
                "Refusing to delete system group '{$this->name}'."
            );
        }

        $id = $this->id;

        self::db()->transaction(function (Database $db) use ($id): void {

            // Find users belonging to this group before deleting memberships.
            $members = $db->query(
                'SELECT `user_id`
                FROM `#__user_group_member`
                WHERE `group_id` = ?',
                [$id]
            )->rows;

            // Delete group permissions.
            $db->query(
                'DELETE FROM `#__user_permission`
                WHERE `user_group_id` = ?',
                [$id]
            );

            // Delete memberships.
            $db->query(
                'DELETE FROM `#__user_group_member`
                WHERE `group_id` = ?',
                [$id]
            );

            // Delete the group.
            $db->query(
                'DELETE FROM `#__user_group`
                WHERE `id` = ?',
                [$id]
            );

            // Refresh authorization revisions for affected users.
            foreach ($members as $member) {
                User::bumpAuthRevision((int) $member['user_id']);
            }
        });

        $this->id = null;
    }

    // =========================================================================
    // Membership
    // =========================================================================
    public static function addUser(int $userId, int $groupId): void
    {
        if ($userId <= 0 || $groupId <= 0) {
            throw new InvalidArgumentException('Invalid user or group ID.');
        }

        $result = self::db()->query(
            'INSERT IGNORE INTO `#__user_group_member`
                (`user_id`, `group_id`)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE
                `group_id` = VALUES(`group_id`)',
            [$userId, $groupId]
        );

        if ($result->affected > 0) {
            User::bumpAuthRevision($userId);
        }
    }

    public static function removeUser(int $userId, int $groupId): void
    {
        if ($userId <= 0 || $groupId <= 0) {
            throw new InvalidArgumentException('Invalid user or group ID.');
        }

        $result = self::db()->query(
            'DELETE FROM `#__user_group_member`
            WHERE `user_id` = ?
            AND `group_id` = ?',
            [$userId, $groupId]
        );

        if ($result->affected > 0) {
            User::bumpAuthRevision($userId);
        }
    }

    // =========================================================================
    // Permissions
    // =========================================================================

    /**
     * Create or update the permission row for a group.
     *
     * @param array<int, string> $permissions
     */
    private static function savePermissionRow(
        int $groupId,
        array $permissions
    ): void {
        if ($groupId <= 0) {
            throw new InvalidArgumentException('Invalid group ID.');
        }

        self::db()->query(
            'INSERT INTO `#__user_permission`
                (`user_group_id`, `permission`)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE
                `permission` = VALUES(`permission`)',
            [
                $groupId,
                json_encode($permissions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]
        );

        // Refresh authorization revisions for all group members.
        User::bumpAuthRevisionForGroup($groupId);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): self
    {
        $permissions = json_decode(
            (string) ($row['permission'] ?? '[]'),
            true
        );

        if (!is_array($permissions)) {
            $permissions = [];
        }

        $group = new self();

        $group->id          = (int) $row['id'];
        $group->name        = (string) $row['name'];
        $group->label       = (string) $row['label'];
        $group->description = (string) ($row['description'] ?? '');
        $group->isSystem    = (bool) ($row['is_system'] ?? false);
        $group->level       = (int) ($row['level'] ?? 0);
        $group->permissions = self::normalisePermissions($permissions);

        return $group;
    }

    /**
     * @param array<int|string, mixed> $permissions
     * @return array<int, string>
     */
    public static function normalisePermissions(array $permissions): array
    {
        $out = [];

        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                continue;
            }

            $permission = strtolower(trim($permission));

            if ($permission === '') {
                continue;
            }

            if (!preg_match(
                '/^(\*|[a-z0-9_]+(\.[a-z0-9_]+)*(\.\*)?)$/',
                $permission
            )) {
                throw new InvalidArgumentException(
                    "Invalid permission: {$permission}"
                );
            }

            $out[$permission] = true;
        }

        return array_keys($out);
    }
}