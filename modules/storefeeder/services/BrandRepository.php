<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use InvalidArgumentException;
use System\Library\Database;

final class BrandRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return array<int, array{id:int,name:string,website:string,logo_url:string,notes:string,active:bool,product_count:int}>
     */
    public function all(string $q = '', ?bool $activeOnly = null): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }

        $clauses = [$this->tenantClause('b')];
        $params  = [$this->tenantId()];

        if ($q !== '') {
            $clauses[] = '(b.`name` LIKE ? OR b.`website` LIKE ? OR b.`notes` LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        if ($activeOnly === true) {
            $clauses[] = 'b.`active` = 1';
        } elseif ($activeOnly === false) {
            $clauses[] = 'b.`active` = 0';
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);

        $rows = $this->db->query(
            "SELECT b.`id`, b.`name`,
                    COALESCE(b.`website`, '') AS website,
                    COALESCE(b.`logo_url`, '') AS logo_url,
                    COALESCE(b.`notes`, '') AS notes,
                    COALESCE(b.`active`, 1) AS active,
                    COUNT(p.`id`) AS product_count
               FROM `#__sf_brands` b
               LEFT JOIN `#__sf_products` p
                 ON p.`brand` = b.`name` AND p.`tenant_id` = b.`tenant_id`
              {$where}
              GROUP BY b.`id`, b.`name`, b.`website`, b.`logo_url`, b.`notes`, b.`active`
              ORDER BY b.`name` ASC",
            $params
        )->rows;

        return array_map(fn(array $row) => $this->mapRow($row), $rows);
    }

    /** @return array<int, string> id => name */
    public function namesById(): array
    {
        $map = [];
        foreach ($this->all('', true) as $row) {
            $map[$row['id']] = $row['name'];
        }
        return $map;
    }

    /**
     * Ensure a brand exists and return its canonical name (trimmed).
     * Empty string clears brand.
     */
    public function ensure(string $name): string
    {
        $name = mb_substr(trim($name), 0, 128);
        if ($name === '') {
            return '';
        }
        if (!TenantContext::get()->has()) {
            return $name;
        }

        $tid = $this->tenantId();

        $existing = $this->db->query(
            'SELECT `name` FROM `#__sf_brands` WHERE `name` = ? AND `tenant_id` = ? LIMIT 1',
            [$name, $tid]
        )->row;

        if ($existing !== []) {
            return (string) $existing['name'];
        }

        $ci = $this->db->query(
            'SELECT `name` FROM `#__sf_brands` WHERE LOWER(`name`) = LOWER(?) AND `tenant_id` = ? LIMIT 1',
            [$name, $tid]
        )->row;
        if ($ci !== []) {
            return (string) $ci['name'];
        }

        $this->db->insert('sf_brands', $this->withTenant([
            'name'       => $name,
            'created_at' => date('Y-m-d H:i:s'),
        ]));

        return $name;
    }

    public function create(string $name, array $extra = []): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Brand name is required.');
        }
        $canonical = $this->ensure($name);
        if ($extra !== [] && TenantContext::get()->has()) {
            $row = $this->db->query(
                'SELECT `id` FROM `#__sf_brands` WHERE `name` = ? AND `tenant_id` = ? LIMIT 1',
                [$canonical, $this->tenantId()]
            )->row;
            if ($row !== []) {
                $this->update((int) $row['id'], $extra + ['name' => $canonical]);
            }
        }
        return $canonical;
    }

    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $tid = $this->tenantId();
        $row = $this->db->query(
            "SELECT b.`id`, b.`name`, b.`created_at`,
                    COALESCE(b.`website`, '') AS website,
                    COALESCE(b.`logo_url`, '') AS logo_url,
                    COALESCE(b.`notes`, '') AS notes,
                    COALESCE(b.`active`, 1) AS active,
                    COUNT(p.`id`) AS product_count
               FROM `#__sf_brands` b
               LEFT JOIN `#__sf_products` p
                 ON p.`brand` = b.`name` AND p.`tenant_id` = b.`tenant_id`
              WHERE b.`id` = ? AND b.`tenant_id` = ?
              GROUP BY b.`id`, b.`name`, b.`created_at`, b.`website`, b.`logo_url`, b.`notes`, b.`active`
              LIMIT 1",
            [$id, $tid]
        )->row;
        if ($row === []) {
            return null;
        }
        $mapped = $this->mapRow($row);
        $mapped['created_at'] = (string) ($row['created_at'] ?? '');
        return $mapped;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): string
    {
        $brand = $this->find($id);
        if ($brand === null) {
            throw new InvalidArgumentException('Brand not found.');
        }

        $payload = [];
        $newName = $brand['name'];
        $tid     = $this->tenantId();

        if (array_key_exists('name', $data)) {
            $newName = mb_substr(trim((string) $data['name']), 0, 128);
            if ($newName === '') {
                throw new InvalidArgumentException('Brand name is required.');
            }
            $clash = $this->db->query(
                'SELECT `id` FROM `#__sf_brands` WHERE LOWER(`name`) = LOWER(?) AND `id` <> ? AND `tenant_id` = ? LIMIT 1',
                [$newName, $id, $tid]
            )->row;
            if ($clash !== []) {
                throw new InvalidArgumentException("Brand '{$newName}' already exists.");
            }
            $payload['name'] = $newName;
        }

        foreach (['website', 'logo_url', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $max = $field === 'logo_url' ? 500 : ($field === 'notes' ? 500 : 255);
                $payload[$field] = mb_substr(trim((string) $data[$field]), 0, $max);
            }
        }
        if (array_key_exists('active', $data)) {
            $payload['active'] = !empty($data['active']) ? 1 : 0;
        }

        if ($payload !== []) {
            $this->db->update('sf_brands', $payload, $this->withTenant(['id' => $id]));
        }

        if (isset($payload['name']) && $brand['name'] !== $newName && $brand['name'] !== '') {
            $this->db->query(
                'UPDATE `#__sf_products` SET `brand` = ? WHERE `brand` = ? AND `tenant_id` = ?',
                [$newName, $brand['name'], $tid]
            );
        }

        return $newName;
    }

    public function rename(int $id, string $newName): string
    {
        return $this->update($id, ['name' => $newName]);
    }

    public function productCount(string $name): int
    {
        if ($name === '' || !TenantContext::get()->has()) {
            return 0;
        }
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM `#__sf_products` WHERE `brand` = ? AND `tenant_id` = ?',
            [$name, $this->tenantId()]
        )->value;
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $row = $this->db->first('sf_brands', $this->withTenant(['id' => $id]));
        if ($row === []) {
            return;
        }
        $name = (string) ($row['name'] ?? '');
        $this->db->query(
            'DELETE FROM `#__sf_brands` WHERE `id` = ? AND `tenant_id` = ?',
            [$id, $tid]
        );
        if ($name !== '') {
            $this->db->query(
                "UPDATE `#__sf_products` SET `brand` = '' WHERE `brand` = ? AND `tenant_id` = ?",
                [$name, $tid]
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'name'          => (string) $row['name'],
            'website'       => (string) ($row['website'] ?? ''),
            'logo_url'      => (string) ($row['logo_url'] ?? ''),
            'notes'         => (string) ($row['notes'] ?? ''),
            'active'        => (bool) ($row['active'] ?? true),
            'product_count' => (int) ($row['product_count'] ?? 0),
        ];
    }
}
