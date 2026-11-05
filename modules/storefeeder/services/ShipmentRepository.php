<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;
use System\Library\Database;

final class ShipmentRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('sf_shipments', $this->withTenant(['id' => $id]));
        return $row !== [] ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findLatestForOrder(int $orderId): ?array
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__sf_shipments`
              WHERE `order_id` = ? AND `tenant_id` = ?
              ORDER BY `id` DESC
              LIMIT 1",
            [$orderId, $this->tenantId()]
        )->row;

        return $row !== [] ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $id = (int) $this->db->insert('sf_shipments', $this->withTenant([
            'order_id'           => (int) ($data['order_id'] ?? 0),
            'courier_channel_id' => (int) ($data['courier_channel_id'] ?? 0),
            'carrier_code'       => mb_substr((string) ($data['carrier_code'] ?? 'EVRI'), 0, 64),
            'barcode'            => mb_substr((string) ($data['barcode'] ?? ''), 0, 64),
            'tracking_number'    => mb_substr((string) ($data['tracking_number'] ?? ''), 0, 128),
            'client_uid'         => mb_substr((string) ($data['client_uid'] ?? ''), 0, 128),
            'label_path'         => mb_substr((string) ($data['label_path'] ?? ''), 0, 255),
            'status'             => mb_substr((string) ($data['status'] ?? 'created'), 0, 32),
            'raw_response'       => $data['raw_response'] ?? null,
        ]));

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Failed to create shipment record.');
        }
        return $row;
    }

    public function updateLabelPath(int $id, string $relativePath): void
    {
        if ($this->find($id) === null) {
            return;
        }
        $this->db->update('sf_shipments', [
            'label_path' => mb_substr($relativePath, 0, 255),
        ], $this->withTenant(['id' => $id]));
    }
}
