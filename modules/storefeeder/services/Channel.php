<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

final class Channel
{
    public ?int    $id           = null;
    public int     $tenantId     = 0;
    public string  $name         = '';
    public string  $type         = '';
    public string  $label        = '';
    public string  $credentials  = '';      // encrypted blob
    public array   $settings     = [];
    public bool    $enabled      = false;
    public string  $status       = 'disconnected';
    public string  $lastError    = '';
    public ?string $lastSyncedAt = null;
    public ?string $createdAt    = null;
    public ?string $updatedAt    = null;
    public string  $refreshToken      = '';
    public string  $accessToken       = '';
    public string  $oauthState        = '';
    public ?string $accessExpiresAt   = null;
    public ?string $refreshExpiresAt  = null;

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function isShopify(): bool
    {
        return $this->type === 'shopify';
    }

    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'tenant_id'      => $this->tenantId,
            'name'           => $this->name,
            'type'           => $this->type,
            'label'          => $this->label,
            'enabled'        => $this->enabled,
            'status'         => $this->status,
            'last_error'     => $this->lastError,
            'last_synced_at' => $this->lastSyncedAt,
        ];
    }
}