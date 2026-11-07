<?php
declare(strict_types=1);

namespace Module\Store\Services;

use Module\Store\Services\Channels\Marketplace\Ebay\EbayChannelClient;
use RuntimeException;

/**
 * Import active eBay listings (Trading API GetMyeBaySelling) into local products.
 */
final class EbayListingImportService
{
    private const MAX_PAGES = 50;
    private const PAGE_SIZE = 100;

    public function __construct(
        private ChannelClientFactory $factory,
        private ProductRepository $products,
        private VariantRepository $variants,
        private ListingRepository $listings,
        private InventoryService $inventory,
        private string $storageDir = '',
        private ?ChannelAttributeRepository $channelAttributes = null,
    ) {}

    /**
     * @return array{
     *   pages:int, fetched:int, created:int, updated:int, linked:int,
     *   skipped:int, errors:int, error_samples:array<int, string>
     * }
     */
    public function importChannel(Channel $channel): array
    {
        if (!$this->channelTenantAllowed($channel)) {
            return [
                'pages' => 0, 'fetched' => 0, 'created' => 0, 'updated' => 0, 'linked' => 0,
                'skipped' => 0, 'errors' => 0, 'error_samples' => [],
            ];
        }
        if (!TenantContext::get()->has()) {
            return [
                'pages' => 0, 'fetched' => 0, 'created' => 0, 'updated' => 0, 'linked' => 0,
                'skipped' => 0, 'errors' => 0, 'error_samples' => [],
            ];
        }

        if ($channel->type !== 'ebay') {
            throw new RuntimeException('Only eBay channels support Trading API listing import.');
        }
        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }

        $client = $this->factory->make($channel);
        if (!$client instanceof EbayChannelClient) {
            throw new RuntimeException('eBay client unavailable for this channel.');
        }
        if (!method_exists($client, 'fetchActiveSellingPage')) {
            throw new RuntimeException('This eBay client does not support active listing pull.');
        }

        $stats = [
            'pages'         => 0,
            'fetched'       => 0,
            'created'       => 0,
            'updated'       => 0,
            'linked'        => 0,
            'skipped'       => 0,
            'errors'        => 0,
            'error_samples' => [],
        ];

        $page = 1;
        do {
            $result = $client->fetchActiveSellingPage($page, self::PAGE_SIZE);
            $stats['pages']++;

            foreach ($result['items'] as $item) {
                $stats['fetched']++;
                try {
                    $item = $this->enrichWithItemDetails($client, $item);
                    $outcome = $this->importItem((int) $channel->id, $item);
                    $stats[$outcome]++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    if (count($stats['error_samples']) < 8) {
                        $sku = (string) ($item['sku'] ?? $item['item_id'] ?? '?');
                        $stats['error_samples'][] = $sku . ': ' . $e->getMessage();
                    }
                    error_log('eBay listing import #' . ($item['item_id'] ?? '') . ': ' . $e->getMessage());
                }
            }

            $hasMore = !empty($result['has_more']);
            $page++;
            if ($page > self::MAX_PAGES) {
                break;
            }
        } while ($hasMore);

        return $stats;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function enrichWithItemDetails(EbayChannelClient $client, array $item): array
    {
        $itemId = trim((string) ($item['item_id'] ?? ''));
        if ($itemId === '' || !method_exists($client, 'fetchItemDetails')) {
            return $item;
        }
        try {
            $details = $client->fetchItemDetails($itemId);
            $item['aspects'] = is_array($details['aspects'] ?? null) ? $details['aspects'] : [];
            $item['description'] = (string) ($details['description'] ?? '');
            $item['category_id'] = (string) ($details['category_id'] ?? '');
            $item['category_name'] = (string) ($details['category_name'] ?? '');
            $item['condition'] = EbayCondition::normalize((string) ($details['condition'] ?? ''));
            // ActiveList often omits Variations — GetItem ReturnAll includes them.
            $fromItem = is_array($details['variations'] ?? null) ? $details['variations'] : [];
            $existing = is_array($item['variations'] ?? null) ? $item['variations'] : [];
            if ($fromItem !== [] && (count($fromItem) > count($existing) || $existing === [])) {
                $item['variations'] = $fromItem;
            }
            // Brief pause to stay under Trading API call limits on large imports.
            usleep(120_000);
        } catch (\Throwable $e) {
            error_log('eBay GetItem #' . $itemId . ': ' . $e->getMessage());
        }
        return $item;
    }

