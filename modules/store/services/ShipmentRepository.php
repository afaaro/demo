<?php
declare(strict_types=1);

namespace Module\Store\Services;

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
        $row = $this->db->first('store_shipments', $this->withTenant(['id' => $id]));
        return $row !== [] ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findLatestForOrder(int $orderId): ?array
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_shipments`
              WHERE `order_id` = ? AND `tenant_id` = ?
                AND LOWER(`status`) <> 'voided'
              ORDER BY `parcel_index` ASC, `id` ASC
              LIMIT 1",
            [$orderId, $this->tenantId()]
        )->row;

        return $row !== [] ? $row : null;
    }

    /**
     * All shipments for an order (active first, then voided), parcel order.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrder(int $orderId): array
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        return $this->db->query(
            "SELECT * FROM `#__store_shipments`
              WHERE `order_id` = ? AND `tenant_id` = ?
              ORDER BY (LOWER(`status`) = 'voided') ASC, `parcel_index` ASC, `id` ASC",
            [$orderId, $this->tenantId()]
        )->rows;
    }

    /** Active (non-voided) shipment count for an order. */
    public function activeCountForOrder(int $orderId): int
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return 0;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_shipments`
              WHERE `order_id` = ? AND `tenant_id` = ?
                AND LOWER(`status`) <> 'voided'",
            [$orderId, $this->tenantId()]
        )->value;
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
        $id = (int) $this->db->insert('store_shipments', $this->withTenant([
            'order_id'           => (int) ($data['order_id'] ?? 0),
            'parcel_index'       => max(1, (int) ($data['parcel_index'] ?? 1)),
            'courier_channel_id' => (int) ($data['courier_channel_id'] ?? 0),
            'carrier_code'       => mb_substr((string) ($data['carrier_code'] ?? 'EVRI'), 0, 64),
            'barcode'            => mb_substr((string) ($data['barcode'] ?? ''), 0, 64),
            'tracking_number'    => mb_substr((string) ($data['tracking_number'] ?? ''), 0, 128),
            'client_uid'         => mb_substr((string) ($data['client_uid'] ?? ''), 0, 128),
            'label_path'         => mb_substr((string) ($data['label_path'] ?? ''), 0, 255),
            'status'             => mb_substr((string) ($data['status'] ?? 'created'), 0, 32),
            'carrier_cost'       => round((float) ($data['carrier_cost'] ?? 0), 2),
            'raw_response'       => $data['raw_response'] ?? null,
        ]));

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Failed to create shipment record.');
        }
        return $row;
    }

    public function markVoided(int $id): void
    {
        if ($this->find($id) === null) {
            return;
        }
        $this->db->update('store_shipments', [
            'status'  => 'voided',
            'barcode' => '',
        ], $this->withTenant(['id' => $id]));
    }

    public function updateLabelPath(int $id, string $relativePath): void
    {
        if ($this->find($id) === null) {
            return;
        }
        $this->db->update('store_shipments', [
            'label_path' => mb_substr($relativePath, 0, 255),
        ], $this->withTenant(['id' => $id]));
    }

    public function updateStatus(int $id, string $status, ?float $carrierCost = null): void
    {
        if ($this->find($id) === null) {
            return;
        }
        $allowed = ['created', 'in_transit', 'delivered', 'exception', 'voided'];
        $status = strtolower(trim($status));
        if (!in_array($status, $allowed, true)) {
            $status = 'created';
        }
        $payload = [
            'status'     => $status,
            'tracked_at' => date('Y-m-d H:i:s'),
        ];
        if ($carrierCost !== null) {
            $payload['carrier_cost'] = round(max(0, $carrierCost), 2);
        }
        $this->db->update('store_shipments', $payload, $this->withTenant(['id' => $id]));
    }

    /**
     * Open shipments that still need tracking updates.
     *
     * @return list<array<string, mixed>>
     */
    public function openForTracking(int $limit = 50): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        return $this->db->query(
            "SELECT * FROM `#__store_shipments`
              WHERE `tenant_id` = ?
                AND `tracking_number` <> ''
                AND LOWER(`status`) NOT IN ('delivered')
              ORDER BY COALESCE(`tracked_at`, `created_at`) ASC
              LIMIT {$limit}",
            [$this->tenantId()]
        )->rows;
    }
}
