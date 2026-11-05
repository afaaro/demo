<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;

/**
 * Returns a valid access token for any channel.
 *
 * Delegates to the channel client's own token handling — the client
 * knows its own refresh strategy (eBay, Shopify, Amazon all differ).
 */
final class ChannelTokenManager
{
    public function __construct(
        private ChannelClientFactory $factory,
        private ChannelRepository    $channels,
    ) {}

    /**
     * Return a valid access token for the given channel, refreshing if needed.
     */
    public function accessToken(Channel $channel): string
    {
        return $this->factory->make($channel)->accessToken();
    }
}