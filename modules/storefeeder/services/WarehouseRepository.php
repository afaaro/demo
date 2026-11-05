<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use InvalidArgumentException;
use System\Library\Database;

final class WarehouseRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return array<int, array{
     *   id:int,name:string,company:string,address:string,contact_name:string,email:string,
     *   phone:string,notes:string,active:bool,product_count:int,po_count:int,stock_units:int
     * }>
     */
    public function all(string $q = '', ?bool $activeOnly = null): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }

        $clauses = [$this->tenantClause('w')];
        $params  = [$this->tenantId()];

        if ($q !== '') {
            $clauses[] = '(w.`name` LIKE ? OR w.`company` LIKE ? OR w.`contact_name` LIKE ?'
                . ' OR w.`email` LIKE ? OR w.`phone` LIKE ? OR w.`address` LIKE ? OR w.`notes` LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
        if ($activeOnly === true) {
            $clauses[] = 'COALESCE(w.`active`, 1) = 1';
        } elseif ($activeOnly === false) {
            $clauses[] = 'COALESCE(w.`active`, 1) = 0';
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);
        $productJoin = $this->productJoinSql('w', 'p');
        $poJoin = $this->poJoinSql('w', 'po');
        $stockSub = $this->stockUnitsSubquerySql();

        $rows = $this->db->query(
            "SELECT w.`id`, w.`name`,
                    COALESCE(w.`company`, '') AS company,
                    COALESCE(w.`address`, '') AS address,
                    COALESCE(w.`contact_name`, '') AS contact_name,
                    COALESCE(w.`email`, '') AS email,
                    COALESCE(w.`phone`, '') AS phone,
                    COALESCE(w.`notes`, '') AS notes,
                    COALESCE(w.`active`, 1) AS active,
                    COUNT(DISTINCT p.`id`) AS product_count,
                    COUNT(DISTINCT po.`id`) AS po_count,
                    COALESCE(({$stockSub}), 0) AS stock_units
               FROM `#__sf_warehouses` w
               LEFT JOIN `#__sf_products` p ON {$productJoin}
               LEFT JOIN `#__sf_purchase_orders` po ON {$poJoin}
              {$where}
              GROUP BY w.`id`, w.`name`, w.`company`, w.`address`, w.`contact_name`,
                       w.`email`, w.`phone`, w.`notes`, w.`active`
              ORDER BY w.`name` ASC",
            $params
        )->rows;

        return array_map(fn(array $row) => $this->mapRow($row), $rows);
    }

    public function findByName(string $name): ?array
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $row = $this->db->query(
            "SELECT `id` FROM `#__sf_warehouses` WHERE `name` = ? AND `tenant_id` = ? LIMIT 1",
            [$name, $this->tenantId()]
        )->row;
        if ($row === []) {
            return null;
        }
        return $this->find((int) $row['id']);
    }

    /**
     * Ensure a warehouse exists and return its canonical name (trimmed).
     * Empty string becomes Default.
     */
    public function ensure(string $name): string
    {
        $name = mb_substr(trim($name), 0, 128);
        if ($name === '') {
            $name = 'Default';
        }
        if (!TenantContext::get()->has()) {
            return $name;
        }

        $tid = $this->tenantId();

        $existing = $this->db->query(
            "SELECT `name` FROM `#__sf_warehouses` WHERE `name` = ? AND `tenant_id` = ? LIMIT 1",
            [$name, $tid]
        )->row;

        if ($existing !== []) {
            return (string) $existing['name'];
        }

        $ci = $this->db->query(
            "SELECT `name` FROM `#__sf_warehouses` WHERE LOWER(`name`) = LOWER(?) AND `tenant_id` = ? LIMIT 1",
            [$name, $tid]
        )->row;
        if ($ci !== []) {
            return (string) $ci['name'];
        }

        $this->db->insert('sf_warehouses', $this->withTenant([
            'name'       => $name,
            'active'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]));

        return $name;
    }

    public function create(string $name, array $extra = []): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Warehouse name is required.');
        }
        $canonical = $this->ensure($name);
        if ($extra !== [] && TenantContext::get()->has()) {
            $row = $this->db->query(
                'SELECT `id` FROM `#__sf_warehouses` WHERE `name` = ? AND `tenant_id` = ? LIMIT 1',
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
        $productJoin = $this->productJoinSql('w', 'p');
        $poJoin = $this->poJoinSql('w', 'po');
        $stockSub = $this->stockUnitsSubquerySql();

        $row = $this->db->query(
            "SELECT w.`id`, w.`name`, w.`created_at`,
                    COALESCE(w.`company`, '') AS company,
                    COALESCE(w.`address`, '') AS address,
                    COALESCE(w.`contact_name`, '') AS contact_name,
                    COALESCE(w.`email`, '') AS email,
                    COALESCE(w.`phone`, '') AS phone,
                    COALESCE(w.`notes`, '') AS notes,
                    COALESCE(w.`active`, 1) AS active,
                    COUNT(DISTINCT p.`id`) AS product_count,
                    COUNT(DISTINCT po.`id`) AS po_count,
                    COALESCE(({$stockSub}), 0) AS stock_units
               FROM `#__sf_warehouses` w
               LEFT JOIN `#__sf_products` p ON {$productJoin}
               LEFT JOIN `#__sf_purchase_orders` po ON {$poJoin}
              WHERE w.`id` = ? AND w.`tenant_id` = ?
              GROUP BY w.`id`, w.`name`, w.`created_at`, w.`company`, w.`address`,
                       w.`contact_name`, w.`email`, w.`phone`, w.`notes`, w.`active`
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
        $warehouse = $this->find($id);
        if ($warehouse === null) {
            throw new InvalidArgumentException('Warehouse not found.');
        }

        $payload = [];
        $newName = $warehouse['name'];
        $tid     = $this->tenantId();

        if (array_key_exists('name', $data)) {
            $newName = mb_substr(trim((string) $data['name']), 0, 128);
            if ($newName === '') {
                throw new InvalidArgumentException('Warehouse name is required.');
            }
            if (strcasecmp($warehouse['name'], 'Default') === 0 && strcasecmp($newName, 'Default') !== 0) {
                throw new InvalidArgumentException('The Default warehouse cannot be renamed.');
            }
            $clash = $this->db->query(
                "SELECT `id` FROM `#__sf_warehouses` WHERE LOWER(`name`) = LOWER(?) AND `id` <> ? AND `tenant_id` = ? LIMIT 1",
                [$newName, $id, $tid]
            )->row;
            if ($clash !== []) {
                throw new InvalidArgumentException("Warehouse '{$newName}' already exists.");
            }
            $payload['name'] = $newName;
        }

        foreach (['company', 'contact_name', 'email', 'phone', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $max = match ($field) {
                    'email' => 255,
                    'notes' => 500,
                    'phone' => 64,
                    default => 128,
                };
                $payload[$field] = mb_substr(trim((string) $data[$field]), 0, $max);
            }
        }
        if (array_key_exists('address', $data)) {
            $payload['address'] = trim((string) $data['address']);
        }
        if (array_key_exists('active', $data)) {
            if (strcasecmp($warehouse['name'], 'Default') === 0 && empty($data['active'])) {
                throw new InvalidArgumentException('The Default warehouse cannot be deactivated.');
            }
            $payload['active'] = !empty($data['active']) ? 1 : 0;
        }

        if ($payload !== []) {
            $this->db->update('sf_warehouses', $payload, $this->withTenant(['id' => $id]));
        }

        $oldName = $warehouse['name'];
        if (isset($payload['name']) && $oldName !== '' && $oldName !== $newName) {
            $this->db->query(
                "UPDATE `#__sf_products` SET `warehouse` = ? WHERE `warehouse` = ? AND `tenant_id` = ?",
                [$newName, $oldName, $tid]
            );
            $this->db->query(
                "UPDATE `#__sf_purchase_orders` SET `warehouse` = ? WHERE `warehouse` = ? AND `tenant_id` = ?",
                [$newName, $oldName, $tid]
            );
        }

        return $newName;
    }

    /** @deprecated Use update() */
    public function rename(int $id, string $newName): string
    {
        $warehouse = $this->find($id);
        if ($warehouse === null) {
            throw new InvalidArgumentException('Warehouse not found.');
        }
        return $this->update($id, [
            'name'         => $newName,
            'company'      => (string) ($warehouse['company'] ?? ''),
            'address'      => (string) ($warehouse['address'] ?? ''),
            'contact_name' => (string) ($warehouse['contact_name'] ?? ''),
            'email'        => (string) ($warehouse['email'] ?? ''),
            'phone'        => (string) ($warehouse['phone'] ?? ''),
            'notes'        => (string) ($warehouse['notes'] ?? ''),
            'active'       => !empty($warehouse['active']),
        ]);
    }

    public function productCount(string $name): int
    {
        if ($name === '' || !TenantContext::get()->has()) {
            return 0;
        }
        $tid = $this->tenantId();
        if (strcasecmp($name, 'Default') === 0) {
            return (int) $this->db->query(
                "SELECT COUNT(*) FROM `#__sf_products`
                  WHERE (`warehouse` = 'Default' OR `warehouse` = '' OR `warehouse` IS NULL)
                    AND `tenant_id` = ?",
                [$tid]
            )->value;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_products` WHERE `warehouse` = ? AND `tenant_id` = ?",
            [$name, $tid]
        )->value;
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $row = $this->db->first('sf_warehouses', $this->withTenant(['id' => $id]));
        if ($row === []) {
            return;
        }
        $name = (string) ($row['name'] ?? '');
        if (strcasecmp($name, 'Default') === 0) {
            throw new InvalidArgumentException('The Default warehouse cannot be deleted.');
        }
        $this->ensure('Default');
        $this->db->query(
            'DELETE FROM `#__sf_warehouses` WHERE `id` = ? AND `tenant_id` = ?',
            [$id, $tid]
        );
        if ($name !== '') {
            $this->db->query(
                "UPDATE `#__sf_products` SET `warehouse` = 'Default' WHERE `warehouse` = ? AND `tenant_id` = ?",
                [$name, $tid]
            );
            $this->db->query(
                "UPDATE `#__sf_purchase_orders` SET `warehouse` = 'Default' WHERE `warehouse` = ? AND `tenant_id` = ?",
                [$name, $tid]
            );
        }
    }

    private function productJoinSql(string $wAlias, string $pAlias): string
    {
        return "({$pAlias}.`warehouse` = {$wAlias}.`name`
            OR ({$wAlias}.`name` = 'Default'
                AND ({$pAlias}.`warehouse` = '' OR {$pAlias}.`warehouse` IS NULL)))
            AND {$pAlias}.`tenant_id` = {$wAlias}.`tenant_id`";
    }

    private function poJoinSql(string $wAlias, string $poAlias): string
    {
        return "({$poAlias}.`warehouse` = {$wAlias}.`name`
            OR ({$wAlias}.`name` = 'Default'
                AND ({$poAlias}.`warehouse` = '' OR {$poAlias}.`warehouse` IS NULL
                     OR {$poAlias}.`warehouse` = 'Default')))
            AND {$poAlias}.`tenant_id` = {$wAlias}.`tenant_id`";
    }

    private function stockUnitsSubquerySql(): string
    {
        return "SELECT SUM(COALESCE(i2.`quantity`, 0))
                  FROM `#__sf_products` p2
                  INNER JOIN `#__sf_product_variants` v2 ON v2.`product_id` = p2.`id`
                  LEFT JOIN `#__sf_inventory` i2 ON i2.`variant_id` = v2.`id`
                 WHERE p2.`tenant_id` = w.`tenant_id`
                   AND (
                    p2.`warehouse` = w.`name`
                    OR (w.`name` = 'Default' AND (p2.`warehouse` = '' OR p2.`warehouse` IS NULL))
                 )";
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'name'         => (string) $row['name'],
            'company'      => (string) ($row['company'] ?? ''),
            'address'      => (string) ($row['address'] ?? ''),
            'contact_name' => (string) ($row['contact_name'] ?? ''),
            'email'        => (string) ($row['email'] ?? ''),
            'phone'        => (string) ($row['phone'] ?? ''),
            'notes'        => (string) ($row['notes'] ?? ''),
            'active'       => (bool) ($row['active'] ?? true),
            'product_count'=> (int) ($row['product_count'] ?? 0),
            'po_count'     => (int) ($row['po_count'] ?? 0),
            'stock_units'  => (int) ($row['stock_units'] ?? 0),
        ];
    }
}
