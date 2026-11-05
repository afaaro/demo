<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels;

/**
 * Every channel integration implements this.
 *
 * Methods that a channel does not support should throw NotSupportedException
 * rather than return a null or empty value, so callers can fail cleanly.
 */
interface ChannelClientInterface
{
    /**
     * Return true if the channel credentials are valid and the connection
     * is working. Used by the "Test connection" button.
     *
     * @return array{ok: bool, error?: string}
     */
    public function testConnection(): array;

    /**
     * Return the OAuth consent URL to send the seller to.
     * Return null for channels that use static credentials (no OAuth).
     */
    public function consentUrl(string $state): ?string;

    /**
     * Handle the OAuth callback: exchange the code for tokens.
     * Return the token array to persist.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_in?: int,
     *     refresh_token_expires_in?: int
     * }
     */
    public function handleCallback(string $code): array;

    /**
     * Return a fresh access token, refreshing if needed.
     */
    public function accessToken(): string;

    /**
     * Push a variant to the channel. Returns the external id.
     *
     * @param array<string, mixed> $variantData
     */
    public function pushVariant(int $variantId, array $variantData): string;

    /**
     * Sync inventory for a listing.
     *
     * @param string $sku  Channel SKU / inventory item key (required for eBay)
     */
    public function syncInventory(int $variantId, int $quantity, string $sku = ''): void;

    /**
     * Fetch a page of orders.
     *
     * @return array{orders: array<int, array<string, mixed>>, next: ?string}
     */
    public function fetchOrders(int $days = 30, int $limit = 50, ?string $offset = null): array;

    /**
     * Fetch a page of conversations (message threads).
     *
     * Channels without a messaging API should throw NotSupportedException.
     *
     * @param string $conversationType  e.g. FROM_MEMBERS, FROM_EBAY
     * @param string $status            optional filter: ACTIVE, ARCHIVED, …
     *
     * @return array{
     *     conversations: array<int, array<string, mixed>>,
     *     limit: int,
     *     offset: int,
     *     total: int,
     *     href?: string|null,
     *     next?: string|null
     * }
     */
    /**
     * @param int|string $offset  Numeric offset (eBay) or opaque page token (Mirakl / Amazon)
     */
    public function fetchConversations(
        int $limit = 25,
        int|string $offset = 0,
        string $conversationType = 'FROM_MEMBERS',
        string $status = '',
    ): array;
}