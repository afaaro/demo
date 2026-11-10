<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class SupplierClaimRepository
{
    use TenantScoped;

    public const STATUSES = ['open', 'chasing', 'resolved'];

    public function __construct(private Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $status = '', int $limit = 200): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $params = [$this->tenantId()];
        $sql = "SELECT c.*,
                       po.`reference` AS po_reference,
                       s.`name` AS supplier_name
                  FROM `#__store_supplier_claims` c
             LEFT JOIN `#__store_purchase_orders` po
                    ON po.`id` = c.`po_id` AND po.`tenant_id` = c.`tenant_id`
             LEFT JOIN `#__store_suppliers` s
                    ON s.`id` = c.`supplier_id` AND s.`tenant_id` = c.`tenant_id`
                 WHERE c.`tenant_id` = ?";
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $sql .= ' AND c.`status` = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY c.`id` DESC LIMIT ?';
        $params[] = $limit;

        return array_map([$this, 'hydrate'], $this->db->query($sql, $params)->rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT c.*, po.`reference` AS po_reference, s.`name` AS supplier_name
               FROM `#__store_supplier_claims` c
          LEFT JOIN `#__store_purchase_orders` po
                 ON po.`id` = c.`po_id` AND po.`tenant_id` = c.`tenant_id`
          LEFT JOIN `#__store_suppliers` s
                 ON s.`id` = c.`supplier_id` AND s.`tenant_id` = c.`tenant_id`
              WHERE c.`id` = ? AND c.`tenant_id` = ?
              LIMIT 1",
            [$id, $this->tenantId()]
        )->row;
        return $row === [] ? null : $this->hydrate($row);
    }

    /**
     * Create a claim from a PO shortfall / write-off line.
     *
     * @return array<string, mixed>
     */
    public function createFromShortfall(
        int $poId,
        int $poLineId,
        int $supplierId,
        int $qty,
        string $reason = 'shortfall',
        string $notes = '',
    ): array {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $qty = max(1, $qty);
        $id = (int) $this->db->insert('store_supplier_claims', $this->withTenant([
            'po_id'       => $poId,
            'po_line_id'  => $poLineId,
            'supplier_id' => $supplierId,
            'qty'         => $qty,
            'reason'      => mb_substr(trim($reason) !== '' ? trim($reason) : 'shortfall', 0, 255),
            'status'      => 'open',
            'notes'       => $notes,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]));
        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Failed to create supplier claim.');
        }
        return $row;
    }

    public function updateStatus(int $id, string $status, string $notes = ''): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Invalid claim status.');
        }
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Claim not found.');
        }
        $payload = [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($notes !== '') {
            $payload['notes'] = $notes;
        }
        $this->db->update('store_supplier_claims', $payload, $this->withTenant(['id' => $id]));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'tenant_id'     => (int) ($row['tenant_id'] ?? 0),
            'po_id'         => (int) ($row['po_id'] ?? 0),
            'po_line_id'    => (int) ($row['po_line_id'] ?? 0),
            'supplier_id'   => (int) ($row['supplier_id'] ?? 0),
            'qty'           => (int) ($row['qty'] ?? 0),
            'reason'        => (string) ($row['reason'] ?? ''),
            'status'        => (string) ($row['status'] ?? 'open'),
            'notes'         => (string) ($row['notes'] ?? ''),
            'po_reference'  => (string) ($row['po_reference'] ?? ''),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'created_at'    => (string) ($row['created_at'] ?? ''),
            'updated_at'    => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
