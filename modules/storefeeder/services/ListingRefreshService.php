<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;

/**
 * Refresh listing status from the channel; optionally relist ended offers.
 */
final class ListingRefreshService
{
    public function __construct(
        private ChannelClientFactory       $factory,
        private ListingRepository          $listings,
        private ListingSyncService         $sync,
        private InventoryService           $inventory,
    ) {}

    /**
     * @return array{checked: int, active: int, ended: int, relisted: int, synced_qty: int, errors: int}
     */
    public function refreshChannel(Channel $channel): array
    {
        if (!$this->channelTenantAllowed($channel)) {
            return [
                'checked' => 0, 'active' => 0, 'ended' => 0, 'relisted' => 0,
                'synced_qty' => 0, 'errors' => 0,
            ];
        }
        if (!TenantContext::get()->has()) {
            return [
                'checked' => 0, 'active' => 0, 'ended' => 0, 'relisted' => 0,
                'synced_qty' => 0, 'errors' => 0,
            ];
        }

        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }
        if (!ChannelSettings::bool($channel, 'refresh_listings', true)) {
            throw new RuntimeException('Refresh Listings is turned off for this integration.');
        }

        $client = $this->factory->make($channel);
        if (!method_exists($client, 'getListingStatusBySku')) {
            throw new RuntimeException('This channel type does not support listing refresh.');
        }

        $checked = 0;
        $active  = 0;
        $ended   = 0;
        $relisted = 0;
        $syncedQty = 0;
        $errors  = 0;

        $canRelist = ChannelSettings::bool($channel, 'relist_ended_listings', false)
            || ChannelSettings::bool($channel, 'automatic_relisting', true);
        $syncQty = ChannelSettings::bool($channel, 'automatic_inventory_updates', true);

        foreach ($this->listings->forChannel((int) $channel->id) as $listing) {
            $sku = trim($listing->externalSku);
            if ($sku === '' || (int) $listing->variantId <= 0) {
                continue;
            }
            $checked++;

            try {
                $info = $client->getListingStatusBySku($sku);
                $status = strtoupper((string) ($info['status'] ?? ''));

                $isEnded = in_array($status, [
                    'ENDED', 'INACTIVE', 'UNPUBLISHED', 'WITHDRAWN', 'MISSING', 'COMPLETED',
                ], true);

                if ($isEnded) {
                    $ended++;
                    $this->listings->upsert(
                        (int) $listing->variantId,
                        (int) $channel->id,
                        (string) ($info['listing_id'] ?: $listing->externalId),
                        $sku,
                        'ended',
                    );

                    if ($canRelist) {
                        try {
                            $offerId = (string) ($info['offer_id'] ?? '');
                            if ($offerId !== '' && method_exists($client, 'republishOffer')
                                && !in_array($status, ['MISSING'], true)
                            ) {
                                $listingId = $client->republishOffer($offerId);
                                $this->listings->upsert(
                                    (int) $listing->variantId,
                                    (int) $channel->id,
                                    $listingId !== '' ? $listingId : $listing->externalId,
                                    $sku,
                                    'pushed',
                                );
                            } else {
                                $this->sync->pushVariant($channel, (int) $listing->variantId);
                            }
                            $relisted++;
                        } catch (\Throwable $e) {
                            $errors++;
                            $this->listings->markError(
                                (int) $listing->variantId,
                                (int) $channel->id,
                                'Relist failed: ' . $e->getMessage()
                            );
                        }
                    }
                    continue;
                }

                $active++;
                $this->listings->upsert(
                    (int) $listing->variantId,
                    (int) $channel->id,
                    (string) ($info['listing_id'] ?: $listing->externalId),
                    $sku,
                    'pushed',
                );

                if ($syncQty) {
                    $qty   = $this->inventory->sellableAvailable((int) $listing->variantId);
                    if ($qty > 0 || ChannelSettings::bool($channel, 'include_out_of_stock', true)) {
                        $client->syncInventory((int) $listing->variantId, $qty, $sku);
                        $syncedQty++;
                    }
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->listings->markError(
                    (int) $listing->variantId,
                    (int) $channel->id,
                    'Refresh failed: ' . $e->getMessage()
                );
            }
        }

        return [
            'checked'    => $checked,
            'active'     => $active,
            'ended'      => $ended,
            'relisted'   => $relisted,
            'synced_qty' => $syncedQty,
            'errors'     => $errors,
        ];
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
