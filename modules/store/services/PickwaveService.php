<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class PickwaveService
{
    public function __construct(
        private PickwaveRepository $pickwaves,
        private ?OrderDespatchService $despatch = null,
        private ?CourierShipmentService $courier = null,
        private ?Database $db = null,
    ) {}

    /**
     * Consolidated picking lines: SKU + title → total qty, sorted by pick bin.
     *
     * @param  array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}> $orders
     * @return array<int, array{external_sku: string, title: string, qty: int, pick_bin: string, variant_id: int}>
     */
    public function consolidatePickingLines(array $orders): array
    {
        /** @var array<string, array{external_sku: string, title: string, qty: int, pick_bin: string, variant_id: int}> $map */
        $map = [];
        $variantIds = [];
        $bundles = $this->db !== null ? new ProductBundleRepository($this->db) : null;

        foreach ($orders as $entry) {
            // Prefer orders whose warehouse matches wave context when set on order.
            foreach ($entry['items'] as $item) {
                $expanded = $this->expandKitPickLines($item, $bundles);
                foreach ($expanded as $pick) {
                    $sku   = trim((string) ($pick['external_sku'] ?? ''));
                    $title = trim((string) ($pick['title'] ?? ''));
                    $qty   = max(0, (int) ($pick['qty'] ?? 0));
                    if ($qty <= 0) {
                        continue;
                    }
                    $vid   = (int) ($pick['variant_id'] ?? 0);
                    $key   = strtolower($sku !== '' ? $sku : ('title:' . $title));

                    if (!isset($map[$key])) {
                        $map[$key] = [
                            'external_sku' => $sku,
                            'title'        => $title,
                            'qty'          => 0,
                            'pick_bin'     => '',
                            'variant_id'   => $vid,
                        ];
                    } elseif ($map[$key]['title'] === '' && $title !== '') {
                        $map[$key]['title'] = $title;
                    }
                    if ($map[$key]['variant_id'] <= 0 && $vid > 0) {
                        $map[$key]['variant_id'] = $vid;
                    }
                    if ($vid > 0) {
                        $variantIds[$vid] = true;
                    }

                    $map[$key]['qty'] += $qty;
                }
            }
        }

        $bins = $this->pickBinsForVariants(array_keys($variantIds));
        foreach ($map as $key => $line) {
            $vid = (int) ($line['variant_id'] ?? 0);
            if ($vid > 0 && isset($bins[$vid])) {
                $map[$key]['pick_bin'] = $bins[$vid];
            }
        }

        $lines = array_values($map);
        usort($lines, static function (array $a, array $b): int {
            $binA = (string) ($a['pick_bin'] ?? '');
            $binB = (string) ($b['pick_bin'] ?? '');
            // Empty bins last.
            if ($binA === '' && $binB !== '') {
                return 1;
            }
            if ($binA !== '' && $binB === '') {
                return -1;
            }
            $binCmp = strcasecmp($binA, $binB);
            if ($binCmp !== 0) {
                return $binCmp;
            }
            $skuCmp = strcasecmp($a['external_sku'], $b['external_sku']);
            if ($skuCmp !== 0) {
                return $skuCmp;
            }
            return strcasecmp($a['title'], $b['title']);
        });

        return $lines;
    }

    /**
     * Expand kit parent lines into component pick lines when bundle components exist
     * and the kit parent was not already replaced by allocated component rows.
     *
     * @param array<string, mixed> $item
     * @return list<array{external_sku:string, title:string, qty:int, variant_id:int}>
     */
    private function expandKitPickLines(array $item, ?ProductBundleRepository $bundles): array
    {
        $sku = trim((string) ($item['external_sku'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $ordered = max(0, (int) ($item['qty'] ?? 1));
        $shipped = max(0, (int) ($item['qty_shipped'] ?? 0));
        $qty = max(0, $ordered - $shipped);
        $vid = (int) ($item['variant_id'] ?? 0);
        $base = [[
            'external_sku' => $sku,
            'title'        => $title,
            'qty'          => $qty,
            'variant_id'   => $vid,
        ]];
        if ($qty <= 0 || $bundles === null || $this->db === null) {
            return $base;
        }
        // Already a kit component line — keep as-is.
        if (trim((string) ($item['kit_sku'] ?? '')) !== '') {
            return $base;
        }
        $productId = (int) ($item['product_id'] ?? 0);
        if ($productId <= 0 && $vid > 0) {
            $row = $this->db->query(
                'SELECT `product_id` FROM `#__store_product_variants` WHERE `id` = ? LIMIT 1',
                [$vid]
            )->row;
            $productId = (int) ($row['product_id'] ?? 0);
        }
        if ($productId <= 0) {
            return $base;
        }
        $components = $bundles->forProduct($productId);
        if ($components === []) {
            return $base;
        }
        $out = [];
        foreach ($components as $comp) {
            $compSku = trim((string) ($comp['component_sku'] ?? ''));
            $compQty = $qty * max(1, (int) $comp['qty']);
            $compVid = 0;
            if ($compSku !== '') {
                $vrow = $this->db->query(
                    'SELECT `id` FROM `#__store_product_variants` WHERE `sku` = ? LIMIT 1',
                    [$compSku]
                )->row;
                $compVid = (int) ($vrow['id'] ?? 0);
            }
            if ($compVid <= 0 && (int) $comp['component_product_id'] > 0) {
                $vrow = $this->db->query(
                    'SELECT `id`, `sku` FROM `#__store_product_variants`
                      WHERE `product_id` = ? ORDER BY `id` ASC LIMIT 1',
                    [(int) $comp['component_product_id']]
                )->row;
                $compVid = (int) ($vrow['id'] ?? 0);
                if ($compSku === '') {
                    $compSku = (string) ($vrow['sku'] ?? '');
                }
            }
            $out[] = [
                'external_sku' => $compSku !== '' ? $compSku : $sku,
                'title'        => (string) ($comp['component_name'] !== '' ? $comp['component_name'] : $compSku),
                'qty'          => $compQty,
                'variant_id'   => $compVid,
            ];
        }
        return $out !== [] ? $out : $base;
    }

    /**
     * @param list<int> $variantIds
     * @return array<int, string> variant_id => pick_bin
     */
    private function pickBinsForVariants(array $variantIds): array
    {
        $variantIds = array_values(array_filter(array_map('intval', $variantIds), static fn(int $id) => $id > 0));
        if ($variantIds === [] || $this->db === null) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $rows = $this->db->query(
            "SELECT `id`, `pick_bin` FROM `#__store_product_variants`
              WHERE `id` IN ({$placeholders})",
            $variantIds
        )->rows;
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = trim((string) ($row['pick_bin'] ?? ''));
        }
        return $out;
    }

    public const STAGE_PICK = 'pick';
    public const STAGE_PACK = 'pack';

    /**
     * Confirmed scan qty by normalized SKU key for a pickwave stage.
     *
     * @return array<string, int>
     */
    public function scanTotals(int $pickwaveId, string $stage = self::STAGE_PICK): array
    {
        if ($pickwaveId <= 0 || $this->db === null) {
            return [];
        }
        $stage = $this->normalizeStage($stage);
        $rows = $this->db->query(
            "SELECT `sku_key`, SUM(`qty`) AS qty
               FROM `#__store_pickwave_scans`
              WHERE `pickwave_id` = ?
                AND COALESCE(NULLIF(`stage`, ''), 'pick') = ?
              GROUP BY `sku_key`",
            [$pickwaveId, $stage]
        )->rows;
        $out = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row['sku_key'] ?? '')));
            if ($key === '') {
                continue;
            }
            $out[$key] = (int) ($row['qty'] ?? 0);
        }
        return $out;
    }

    public function recordScan(
        int $pickwaveId,
        string $sku,
        int $qty,
        ?int $userId = null,
        string $stage = self::STAGE_PICK,
    ): void {
        if ($this->db === null) {
            throw new RuntimeException('Database required for scan confirm.');
        }
        $stage = $this->normalizeStage($stage);
        $data = $this->pickwaves->find($pickwaveId);
        if ($data === null) {
            throw new RuntimeException('Pickwave not found.');
        }
        if (strtolower((string) ($data['wave']['status'] ?? '')) !== 'open') {
            throw new RuntimeException('Only open pickwaves accept scans.');
        }
        $sku = trim($sku);
        $qty = max(1, $qty);
        if ($sku === '') {
            throw new RuntimeException('SKU is required.');
        }
        $key = strtolower($sku);
        $lines = $this->consolidatePickingLines($data['orders']);
        $needed = 0;
        foreach ($lines as $line) {
            if (strtolower((string) $line['external_sku']) === $key) {
                $needed = (int) $line['qty'];
                break;
            }
        }
        if ($needed <= 0) {
            throw new RuntimeException('SKU not on this pickwave.');
        }
        $totals = $this->scanTotals($pickwaveId, $stage);
        $already = $totals[$key] ?? 0;
        if ($already + $qty > $needed) {
            throw new RuntimeException('Scan would exceed ordered qty (' . $needed . ').');
        }

        $payload = [
            'pickwave_id' => $pickwaveId,
            'sku_key'     => $key,
            'sku'         => mb_substr($sku, 0, 128),
            'qty'         => $qty,
            'stage'       => $stage,
            'user_id'     => $userId,
            'created_at'  => date('Y-m-d H:i:s'),
        ];
        if (TenantContext::get()->has()) {
            $payload['tenant_id'] = TenantContext::get()->requireId();
        }
        $this->db->insert('store_pickwave_scans', $payload);
    }

    public function recordPackScan(int $pickwaveId, string $sku, int $qty, ?int $userId = null): void
    {
        $this->recordScan($pickwaveId, $sku, $qty, $userId, self::STAGE_PACK);
    }

    /**
     * @return array{complete: bool, needed: int, scanned: int, missing: list<array{sku: string, needed: int, scanned: int}>}
     */
    public function scanProgress(int $pickwaveId, string $stage = self::STAGE_PICK): array
    {
        $data = $this->pickwaves->find($pickwaveId);
        if ($data === null) {
            throw new RuntimeException('Pickwave not found.');
        }
        $stage = $this->normalizeStage($stage);
        $lines = $this->consolidatePickingLines($data['orders']);
        $totals = $this->scanTotals($pickwaveId, $stage);
        $needed = 0;
        $scanned = 0;
        $missing = [];
        foreach ($lines as $line) {
            $sku = (string) $line['external_sku'];
            $key = strtolower($sku !== '' ? $sku : ('title:' . $line['title']));
            $need = (int) $line['qty'];
            $got = (int) ($totals[strtolower($sku)] ?? $totals[$key] ?? 0);
            $needed += $need;
            $scanned += min($got, $need);
            if ($got < $need && $sku !== '') {
                $missing[] = ['sku' => $sku, 'needed' => $need, 'scanned' => $got];
            }
        }
        return [
            'complete' => $missing === [],
            'needed'   => $needed,
            'scanned'  => $scanned,
            'missing'  => $missing,
        ];
    }

    /**
     * @return array{complete: bool, needed: int, scanned: int, missing: list<array{sku: string, needed: int, scanned: int}>}
     */
    public function packProgress(int $pickwaveId): array
    {
        return $this->scanProgress($pickwaveId, self::STAGE_PACK);
    }

    private function normalizeStage(string $stage): string
    {
        $stage = strtolower(trim($stage));
        return $stage === self::STAGE_PACK ? self::STAGE_PACK : self::STAGE_PICK;
    }

    /**
     * Packing payloads with decoded shipping address.
     *
     * @param  array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}> $orders
     * @return array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>, address: array<string, mixed>}>
     */
    public function packingPayloads(array $orders): array
    {
        $out = [];
        foreach ($orders as $entry) {
            $raw = $entry['order']['shipping_address'] ?? [];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $address = is_array($decoded) ? $decoded : [];
            } elseif (is_array($raw)) {
                $address = $raw;
            } else {
                $address = [];
            }

            $out[] = [
                'order'   => $entry['order'],
                'items'   => $entry['items'],
                'address' => $address,
            ];
        }
        return $out;
    }

    /**
     * Flat pick lines (one row per order item) — Store Edit Pickwave table.
     *
     * @param  array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}> $orders
     * @return array<int, array{order: array<string, mixed>, item: array<string, mixed>}>
     */
    public function flatPickLines(array $orders): array
    {
        $out = [];
        foreach ($orders as $entry) {
            foreach ($entry['items'] as $item) {
                $out[] = [
                    'order' => $entry['order'],
                    'item'  => $item,
                ];
            }
        }
        return $out;
    }

    /** True when buyer shipping method looks like Evri / Hermes. */
    public static function isEvriShippingMethod(string $method): bool
    {
        $m = strtoupper(trim($method));
        if ($m === '') {
            return false;
        }
        return str_contains($m, 'HERMES') || str_contains($m, 'EVRI');
    }

    /**
     * Prefer courier types that match the buyer shipping method.
     *
     * @return list<string>
     */
    public static function preferredCourierTypes(string $method): array
    {
        $m = strtolower(trim($method));
        $prefer = [];
        if ($m === '' || str_contains($m, 'evri') || str_contains($m, 'hermes')) {
            $prefer[] = 'evri';
        }
        if ($m === '' || str_contains($m, 'royal') || str_contains($m, 'tracked') || (bool) preg_match('/\brm\b/', $m)) {
            $prefer[] = 'royalmail';
        }
        if ($m === '' || str_contains($m, 'parcel') || str_contains($m, 'pfw')) {
            $prefer[] = 'parcelforce';
        }
        if ($m === '' || str_contains($m, 'dpd')) {
            $prefer[] = 'dpd';
        }
        return $prefer !== [] ? array_values(array_unique($prefer)) : CourierShipmentService::COURIER_TYPES;
    }

    /**
     * Complete the pickwave, optionally create courier labels, mark orders fulfilled, notify channels.
     *
     * @return array{
     *   order_count: int,
     *   notified: int,
     *   local_only: int,
     *   labels: int,
     *   label_errors: int
     * }
     */
    /**
     * @param 'hard'|'soft'|'off' $packConfirmMode hard blocks despatch until pack scans complete (default).
     */
    public function despatch(
        int $pickwaveId,
        bool $createCourierLabels = true,
        string $packConfirmMode = 'hard',
    ): array {
        $data = $this->pickwaves->find($pickwaveId);
        if ($data === null) {
            throw new RuntimeException('Pickwave not found.');
        }

        if (strtolower((string) ($data['wave']['status'] ?? '')) !== 'open') {
            throw new RuntimeException('Only open pickwaves can be despatched.');
        }

        $progress = $this->scanProgress($pickwaveId, self::STAGE_PICK);
        if (!$progress['complete'] && $progress['needed'] > 0) {
            $left = count($progress['missing']);
            throw new RuntimeException(
                'Cannot despatch: ' . $left . ' SKU line(s) still need pick scan confirmation '
                . '(' . $progress['scanned'] . '/' . $progress['needed'] . ' units).'
            );
        }

        $packConfirmMode = strtolower(trim($packConfirmMode));
        if ($packConfirmMode !== 'soft' && $packConfirmMode !== 'off') {
            $pack = $this->packProgress($pickwaveId);
            if (!$pack['complete'] && $pack['needed'] > 0) {
                $left = count($pack['missing']);
                throw new RuntimeException(
                    'Cannot despatch: ' . $left . ' SKU line(s) still need pack scan confirmation '
                    . '(' . $pack['scanned'] . '/' . $pack['needed'] . ' units).'
                );
            }
        }

        $notified     = 0;
        $localOnly    = 0;
        $labels       = 0;
        $labelErrors  = 0;
        $courierReady = $createCourierLabels
            && $this->courier !== null
            && $this->courier->findDefaultCourierChannel() !== null;

        foreach ($data['orders'] as $entry) {
            $order = $entry['order'];
            $items = $entry['items'];
            $tracking = trim((string) ($order['tracking_number'] ?? ''));
            $carrier  = trim((string) ($order['carrier_code'] ?? ''));
            $method   = trim((string) (
                $order['mapped_shipping_method']
                ?? $order['shipping_method']
                ?? ''
            ));
            $preferredCourier = trim((string) ($order['preferred_courier'] ?? ''));

            // Ensure packaging / shipping rules before label when possible.
            if ($this->db !== null && (int) ($order['id'] ?? 0) > 0) {
                try {
                    if ((float) ($order['order_weight_kg'] ?? 0) <= 0
                        || (int) ($order['packaging_size_id'] ?? 0) <= 0
                    ) {
                        $pack = (new OrderPackagingService($this->db))->suggestAndPersist((int) $order['id']);
                        $order['order_weight_kg'] = $pack['order_weight_kg'];
                        if ($pack['packaging_size_id'] !== null) {
                            $order['packaging_size_id'] = $pack['packaging_size_id'];
                        }
                    }
                    if ($preferredCourier === '' || $method === '') {
                        $applied = (new ShippingRuleApplyService($this->db))->applyToOrder($order);
                        if ($applied !== null) {
                            $method = $applied['mapped_shipping_method'];
                            $preferredCourier = $applied['preferred_courier'];
                            $order['mapped_shipping_method'] = $method;
                            $order['preferred_courier'] = $preferredCourier;
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('Pickwave pre-despatch packaging/rules: ' . $e->getMessage());
                }
            }

            // Create a courier label when no tracking yet and a courier is connected.
            $wantLabel = $courierReady && ($tracking === '' || $carrier === '');

            if ($wantLabel) {
                try {
                    $prefer = $preferredCourier !== ''
                        ? [$preferredCourier]
                        : self::preferredCourierTypes($method);
                    $courierChannel = $this->courier->findDefaultCourierChannel($prefer);
                    $created  = $this->courier->createCourierShipment($order, $items, $courierChannel);
                    $tracking = $created['tracking_number'];
                    $carrier  = $created['carrier_code'];
                    $labels++;
                    $order['tracking_number'] = $tracking;
                    $order['carrier_code']    = $carrier;
                } catch (\Throwable $e) {
                    $labelErrors++;
                    error_log(
                        'Pickwave courier label order #' . ($order['id'] ?? '?') . ': ' . $e->getMessage()
                    );
                }
            }

            if ($this->despatch !== null && $tracking !== '' && $carrier !== '') {
                try {
                    $result = $this->despatch->despatchOrder($order, $items, $tracking, $carrier);
                    if (!empty($result['notified'])) {
                        $notified++;
                    } else {
                        $localOnly++;
                    }
                    continue;
                } catch (\Throwable $e) {
                    error_log('Pickwave despatch order #' . ($order['id'] ?? '?') . ': ' . $e->getMessage());
                    // Fall through to local fulfill.
                }
            }

            // Persist tracking from Evri even when channel notify was skipped/failed.
            if ($tracking !== '' && $carrier !== '') {
                $this->pickwaves->markOrderFulfilledWithTracking(
                    (int) $order['id'],
                    $tracking,
                    $carrier,
                );
            } else {
                $this->pickwaves->markOrdersFulfilled([(int) $order['id']]);
            }
            $localOnly++;
        }

        $this->pickwaves->markCompleted($pickwaveId);

        return [
            'order_count'  => count($data['orders']),
            'notified'     => $notified,
            'local_only'   => $localOnly,
            'labels'       => $labels,
            'label_errors' => $labelErrors,
        ];
    }
}
