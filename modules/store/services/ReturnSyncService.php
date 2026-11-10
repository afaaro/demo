<?php
declare(strict_types=1);

namespace Module\Store\Services;

use Module\Store\Services\Channels\Marketplace\Ebay\EbayChannelClient;
use RuntimeException;
use System\Library\Database;

/**
 * Pull eBay Post-Order returns into store_returns.
 */
final class ReturnSyncService
{
    use TenantScoped;

    public function __construct(
        private Database $db,
        private ChannelClientFactory $factory,
        private ReturnRepository $returns,
    ) {}

    /**
     * @return array{created: int, updated: int, skipped: int, errors: int}
     */
    public function syncChannel(Channel $channel, int $lookbackDays = 90): array
    {
        if (!$this->channelTenantAllowed($channel)) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        }
        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }
        if ($channel->type !== 'ebay') {
            throw new RuntimeException('Return sync is currently supported for eBay channels.');
        }

        $client = $this->factory->make($channel);
        if (!$client instanceof EbayChannelClient) {
            throw new RuntimeException('eBay client unavailable.');
        }
        if (!method_exists($client, 'searchReturns')) {
            throw new RuntimeException('This eBay client does not support return search.');
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        foreach (['ALL', 'RETURN_OPEN', 'RETURN_CLOSED'] as $state) {
            try {
                $this->pullReturns($client, $channel, $state, $lookbackDays, $stats);
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('eBay return sync [' . $state . ']: ' . $e->getMessage());
            }
        }
        return $stats;
    }

    /**
     * @param array{created: int, updated: int, skipped: int, errors: int} $stats
     */
    private function pullReturns(
        EbayChannelClient $client,
        Channel $channel,
        string $state,
        int $lookbackDays,
        array &$stats,
    ): void {
        $offset = 0;
        $limit = 50;
        for ($page = 0; $page < 20; $page++) {
            $result = $client->searchReturns($state, $limit, $offset, $lookbackDays);
            $members = is_array($result['members'] ?? null) ? $result['members'] : [];
            if ($members === []) {
                break;
            }
            foreach ($members as $member) {
                if (!is_array($member)) {
                    $stats['skipped']++;
                    continue;
                }
                try {
                    $status = $this->upsertReturn($channel, $member);
                    if ($status === 'created') {
                        $stats['created']++;
                    } elseif ($status === 'updated') {
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    error_log('eBay return upsert: ' . $e->getMessage());
                }
            }
            $total = (int) ($result['total'] ?? 0);
            $offset += $limit;
            if ($offset >= $total || count($members) < $limit) {
                break;
            }
        }
    }

    /**
     * @param array<string, mixed> $member
     */
    private function upsertReturn(Channel $channel, array $member): string
    {
        $summary = is_array($member['summary'] ?? null) ? $member['summary'] : $member;
        $externalId = trim((string) (
            $summary['returnId']
            ?? $member['returnId']
            ?? $summary['return_id']
            ?? ''
        ));
        if ($externalId === '') {
            return 'skipped';
        }

        $orderIdExt = trim((string) (
            $summary['orderId']
            ?? $member['orderId']
            ?? ($summary['creationInfo']['item']['itemId'] ?? '')
            ?? ''
        ));
        // Prefer marketplace order id when present.
        if (is_array($summary['orderId'] ?? null)) {
            $orderIdExt = trim((string) ($summary['orderId']['id'] ?? $orderIdExt));
        }

        $statusRaw = (string) (
            $summary['status']
            ?? $summary['state']
            ?? $member['status']
            ?? 'open'
        );
        $status = $this->mapStatus($statusRaw);

        $reason = (string) (
            $summary['creationInfo']['reason']
            ?? $summary['buyerResponseDue']['activityDue']
            ?? $summary['returnReason']
            ?? ''
        );
        if (is_array($reason)) {
            $reason = (string) ($reason['description'] ?? $reason['type'] ?? json_encode($reason));
        }

        $buyer = (string) (
            $summary['buyerLoginName']
            ?? $member['buyerLoginName']
            ?? ''
        );

        $refund = 0.0;
        $currency = 'GBP';
        $refundAmount = $summary['sellerTotalRefund']['estimatedRefundAmount']
            ?? $summary['buyerTotalRefund']['estimatedRefundAmount']
            ?? $summary['refundAmount']
            ?? null;
        if (is_array($refundAmount)) {
            $refund = (float) ($refundAmount['value'] ?? 0);
            $currency = (string) ($refundAmount['currency'] ?? $currency);
        }

        $items = [];
        $item = is_array($summary['creationInfo']['item'] ?? null)
            ? $summary['creationInfo']['item']
            : null;
        if ($item !== null) {
            $items[] = [
                'sku'      => (string) ($item['sku'] ?? ''),
                'title'    => (string) ($item['itemTitle'] ?? ''),
                'qty'      => (int) ($item['returnQuantity'] ?? $item['quantity'] ?? 1),
                'item_id'  => (string) ($item['itemId'] ?? ''),
            ];
        }

        $localOrderId = $this->resolveLocalOrderId((int) $channel->id, $orderIdExt);

        return $this->returns->upsertByExternal(
            (int) $channel->id,
            $externalId,
            [
                'order_id'          => $localOrderId,
                'external_order_id' => $orderIdExt,
                'status'            => $status,
                'reason'            => mb_substr($reason, 0, 255),
                'refund_amount'     => $refund,
                'currency'          => $currency,
                'buyer_username'    => $buyer,
                'items'             => $items,
                'notes'             => 'Synced from eBay Post-Order',
            ]
        );
    }

    private function mapStatus(string $raw): string
    {
        $u = strtoupper(trim($raw));
        return match (true) {
            str_contains($u, 'CLOSE') || str_contains($u, 'COMPLETE') => 'closed',
            str_contains($u, 'REFUND') => 'refunded',
            str_contains($u, 'RECEIV') || str_contains($u, 'DELIVER') => 'received',
            str_contains($u, 'APPROV') || str_contains($u, 'WAIT') => 'approved',
            str_contains($u, 'CANCEL') => 'cancelled',
            default => 'open',
        };
    }

    private function resolveLocalOrderId(int $channelId, string $externalOrderId): ?int
    {
        $externalOrderId = trim($externalOrderId);
        if ($channelId <= 0 || $externalOrderId === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT `id` FROM `#__store_orders`
              WHERE `channel_id` = ? AND `external_order_id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$channelId, $externalOrderId, $this->tenantId()]
        )->row;
        return $row !== [] && $row !== null ? (int) $row['id'] : null;
    }

    private function channelTenantAllowed(Channel $channel): bool
    {
        $tid = (int) $channel->tenantId;
        if ($tid <= 0) {
            return false;
        }
        if (TenantContext::get()->has() && TenantContext::get()->id() !== $tid) {
            return false;
        }
        return true;
    }
}
