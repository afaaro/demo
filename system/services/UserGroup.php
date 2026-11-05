<?php
declare(strict_types=1);

namespace System\Services;

use System\Library\Database;
use InvalidArgumentException;
use RuntimeException;

/**
 * UserGroup — merged value object + repository (single-class design).
 *
 * An instance represents one row. Static factories hydrate instances;
 * instance methods read the loaded row. Static write methods (create,
 * delete, addUser, removeUser) operate on the shared DB context.
 *
 * Context is set once at bootstrap via UserGroup::setContext().
 */
final class UserGroup
{
    // =========================================================================
    // Loaded row
    // =========================================================================

    public ?int    $id          = null;
    public string  $name        = '';
    public string  $label       = '';
    public string  $description = '';
    public bool    $isSystem    = false;
    /** PHP-Fusion level: 0 custom, -101 member, -102 admin, -103 super. */
    public int     $level       = 0;

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
    // Instance methods — operate on loaded state
    // =========================================================================

    public function can(string $permission): bool
    {
        return self::permissionMatches($this->permissions, $permission);
    }

    /** @param array<int, string> $permissions */
    public static function permissionMatches(array $permissions, string $permission): bool
    {
        if (in_array('*', $permissions, true)) return true;
        if (in_array($permission, $permissions, true)) return true;

        $parts = explode('.', $permission);
        while (count($parts) > 1) {
            array_pop($parts);
            if (in_array(implode('.', $parts) . '.*', $permissions, true)) {
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
        $row = self::db()->first('user_groups', ['id' => $id]);
        return $row !== [] ? self::hydrate($row) : null;
    }

    public static function findByName(string $name): ?self
    {
        $row = self::db()->first('user_groups', ['name' => strtolower(trim($name))]);
        return $row !== [] ? self::hydrate($row) : null;
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        $rows = self::db()->query(
            'SELECT * FROM `user_groups` ORDER BY `id` ASC'
        )->rows;

        return array_map(fn($r) => self::hydrate($r), $rows);
    }

    /** @return array<int, self> */
    public static function forUser(int $userId): array
    {
        $rows = self::db()->query(
            'SELECT g.* FROM `user_groups` g
               JOIN `user_group_members` m ON m.`group_id` = g.`id`
              WHERE m.`user_id` = ?
              ORDER BY g.`name`',
            [$userId]
        )->rows;

        return array_map(fn($r) => self::hydrate($r), $rows);
    }

    /**
     * Batch: one query, returns [userId => [UserGroup, ...]].
     *
     * @param array<int, int> $userIds
     * @return array<int, array<int, self>>
     */
    public static function forUsers(array $userIds): array
    {
        if ($userIds === []) return [];

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $rows = self::db()->query(
            "SELECT m.`user_id`, g.*
               FROM `user_groups` g
               JOIN `user_group_members` m ON m.`group_id` = g.`id`
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
        $row = self::db()->first('user_groups', ['name' => strtolower(trim($name))]);
        if ($row === []) {
            throw new InvalidArgumentException("Unknown group: {$name}");
        }
        return (int) $row['id'];
    }

    /** @return list<int> */
    public static function idsWithLevel(int $level): array
    {
        try {
            $rows = self::db()->query(
                'SELECT `id` FROM `user_groups` WHERE `level` = ?',
                [$level]
            )->rows;
        } catch (\Throwable) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    public static function countUsersWithLevel(int $level, int $exceptUserId = 0): int
    {
        try {
            return (int) self::db()->query(
                'SELECT COUNT(DISTINCT m.`user_id`)
                   FROM `user_group_members` m
                   JOIN `user_groups` g ON g.`id` = m.`group_id`
                  WHERE g.`level` = ?
                    AND m.`user_id` <> ?',
                [$level, $exceptUserId]
            )->value;
        } catch (\Throwable) {
            return 0;
        }
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
        $name  = strtolower(trim((string) ($data['name']  ?? '')));
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

        $perms = self::normalisePermissions($data['permissions'] ?? []);

        $id = self::db()->insert('user_groups', [
            'name'        => $name,
            'label'       => $label,
            'description' => (string) ($data['description'] ?? ''),
            'is_system'   => !empty($data['is_system']) ? 1 : 0,
            'level'       => (int) ($data['level'] ?? 0),
            'permissions' => json_encode($perms, JSON_UNESCAPED_SLASHES),
        ]);

        return self::find($id)
            ?? throw new RuntimeException('Failed to reload group.');
    }

    /**
     * Persist changes made to this instance back to the DB.
     */
    public function save(): void
    {
        if ($this->id === null) {
            throw new RuntimeException('Cannot save an unsaved UserGroup.');
        }

        self::db()->update('user_groups', [
            'label'       => $this->label,
            'description' => $this->description,
            'level'       => $this->level,
            'permissions' => json_encode($this->permissions, JSON_UNESCAPED_SLASHES),
        ], ['id' => $this->id]);
    }

    public function setPermissions(array $permissions): void
    {
        $this->permissions = self::normalisePermissions($permissions);
        if ($this->id !== null) {
            self::db()->update('user_groups', [
                'permissions' => json_encode($this->permissions, JSON_UNESCAPED_SLASHES),
            ], ['id' => $this->id]);
        }
    }

    public function delete(bool $force = false): void
    {
        if ($this->id === null) return;
        if ($this->isSystem && !$force) {
            throw new RuntimeException("Refusing to delete system group '{$this->name}'.");
        }

        $id = $this->id;
        self::db()->transaction(function (Database $db) use ($id) {
            $db->query('DELETE FROM `user_group_members` WHERE `group_id` = ?', [$id]);
            $db->query('DELETE FROM `user_groups`        WHERE `id`       = ?', [$id]);
        });
        $this->id = null;
    }

    // =========================================================================
    // Membership — static (schema has no joined_at)
    // =========================================================================

    public static function addUser(int $userId, int $groupId): void
    {
        self::db()->query(
            'INSERT IGNORE INTO `user_group_members` (`user_id`, `group_id`) VALUES (?, ?)',
            [$userId, $groupId]
        );
    }

    public static function removeUser(int $userId, int $groupId): void
    {
        self::db()->query(
            'DELETE FROM `user_group_members` WHERE `user_id` = ? AND `group_id` = ?',
            [$userId, $groupId]
        );
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): self
    {
        $perms = json_decode((string) ($row['permissions'] ?? '[]'), true);
        if (!is_array($perms)) $perms = [];

        $g = new self();
        $g->id          = (int)    $row['id'];
        $g->name        = (string) $row['name'];
        $g->label       = (string) $row['label'];
        $g->description = (string) ($row['description'] ?? '');
        $g->isSystem    = (bool)   ($row['is_system']   ?? false);
        $g->level       = (int)    ($row['level']       ?? 0);
        $g->permissions = array_values(array_filter($perms, 'is_string'));

        return $g;
    }

    /**
     * @param array<int|string, mixed> $permissions
     * @return array<int, string>
     */
    public static function normalisePermissions(array $permissions): array
    {
        $out = [];
        foreach ($permissions as $perm) {
            if (!is_string($perm)) continue;
            $perm = trim($perm);
            if ($perm === '') continue;
            if (!preg_match('/^(\*|[a-z0-9_]+(\.[a-z0-9_]+)*(\.\*)?)$/i', $perm)) {
                throw new InvalidArgumentException("Invalid permission: {$perm}");
            }
            $out[$perm] = true;
        }
        return array_keys($out);
    }
}