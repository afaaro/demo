<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

/**
 * Mark orders shipped locally and optionally notify the sales channel.
 * Supports partial line quantities (qty_shipped).
 */
final class OrderDespatchService
{
    use TenantScoped;

    public function __construct(
        private Database             $db,
        private ChannelRepository    $channels,
        private ChannelClientFactory $factory,
    ) {}

    /**
     * @param array<string, mixed> $order  store_orders row
     * @param array<int, array<string, mixed>> $items
     * @param array<int, int>|null $shipQtys  order_item_id => qty to ship this time (null = remaining)
     * @return array{
     *   notified: bool,
     *   local: bool,
     *   status: string,
     *   skipped_reason?: string,
     *   shipped_lines: int
     * }
     */
    public function despatchOrder(
        array $order,
        array $items,
        string $tracking,
        string $carrier,
        bool $forceNotify = false,
        ?array $shipQtys = null,
        ?array $serials = null,
    ): array {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            throw new RuntimeException('Invalid order.');
        }
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        if ((int) ($order['tenant_id'] ?? 0) !== $this->tenantId()) {
            throw new RuntimeException('Invalid order.');
        }

        $channel = $this->channels->find((int) ($order['channel_id'] ?? 0));
        if ($channel === null) {
            throw new RuntimeException('Channel not found for order.');
        }

