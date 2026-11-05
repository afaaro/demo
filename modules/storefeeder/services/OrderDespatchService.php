<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;
use System\Library\Database;

/**
 * Mark orders shipped locally and optionally notify the sales channel.
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
     * @param array<string, mixed> $order  sf_orders row
     * @param array<int, array<string, mixed>> $items
     * @return array{notified: bool, local: bool, skipped_reason?: string}
     */
    public function despatchOrder(
        array $order,
        array $items,
        string $tracking,
        string $carrier,
        bool $forceNotify = false,
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
                foreach ($items as $item) {
                    $lineId = trim((string) ($item['external_line_item_id'] ?? ''));
                    if ($lineId === '') {
                        throw new RuntimeException(
                            'Missing channel line item IDs. Sync orders again, then retry.'
                        );
                    }
                    $lineItems[] = [
                        'lineItemId' => $lineId,
                        'quantity'   => max(1, (int) ($item['qty'] ?? 1)),
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

        $this->db->update('sf_orders', [
            'status'          => 'FULFILLED',
            'tracking_number' => mb_substr($tracking, 0, 128),
            'carrier_code'    => mb_substr($carrier, 0, 64),
            'shipped_at'      => date('Y-m-d H:i:s'),
        ], $this->withTenant(['id' => $orderId]));

        // Deduct stock and clear reservation for this order.
        (new InventoryService($this->db))->consumeFulfilledOrder($orderId);

        $out = ['notified' => $notified, 'local' => true];
        if ($skipped !== null) {
            $out['skipped_reason'] = $skipped;
        }
        return $out;
    }
}
