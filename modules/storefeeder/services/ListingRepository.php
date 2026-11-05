<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use System\Library\Database;

final class ListingRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    public function find(int $variantId, int $channelId): ?Listing
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('sf_listings', $this->withTenant([
            'variant_id' => $variantId,
            'channel_id' => $channelId,
        ]));

        return $row === [] ? null : $this->hydrate($row);
    }

    public function findById(int $id): ?Listing
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('sf_listings', $this->withTenant(['id' => $id]));
        return $row === [] ? null : $this->hydrate($row);
    }

    public function findByExternalId(int $channelId, string $externalId): ?Listing
    {
        $externalId = trim($externalId);
        if ($channelId <= 0 || $externalId === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__sf_listings`
              WHERE `channel_id` = ? AND `external_id` = ? AND `tenant_id` = ?
              ORDER BY `id` ASC
              LIMIT 1",
            [$channelId, mb_substr($externalId, 0, 128), $this->tenantId()]
        )->row;

        return $row === [] ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, Listing>
     */
    public function findAllByExternalId(int $channelId, string $externalId): array
    {
        $externalId = trim($externalId);
        if ($channelId <= 0 || $externalId === '' || !TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__sf_listings`
              WHERE `channel_id` = ? AND `external_id` = ? AND `tenant_id` = ?
              ORDER BY `id` ASC",
            [$channelId, mb_substr($externalId, 0, 128), $this->tenantId()]
        )->rows;

        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    /**
     * Variation listings share one eBay ItemID — disambiguate by external_sku.
     */
    public function findByExternalIdAndSku(
        int $channelId,
        string $externalId,
        string $externalSku,
    ): ?Listing {
        $externalId = trim($externalId);
        $externalSku = trim($externalSku);
        if ($channelId <= 0 || $externalId === '' || $externalSku === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__sf_listings`
              WHERE `channel_id` = ?
                AND `external_id` = ?
                AND `external_sku` = ?
                AND `tenant_id` = ?
              ORDER BY `id` ASC
              LIMIT 1",
            [
                $channelId,
                mb_substr($externalId, 0, 128),
                mb_substr($externalSku, 0, 128),
                $this->tenantId(),
            ]
        )->row;

        return $row === [] ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, Listing>
     */
    public function forVariant(int $variantId): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__sf_listings` WHERE `variant_id` = ? AND `tenant_id` = ? ORDER BY `id`",
            [$variantId, $this->tenantId()]
        )->rows;

        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    /**
     * All listings for variants of a product, newest first within channel.
     *
     * @return array<int, Listing>
     */
    public function forProduct(int $productId): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT l.*
               FROM `#__sf_listings` l
               JOIN `#__sf_product_variants` v
                 ON v.`id` = l.`variant_id` AND v.`tenant_id` = l.`tenant_id`
              WHERE v.`product_id` = ? AND l.`tenant_id` = ?
              ORDER BY l.`channel_id` ASC, l.`id` ASC",
            [$productId, $tid]
        )->rows;

        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    /**
     * @param array{channel_title?: string, price?: float|null, oversell?: int, controlled?: bool} $data
     */
    public function updateFields(int $listingId, array $data): void
    {
        if ($this->findById($listingId) === null) {
            return;
        }
        $patch = [];
        if (array_key_exists('channel_title', $data)) {
            $patch['channel_title'] = mb_substr(trim((string) $data['channel_title']), 0, 255);
        }
        if (array_key_exists('price', $data)) {
            $price = $data['price'];
            $patch['price'] = $price === null || $price === '' ? null : round((float) $price, 2);
        }
        if (array_key_exists('oversell', $data)) {
            $patch['oversell'] = max(0, (int) $data['oversell']);
        }
        if (array_key_exists('controlled', $data)) {
            $patch['controlled'] = !empty($data['controlled']) ? 1 : 0;
        }
        if ($patch === []) {
            return;
        }
        $patch['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('sf_listings', $patch, $this->withTenant(['id' => $listingId]));
    }

    /**
     * @return array<int, Listing>
     */
    public function forChannel(int $channelId, ?string $status = null): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        if ($status !== null && $status !== '') {
            $rows = $this->db->query(
                "SELECT * FROM `#__sf_listings`
                 WHERE `channel_id` = ? AND `status` = ? AND `tenant_id` = ?
                 ORDER BY `id`",
                [$channelId, $status, $tid]
            )->rows;
        } else {
            $rows = $this->db->query(
                "SELECT * FROM `#__sf_listings` WHERE `channel_id` = ? AND `tenant_id` = ? ORDER BY `id`",
                [$channelId, $tid]
            )->rows;
        }

        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function upsert(
        int $variantId,
        int $channelId,
        string $externalId,
        string $externalSku,
        string $status,
        string $lastError = '',
    ): Listing {
        if (!TenantContext::get()->has()) {
            throw new \RuntimeException('No tenant context.');
        }
        $existing = $this->find($variantId, $channelId);
        $data = [
            'variant_id'     => $variantId,
            'channel_id'     => $channelId,
            'external_id'    => mb_substr($externalId, 0, 128),
            'external_sku'   => mb_substr($externalSku, 0, 128),
            'status'         => $status,
            'last_error'     => mb_substr($lastError, 0, 500),
            'last_synced_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            $id = $this->db->insert('sf_listings', $this->withTenant($data));
            return $this->findById((int) $id) ?? throw new \RuntimeException('Failed to create listing.');
        }

        $this->db->update('sf_listings', $data, $this->withTenant(['id' => (int) $existing->id]));
        return $this->findById((int) $existing->id) ?? $existing;
    }

    public function markError(int $variantId, int $channelId, string $error): void
    {
        $existing = $this->find($variantId, $channelId);
        if ($existing === null) {
            $this->upsert($variantId, $channelId, '', '', 'error', $error);
            return;
        }

        $this->db->update('sf_listings', [
            'status'     => 'error',
            'last_error' => mb_substr($error, 0, 500),
        ], $this->withTenant(['id' => (int) $existing->id]));
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            'DELETE FROM `#__sf_listings` WHERE `id` = ? AND `tenant_id` = ?',
            [$id, $this->tenantId()]
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Listing
    {
        $l = new Listing();
        $l->id           = (int) $row['id'];
        $l->variantId    = (int) $row['variant_id'];
        $l->channelId    = (int) $row['channel_id'];
        $l->externalId   = (string) $row['external_id'];
        $l->externalSku  = (string) $row['external_sku'];
        $l->channelTitle = (string) ($row['channel_title'] ?? '');
        $priceRaw        = $row['price'] ?? null;
        $l->price        = $priceRaw === null || $priceRaw === '' ? null : (float) $priceRaw;
        $l->oversell     = (int) ($row['oversell'] ?? 0);
        $l->controlled   = (bool) ($row['controlled'] ?? true);
        $l->status       = (string) $row['status'];
        $l->lastError    = (string) ($row['last_error'] ?? '');
        $l->lastSyncedAt = $row['last_synced_at'] ?? null;
        $l->createdAt    = $row['created_at'] ?? null;
        $l->updatedAt    = $row['updated_at'] ?? null;
        return $l;
    }
}