        // Resolve shippable lines / quantities.
        $toShip = [];
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? 0);
            $ordered = max(0, (int) ($item['qty'] ?? $item['order_qty'] ?? 0));
            $already = max(0, (int) ($item['qty_shipped'] ?? 0));
            $remaining = max(0, $ordered - $already);
            if ($remaining <= 0) {
                continue;
            }
            $want = $remaining;
            if ($shipQtys !== null) {
                if ($itemId <= 0 || !array_key_exists($itemId, $shipQtys)) {
                    continue;
                }
                $want = max(0, min($remaining, (int) $shipQtys[$itemId]));
            }
            if ($want <= 0) {
                continue;
            }
            $toShip[] = [
                'item'     => $item,
                'item_id'  => $itemId,
                'qty'      => $want,
                'variant_id' => (int) ($item['variant_id'] ?? 0),
            ];
        }

        if ($toShip === []) {
            throw new RuntimeException('No remaining quantity to ship.');
        }

        $this->assertSerialsCaptured($orderId, $toShip, $serials);

        // eBay (and similar) combine tracking + status in one call — notify if either toggle is on.
        $wantNotify = $forceNotify
            || ChannelSettings::bool($channel, 'send_despatch_notifications', true)
            || ChannelSettings::bool($channel, 'update_channel_status', true);

        $carrierUpper = strtoupper($carrier);
        $isRoyalMail = str_contains($carrierUpper, 'ROYAL')
            || str_contains($carrierUpper, 'RM')
            || $carrierUpper === 'ROYAL_MAIL';
        if ($isRoyalMail
            && !ChannelSettings::bool($channel, 'send_royal_mail_delivery_confirmation', true)
        ) {
            $wantNotify = false;
        }

        $notified = false;
        $skipped  = null;

        if (!$wantNotify) {
            $skipped = $isRoyalMail
                && !ChannelSettings::bool($channel, 'send_royal_mail_delivery_confirmation', true)
                ? 'Royal Mail delivery confirmation is off for this integration.'
                : 'Despatch notifications disabled for this integration.';
        } elseif (!$channel->isConnected()) {
            $skipped = 'Channel not connected — marked locally only.';
        } else {
            $client = $this->factory->make($channel);
            if (!method_exists($client, 'shipOrder')) {
                $skipped = 'Channel type does not support shipping fulfillment.';
            } else {
                $lineItems = [];
                foreach ($toShip as $row) {
                    $item = $row['item'];
                    $lineId = trim((string) ($item['external_line_item_id'] ?? ''));
                    if ($lineId === '') {
                        throw new RuntimeException(
                            'Missing channel line item IDs. Sync orders again, then retry.'
                        );
                    }
                    $lineItems[] = [
                        'lineItemId' => $lineId,
                        'quantity'   => (int) $row['qty'],
                    ];
                }

                $client->shipOrder(
                    (string) $order['external_order_id'],
                    $lineItems,
                    $tracking,
                    $carrier,
                );
                $notified = true;
            }
        }

        // Persist qty_shipped increments.
        foreach ($toShip as $row) {
            if ($row['item_id'] <= 0) {
                continue;
            }
            $this->db->query(
                "UPDATE `#__store_order_items`
                    SET `qty_shipped` = LEAST(`qty`, `qty_shipped` + ?)
                  WHERE `id` = ? AND `order_id` = ? AND `tenant_id` = ?",
                [(int) $row['qty'], (int) $row['item_id'], $orderId, $this->tenantId()]
            );
        }

        $fullyShipped = $this->isFullyShipped($orderId);
        $newStatus = $fullyShipped ? 'FULFILLED' : 'PARTIALLY_SHIPPED';

        $payload = [
            'status'          => $newStatus,
            'tracking_number' => mb_substr($tracking, 0, 128),
            'carrier_code'    => mb_substr($carrier, 0, 64),
        ];
        if ($fullyShipped) {
            $payload['shipped_at'] = date('Y-m-d H:i:s');
        }
        $this->db->update('store_orders', $payload, $this->withTenant(['id' => $orderId]));

        // Capture serials when provided.
        if (is_array($serials) && $serials !== []) {
            (new SerialRepository($this->db))->captureForOrder($orderId, $serials);
        }

        // Deduct stock — expand kits to components when update_kit_inventory is set.
        $consumeLines = $this->resolveConsumeLines($toShip);
        $inv = new InventoryService($this->db);
        $inv->consumeShippedQuantities(
            $orderId,
            $consumeLines,
            (string) ($order['warehouse'] ?? '')
        );

        $out = [
            'notified'      => $notified,
            'local'         => true,
            'status'        => $newStatus,
            'shipped_lines' => count($toShip),
        ];
        if ($skipped !== null) {
            $out['skipped_reason'] = $skipped;
        }
        return $out;
    }

    /**
     * @param list<array{item: array<string, mixed>, item_id: int, qty: int, variant_id: int}> $toShip
     * @param list<array{order_item_id?:int, serial?:string, lot?:string, variant_id?:int}>|null $serials
     */
    private function assertSerialsCaptured(int $orderId, array $toShip, ?array $serials): void
    {
        $serialRepo = new SerialRepository($this->db);
        $needByItem = [];
        foreach ($toShip as $row) {
            if (!$serialRepo->variantRequiresSerial((int) $row['variant_id'], $row['item'])) {
                continue;
            }
            $needByItem[(int) $row['item_id']] = (int) $row['qty'];
        }
        if ($needByItem === []) {
            return;
        }
        $provided = [];
        foreach (is_array($serials) ? $serials : [] as $s) {
            $itemId = (int) ($s['order_item_id'] ?? 0);
            $serial = trim((string) ($s['serial'] ?? ''));
            if ($itemId <= 0 || $serial === '') {
                continue;
            }
            $provided[$itemId] = ($provided[$itemId] ?? 0) + 1;
        }
        foreach ($needByItem as $itemId => $needQty) {
            $have = (int) ($provided[$itemId] ?? 0);
            if ($have < $needQty) {
                throw new RuntimeException(
                    "Serial numbers required for order item #{$itemId} ({$have}/{$needQty} provided)."
                );
            }
        }
    }

    /**
     * @param list<array{item: array<string, mixed>, item_id: int, qty: int, variant_id: int}> $toShip
     * @return list<array{variant_id:int, qty:int}>
     */
    private function resolveConsumeLines(array $toShip): array
    {
        $bundles = new ProductBundleRepository($this->db);
        $out = [];
        foreach ($toShip as $row) {
            $vid = (int) $row['variant_id'];
            $qty = (int) $row['qty'];
            if ($vid <= 0 || $qty <= 0) {
                continue;
            }
            $productId = (int) ($row['item']['product_id'] ?? 0);
            if ($productId <= 0) {
                $vrow = $this->db->query(
                    "SELECT `product_id` FROM `#__store_product_variants`
                      WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
                    [$vid, $this->tenantId()]
                )->row;
                $productId = (int) ($vrow['product_id'] ?? 0);
            }
            $components = $productId > 0 ? $bundles->forProduct($productId) : [];
            $consumeComponents = false;
            foreach ($components as $comp) {
                if (!empty($comp['update_kit_inventory'])) {
                    $consumeComponents = true;
                    break;
                }
            }
            // Also honour product-level shipping/kit flag if present on item.
            if (!$consumeComponents && !empty($row['item']['update_kit_inventory'])) {
                $consumeComponents = true;
            }
            if ($consumeComponents && $components !== []) {
                foreach ($components as $comp) {
                    $compSku = (string) ($comp['component_sku'] ?? '');
                    $compVid = $this->variantIdForSku($compSku, (int) $comp['component_product_id']);
                    if ($compVid <= 0) {
                        continue;
                    }
                    $out[] = [
                        'variant_id' => $compVid,
                        'qty'        => $qty * max(1, (int) $comp['qty']),
                    ];
                }
                continue;
            }
            $out[] = ['variant_id' => $vid, 'qty' => $qty];
        }
        return $out;
    }

    private function variantIdForSku(string $sku, int $productId): int
    {
        $sku = trim($sku);
        if ($sku !== '') {
            $row = $this->db->query(
                "SELECT `id` FROM `#__store_product_variants`
                  WHERE `sku` = ? AND `tenant_id` = ? LIMIT 1",
                [$sku, $this->tenantId()]
            )->row;
            if ($row !== []) {
                return (int) $row['id'];
            }
        }
        if ($productId > 0) {
            $row = $this->db->query(
                "SELECT `id` FROM `#__store_product_variants`
                  WHERE `product_id` = ? AND `tenant_id` = ?
                  ORDER BY `id` ASC LIMIT 1",
                [$productId, $this->tenantId()]
            )->row;
            return (int) ($row['id'] ?? 0);
        }
        return 0;
    }

    private function isFullyShipped(int $orderId): bool
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS open_lines
               FROM `#__store_order_items`
              WHERE `order_id` = ? AND `tenant_id` = ?
                AND `qty_shipped` < `qty`",
            [$orderId, $this->tenantId()]
        )->row;
        return (int) ($row['open_lines'] ?? 0) === 0;
    }
}
