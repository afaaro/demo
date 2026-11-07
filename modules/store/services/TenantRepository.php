<?php
declare(strict_types=1);

namespace Module\Store\Services;

use InvalidArgumentException;
use System\Library\Database;

final class TenantRepository
{
    public function __construct(private Database $db) {}

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->db->first('store_tenants', ['id' => $id]);
        return $row === [] ? null : $this->hydrate($row);
    }

    /**
     * Platform home tenant (id=1): the domain holder's own org.
     * Client tenants are created under this platform.
     *
     * @return array<string, mixed>
     */
    public function ensurePlatformHome(): array
    {
        $settings = json_encode(['platform_home' => true], JSON_UNESCAPED_UNICODE) ?: '{}';

        $existing = $this->find(1);
        if ($existing !== null) {
            if (($existing['status'] ?? '') !== 'active') {
                $this->updateStatus(1, 'active');
                $existing['status'] = 'active';
            }
            $meta = is_array($existing['settings'] ?? null) ? $existing['settings'] : [];
            if (empty($meta['platform_home'])) {
                $meta['platform_home'] = true;
                $this->db->update('store_tenants', [
                    'settings'   => json_encode($meta, JSON_UNESCAPED_UNICODE) ?: '{}',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => 1]);
                $existing['settings'] = $meta;
            }
            return $existing;
        }

        try {
            $this->db->insert('store_tenants', [
                'id'         => 1,
                'slug'       => 'platform',
                'name'       => 'Platform',
                'status'     => 'active',
                'settings'   => $settings,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Race or slug conflict — fall through to re-read / create by slug.
        }

        $existing = $this->find(1);
        if ($existing !== null) {
            return $existing;
        }

        foreach (['platform', 'default'] as $slug) {
            $bySlug = $this->findBySlug($slug);
            if ($bySlug !== null) {
                return $bySlug;
            }
        }

        $id = $this->create([
            'slug'     => 'platform',
            'name'     => 'Platform',
            'status'   => 'active',
            'settings' => ['platform_home' => true],
        ]);
        $created = $this->find($id);
        if ($created === null) {
            throw new InvalidArgumentException('Unable to create platform home tenant.');
        }
        return $created;
    }

    /** @deprecated use ensurePlatformHome() */
    public function ensureDefault(): array
    {
        return $this->ensurePlatformHome();
    }

    public function isPlatformHome(array $tenant): bool
    {
        if ((int) ($tenant['id'] ?? 0) === 1) {
            return true;
        }
        $settings = $tenant['settings'] ?? [];
        return is_array($settings) && !empty($settings['platform_home']);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }
        $row = $this->db->first('store_tenants', ['slug' => $slug]);
        return $row === [] ? null : $this->hydrate($row);
    }

    /** @return list<array<string, mixed>> */
    public function all(string $status = ''): array
    {
        if ($status !== '') {
            $rows = $this->db->query(
                'SELECT * FROM `#__store_tenants` WHERE `status` = ? ORDER BY `name`',
                [$status]
            )->rows;
        } else {
            $rows = $this->db->query(
                'SELECT * FROM `#__store_tenants` ORDER BY `name`'
            )->rows;
        }
        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    /**
     * @param array{slug:string,name:string,status?:string,settings?:array<string,mixed>} $data
     */
    public function create(array $data): int
    {
        $slug = $this->normalizeSlug((string) ($data['slug'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($slug === '' || $name === '') {
            throw new InvalidArgumentException('Tenant slug and name are required.');
        }
        if ($this->findBySlug($slug) !== null) {
            throw new InvalidArgumentException('Tenant slug already exists.');
        }
        $settings = $data['settings'] ?? [];
        $json = json_encode(is_array($settings) ? $settings : [], JSON_UNESCAPED_UNICODE);
        return (int) $this->db->insert('store_tenants', [
            'slug'       => $slug,
            'name'       => mb_substr($name, 0, 128),
            'status'     => (($data['status'] ?? 'active') === 'suspended') ? 'suspended' : 'active',
            'settings'   => is_string($json) ? $json : '{}',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateStatus(int $id, string $status): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid tenant.');
        }
        $status = $status === 'suspended' ? 'suspended' : 'active';
        $this->db->update('store_tenants', [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function update(int $id, string $name, string $status = 'active'): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid tenant.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        $this->db->update('store_tenants', [
            'name'       => mb_substr($name, 0, 128),
            'status'     => $status === 'suspended' ? 'suspended' : 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function membershipsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT tu.*, t.`slug`, t.`name` AS tenant_name, t.`status` AS tenant_status
               FROM `#__store_tenant_users` tu
               JOIN `#__store_tenants` t ON t.`id` = tu.`tenant_id`
              WHERE tu.`user_id` = ?
              ORDER BY t.`name`",
            [$userId]
        )->rows;
        return array_map(static function (array $r): array {
            return [
                'id'            => (int) $r['id'],
                'tenant_id'     => (int) $r['tenant_id'],
                'user_id'       => (int) $r['user_id'],
                'role'          => (string) $r['role'],
                'slug'          => (string) $r['slug'],
                'tenant_name'   => (string) $r['tenant_name'],
                'tenant_status' => (string) $r['tenant_status'],
                'created_at'    => (string) ($r['created_at'] ?? ''),
            ];
        }, $rows);
    }

    public function addUser(int $tenantId, int $userId, string $role = 'staff'): void
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Invalid tenant or user.');
        }
        $role = in_array($role, ['owner', 'admin', 'staff'], true) ? $role : 'staff';
        $this->db->query(
            'INSERT IGNORE INTO `#__store_tenant_users` (`tenant_id`, `user_id`, `role`, `created_at`)
             VALUES (?, ?, ?, ?)',
            [$tenantId, $userId, $role, date('Y-m-d H:i:s')]
        );
    }

    public function removeUser(int $tenantId, int $userId): void
    {
        $this->db->query(
            'DELETE FROM `#__store_tenant_users` WHERE `tenant_id` = ? AND `user_id` = ?',
            [$tenantId, $userId]
        );
    }

    public function setUserRole(int $tenantId, int $userId, string $role): void
    {
        $role = in_array($role, ['owner', 'admin', 'staff'], true) ? $role : 'staff';
        $this->db->update('store_tenant_users', ['role' => $role], [
            'tenant_id' => $tenantId,
            'user_id'   => $userId,
        ]);
    }

    /**
     * Assign a single primary owner. Previous owners are demoted to admin.
     */
    public function setOwner(int $tenantId, int $userId): void
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Invalid tenant or user.');
        }

        $this->db->query(
            "UPDATE `#__store_tenant_users`
                SET `role` = 'admin'
              WHERE `tenant_id` = ?
                AND `role` = 'owner'
                AND `user_id` <> ?",
            [$tenantId, $userId]
        );

        $exists = (int) $this->db->query(
            'SELECT COUNT(*) FROM `#__store_tenant_users` WHERE `tenant_id` = ? AND `user_id` = ?',
            [$tenantId, $userId]
        )->value;

        if ($exists > 0) {
            $this->setUserRole($tenantId, $userId, 'owner');
            return;
        }

        $this->db->query(
            'INSERT INTO `#__store_tenant_users` (`tenant_id`, `user_id`, `role`, `created_at`)
             VALUES (?, ?, ?, ?)',
            [$tenantId, $userId, 'owner', date('Y-m-d H:i:s')]
        );
    }

    /** @return array<string, mixed>|null */
    public function ownerForTenant(int $tenantId): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }
        $row = $this->db->query(
            "SELECT tu.*, u.`username`, u.`email`
               FROM `#__store_tenant_users` tu
               LEFT JOIN `#__users` u ON u.`id` = tu.`user_id`
              WHERE tu.`tenant_id` = ? AND tu.`role` = 'owner'
              ORDER BY tu.`id` ASC
              LIMIT 1",
            [$tenantId]
        )->row;
        if ($row === []) {
            return null;
        }
        return [
            'id'       => (int) $row['id'],
            'tenant_id'=> (int) $row['tenant_id'],
            'user_id'  => (int) $row['user_id'],
            'role'     => (string) $row['role'],
            'username' => (string) ($row['username'] ?? ''),
            'email'    => (string) ($row['email'] ?? ''),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function usersForTenant(int $tenantId): array
    {
        $rows = $this->db->query(
            "SELECT tu.*, u.`username`, u.`email`
               FROM `#__store_tenant_users` tu
               LEFT JOIN `#__users` u ON u.`id` = tu.`user_id`
              WHERE tu.`tenant_id` = ?
              ORDER BY tu.`role`, u.`username`",
            [$tenantId]
        )->rows;
        return array_map(static function (array $r): array {
            return [
                'id'       => (int) $r['id'],
                'tenant_id'=> (int) $r['tenant_id'],
                'user_id'  => (int) $r['user_id'],
                'role'     => (string) $r['role'],
                'username' => (string) ($r['username'] ?? ''),
                'email'    => (string) ($r['email'] ?? ''),
                'created_at' => (string) ($r['created_at'] ?? ''),
            ];
        }, $rows);
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $raw = $row['settings'] ?? '{}';
        $settings = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        return [
            'id'         => (int) $row['id'],
            'slug'       => (string) $row['slug'],
            'name'       => (string) $row['name'],
            'status'     => (string) $row['status'],
            'settings'   => is_array($settings) ? $settings : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
