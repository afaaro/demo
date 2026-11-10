<?php
declare(strict_types=1);

namespace Module\Store\Services;

final class Listing
{
    public ?int    $id           = null;
    public ?int    $variantId    = null;
    public ?int    $channelId    = null;
    public string  $externalId   = '';
    public string  $externalSku  = '';
    public string  $channelTitle = '';
    public ?float  $price        = null;
    public ?float  $minPrice     = null;
    public int     $oversell     = 0;
    public int     $qtyBuffer    = 0;
    public bool    $controlled   = true;
    public string  $status       = 'pending';
    public string  $lastError    = '';
    public ?string $lastSyncedAt = null;
    public ?string $createdAt    = null;
    public ?string $updatedAt    = null;

    public function isPushed(): bool
    {
        return $this->status === 'pushed';
    }

    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'variant_id'     => $this->variantId,
            'channel_id'     => $this->channelId,
            'external_id'    => $this->externalId,
            'external_sku'   => $this->externalSku,
            'channel_title'  => $this->channelTitle,
            'price'          => $this->price,
            'min_price'      => $this->minPrice,
            'oversell'       => $this->oversell,
            'qty_buffer'     => $this->qtyBuffer,
            'controlled'     => $this->controlled,
            'status'         => $this->status,
            'last_error'     => $this->lastError,
            'last_synced_at' => $this->lastSyncedAt,
        ];
    }
}