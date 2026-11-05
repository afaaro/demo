<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

/**
 * Push local stock to connected channels that have Automatic Inventory Updates on.
 */
final class ChannelStockPublisher
{
    use TenantScoped;

    public function __construct(
        private ChannelRepository    $channels,
        private ChannelClientFactory $factory,
        private ListingRepository    $listings,
    ) {}

    /**
     * @return array{pushed: int, skipped: int, errors: int}
     */
    public function publish(int $variantId, int $quantity): array
    {
        if (!TenantContext::get()->has()) {
            return ['pushed' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $pushed  = 0;
        $skipped = 0;
        $errors  = 0;
        $qty     = max(0, $quantity);

        foreach ($this->channels->enabled() as $channel) {
            if (!$channel->isConnected()) {
                $skipped++;
                continue;
            }
            if ($this->factory->isCourier($channel)) {
                $skipped++;
                continue;
            }
            if (!ChannelSettings::bool($channel, 'automatic_inventory_updates', true)) {
                $skipped++;
                continue;
            }

            if (ChannelSettings::bool($channel, 'enforce_channel_warehouse', false)) {
                $loc = trim((string) ($channel->settings['merchant_location_key'] ?? ''));
                if ($loc === '') {
                    $skipped++;
                    continue;
                }
            }

            $listing = $this->listings->find($variantId, (int) $channel->id);
            if ($listing === null || !$listing->isPushed()) {
                $skipped++;
                continue;
            }

            // When Include Out of Stock is off, do not push zero/negative qty.
            if ($qty <= 0 && !ChannelSettings::bool($channel, 'include_out_of_stock', true)) {
                $skipped++;
                continue;
            }

            try {
                $client = $this->factory->make($channel);
                $sku    = $listing->externalSku !== '' ? $listing->externalSku : '';
                $client->syncInventory($variantId, $qty, $sku);
                $this->listings->upsert(
                    $variantId,
                    (int) $channel->id,
                    $listing->externalId,
                    $listing->externalSku,
                    'pushed',
                );
                $pushed++;
            } catch (\Throwable $e) {
                $errors++;
                error_log(
                    "ChannelStockPublisher variant #{$variantId} channel #{$channel->id}: "
                    . $e->getMessage()
                );
                $this->listings->markError($variantId, (int) $channel->id, $e->getMessage());
            }
        }

        return ['pushed' => $pushed, 'skipped' => $skipped, 'errors' => $errors];
    }
}