    /**
     * @param array{
     *   item_id:string, sku:string, title:string, price:float, currency:string,
     *   quantity:int, quantity_sold:int, image_url:string,
     *   variations: array<int, array{sku:string, title:string, price:float, quantity:int}>,
     *   aspects?: array<string, list<string>>,
     *   description?: string
     * } $item
     * @return 'created'|'updated'|'linked'|'skipped'
     */
    private function importItem(int $channelId, array $item): string
    {
        $itemId = trim((string) ($item['item_id'] ?? ''));
        $sku = trim((string) ($item['sku'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        if ($itemId === '' || $sku === '' || $title === '') {
            return 'skipped';
        }

        $variations = is_array($item['variations'] ?? null) ? $item['variations'] : [];

        // Recover variations from existing channel listings when eBay XML omitted them.
        if ($variations === []) {
            $linked = $this->listings->findAllByExternalId($channelId, $itemId);
            if (count($linked) > 1) {
                foreach ($linked as $listing) {
                    $vSku = trim($listing->externalSku);
                    if ($vSku === '') {
                        continue;
                    }
                    $variant = $this->variants->find((int) $listing->variantId);
                    $vTitle = $title !== '' ? $title : $vSku;
                    if ($variant !== null && trim($variant->name) !== '') {
                        $vTitle = $variant->name;
                    }
                    $variations[] = [
                        'sku'      => $vSku,
                        'title'    => $vTitle,
                        'price'    => (float) ($listing->price ?? $variant?->price ?? $item['price'] ?? 0),
                        'quantity' => 0,
                    ];
                }
            }
        }

        if ($variations !== []) {
            // One parent product; each eBay variation becomes a child variant (+).
            return $this->importVariationItem($channelId, $item, $variations);
        }

        $existingListing = $this->listings->findByExternalIdAndSku($channelId, $itemId, $sku);
        if ($existingListing !== null) {
            $this->refreshMappedListing($existingListing, $item, $channelId, $itemId, $sku);
            return 'linked';
        }

        return $this->importSimpleItem($channelId, $item, null);
    }

    /**
     * @param array<string, mixed> $item
     * @param Listing|null $existingListing
     */
    private function importSimpleItem(int $channelId, array $item, ?Listing $existingListing): string
    {
        $itemId = (string) $item['item_id'];
        $sku = (string) $item['sku'];
        $title = (string) $item['title'];
        $price = (float) $item['price'];
        $qty = max(0, (int) $item['quantity']);

        $created = false;

        $variant = $this->variants->findBySku($sku);
        if ($variant !== null && $variant->productId !== null) {
            $product = $this->products->find((int) $variant->productId);
        } else {
            $product = $this->products->findBySku($sku);
            $variant = null;
        }

        if ($product === null) {
            $image = $this->maybeDownloadImage((string) ($item['image_url'] ?? ''));
            $product = $this->products->create(array_merge([
                'sku'         => $sku,
                'name'        => mb_substr($title, 0, 255),
                'description' => $this->importDescription($item, ''),
                'price'       => $price,
                'image'       => $image ?? '',
                'active'      => true,
            ], $this->productFieldsFromAspects($item)));
            $created = true;
            $this->saveChannelAspects($channelId, (int) $product->id, $item);
        } else {
            $this->products->update((int) $product->id, array_merge([
                'name'        => mb_substr($title, 0, 255),
                'price'       => $price,
                'active'      => true,
                'description' => $this->importDescription($item, $product->description),
            ], $this->productFieldsFromAspects($item, $product)));
            $product = $this->products->find((int) $product->id) ?? $product;
            $this->saveChannelAspects($channelId, (int) $product->id, $item);
        }

        if ($variant === null) {
            $variant = $this->variants->ensureDefaultVariant((int) $product->id, [
                'sku'    => $sku,
                'name'   => mb_substr($title, 0, 255),
                'price'  => $price,
                'active' => true,
            ]);
        }

        if ($variant->id !== null) {
            $attrs = [];
            foreach ($variant->attributes as $attrId => $a) {
                $attrs[(int) $attrId] = (int) ($a['value_id'] ?? 0);
            }
            $this->variants->update((int) $variant->id, [
                'sku'        => $variant->sku !== '' ? $variant->sku : $sku,
                'name'       => mb_substr($title, 0, 255),
                'price'      => $price,
                'cost'       => $variant->cost,
                'pick_bin'   => $variant->pickBin,
                'active'     => true,
                'attributes' => $attrs,
            ]);
            $variant = $this->variants->find((int) $variant->id) ?? $variant;
        }

        $this->setStock((int) $variant->id, $qty);
        $listing = $this->listings->upsert((int) $variant->id, $channelId, $itemId, $sku, 'pushed');
        if ($listing->id !== null) {
            $this->listings->updateFields((int) $listing->id, [
                'channel_title' => mb_substr($title, 0, 255),
                'price'         => $price,
                'controlled'    => true,
            ]);
        }

        if ($existingListing !== null && !$created) {
            return 'linked';
        }
        return $created ? 'created' : 'updated';
    }

    /**
     * One parent product for the eBay ItemID; each variation is a child variant.
     *
     * @param array<string, mixed> $item
     * @param array<int, array{sku:string, title:string, price:float, quantity:int}> $variations
     * @return 'created'|'updated'|'linked'|'skipped'
     */
    private function importVariationItem(
        int $channelId,
        array $item,
        array $variations,
    ): string {
        $itemId = (string) $item['item_id'];
        $parentSku = trim((string) ($item['sku'] ?? ''));
        if ($parentSku === '') {
            $parentSku = 'EBAY-' . $itemId;
        }
        $title = (string) $item['title'];
        $parentPrice = (float) $item['price'];

        $product = $this->resolveVariationParentProduct($channelId, $itemId, $parentSku);
        $created = false;

        if ($product === null) {
            $image = $this->maybeDownloadImage((string) ($item['image_url'] ?? ''));
            $productSku = $parentSku;
            if ($this->products->findBySku($productSku) !== null) {
                $productSku = 'EBAY-' . $itemId;
                if ($this->products->findBySku($productSku) !== null) {
                    $productSku = $this->allocateUniqueProductSku($parentSku);
                }
            }
            $product = $this->products->create(array_merge([
                'sku'         => $productSku,
                'name'        => mb_substr($title, 0, 255),
                'description' => $this->importDescription($item, ''),
                'price'       => $parentPrice,
                'image'       => $image ?? '',
                'active'      => true,
            ], $this->productFieldsFromAspects($item)));
            $created = true;
            $this->saveChannelAspects($channelId, (int) $product->id, $item);
        } else {
            $this->products->update((int) $product->id, array_merge([
                'name'        => mb_substr($title, 0, 255),
                'price'       => $parentPrice,
                'active'      => true,
                'description' => $this->importDescription($item, $product->description),
            ], $this->productFieldsFromAspects($item, $product)));
            $product = $this->products->find((int) $product->id) ?? $product;
            $this->saveChannelAspects($channelId, (int) $product->id, $item);
        }

        $parentId = (int) $product->id;
        $any = false;

        foreach ($variations as $v) {
            $vSku = trim((string) ($v['sku'] ?? ''));
            if ($vSku === '') {
                continue;
            }
            $any = true;
            $vPrice = (float) ($v['price'] ?? $parentPrice);
            $vQty = max(0, (int) ($v['quantity'] ?? 0));
            $vTitle = trim((string) ($v['title'] ?? $title));
            if ($vTitle === '') {
                $vTitle = $title !== '' ? $title : $vSku;
            }

            $variant = $this->variants->findBySku($vSku);
            if ($variant === null) {
                $variant = $this->variants->create($parentId, [
                    'sku'    => $vSku,
                    'name'   => mb_substr($vTitle, 0, 255),
                    'price'  => $vPrice,
                    'active' => true,
                ]);
            } else {
                if ((int) $variant->productId !== $parentId) {
                    // Re-attach SKUs that were previously split into separate products.
                    $this->variants->reassignProduct((int) $variant->id, $parentId);
                    $variant = $this->variants->find((int) $variant->id) ?? $variant;
                }
                $attrs = [];
                foreach ($variant->attributes as $attrId => $a) {
                    $attrs[(int) $attrId] = (int) ($a['value_id'] ?? 0);
                }
                $this->variants->update((int) $variant->id, [
                    'sku'        => $vSku,
                    'name'       => mb_substr($vTitle, 0, 255),
                    'price'      => $vPrice,
                    'cost'       => $variant->cost,
                    'pick_bin'   => $variant->pickBin,
                    'active'     => true,
                    'attributes' => $attrs,
                ]);
                $variant = $this->variants->find((int) $variant->id) ?? $variant;
            }

            $this->setStock((int) $variant->id, $vQty);
            $listing = $this->listings->upsert(
                (int) $variant->id,
                $channelId,
                $itemId,
                $vSku,
                'pushed'
            );
            if ($listing->id !== null) {
                $this->listings->updateFields((int) $listing->id, [
                    'channel_title' => mb_substr($vTitle, 0, 255),
                    'price'         => $vPrice,
                    'controlled'    => true,
                ]);
            }
        }

        if (!$any) {
            return 'skipped';
        }

        // Hide orphan simple products left empty after re-attaching variants.
        $this->deactivateEmptyOrphanProducts($channelId, $itemId, $parentId);

        return $created ? 'created' : 'updated';
    }

    /**
     * Prefer an existing parent already linked to this eBay ItemID.
     */
    private function resolveVariationParentProduct(
        int $channelId,
        string $itemId,
        string $parentSku,
    ): ?Product {
        $bySku = $this->products->findBySku($parentSku)
            ?? $this->products->findBySku('EBAY-' . $itemId);
        if ($bySku !== null) {
            return $bySku;
        }

        $best = null;
        $bestCount = -1;
        $seen = [];
        foreach ($this->listings->findAllByExternalId($channelId, $itemId) as $listing) {
            $variant = $this->variants->find((int) $listing->variantId);
            if ($variant === null || $variant->productId === null) {
                continue;
            }
            $pid = (int) $variant->productId;
            if (isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $product = $this->products->find($pid);
            if ($product === null) {
                continue;
            }
            $count = count($this->variants->forProduct($pid));
            if ($count > $bestCount) {
                $best = $product;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * After regrouping variations, archive leftover empty products that only
     * existed because SKUs were previously imported as separate simples.
     */
    private function deactivateEmptyOrphanProducts(
        int $channelId,
        string $itemId,
        int $keepProductId,
    ): void {
        $seen = [];
        foreach ($this->listings->findAllByExternalId($channelId, $itemId) as $listing) {
            $variant = $this->variants->find((int) $listing->variantId);
            if ($variant === null || $variant->productId === null) {
                continue;
            }
            $pid = (int) $variant->productId;
            if ($pid === $keepProductId || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            if ($this->variants->forProduct($pid) !== []) {
                continue;
            }
            try {
                $this->products->update($pid, ['active' => false]);
            } catch (\Throwable) {
                // non-fatal
            }
        }
    }

    /** @param array<string, mixed> $item */
    private function refreshMappedListing(
        Listing $listing,
        array $item,
        int $channelId,
        string $itemId,
        string $sku,
    ): void {
        $vid = (int) $listing->variantId;
        if ($vid <= 0) {
            return;
        }
        $this->setStock($vid, max(0, (int) ($item['quantity'] ?? 0)));
        $this->listings->upsert($vid, $channelId, $itemId, $sku, 'pushed');
        if ($listing->id !== null) {
            $this->listings->updateFields((int) $listing->id, [
                'channel_title' => mb_substr((string) ($item['title'] ?? ''), 0, 255),
                'price'         => (float) ($item['price'] ?? 0),
            ]);
        }
        $variant = $this->variants->find($vid);
        if ($variant !== null && $variant->productId !== null) {
            $product = $this->products->find((int) $variant->productId);
            if ($product === null) {
                return;
            }
            $this->products->update((int) $variant->productId, array_merge([
                'name'        => mb_substr((string) ($item['title'] ?? $variant->name), 0, 255),
                'price'       => (float) ($item['price'] ?? $variant->price),
                'description' => $this->importDescription($item, $product->description ?? ''),
            ], $this->productFieldsFromAspects($item, $product)));
            $this->saveChannelAspects($channelId, (int) $variant->productId, $item);
        }
    }

    private function allocateUniqueProductSku(string $base): string
    {
        $base = mb_substr(trim($base) !== '' ? trim($base) : 'SKU', 0, 100);
        for ($i = 2; $i <= 50; $i++) {
            $candidate = mb_substr($base . '-' . $i, 0, 128);
            if ($this->products->findBySku($candidate) === null) {
                return $candidate;
            }
        }
        return mb_substr($base . '-' . bin2hex(random_bytes(3)), 0, 128);
    }

    /** @param array<string, mixed> $item */
    private function saveChannelAspects(int $channelId, int $productId, array $item): void
    {
        if ($this->channelAttributes === null || $productId <= 0 || $channelId <= 0) {
            return;
        }
        $aspects = EbayAspectResolver::normalizeProductAspects($item['aspects'] ?? []);
        if ($aspects === []) {
            return;
        }
        $this->channelAttributes->mergeProductOverrides($productId, $channelId, $aspects);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function productFieldsFromAspects(array $item, ?Product $existing = null): array
    {
        $aspects = EbayAspectResolver::normalizeProductAspects($item['aspects'] ?? []);
        $out = [];
        // Aspects are stored per-channel via ChannelAttributeRepository (not product JSON).

        $catId = trim((string) ($item['category_id'] ?? ''));
        if ($catId !== '' && ($existing === null || trim($existing->ebayCategoryId) === '')) {
            $out['ebay_category_id'] = mb_substr($catId, 0, 32);
            $catName = trim((string) ($item['category_name'] ?? ''));
            if ($catName !== '') {
                $out['ebay_category_name'] = mb_substr($catName, 0, 255);
            }
        }
        $condition = EbayCondition::normalize((string) ($item['condition'] ?? ''));
        if ($condition !== '' && ($existing === null || trim($existing->ebayCondition) === '')) {
            $out['ebay_condition'] = $condition;
        }

        $brand = $this->firstAspectValue($aspects, ['Brand', 'Manufacturer', 'Brand Name']);
        if ($brand !== '' && ($existing === null || trim($existing->brand) === '')) {
            $out['brand'] = mb_substr($brand, 0, 128);
        }
        $mpn = $this->firstAspectValue($aspects, ['MPN', 'Manufacturer Part Number', 'Part Number']);
        if ($mpn !== '' && ($existing === null || trim($existing->mpn) === '')) {
            $out['mpn'] = mb_substr($mpn, 0, 64);
        }
        $ean = $this->firstAspectValue($aspects, ['EAN', 'European Article Number']);
        if ($ean !== '' && ($existing === null || trim($existing->barcode) === '')) {
            $out['barcode'] = mb_substr($ean, 0, 64);
        }
        $upc = $this->firstAspectValue($aspects, ['UPC', 'Universal Product Code']);
        if ($upc !== '' && ($existing === null || trim($existing->upc) === '')) {
            $out['upc'] = mb_substr($upc, 0, 64);
        }

        return $out;
    }

    /** @param array<string, list<string>> $aspects @param list<string> $names */
    private function firstAspectValue(array $aspects, array $names): string
    {
        foreach ($names as $name) {
            foreach ($aspects as $key => $vals) {
                if (strcasecmp((string) $key, $name) !== 0) {
                    continue;
                }
                $v = trim((string) ($vals[0] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    /** @param array<string, mixed> $item */
    private function importDescription(array $item, string $existing): string
    {
        $desc = trim((string) ($item['description'] ?? ''));
        if ($desc === '') {
            return $existing;
        }
        // Prefer eBay description when local is empty; otherwise keep local edits.
        if (trim($existing) === '') {
            return $desc;
        }
        return $existing;
    }

    private function setStock(int $variantId, int $quantity): void
    {
        if ($variantId <= 0) {
            return;
        }
        $current = $this->inventory->get($variantId);
        $have = (int) ($current['quantity'] ?? 0);
        $delta = $quantity - $have;
        if ($delta !== 0) {
            $this->inventory->adjust($variantId, $delta, 'ebay_import', 'Imported from eBay Trading API');
        }
    }

    private function maybeDownloadImage(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || $this->storageDir === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Store/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if ($body === false || $code >= 400 || strlen((string) $body) < 32) {
                return null;
            }

            $ext = 'jpg';
            if (str_contains($ctype, 'png')) {
                $ext = 'png';
            } elseif (str_contains($ctype, 'webp')) {
                $ext = 'webp';
            } elseif (str_contains($ctype, 'gif')) {
                $ext = 'gif';
            }

            $relative = 'uploads/' . date('Y/m') . '/ebay_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $target = rtrim($this->storageDir, '/\\') . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return null;
            }
            if (@file_put_contents($target, $body) === false) {
                return null;
            }
            @chmod($target, 0644);
            return $relative;
        } catch (\Throwable) {
            return null;
        }
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
