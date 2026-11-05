<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;

final class PickwaveService
{
    public function __construct(
        private PickwaveRepository $pickwaves,
        private ?OrderDespatchService $despatch = null,
        private ?CourierShipmentService $courier = null,
    ) {}

    /**
     * Consolidated picking lines: SKU + title → total qty.
     *
     * @param  array<int, array{order: array<string, mixed>, items: array<int, array<string, mixed>>}> $orders
     * @return array<int, array{external_sku: string, title: string, qty: int}>
     */
    public function consolidatePickingLines(array $orders): array
    {
        /** @var array<string, array{external_sku: string, title: string, qty: int}> $map */
        $map = [];

        foreach ($orders as $entry) {
            foreach ($entry['items'] as $item) {
                $sku   = trim((string) ($item['external_sku'] ?? ''));
                $title = trim((string) ($item['title'] ?? ''));
                $qty   = max(1, (int) ($item['qty'] ?? 1));
                $key   = strtolower($sku !== '' ? $sku : ('title:' . $title));

                if (!isset($map[$key])) {
                    $map[$key] = [
                        'external_sku' => $sku,
                        'title'        => $title,
                        'qty'          => 0,
                    ];
                } elseif ($map[$key]['title'] === '' && $title !== '') {
                    $map[$key]['title'] = $title;
                }

                $map[$key]['qty'] += $qty;
            }
        }

        $lines = array_values($map);
        usort($lines, static function (array $a, array $b): int {
            $skuCmp = strcasecmp($a['external_sku'], $b['external_sku']);
            if ($skuCmp !== 0) {
                return $skuCmp;
            }
            return strcasecmp($a['title'], $b['title']);
        });

        return $lines;
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
     * Flat pick lines (one row per order item) — StoreFeeder Edit Pickwave table.
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
    public function despatch(int $pickwaveId, bool $createCourierLabels = true): array
    {
        $data = $this->pickwaves->find($pickwaveId);
        if ($data === null) {
            throw new RuntimeException('Pickwave not found.');
        }

        if (strtolower((string) ($data['wave']['status'] ?? '')) !== 'open') {
            throw new RuntimeException('Only open pickwaves can be despatched.');
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
            $method   = trim((string) ($order['shipping_method'] ?? ''));

            // Create a courier label when no tracking yet and a courier is connected.
            $wantLabel = $courierReady && ($tracking === '' || $carrier === '');

            if ($wantLabel) {
                try {
                    $prefer = self::preferredCourierTypes($method);
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
