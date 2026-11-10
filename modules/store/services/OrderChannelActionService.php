<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

/**
 * Channel cancel + refund cockpit helpers.
 */
final class OrderChannelActionService
{
    use TenantScoped;

    public function __construct(
        private Database $db,
        private ChannelRepository $channels,
        private ChannelClientFactory $factory,
        private OrderRepository $orders,
        private SyncLogRepository $syncLog,
        private OrderAuditRepository $audit,
    ) {}

    /**
     * Cancel on channel (if supported) then mark local CANCELLED.
     *
     * @return array{channel: string, local: bool, skipped?: string}
     */
    public function cancelOnChannelAndLocal(
        int $orderId,
        ?int $userId = null,
        string $userLabel = '',
        string $reason = 'seller_cancel',
    ): array {
        $data = $this->orders->find($orderId);
        if ($data === null) {
            throw new RuntimeException('Order not found.');
        }
        $order = $data['order'];
        $channel = $this->channels->find((int) ($order['channel_id'] ?? 0));
        if ($channel === null) {
            throw new RuntimeException('Channel not found.');
        }

        $channelStatus = 'skipped';
        $skipped = null;

        if (!$channel->isConnected()) {
            $skipped = 'Channel not connected — cancelled locally only.';
            $this->syncLog->log((int) $channel->id, 'order_cancel', 'skipped', $skipped, [
                'order_id' => $orderId,
            ], $userId, $userLabel);
        } else {
            $client = $this->factory->make($channel);
            try {
                if (method_exists($client, 'cancelOrder')) {
                    $client->cancelOrder((string) $order['external_order_id'], $reason);
                    $channelStatus = 'ok';
                    $this->syncLog->log((int) $channel->id, 'order_cancel', 'ok', 'Cancelled on channel.', [
                        'order_id' => $orderId,
                        'external_order_id' => (string) $order['external_order_id'],
                    ], $userId, $userLabel);
                } elseif (method_exists($client, 'cancelFulfillment')) {
                    $client->cancelFulfillment((string) $order['external_order_id'], $reason);
                    $channelStatus = 'ok';
                    $this->syncLog->log((int) $channel->id, 'order_cancel', 'ok', 'Cancelled fulfillment on channel.', [
                        'order_id' => $orderId,
                    ], $userId, $userLabel);
                } else {
                    $skipped = 'Channel type does not expose cancel API — cancelled locally only.';
                    $this->syncLog->log((int) $channel->id, 'order_cancel', 'skipped', $skipped, [
                        'order_id' => $orderId,
                        'channel_type' => $channel->type,
                    ], $userId, $userLabel);
                }
            } catch (\Throwable $e) {
                $channelStatus = 'error';
                $skipped = $e->getMessage();
                $this->syncLog->log((int) $channel->id, 'order_cancel', 'error', $e->getMessage(), [
                    'order_id' => $orderId,
                ], $userId, $userLabel);
            }
        }

        $this->db->update('store_orders', [
            'status'  => 'CANCELLED',
            'on_hold' => 0,
        ], $this->withTenant(['id' => $orderId]));

        $this->audit->log(
            $orderId,
            'channel_cancel',
            'Cancelled on channel + local',
            ['channel_status' => $channelStatus, 'skipped' => $skipped, 'reason' => $reason],
            $userId,
            $userLabel
        );

        $out = ['channel' => $channelStatus, 'local' => true];
        if ($skipped !== null) {
            $out['skipped'] = $skipped;
        }
        return $out;
    }

    /**
     * Issue refund via eBay Post-Order / Amazon helpers when available.
     *
     * @return array{status: string, skipped?: string}
     */
    public function issueRefund(
        int $orderId,
        ?float $amount = null,
        string $currency = 'GBP',
        string $comments = '',
        ?string $returnId = null,
        ?int $userId = null,
        string $userLabel = '',
    ): array {
        $data = $this->orders->find($orderId);
        if ($data === null) {
            throw new RuntimeException('Order not found.');
        }
        $order = $data['order'];
        $channel = $this->channels->find((int) ($order['channel_id'] ?? 0));
        if ($channel === null) {
            throw new RuntimeException('Channel not found.');
        }

        $amount ??= (float) ($order['total'] ?? 0);
        $currency = $currency !== '' ? $currency : (string) ($order['currency'] ?? 'GBP');

        if (!$channel->isConnected()) {
            $msg = 'Channel not connected — refund skipped.';
            $this->syncLog->log((int) $channel->id, 'order_refund', 'skipped', $msg, [
                'order_id' => $orderId,
            ], $userId, $userLabel);
            $this->audit->log($orderId, 'refund_skip', $msg, [], $userId, $userLabel);
            return ['status' => 'skipped', 'skipped' => $msg];
        }

        $client = $this->factory->make($channel);

        try {
            if ($returnId !== null && $returnId !== '' && method_exists($client, 'issueReturnRefund')) {
                $client->issueReturnRefund($returnId, $amount, $currency, $comments);
                $this->syncLog->log((int) $channel->id, 'order_refund', 'ok', 'Return refund issued.', [
                    'order_id' => $orderId,
                    'return_id' => $returnId,
                    'amount' => $amount,
                ], $userId, $userLabel);
                $this->audit->log($orderId, 'refund', 'Return refund issued', [
                    'return_id' => $returnId,
                    'amount' => $amount,
                    'currency' => $currency,
                ], $userId, $userLabel);
                return ['status' => 'ok'];
            }

            if (method_exists($client, 'issueOrderRefund')) {
                $client->issueOrderRefund((string) $order['external_order_id'], $amount, $currency, $comments);
                $this->syncLog->log((int) $channel->id, 'order_refund', 'ok', 'Order refund issued.', [
                    'order_id' => $orderId,
                    'amount' => $amount,
                ], $userId, $userLabel);
                $this->audit->log($orderId, 'refund', 'Order refund issued', [
                    'amount' => $amount,
                    'currency' => $currency,
                ], $userId, $userLabel);
                return ['status' => 'ok'];
            }

            if (method_exists($client, 'refundOrder')) {
                $client->refundOrder((string) $order['external_order_id'], $amount, $currency);
                $this->syncLog->log((int) $channel->id, 'order_refund', 'ok', 'Order refund issued.', [
                    'order_id' => $orderId,
                    'amount' => $amount,
                ], $userId, $userLabel);
                $this->audit->log($orderId, 'refund', 'Order refund issued', [
                    'amount' => $amount,
                ], $userId, $userLabel);
                return ['status' => 'ok'];
            }

            $msg = 'No refund helper for channel type ' . $channel->type . ' — logged skip.';
            $this->syncLog->log((int) $channel->id, 'order_refund', 'skipped', $msg, [
                'order_id' => $orderId,
                'amount' => $amount,
            ], $userId, $userLabel);
            $this->audit->log($orderId, 'refund_skip', $msg, ['amount' => $amount], $userId, $userLabel);
            return ['status' => 'skipped', 'skipped' => $msg];
        } catch (\Throwable $e) {
            $this->syncLog->log((int) $channel->id, 'order_refund', 'error', $e->getMessage(), [
                'order_id' => $orderId,
            ], $userId, $userLabel);
            $this->audit->log($orderId, 'refund_error', $e->getMessage(), [], $userId, $userLabel);
            throw $e;
        }
    }
}
