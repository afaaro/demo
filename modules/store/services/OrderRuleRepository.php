<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class OrderRuleRepository
{
    use TenantScoped;

    public const TYPES = ['unpaid', 'bad_address', 'high_value', 'unmapped'];
    public const ACTIONS = ['hold', 'attention'];

    public function __construct(private Database $db) {}

    /** @return list<array<string, mixed>> */
    public function all(bool $enabledOnly = false): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $sql = "SELECT * FROM `#__store_order_rules` WHERE `tenant_id` = ?";
        $params = [$this->tenantId()];
        if ($enabledOnly) {
            $sql .= ' AND `enabled` = 1';
        }
        $sql .= ' ORDER BY `id` ASC';
        return $this->db->query($sql, $params)->rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('store_order_rules', $this->withTenant(['id' => $id]));
        return $row === [] ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $type = strtolower(trim((string) ($data['type'] ?? 'unpaid')));
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Invalid rule type.');
        }
        $action = strtolower(trim((string) ($data['action'] ?? 'hold')));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException('Invalid rule action.');
        }
        $this->db->insert('store_order_rules', $this->withTenant([
            'name'       => mb_substr(trim((string) ($data['name'] ?? '')), 0, 128),
            'type'       => $type,
            'threshold'  => round((float) ($data['threshold'] ?? 0), 2),
            'action'     => $action,
            'enabled'    => !empty($data['enabled']) ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]));
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($this->find($id) === null) {
            throw new RuntimeException('Order rule not found.');
        }
        $type = strtolower(trim((string) ($data['type'] ?? 'unpaid')));
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Invalid rule type.');
        }
        $action = strtolower(trim((string) ($data['action'] ?? 'hold')));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException('Invalid rule action.');
        }
        $this->db->update('store_order_rules', [
            'name'       => mb_substr(trim((string) ($data['name'] ?? '')), 0, 128),
            'type'       => $type,
            'threshold'  => round((float) ($data['threshold'] ?? 0), 2),
            'action'     => $action,
            'enabled'    => !empty($data['enabled']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $this->withTenant(['id' => $id]));
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "DELETE FROM `#__store_order_rules` WHERE `id` = ? AND `tenant_id` = ?",
            [$id, $this->tenantId()]
        );
    }
}
