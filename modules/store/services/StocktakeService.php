<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class StocktakeService
{
    use TenantScoped;

    public function __construct(
        private Database $db,
        private ?InventoryService $inventory = null,
    ) {
        $this->inventory ??= new InventoryService($db);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $status = '', int $limit = 100): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $params = [$this->tenantId()];
        $sql = "SELECT * FROM `#__store_stocktakes` WHERE `tenant_id` = ?";
        if ($status !== '') {
            $sql .= ' AND `status` = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY `id` DESC LIMIT ' . $limit;
        return $this->db->query($sql, $params)->rows;
    }

    /** @return array{session: array<string, mixed>, lines: list<array<string, mixed>}|null} */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $session = $this->db->query(
            "SELECT * FROM `#__store_stocktakes` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
            [$id, $this->tenantId()]
        )->row;
        if ($session === [] || $session === null) {
            return null;
        }
        $lines = $this->db->query(
            "SELECT * FROM `#__store_stocktake_lines`
              WHERE `stocktake_id` = ? AND `tenant_id` = ?
              ORDER BY `sku` ASC, `id` ASC",
            [$id, $this->tenantId()]
        )->rows;
        return ['session' => $session, 'lines' => $lines];
    }

    public function create(string $name, string $warehouse = '', ?int $userId = null): int
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $name = trim($name);
        if ($name === '') {
            $name = 'Stocktake ' . date('Y-m-d H:i');
        }
        $this->db->insert('store_stocktakes', $this->withTenant([
            'name'       => mb_substr($name, 0, 128),
            'warehouse'  => mb_substr(trim($warehouse), 0, 128),
            'status'     => 'open',
            'notes'      => '',
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]));
        return (int) $this->db->lastInsertId();
    }

    /**
     * Seed lines from current inventory (optional warehouse filter via product.warehouse if present).
     *
     * @return int lines added
     */
    public function seedFromInventory(int $stocktakeId, int $limit = 500): int
    {
        $data = $this->find($stocktakeId);
        if ($data === null) {
            throw new RuntimeException('Stocktake not found.');
        }
        if (strtolower((string) $data['session']['status']) !== 'open') {
            throw new RuntimeException('Only open stocktakes can be seeded.');
        }

        $limit = max(1, min(2000, $limit));
        $warehouse = trim((string) ($data['session']['warehouse'] ?? ''));
        $params = [$this->tenantId()];
        $warehouseId = 0;
        if ($warehouse !== '') {
            try {
                $whRepo = new WarehouseRepository($this->db);
                $canonical = $whRepo->ensure($warehouse);
                $wh = $whRepo->findByName($canonical);
                $warehouseId = (int) ($wh['id'] ?? 0);
            } catch (\Throwable) {
                $warehouseId = 0;
            }
        }

        if ($warehouseId > 0) {
            $sql = "SELECT v.`id` AS variant_id, v.`sku`, p.`name` AS title,
                           COALESCE(loc.`quantity`, inv.`quantity`, 0) AS system_qty
                      FROM `#__store_product_variants` v
                      INNER JOIN `#__store_products` p
                        ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
                      LEFT JOIN `#__store_inventory` inv
                        ON inv.`variant_id` = v.`id` AND inv.`tenant_id` = v.`tenant_id`
                      LEFT JOIN `#__store_inventory_locations` loc
                        ON loc.`variant_id` = v.`id` AND loc.`warehouse_id` = ?
                       AND loc.`tenant_id` = v.`tenant_id`
                     WHERE v.`tenant_id` = ?
                       AND v.`active` = 1
                       AND p.`active` = 1
                       AND (p.`warehouse` = ? OR loc.`id` IS NOT NULL)
                     ORDER BY v.`sku` ASC LIMIT " . $limit;
            $params = [$warehouseId, $this->tenantId(), $warehouse];
        } else {
            $sql = "SELECT v.`id` AS variant_id, v.`sku`, p.`name` AS title,
                           COALESCE(inv.`quantity`, 0) AS system_qty
                      FROM `#__store_product_variants` v
                      INNER JOIN `#__store_products` p
                        ON p.`id` = v.`product_id` AND p.`tenant_id` = v.`tenant_id`
                      LEFT JOIN `#__store_inventory` inv
                        ON inv.`variant_id` = v.`id` AND inv.`tenant_id` = v.`tenant_id`
                     WHERE v.`tenant_id` = ?
                       AND v.`active` = 1
                       AND p.`active` = 1";
            if ($warehouse !== '') {
                $sql .= ' AND p.`warehouse` = ?';
                $params[] = $warehouse;
            }
            $sql .= ' ORDER BY v.`sku` ASC LIMIT ' . $limit;
        }
        $rows = $this->db->query($sql, $params)->rows;

        $added = 0;
        foreach ($rows as $row) {
            $vid = (int) ($row['variant_id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $exists = $this->db->query(
                "SELECT `id` FROM `#__store_stocktake_lines`
                  WHERE `stocktake_id` = ? AND `variant_id` = ? AND `tenant_id` = ?
                  LIMIT 1",
                [$stocktakeId, $vid, $this->tenantId()]
            )->row;
            if ($exists !== [] && $exists !== null) {
                continue;
            }
            $this->db->insert('store_stocktake_lines', $this->withTenant([
                'stocktake_id' => $stocktakeId,
                'variant_id'   => $vid,
                'sku'          => mb_substr((string) ($row['sku'] ?? ''), 0, 128),
                'title'        => mb_substr((string) ($row['title'] ?? ''), 0, 255),
                'system_qty'   => (int) ($row['system_qty'] ?? 0),
                'counted_qty'  => null,
                'notes'        => '',
                'created_at'   => date('Y-m-d H:i:s'),
            ]));
            $added++;
        }
        return $added;
    }

    public function setCountedQty(int $lineId, int $countedQty, string $notes = ''): void
    {
        if ($lineId <= 0 || !TenantContext::get()->has()) {
            throw new RuntimeException('Invalid line.');
        }
        $line = $this->db->query(
            "SELECT l.*, s.`status` AS session_status
               FROM `#__store_stocktake_lines` l
               INNER JOIN `#__store_stocktakes` s
                 ON s.`id` = l.`stocktake_id` AND s.`tenant_id` = l.`tenant_id`
              WHERE l.`id` = ? AND l.`tenant_id` = ?
              LIMIT 1",
            [$lineId, $this->tenantId()]
        )->row;
        if ($line === [] || $line === null) {
            throw new RuntimeException('Line not found.');
        }
        if (strtolower((string) ($line['session_status'] ?? '')) !== 'open') {
            throw new RuntimeException('Stocktake is not open.');
        }
        $this->db->update('store_stocktake_lines', [
            'counted_qty' => max(0, $countedQty),
            'notes'       => mb_substr(trim($notes), 0, 255),
        ], $this->withTenant(['id' => $lineId]));
    }

    /**
     * Finalize: adjust inventory by (counted - system) with reason stocktake.
     *
     * @return array{adjusted: int, skipped: int}
     */
    public function finalize(int $stocktakeId, ?int $userId = null): array
    {
        $data = $this->find($stocktakeId);
        if ($data === null) {
            throw new RuntimeException('Stocktake not found.');
        }
        if (strtolower((string) $data['session']['status']) !== 'open') {
            throw new RuntimeException('Stocktake already finalized.');
        }

        $warehouseName = trim((string) ($data['session']['warehouse'] ?? ''));
        $warehouseId = 0;
        if ($warehouseName !== '') {
            try {
                $whRepo = new WarehouseRepository($this->db);
                $canonical = $whRepo->ensure($warehouseName);
                $wh = $whRepo->findByName($canonical);
                $warehouseId = (int) ($wh['id'] ?? 0);
            } catch (\Throwable) {
                $warehouseId = 0;
            }
        }

        $adjusted = 0;
        $skipped = 0;
        foreach ($data['lines'] as $line) {
            if ($line['counted_qty'] === null || $line['counted_qty'] === '') {
                $skipped++;
                continue;
            }
            $vid = (int) ($line['variant_id'] ?? 0);
            $delta = (int) $line['counted_qty'] - (int) $line['system_qty'];
            if ($vid <= 0 || $delta === 0) {
                $skipped++;
                continue;
            }
            $this->inventory->adjust(
                $vid,
                $delta,
                'stocktake',
                'Stocktake #' . $stocktakeId . ' line #' . (int) $line['id'],
                $userId,
            );
            if ($warehouseId > 0) {
                $this->inventory->adjustAtWarehouse(
                    $vid,
                    $warehouseId,
                    $delta,
                    'stocktake',
                    'Stocktake #' . $stocktakeId . ' @ ' . $warehouseName,
                    $userId,
                    false
                );
            }
            $adjusted++;
        }

        $this->db->update('store_stocktakes', [
            'status'       => 'finalized',
            'finalized_at' => date('Y-m-d H:i:s'),
        ], $this->withTenant(['id' => $stocktakeId]));

        return ['adjusted' => $adjusted, 'skipped' => $skipped];
    }
}
