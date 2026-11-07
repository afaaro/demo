<?php
declare(strict_types=1);

namespace Module\Store\Services;

use InvalidArgumentException;
use System\Library\Database;

final class SupplierRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return array<int, array{
     *   id:int,name:string,company:string,contact_name:string,email:string,phone:string,
     *   website:string,address:string,lead_time_days:int,currency:string,notes:string,
     *   active:bool,product_count:int,po_count:int
     * }>
     */
    public function all(string $q = '', ?bool $activeOnly = null): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }

        $clauses = [$this->tenantClause('s')];
        $params  = [$this->tenantId()];

        if ($q !== '') {
            $clauses[] = '(s.`name` LIKE ? OR s.`company` LIKE ? OR s.`contact_name` LIKE ?'
                . ' OR s.`email` LIKE ? OR s.`phone` LIKE ? OR s.`notes` LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($activeOnly === true) {
            $clauses[] = 's.`active` = 1';
        } elseif ($activeOnly === false) {
            $clauses[] = 's.`active` = 0';
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);

        $rows = $this->db->query(
            "SELECT s.`id`, s.`name`,
                    COALESCE(s.`company`, '') AS company,
                    COALESCE(s.`contact_name`, '') AS contact_name,
                    COALESCE(s.`email`, '') AS email,
                    COALESCE(s.`phone`, '') AS phone,
                    COALESCE(s.`website`, '') AS website,
                    COALESCE(s.`address`, '') AS address,
                    COALESCE(s.`lead_time_days`, 0) AS lead_time_days,
                    COALESCE(s.`currency`, 'GBP') AS currency,
                    COALESCE(s.`po_mode`, 'below_reorder') AS po_mode,
                    COALESCE(s.`sold_days`, 30) AS sold_days,
                    COALESCE(s.`include_in_requisitions`, 1) AS include_in_requisitions,
                    COALESCE(s.`accepts_back_orders`, 1) AS accepts_back_orders,
                    COALESCE(s.`min_order_value`, 0) AS min_order_value,
                    COALESCE(s.`notes`, '') AS notes,
                    COALESCE(s.`active`, 1) AS active,
                    COUNT(DISTINCT p.`id`) AS product_count,
                    COUNT(DISTINCT po.`id`) AS po_count
               FROM `#__store_suppliers` s
               LEFT JOIN `#__store_products` p
                 ON p.`supplier_name` = s.`name` AND p.`tenant_id` = s.`tenant_id`
               LEFT JOIN `#__store_purchase_orders` po
                 ON po.`supplier_name` = s.`name` AND po.`tenant_id` = s.`tenant_id`
              {$where}
              GROUP BY s.`id`, s.`name`, s.`company`, s.`contact_name`, s.`email`, s.`phone`,
                       s.`website`, s.`address`, s.`lead_time_days`, s.`currency`, s.`po_mode`,
                       s.`sold_days`, s.`include_in_requisitions`, s.`accepts_back_orders`,
                       s.`min_order_value`, s.`notes`, s.`active`
              ORDER BY s.`name` ASC",
            $params
        )->rows;

        return array_map(fn(array $row) => $this->mapRow($row), $rows);
    }

    /**
     * Ensure a supplier exists and return its canonical name (trimmed).
     * Empty string clears supplier.
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
            'SELECT `name` FROM `#__store_suppliers` WHERE `name` = ? AND `tenant_id` = ? LIMIT 1',
            [$name, $tid]
        )->row;

        if ($existing !== []) {
            return (string) $existing['name'];
        }

        $ci = $this->db->query(
            'SELECT `name` FROM `#__store_suppliers` WHERE LOWER(`name`) = LOWER(?) AND `tenant_id` = ? LIMIT 1',
            [$name, $tid]
        )->row;
        if ($ci !== []) {
            return (string) $ci['name'];
        }

        $this->db->insert('store_suppliers', $this->withTenant([
            'name'       => $name,
            'created_at' => date('Y-m-d H:i:s'),
        ]));

        return $name;
    }

    public function create(string $name, array $extra = []): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Supplier name is required.');
        }
        $canonical = $this->ensure($name);
        if ($extra !== [] && TenantContext::get()->has()) {
            $row = $this->db->query(
                'SELECT `id` FROM `#__store_suppliers` WHERE `name` = ? AND `tenant_id` = ? LIMIT 1',
                [$canonical, $this->tenantId()]
            )->row;
            if ($row !== []) {
                $this->update((int) $row['id'], $extra + ['name' => $canonical]);
            }
        }
        return $canonical;
    }

    public function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT `id` FROM `#__store_suppliers` WHERE `name` = ? AND `tenant_id` = ? LIMIT 1",
            [$name, $this->tenantId()]
        )->row;
        if ($row === []) {
            $row = $this->db->query(
                "SELECT `id` FROM `#__store_suppliers` WHERE LOWER(`name`) = LOWER(?) AND `tenant_id` = ? LIMIT 1",
                [$name, $this->tenantId()]
            )->row;
        }
        if ($row === []) {
            return null;
        }
        return $this->find((int) $row['id']);
    }

    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $tid = $this->tenantId();
        $row = $this->db->query(
            "SELECT s.`id`, s.`name`, s.`created_at`,
                    COALESCE(s.`company`, '') AS company,
                    COALESCE(s.`contact_name`, '') AS contact_name,
                    COALESCE(s.`email`, '') AS email,
                    COALESCE(s.`phone`, '') AS phone,
                    COALESCE(s.`website`, '') AS website,
                    COALESCE(s.`address`, '') AS address,
                    COALESCE(s.`lead_time_days`, 0) AS lead_time_days,
                    COALESCE(s.`currency`, 'GBP') AS currency,
                    COALESCE(s.`po_mode`, 'below_reorder') AS po_mode,
                    COALESCE(s.`sold_days`, 30) AS sold_days,
                    COALESCE(s.`include_in_requisitions`, 1) AS include_in_requisitions,
                    COALESCE(s.`accepts_back_orders`, 1) AS accepts_back_orders,
                    COALESCE(s.`min_order_value`, 0) AS min_order_value,
                    COALESCE(s.`notes`, '') AS notes,
                    COALESCE(s.`active`, 1) AS active,
                    COUNT(DISTINCT p.`id`) AS product_count,
                    COUNT(DISTINCT po.`id`) AS po_count
               FROM `#__store_suppliers` s
               LEFT JOIN `#__store_products` p
                 ON p.`supplier_name` = s.`name` AND p.`tenant_id` = s.`tenant_id`
               LEFT JOIN `#__store_purchase_orders` po
                 ON po.`supplier_name` = s.`name` AND po.`tenant_id` = s.`tenant_id`
              WHERE s.`id` = ? AND s.`tenant_id` = ?
              GROUP BY s.`id`, s.`name`, s.`created_at`, s.`company`, s.`contact_name`, s.`email`,
                       s.`phone`, s.`website`, s.`address`, s.`lead_time_days`, s.`currency`,
                       s.`po_mode`, s.`sold_days`, s.`include_in_requisitions`, s.`accepts_back_orders`,
                       s.`min_order_value`, s.`notes`, s.`active`
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
        $supplier = $this->find($id);
        if ($supplier === null) {
            throw new InvalidArgumentException('Supplier not found.');
        }

        $payload = [];
        $newName = $supplier['name'];
        $tid     = $this->tenantId();

        if (array_key_exists('name', $data)) {
            $newName = mb_substr(trim((string) $data['name']), 0, 128);
            if ($newName === '') {
                throw new InvalidArgumentException('Supplier name is required.');
            }
            $clash = $this->db->query(
                'SELECT `id` FROM `#__store_suppliers` WHERE LOWER(`name`) = LOWER(?) AND `id` <> ? AND `tenant_id` = ? LIMIT 1',
                [$newName, $id, $tid]
            )->row;
            if ($clash !== []) {
                throw new InvalidArgumentException("Supplier '{$newName}' already exists.");
            }
            $payload['name'] = $newName;
        }

        foreach (['company', 'contact_name', 'email', 'phone', 'website', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $max = match ($field) {
                    'email', 'website' => 255,
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
        if (array_key_exists('lead_time_days', $data)) {
            $payload['lead_time_days'] = max(0, (int) $data['lead_time_days']);
        }
        if (array_key_exists('currency', $data)) {
            $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $data['currency']) ?? '');
            $payload['currency'] = strlen($cur) === 3 ? $cur : 'GBP';
        }
        if (array_key_exists('po_mode', $data)) {
            $mode = (string) $data['po_mode'];
            $allowed = array_keys(InventoryPlannerService::modeOptions());
            $payload['po_mode'] = in_array($mode, $allowed, true) ? $mode : 'below_reorder';
        }
        if (array_key_exists('sold_days', $data)) {
            $payload['sold_days'] = max(1, min(365, (int) $data['sold_days']));
        }
        if (array_key_exists('include_in_requisitions', $data)) {
            $payload['include_in_requisitions'] = !empty($data['include_in_requisitions']) ? 1 : 0;
        }
        if (array_key_exists('accepts_back_orders', $data)) {
            $payload['accepts_back_orders'] = !empty($data['accepts_back_orders']) ? 1 : 0;
        }
        if (array_key_exists('min_order_value', $data)) {
            $payload['min_order_value'] = max(0, round((float) $data['min_order_value'], 2));
        }
        if (array_key_exists('active', $data)) {
            $payload['active'] = !empty($data['active']) ? 1 : 0;
        }

        if ($payload !== []) {
            $this->db->update('store_suppliers', $payload, $this->withTenant(['id' => $id]));
        }

        if (isset($payload['name']) && $supplier['name'] !== $newName && $supplier['name'] !== '') {
            $this->db->query(
                'UPDATE `#__store_products` SET `supplier_name` = ? WHERE `supplier_name` = ? AND `tenant_id` = ?',
                [$newName, $supplier['name'], $tid]
            );
            $this->db->query(
                'UPDATE `#__store_purchase_orders` SET `supplier_name` = ? WHERE `supplier_name` = ? AND `tenant_id` = ?',
                [$newName, $supplier['name'], $tid]
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
            'SELECT COUNT(*) FROM `#__store_products` WHERE `supplier_name` = ? AND `tenant_id` = ?',
            [$name, $this->tenantId()]
        )->value;
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $row = $this->db->first('store_suppliers', $this->withTenant(['id' => $id]));
        if ($row === []) {
            return;
        }
        $name = (string) ($row['name'] ?? '');
        $this->db->query(
            'DELETE FROM `#__store_suppliers` WHERE `id` = ? AND `tenant_id` = ?',
            [$id, $tid]
        );
        if ($name !== '') {
            $this->db->query(
                "UPDATE `#__store_products` SET `supplier_name` = '' WHERE `supplier_name` = ? AND `tenant_id` = ?",
                [$name, $tid]
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'name'           => (string) $row['name'],
            'company'        => (string) ($row['company'] ?? ''),
            'contact_name'   => (string) ($row['contact_name'] ?? ''),
            'email'          => (string) ($row['email'] ?? ''),
            'phone'          => (string) ($row['phone'] ?? ''),
            'website'        => (string) ($row['website'] ?? ''),
            'address'        => (string) ($row['address'] ?? ''),
            'lead_time_days' => (int) ($row['lead_time_days'] ?? 0),
            'currency'       => (string) (($row['currency'] ?? 'GBP') !== '' ? $row['currency'] : 'GBP'),
            'po_mode'        => (string) (($row['po_mode'] ?? 'below_reorder') !== ''
                ? $row['po_mode'] : 'below_reorder'),
            'sold_days'      => max(1, (int) ($row['sold_days'] ?? 30)),
            'include_in_requisitions' => (bool) ($row['include_in_requisitions'] ?? true),
            'accepts_back_orders'     => (bool) ($row['accepts_back_orders'] ?? true),
            'min_order_value'         => (float) ($row['min_order_value'] ?? 0),
            'notes'          => (string) ($row['notes'] ?? ''),
            'active'         => (bool) ($row['active'] ?? true),
            'product_count'  => (int) ($row['product_count'] ?? 0),
            'po_count'       => (int) ($row['po_count'] ?? 0),
        ];
    }
}
