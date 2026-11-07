<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Courier;

use Module\Store\Services\Channel;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\CredentialsVault;
use Module\Store\Services\Channels\ChannelClientInterface;
use Module\Store\Services\Channels\NotSupportedException;

/**
 * Shared marketplace no-ops for courier integrations (labels only).
 */
abstract class AbstractCourierChannelClient implements ChannelClientInterface
{
    /** @param array<string, string> $creds */
    public function __construct(
        protected Channel $channel,
        protected array $creds,
        protected CredentialsVault $vault,
        protected ChannelRepository $channels,
    ) {}

    public function consentUrl(string $state): ?string
    {
        return null;
    }

    public function handleCallback(string $code): array
    {
        throw new NotSupportedException(
            ucfirst($this->channel->type) . ' uses API credentials (no OAuth callback).'
        );
    }

    public function pushVariant(int $variantId, array $variantData): string
    {
        unset($variantId, $variantData);
        return '';
    }

    public function syncInventory(int $variantId, int $quantity, string $sku = ''): void
    {
        unset($variantId, $quantity, $sku);
    }

    public function syncPrice(string $sku, float $price, string $currency = 'GBP'): void
    {
        unset($sku, $price, $currency);
    }

    public function fetchOrders(int $days = 30, int $limit = 50, ?string $offset = null): array
    {
        unset($days, $limit, $offset);
        return ['orders' => [], 'next' => null];
    }

    public function fetchConversations(
        int $limit = 25,
        int|string $offset = 0,
        string $conversationType = 'FROM_MEMBERS',
        string $status = '',
    ): array {
        unset($conversationType, $status);
        return [
            'conversations' => [],
            'limit'         => max(1, min(100, $limit)),
            'offset'        => is_int($offset) ? $offset : 0,
            'total'         => 0,
            'next'          => null,
        ];
    }

    /** Marketplace / eBay shippingCarrierCode for this courier. */
    abstract public function marketplaceCarrierCode(): string;

    /**
     * @param array<string, mixed> $parcel
     * @return array{barcode: string, tracking_number: string, client_uid: string, status: string, raw: array<string, mixed>}
     */
    abstract public function createParcel(array $parcel): array;

    /**
     * @return array{binary: string, content_type: string}
     */
    abstract public function downloadLabel(string $barcode): array;
}
