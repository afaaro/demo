<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

/**
 * Push a local variant to a channel and sync inventory quantities.
 */
final class ListingSyncService
{
    public function __construct(
        private Database             $db,
        private ChannelRepository    $channels,
        private ChannelClientFactory $factory,
        private ListingRepository    $listings,
        private VariantRepository    $variants,
        private ProductRepository    $products,
        private InventoryService     $inventory,
        private ?ListingTemplateRepository $templates = null,
        private ?ChannelAttributeRepository $channelAttributes = null,
    ) {
        $this->channelAttributes ??= new ChannelAttributeRepository($this->db);
    }

    /**
     * @return array{listing_id: string, status: string}
     */
    public function pushVariant(Channel $channel, int $variantId): array
    {
        if (!$this->channelTenantAllowed($channel)) {
            throw new RuntimeException('Channel does not belong to the current tenant.');
        }
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }

        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }

        $variant = $this->variants->find($variantId);
        if ($variant === null) {
            throw new RuntimeException("Variant #{$variantId} not found.");
        }

        $product = $this->products->find((int) $variant->productId);
        if ($product === null) {
            throw new RuntimeException("Product for variant #{$variantId} not found.");
        }

        // Multi-variation products publish as one eBay inventory item group.
        if ($channel->type === 'ebay' && $this->isMultiVariationProduct((int) $product->id)) {
            return $this->pushVariationGroup($channel, $product, $variantId);
        }

        return $this->pushSingleVariant($channel, $product, $variant);
    }

    /**
     * @return array{listing_id: string, status: string}
     */
    private function pushSingleVariant(Channel $channel, Product $product, Variant $variant): array
    {
        $variantId = (int) $variant->id;
        $sku = $variant->sku !== '' ? $variant->sku : $product->sku;
        if ($sku === '') {
            throw new RuntimeException('Variant/product SKU is required to push to a channel.');
        }

        $qty = $this->inventory->sellableAvailable($variantId);
        if ($qty <= 0 && !ChannelSettings::bool($channel, 'include_out_of_stock', true)) {
            throw new RuntimeException(
                'Out-of-stock push blocked: enable "Include Out of Stock Inventory?" on this integration.'
            );
        }
        $price = $variant->price > 0 ? $variant->price : $product->price;
        $currency = ChannelSettings::string($channel, 'currency', 'GBP');

        $imageUrls = $this->productImageUrls($product);
        $this->assertWarehouseIfRequired($channel);

        $fallbackTitle = $product->name !== '' ? $product->name : $variant->name;
        $fallbackDesc  = $product->description !== '' ? $product->description : $product->name;

        // Templates apply to all marketplace channel types (eBay, Amazon, Shopify, B&Q).
        $rendered = (new ListingTemplateRenderer())->apply(
            $this->templates?->defaultForChannel((int) $channel->id),
            $fallbackTitle,
            $fallbackDesc,
            [
                'title'        => $fallbackTitle,
                'description'  => $fallbackDesc,
                'product_name' => $product->name,
                'variant_name' => $variant->name,
                'sku'          => $sku,
                'price'        => number_format((float) $price, 2, '.', ''),
                'currency'     => $currency,
                'barcode'      => (string) ($product->barcode ?? ''),
                'image_url'    => (string) ($product->image ?? ''),
            ],
        );

        $aspects = $this->resolveAspects($channel, $product);
        // Template attribute maps (esp. Amazon product_type / brand) overlay product aspects.
        foreach (($rendered['attributes'] ?? []) as $attrKey => $attrVal) {
            $aspects[(string) $attrKey] = (string) $attrVal;
        }

        $variantData = [
            'sku'         => $sku,
            'title'       => $rendered['title'],
            'description' => $rendered['description'],
            'price'       => $price,
            'currency'    => $currency,
            'quantity'    => $qty,
            'imageUrls'   => $imageUrls,
            'aspects'     => $aspects,
            'barcode'     => (string) ($product->barcode ?? ''),
            'condition'   => EbayCondition::orDefault($product->ebayCondition),
            'policies'    => $this->channelPolicies($channel, $product, $rendered['description'], $qty),
        ];

        try {
            $client = $this->factory->make($channel);
            $externalId = $client->pushVariant($variantId, $variantData);

            $listing = $this->listings->upsert(
                $variantId,
                (int) $channel->id,
                $externalId,
                $sku,
                'pushed',
            );

            return [
                'listing_id' => $listing->externalId,
                'status'     => $listing->status,
            ];
        } catch (\Throwable $e) {
            $this->listings->markError($variantId, (int) $channel->id, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{listing_id: string, status: string}
     */
    private function pushVariationGroup(Channel $channel, Product $product, int $triggerVariantId): array
    {
        $all = $this->variants->forProduct((int) $product->id);
        $active = [];
        foreach ($all as $v) {
            if ($v->id === null || !$v->active || $v->sku === '' || $v->attributes === []) {
                continue;
            }
            $active[] = $v;
        }
        if (count($active) < 2) {
            // Fall back to single-SKU push for the requested variant.
            $variant = $this->variants->find($triggerVariantId);
            if ($variant === null) {
                throw new RuntimeException('Variant not found.');
            }
            return $this->pushSingleVariant($channel, $product, $variant);
        }

        $this->assertWarehouseIfRequired($channel);
        $currency = ChannelSettings::string($channel, 'currency', 'GBP');
        $imageUrls = $this->productImageUrls($product);
        $commonAspects = $this->resolveAspects($channel, $product);

        $fallbackTitle = $product->name !== '' ? $product->name : $active[0]->name;
        $fallbackDesc  = $product->description !== '' ? $product->description : $product->name;
        $rendered = (new ListingTemplateRenderer())->apply(
            $this->templates?->defaultForChannel((int) $channel->id),
            $fallbackTitle,
            $fallbackDesc,
            [
                'title'        => $fallbackTitle,
                'description'  => $fallbackDesc,
                'product_name' => $product->name,
                'variant_name' => '',
                'sku'          => $product->sku,
                'price'        => number_format((float) $product->price, 2, '.', ''),
                'currency'     => $currency,
                'barcode'      => (string) ($product->barcode ?? ''),
                'image_url'    => (string) ($product->image ?? ''),
            ],
        );
        foreach (($rendered['attributes'] ?? []) as $attrKey => $attrVal) {
            $commonAspects[(string) $attrKey] = (string) $attrVal;
        }

        $specValues = []; // attr name => set of values
        $variantPayloads = [];
        foreach ($active as $v) {
            $vid = (int) $v->id;
            $qty = $this->inventory->sellableAvailable($vid);
            if ($qty <= 0 && !ChannelSettings::bool($channel, 'include_out_of_stock', true)) {
                continue;
            }
            $price = $v->price > 0 ? $v->price : $product->price;
            $varAspects = [];
            foreach ($v->attributes as $a) {
                $name = trim((string) ($a['name'] ?? ''));
                $label = trim((string) (($a['label'] ?? '') !== '' ? $a['label'] : ($a['value'] ?? '')));
                if ($name === '' || $label === '') {
                    continue;
                }
                $varAspects[$name] = [$label];
                $specValues[$name] ??= [];
                if (!in_array($label, $specValues[$name], true)) {
                    $specValues[$name][] = $label;
                }
            }
            if ($varAspects === []) {
                continue;
            }

            $variantPayloads[] = [
                'variant_id' => $vid,
                'sku'        => $v->sku,
                'title'      => mb_substr(
                    $rendered['title'] . ($v->label() !== '' ? ' — ' . $v->label() : ''),
                    0,
                    80
                ),
                'price'      => $price,
                'quantity'   => $qty,
                'aspects'    => $varAspects,
            ];
        }

        if (count($variantPayloads) < 2 || $specValues === []) {
            $variant = $this->variants->find($triggerVariantId);
            if ($variant === null) {
                throw new RuntimeException('Not enough variants with attributes to publish a group.');
            }
            return $this->pushSingleVariant($channel, $product, $variant);
        }

        $specifications = [];
        foreach ($specValues as $name => $values) {
            $specifications[] = ['name' => $name, 'values' => array_values($values)];
        }

        $groupKey = $product->sku !== '' ? $product->sku : ('P' . (int) $product->id);
        $policies = $this->channelPolicies($channel, $product, $rendered['description'], 0);
        $condition = EbayCondition::orDefault($product->ebayCondition);

        try {
            $client = $this->factory->make($channel);
            if (!method_exists($client, 'pushVariationGroup')) {
                throw new RuntimeException('This channel does not support multi-variation publish.');
            }
            $externalId = $client->pushVariationGroup([
                'group_key'   => $groupKey,
                'title'       => $rendered['title'],
                'description' => $rendered['description'],
                'imageUrls'   => $imageUrls,
                'aspects'     => $commonAspects,
                'condition'   => $condition,
                'currency'    => $currency,
                'policies'    => $policies,
                'varies_by'   => ['specifications' => $specifications],
                'variants'    => $variantPayloads,
            ]);

            $listing = null;
            foreach ($variantPayloads as $vp) {
                $listing = $this->listings->upsert(
                    (int) $vp['variant_id'],
                    (int) $channel->id,
                    $externalId,
                    (string) $vp['sku'],
                    'pushed',
                );
            }

            return [
                'listing_id' => $listing?->externalId ?? $externalId,
                'status'     => $listing?->status ?? 'pushed',
            ];
        } catch (\Throwable $e) {
            foreach ($variantPayloads as $vp) {
                $this->listings->markError((int) $vp['variant_id'], (int) $channel->id, $e->getMessage());
            }
            throw $e;
        }
    }

    private function isMultiVariationProduct(int $productId): bool
    {
        $count = 0;
        foreach ($this->variants->forProduct($productId) as $v) {
            if ($v->active && $v->sku !== '' && $v->attributes !== []) {
                $count++;
                if ($count >= 2) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string, list<string>> */
    private function resolveAspects(Channel $channel, Product $product): array
    {
        $mapped = $this->categoryMapForProduct($channel, $product);
        if ($mapped !== null && is_array($mapped['aspects'] ?? null) && $mapped['aspects'] !== []) {
            /** @var array<string, list<string>> $aspects */
            $aspects = [];
            foreach ($mapped['aspects'] as $name => $values) {
                $key = is_string($name) ? $name : (string) $name;
                if (is_array($values)) {
                    $aspects[$key] = array_values(array_map('strval', $values));
                } elseif ($values !== null && $values !== '') {
                    $aspects[$key] = [(string) $values];
                }
            }
            if ($aspects !== []) {
                return $aspects;
            }
        }
        return $this->channelAttributes->resolveForProduct($channel, $product);
    }

    /**
     * Prefer store category → channel map over product ebay_category_id.
     *
     * @return array{external_category_id:string, aspects:?array}|null
     */
    private function categoryMapForProduct(Channel $channel, Product $product): ?array
    {
        if (!TenantContext::get()->has() || (int) ($product->id ?? 0) <= 0) {
            return null;
        }
        try {
            $maps = new CategoryMapRepository($this->db);
            $rows = $this->db->query(
                "SELECT `category_id` FROM `#__store_product_categories`
                  WHERE `product_id` = ?",
                [(int) $product->id]
            )->rows;
            foreach ($rows as $row) {
                $map = $maps->findForCategoryChannel((int) $row['category_id'], (int) $channel->id);
                if ($map !== null && trim((string) $map['external_category_id']) !== '') {
                    return [
                        'external_category_id' => (string) $map['external_category_id'],
                        'aspects'              => is_array($map['aspects'] ?? null) ? $map['aspects'] : null,
                    ];
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /** @return list<string> */
    private function productImageUrls(Product $product): array
    {
        if ($product->image === '') {
            return [];
        }
        return [$product->image];
    }

    private function assertWarehouseIfRequired(Channel $channel): void
    {
        if (!ChannelSettings::bool($channel, 'enforce_channel_warehouse', false)) {
            return;
        }
        $loc = trim((string) ($channel->settings['merchant_location_key'] ?? ''));
        if ($loc === '') {
            throw new RuntimeException(
                'Enforce Channel Warehouse is on — set a Merchant location on this integration first.'
            );
        }
    }

    /** @return array<string, mixed> */
    private function channelPolicies(
        Channel $channel,
        Product $product,
        string $description,
        int $qty,
    ): array {
        $mapped = $this->categoryMapForProduct($channel, $product);
        $categoryId = $mapped !== null
            ? trim((string) $mapped['external_category_id'])
            : '';
        if ($categoryId === '') {
            $categoryId = trim($product->ebayCategoryId);
        }
        if ($categoryId === '') {
            $categoryId = (string) ($channel->settings['default_category_id'] ?? '');
        }

        return [
            'category_id'           => $categoryId,
            'fulfillment_policy_id' => (string) ($channel->settings['fulfillment_policy_id'] ?? ''),
            'payment_policy_id'     => (string) ($channel->settings['payment_policy_id'] ?? ''),
            'return_policy_id'      => (string) ($channel->settings['return_policy_id'] ?? ''),
            'merchant_location_key' => (string) ($channel->settings['merchant_location_key'] ?? ''),
            'store_category_1'      => (string) ($channel->settings['store_category_1'] ?? ''),
            'store_category_2'      => (string) ($channel->settings['store_category_2'] ?? ''),
            'listing_duration'      => 'GTC',
            'quantity'              => $qty,
            'description'           => $description,
        ];
    }

    public function syncInventory(Channel $channel, int $variantId): void
    {
        if (!$this->channelTenantAllowed($channel)) {
            throw new RuntimeException('Channel does not belong to the current tenant.');
        }
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }

        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }

        $listing = $this->listings->find($variantId, (int) $channel->id);
        if ($listing === null || !$listing->isPushed()) {
            throw new RuntimeException('Variant is not pushed to this channel yet.');
        }
        if (!$listing->controlled) {
            throw new RuntimeException('Listing is not controlled by Store — inventory sync skipped.');
        }

        $qty = max(0, $this->inventory->sellableAvailable($variantId) + max(0, $listing->oversell));
        $channelBuffer = max(0, ChannelSettings::int($channel, 'sellable_buffer', 0));
        $listingBuffer = max(0, $listing->qtyBuffer);
        $buffer = max($channelBuffer, $listingBuffer);
        $qty = max(0, $qty - $buffer);
        if ($qty <= 0 && !ChannelSettings::bool($channel, 'include_out_of_stock', true)) {
            throw new RuntimeException(
                'Out-of-stock sync blocked: enable "Include Out of Stock Inventory?" on this integration.'
            );
        }

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
    }

    /**
     * Push price to channels with Automatic Price Updates enabled.
     *
     * @return array{pushed: int, skipped: int, errors: int}
     */
    public function publishPriceToEnabledChannels(int $variantId, float $price): array
    {
        if (!TenantContext::get()->has()) {
            return ['pushed' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $pushed  = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($this->channels->enabled() as $channel) {
            if (!$channel->isConnected()) {
                $skipped++;
                continue;
            }
            if ($this->factory->isCourier($channel)) {
                $skipped++;
                continue;
            }
            if (!ChannelSettings::bool($channel, 'automatic_price_updates', true)) {
                $skipped++;
                continue;
            }

            $listing = $this->listings->find($variantId, (int) $channel->id);
            if ($listing === null || !$listing->isPushed() || !$listing->controlled) {
                $skipped++;
                continue;
            }

            $sku = $listing->externalSku !== '' ? $listing->externalSku : '';
            if ($sku === '') {
                $skipped++;
                continue;
            }

            $pushPrice = $listing->price !== null && $listing->price > 0
                ? (float) $listing->price
                : $price;

            $guard = $this->priceFloorGuard($channel, $listing, $variantId, $pushPrice);
            if ($guard !== null) {
                $skipped++;
                try {
                    (new SyncLogRepository($this->db))->log(
                        (int) $channel->id,
                        'price_floor',
                        'skipped',
                        $guard,
                        ['variant_id' => $variantId, 'price' => $pushPrice, 'sku' => $sku],
                    );
                } catch (\Throwable) {
                }
                continue;
            }

            try {
                $client = $this->factory->make($channel);
                if (!method_exists($client, 'syncPrice')) {
                    $skipped++;
                    continue;
                }
                $currency = ChannelSettings::string($channel, 'currency', 'GBP');
                $client->syncPrice($sku, $pushPrice, $currency);
                $pushed++;
            } catch (\Throwable $e) {
                $errors++;
                error_log(
                    "publishPrice variant #{$variantId} channel #{$channel->id}: "
                    . $e->getMessage()
                );
                $this->listings->markError($variantId, (int) $channel->id, $e->getMessage());
            }
        }

        return ['pushed' => $pushed, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Skip price push when below channel min_margin_pct or listing.min_price.
     *
     * @return string|null skip reason
     */
    private function priceFloorGuard(Channel $channel, Listing $listing, int $variantId, float $pushPrice): ?string
    {
        $minPrice = $listing->minPrice;
        if ($minPrice !== null && $minPrice > 0 && $pushPrice + 0.0001 < $minPrice) {
            return 'Price £' . number_format($pushPrice, 2)
                . ' below listing floor £' . number_format($minPrice, 2);
        }

        $minMarginPct = ChannelSettings::float($channel, 'min_margin_pct', 0.0);
        if ($minMarginPct <= 0) {
            return null;
        }

        $cost = 0.0;
        try {
            $row = $this->db->query(
                "SELECT `cost` FROM `#__store_product_variants` WHERE `id` = ? LIMIT 1",
                [$variantId]
            )->row;
            $cost = (float) ($row['cost'] ?? 0);
        } catch (\Throwable) {
        }
        if ($cost <= 0 || $pushPrice <= 0) {
            return null;
        }

        $feePct = ChannelSettings::float($channel, 'channel_percentage_fee', 0.0);
        $fee = $pushPrice * max(0, $feePct) / 100.0;
        $margin = $pushPrice - $cost - $fee;
        $marginPct = ($margin / $pushPrice) * 100.0;
        if ($marginPct + 0.0001 < $minMarginPct) {
            return 'Estimated margin '
                . number_format($marginPct, 1) . '% below channel floor '
                . number_format($minMarginPct, 1) . '%';
        }
        return null;
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
