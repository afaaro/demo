<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Ebay;

use Module\Store\Services\Channel;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\Channels\ChannelClientInterface;
use Module\Store\Services\CredentialsVault;
use RuntimeException;

final class EbayChannelClient implements ChannelClientInterface
{
    private bool $sandbox;

    /** In-request access-token cache (avoids DB + refresh on every API call). */
    private ?string $cachedAccessToken = null;
    private int     $cachedAccessTokenUntil = 0;

    /**
     * @param array<string, string> $creds
     */
    public function __construct(
        private Channel $channel,
        private array $creds,
        private CredentialsVault $vault,
        private ChannelRepository $channels,
    ) {
        $this->sandbox = !empty($channel->settings['sandbox']);
    }

    // =========================================================================
    // Connection / OAuth
    // =========================================================================

    /**
     * @return array{ok: bool, error?: string}
     */
    public function testConnection(): array
    {
        try {
            $client = new EbayFulfillmentClient(
                $this->accessToken(),
                $this->sandbox,
                $this->marketplace(),
            );
            $client->fetchOrders(1, 1);
            return ['ok' => true];
        } catch (\Throwable $e) {
            error_log('eBay testConnection failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function consentUrl(string $state): ?string
    {
        return $this->oauth()->consentUrl($state);
    }

    public function handleCallback(string $code): array
    {
        $tokens = $this->oauth()->exchangeCode($code);

        // Persist directly, since the tokens are specific to this channel.
        $this->channels->storeTokens(
            (int) $this->channel->id,
            $tokens['refresh_token'],
            $tokens['access_token'],
            $tokens['expires_in'],
            $tokens['refresh_token_expires_in'],
            $this->vault,
        );

        return $tokens;
    }

    public function accessToken(): string
    {
        if ($this->cachedAccessToken !== null && time() < $this->cachedAccessTokenUntil) {
            return $this->cachedAccessToken;
        }

        // Reload the channel — tokens may have been refreshed since construction.
        $fresh = $this->channels->find((int) $this->channel->id);
        if ($fresh === null) {
            throw new RuntimeException("Channel #{$this->channel->id} not found.");
        }

        // Still valid for 5+ minutes? Use the stored access token.
        if ($fresh->accessExpiresAt !== null
            && strtotime($fresh->accessExpiresAt) > time() + 300
        ) {
            $decoded = $this->vault->open($fresh->accessToken);
            $token   = (string) ($decoded['token'] ?? '');
            if ($token !== '') {
                $this->cachedAccessToken      = $token;
                $this->cachedAccessTokenUntil = time() + 240;
                return $token;
            }
        }

        // Don't even try if the refresh token has expired.
        if ($fresh->refreshExpiresAt !== null
            && strtotime($fresh->refreshExpiresAt) <= time()
        ) {
            throw new RuntimeException(
                'Refresh token has expired. Please reconnect this channel.'
            );
        }

        $refreshToken = $this->channels->readRefreshToken($fresh, $this->vault);
        if ($refreshToken === null || $refreshToken === '') {
            throw new RuntimeException('No refresh token. Please reconnect.');
        }

        $tokens = $this->oauth()->refresh($refreshToken);

        // Guard against a provider that omits the rotated refresh token.
        $newRefresh = (string) ($tokens['refresh_token'] ?? '');
        if ($newRefresh === '') {
            $newRefresh = $refreshToken;
        }

        $newAccess = (string) ($tokens['access_token'] ?? '');
        if ($newAccess === '') {
            throw new RuntimeException('Refresh returned an empty access token.');
        }

        $this->channels->storeTokens(
            (int) $fresh->id,
            $newRefresh,
            $newAccess,
            (int) $tokens['expires_in'],
            (int) ($tokens['refresh_token_expires_in'] ?? 0),
            $this->vault,
        );

        // $newAccess is the RAW token; do NOT pass it through the vault.
        $this->cachedAccessToken      = $newAccess;
        $this->cachedAccessTokenUntil = time() + 240;
        return $newAccess;
    }

    // =========================================================================
    // Listing / inventory (Sell Inventory API)
    // =========================================================================

    public function pushVariant(int $variantId, array $variantData): string
    {
        $sku = trim((string) ($variantData['sku'] ?? ''));
        if ($sku === '') {
            throw new RuntimeException('SKU is required to push a listing.');
        }

        $inv = new EbayInventoryClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );

        $condition = \Module\Store\Services\EbayCondition::orDefault(
            (string) ($variantData['condition'] ?? 'NEW')
        );
        $inv->putInventoryItem($sku, [
            'title'       => (string) ($variantData['title'] ?? 'Item'),
            'description' => (string) ($variantData['description'] ?? ''),
            'imageUrls'   => is_array($variantData['imageUrls'] ?? null) ? $variantData['imageUrls'] : [],
            'aspects'     => is_array($variantData['aspects'] ?? null) ? $variantData['aspects'] : [],
        ], (int) ($variantData['quantity'] ?? 0), $condition);

        $policies = is_array($variantData['policies'] ?? null) ? $variantData['policies'] : [];
        $offerId  = $inv->createOffer(
            $sku,
            (float) ($variantData['price'] ?? 0),
            (string) ($variantData['currency'] ?? 'GBP'),
            $policies + ['quantity' => (int) ($variantData['quantity'] ?? 0)],
        );

        // createOffer revises existing offers; only publish when still unpublished.
        return $inv->publishOfferIfNeeded($offerId);
    }

    /**
     * Publish a multi-variation listing via inventory item group.
     *
     * @param array{
     *   group_key: string,
     *   title: string,
     *   description: string,
     *   imageUrls?: list<string>,
     *   aspects?: array<string, list<string>>,
     *   condition?: string,
     *   currency: string,
     *   policies: array<string, mixed>,
     *   varies_by: array{specifications: list<array{name: string, values: list<string>}>},
     *   variants: list<array{
     *     sku: string,
     *     title: string,
     *     price: float,
     *     quantity: int,
     *     aspects: array<string, list<string>>
     *   }>
     * } $groupData
     */
    public function pushVariationGroup(array $groupData): string
    {
        $variants = is_array($groupData['variants'] ?? null) ? $groupData['variants'] : [];
        if (count($variants) < 2) {
            throw new RuntimeException('Variation publish needs at least two variants.');
        }

        $inv = new EbayInventoryClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );

        $groupKey = (string) ($groupData['group_key'] ?? '');
        $title = (string) ($groupData['title'] ?? 'Item');
        $description = (string) ($groupData['description'] ?? '');
        $imageUrls = is_array($groupData['imageUrls'] ?? null) ? $groupData['imageUrls'] : [];
        $commonAspects = is_array($groupData['aspects'] ?? null) ? $groupData['aspects'] : [];
        $policies = is_array($groupData['policies'] ?? null) ? $groupData['policies'] : [];
        $currency = (string) ($groupData['currency'] ?? 'GBP');
        $variesBy = is_array($groupData['varies_by'] ?? null) ? $groupData['varies_by'] : [];
        $condition = \Module\Store\Services\EbayCondition::orDefault(
            (string) ($groupData['condition'] ?? 'NEW')
        );

        $skus = [];
        foreach ($variants as $v) {
            $sku = trim((string) ($v['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $skus[] = $sku;
            $aspects = is_array($v['aspects'] ?? null) ? $v['aspects'] : [];
            // Variation aspects override common aspects for the same name.
            $mergedAspects = $commonAspects;
            foreach ($aspects as $name => $vals) {
                $mergedAspects[$name] = $vals;
            }

            $inv->putInventoryItem($sku, [
                'title'       => (string) ($v['title'] ?? $title),
                'description' => $description,
                'imageUrls'   => $imageUrls,
                'aspects'     => $mergedAspects,
            ], (int) ($v['quantity'] ?? 0), $condition);

            $inv->createOffer(
                $sku,
                (float) ($v['price'] ?? 0),
                $currency,
                $policies + [
                    'quantity'    => (int) ($v['quantity'] ?? 0),
                    'description' => $description,
                ],
            );
        }

        if (count($skus) < 2) {
            throw new RuntimeException('Variation publish needs at least two SKUs.');
        }

        $inv->putInventoryItemGroup($groupKey, [
            'title'       => $title,
            'description' => $description,
            'imageUrls'   => $imageUrls,
            'aspects'     => $commonAspects,
            'variantSKUs' => $skus,
            'variesBy'    => $variesBy,
        ]);

        try {
            return $inv->publishOfferByInventoryItemGroup($groupKey);
        } catch (RuntimeException $e) {
            // Group already live: pull listing id from any variant offer.
            foreach ($skus as $sku) {
                $offers = $inv->getOffersBySku($sku);
                foreach ($offers as $offer) {
                    $listing = is_array($offer['listing'] ?? null) ? $offer['listing'] : [];
                    $listingId = trim((string) ($listing['listingId'] ?? $offer['listingId'] ?? ''));
                    if ($listingId !== '') {
                        return $listingId;
                    }
                }
            }
            throw $e;
        }
    }

    /**
     * @return array{status: string, offer_id: string, listing_id: string}
     */
    public function getListingStatusBySku(string $sku): array
    {
        $inv = new EbayInventoryClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );
        $offers = $inv->getOffersBySku($sku);
        if ($offers === []) {
            return ['status' => 'missing', 'offer_id' => '', 'listing_id' => ''];
        }

        $offer = $offers[0];
        $status = strtoupper((string) ($offer['status'] ?? 'UNKNOWN'));
        $listing = is_array($offer['listing'] ?? null) ? $offer['listing'] : [];
        $listingStatus = strtoupper((string) ($listing['listingStatus'] ?? ''));
        if ($listingStatus !== '') {
            $status = $listingStatus;
        }

        return [
            'status'     => $status,
            'offer_id'   => (string) ($offer['offerId'] ?? ''),
            'listing_id' => (string) ($listing['listingId'] ?? $offer['listingId'] ?? ''),
        ];
    }

    public function republishOffer(string $offerId): string
    {
        $inv = new EbayInventoryClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );
        return $inv->publishOfferIfNeeded($offerId);
    }

    public function syncInventory(int $variantId, int $quantity, string $sku = ''): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException(
                "SKU is required to sync inventory for variant #{$variantId}."
            );
        }

        $inv = new EbayInventoryClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );
        $inv->updateQuantity($sku, $quantity);
    }

    public function syncPrice(string $sku, float $price, string $currency = 'GBP'): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('SKU is required to sync price.');
        }

        $inv = new EbayInventoryClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );
        $inv->updatePrice($sku, $price, $currency);
    }

    /**
     * Pull fulfillment / payment / return policies + inventory locations.
     *
     * @return array{
     *   fulfillment: array<int, array{id: string, name: string}>,
     *   payment: array<int, array{id: string, name: string}>,
     *   return: array<int, array{id: string, name: string}>,
     *   locations: array<int, array{id: string, name: string}>
     * }
     */
    public function fetchBusinessPolicies(): array
    {
        $client = new EbayAccountClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );
        return $client->allBusinessPolicies();
    }

    /**
     * Search eBay leaf categories for the channel marketplace.
     *
     * @return array<int, array{id: string, name: string, path: string, leaf: bool}>
     */
    public function suggestCategories(string $query, int $limit = 20): array
    {
        $client = new EbayTaxonomyClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );
        return $client->suggestCategories($query, $limit);
    }

    /**
     * @return list<array{
     *   name: string,
     *   required: bool,
     *   mode: string,
     *   cardinality: string,
     *   values: list<string>
     * }>
     */
    public function getItemAspectsForCategory(string $categoryId): array
    {
        $client = new EbayTaxonomyClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );
        return $client->getItemAspectsForCategory($categoryId);
    }

    /**
     * @return array<int, array{id: string, name: string, path: string, level: int}>
     */
    public function fetchStoreCategories(): array
    {
        $client = new EbayStoresClient(
            $this->accessToken(),
            $this->sandbox,
        );
        return $client->storeCategories();
    }

    // =========================================================================
    // Orders
    // =========================================================================

    public function fetchOrders(int $days = 30, int $limit = 50, ?string $offset = null): array
    {
        $client = new EbayFulfillmentClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );
        // Fulfillment client normalizes each line's buyer quantity from eBay
        // (and re-fetches getOrder when the list payload omits quantity).
        return $client->fetchOrders($days, $limit, $offset);
    }

    /**
     * @param array<int, array{lineItemId: string, quantity: int}> $lineItems
     * @return array<string, mixed>
     */
    public function shipOrder(
        string $orderId,
        array $lineItems,
        string $trackingNumber,
        string $carrierCode,
        ?string $shippedDate = null,
    ): array {
        $client = new EbayFulfillmentClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );
        return $client->createShippingFulfillment(
            $orderId,
            $lineItems,
            $trackingNumber,
            $carrierCode,
            $shippedDate,
        );
    }


    // =========================================================================
    // Selling list — XML (GetMyeBaySelling ActiveList)
    // =========================================================================

    /**
     * Pull one page of active listings from the Trading API.
     *
     * @return array{
     *   items: array<int, array{
     *     item_id:string, sku:string, title:string, price:float, currency:string,
     *     quantity:int, quantity_sold:int, image_url:string,
     *     variations: array<int, array{sku:string, title:string, price:float, quantity:int}>
     *   }>,
     *   page:int, total_pages:int, has_more:bool, total_entries:int
     * }
     */
    public function fetchActiveSellingPage(int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $token = $this->accessToken();
        $endpoint = $this->xmlEndpoint();

        $xml  = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<GetMyeBaySellingRequest xmlns="urn:ebay:apis:eBLBaseComponents">';
        $xml .= '<Version>1209</Version>';
        $xml .= '<ErrorLanguage>en_GB</ErrorLanguage>';
        $xml .= '<ActiveList>';
        $xml .= '<Include>true</Include>';
        $xml .= '<Pagination>';
        $xml .= '<EntriesPerPage>' . $perPage . '</EntriesPerPage>';
        $xml .= '<PageNumber>' . $page . '</PageNumber>';
        $xml .= '</Pagination>';
        $xml .= '</ActiveList>';
        $xml .= '</GetMyeBaySellingRequest>';

        $headers = [
            'X-EBAY-API-IAF-TOKEN: ' . $token,
            'X-EBAY-API-CALL-NAME: GetMyeBaySelling',
            'X-EBAY-API-SITEID: ' . $this->siteId(),
            'X-EBAY-API-COMPATIBILITY-LEVEL: 1209',
            'Content-Type: text/xml;charset=UTF-8',
            'Accept: text/xml',
        ];

        $xmlResponse = $this->postXml($endpoint, $xml, $headers, 'GetMyeBaySelling', 90);

        $items = [];
        $active = $xmlResponse->ActiveList ?? null;
        if ($active !== null && isset($active->ItemArray->Item)) {
            foreach ($active->ItemArray->Item as $item) {
                $parsed = $this->parseActiveSellingItem($item);
                if ($parsed !== null) {
                    $items[] = $parsed;
                }
            }
        }

        $totalPages = (int) ($active->PaginationResult->TotalNumberOfPages ?? 1);
        $totalEntries = (int) ($active->PaginationResult->TotalNumberOfEntries ?? count($items));
        if ($totalPages < 1) {
            $totalPages = 1;
        }

        return [
            'items'         => $items,
            'page'          => $page,
            'total_pages'   => $totalPages,
            'has_more'      => $page < $totalPages,
            'total_entries' => $totalEntries,
        ];
    }

    /**
     * @return array{
     *   item_id:string, sku:string, title:string, price:float, currency:string,
     *   quantity:int, quantity_sold:int, image_url:string,
     *   variations: array<int, array{sku:string, title:string, price:float, quantity:int}>
     * }|null
     */
    private function parseActiveSellingItem(\SimpleXMLElement $item): ?array
    {
        $itemId = trim((string) ($item->ItemID ?? ''));
        if ($itemId === '') {
            return null;
        }

        $title = trim((string) ($item->Title ?? ''));
        $sku = trim((string) ($item->SKU ?? ''));
        if ($sku === '') {
            $sku = 'EBAY-' . $itemId;
        }

        $priceNode = $item->SellingStatus->CurrentPrice
            ?? $item->StartPrice
            ?? null;
        $price = $priceNode !== null ? (float) $priceNode : 0.0;
        $currency = $priceNode !== null
            ? (string) ($priceNode['currencyID'] ?? 'GBP')
            : 'GBP';
        if ($currency === '') {
            $currency = 'GBP';
        }

        $qtyAvail = isset($item->QuantityAvailable)
            ? (int) $item->QuantityAvailable
            : max(0, (int) ($item->Quantity ?? 0) - (int) ($item->SellingStatus->QuantitySold ?? 0));
        $qtySold = (int) ($item->SellingStatus->QuantitySold ?? 0);

        $imageUrl = trim((string) (
            $item->PictureDetails->GalleryURL
            ?? $item->PictureDetails->PictureURL
            ?? ''
        ));
        if ($imageUrl === '' && isset($item->PictureDetails->PictureURL)) {
            foreach ($item->PictureDetails->PictureURL as $pic) {
                $imageUrl = trim((string) $pic);
                if ($imageUrl !== '') {
                    break;
                }
            }
        }

        $variations = $this->parseVariationsFromItem($item, $sku, $title, $price);

        return [
            'item_id'       => $itemId,
            'sku'           => $sku,
            'title'         => $title !== '' ? $title : $sku,
            'price'         => $price,
            'currency'      => $currency,
            'quantity'      => max(0, $qtyAvail),
            'quantity_sold' => max(0, $qtySold),
            'image_url'     => $imageUrl,
            'variations'    => $variations,
            'aspects'       => [],
            'description'   => '',
            'category_id'   => '',
            'condition'     => '',
        ];
    }

    /**
     * Full item details (Item Specifics, description, category) via Trading GetItem.
     *
     * @return array{
     *   aspects: array<string, list<string>>,
     *   description: string,
     *   category_id: string,
     *   category_name: string,
     *   condition: string,
     *   sku: string,
     *   title: string,
     *   price: float,
     *   variations: array<int, array{sku:string, title:string, price:float, quantity:int}>
     * }
     */
    public function fetchItemDetails(string $itemId): array
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            throw new \InvalidArgumentException('Item ID is required.');
        }

        $token = $this->accessToken();
        $endpoint = $this->xmlEndpoint();

        $xml  = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">';
        $xml .= '<Version>1209</Version>';
        $xml .= '<ErrorLanguage>en_GB</ErrorLanguage>';
        $xml .= '<ItemID>' . htmlspecialchars($itemId, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</ItemID>';
        $xml .= '<DetailLevel>ReturnAll</DetailLevel>';
        $xml .= '<IncludeItemSpecifics>true</IncludeItemSpecifics>';
        $xml .= '</GetItemRequest>';

        $headers = [
            'X-EBAY-API-IAF-TOKEN: ' . $token,
            'X-EBAY-API-CALL-NAME: GetItem',
            'X-EBAY-API-SITEID: ' . $this->siteId(),
            'X-EBAY-API-COMPATIBILITY-LEVEL: 1209',
            'Content-Type: text/xml;charset=UTF-8',
            'Accept: text/xml',
        ];

        $xmlResponse = $this->postXml($endpoint, $xml, $headers, 'GetItem', 60);
        $item = $xmlResponse->Item ?? null;
        if ($item === null) {
            throw new RuntimeException('GetItem returned no Item for ' . $itemId);
        }

        $aspects = $this->parseItemSpecifics($item);
        $description = trim((string) ($item->Description ?? ''));
        // Strip HTML to a reasonable plain length for local storage if huge
        if (strlen($description) > 20000) {
            $description = mb_substr($description, 0, 20000);
        }
        $categoryId = trim((string) ($item->PrimaryCategory->CategoryID ?? ''));
        $categoryName = trim((string) ($item->PrimaryCategory->CategoryName ?? ''));
        $conditionId = trim((string) ($item->ConditionID ?? ''));
        $conditionDisplay = trim((string) ($item->ConditionDisplayName ?? ''));
        $condition = \Module\Store\Services\EbayCondition::normalize(
            $conditionId !== '' ? $conditionId : $conditionDisplay
        );

        $title = trim((string) ($item->Title ?? ''));
        $sku = trim((string) ($item->SKU ?? ''));
        if ($sku === '') {
            $sku = 'EBAY-' . $itemId;
        }
        $priceNode = $item->SellingStatus->CurrentPrice
            ?? $item->StartPrice
            ?? null;
        $price = $priceNode !== null ? (float) $priceNode : 0.0;
        $variations = $this->parseVariationsFromItem($item, $sku, $title, $price);

        return [
            'aspects'       => $aspects,
            'description'   => $description,
            'category_id'   => $categoryId,
            'category_name' => $categoryName,
            'condition'     => $condition,
            'sku'           => $sku,
            'title'         => $title !== '' ? $title : $sku,
            'price'         => $price,
            'variations'    => $variations,
        ];
    }

    /**
     * @return array<int, array{sku:string, title:string, price:float, quantity:int}>
     */
    private function parseVariationsFromItem(
        \SimpleXMLElement $item,
        string $parentSku,
        string $title,
        float $fallbackPrice,
    ): array {
        $variations = [];
        if (!isset($item->Variations->Variation)) {
            return $variations;
        }

        $vi = 0;
        foreach ($item->Variations->Variation as $variation) {
            $vi++;
            $vSku = trim((string) ($variation->SKU ?? ''));
            if ($vSku === '') {
                $vSku = $parentSku . '-V' . $vi;
            }
            $vPriceNode = $variation->StartPrice ?? $variation->SellingStatus->CurrentPrice ?? null;
            $vPrice = $vPriceNode !== null ? (float) $vPriceNode : $fallbackPrice;
            $vQty = isset($variation->Quantity)
                ? max(0, (int) $variation->Quantity - (int) ($variation->SellingStatus->QuantitySold ?? 0))
                : (int) ($variation->QuantityAvailable ?? 0);

            $vTitle = $title;
            if (isset($variation->VariationSpecifics->NameValueList)) {
                $parts = [];
                foreach ($variation->VariationSpecifics->NameValueList as $nv) {
                    $val = trim((string) ($nv->Value ?? ''));
                    if ($val !== '') {
                        $parts[] = $val;
                    }
                }
                if ($parts !== []) {
                    $vTitle = $title . ' — ' . implode(' / ', $parts);
                }
            }

            $variations[] = [
                'sku'      => $vSku,
                'title'    => $vTitle !== '' ? $vTitle : $vSku,
                'price'    => $vPrice,
                'quantity' => max(0, $vQty),
            ];
        }

        return $variations;
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseItemSpecifics(\SimpleXMLElement $item): array
    {
        $out = [];
        $lists = [];
        if (isset($item->ItemSpecifics->NameValueList)) {
            $lists[] = $item->ItemSpecifics->NameValueList;
        }
        // Shared variation specifics (e.g. Brand on multi-SKU listings)
        if (isset($item->Variations->VariationSpecificsSet->NameValueList)) {
            $lists[] = $item->Variations->VariationSpecificsSet->NameValueList;
        }

        foreach ($lists as $nvList) {
            foreach ($nvList as $nv) {
                $name = trim((string) ($nv->Name ?? ''));
                if ($name === '') {
                    continue;
                }
                $out[$name] ??= [];
                if (isset($nv->Value)) {
                    foreach ($nv->Value as $valNode) {
                        $val = trim((string) $valNode);
                        if ($val !== '' && !in_array($val, $out[$name], true)) {
                            $out[$name][] = $val;
                        }
                    }
                }
            }
        }

        return $out;
    }

    // =========================================================================
    // Messaging — XML (GetMyMessages / GetMemberMessages)
    // =========================================================================

    public function fetchMyMessages(
        int $folderId = 0,           // 0 = Inbox, 1 = Sent
        string $detailLevel = 'ReturnHeaders',
        array $messageIds = [],
        int $page = 1,
        int $perPage = 25
    ): array {
        // eBay only accepts these three DetailLevel values for GetMyMessages.
        $allowedLevels = ['ReturnSummary', 'ReturnHeaders', 'ReturnMessages'];
        if (!in_array($detailLevel, $allowedLevels, true)) {
            throw new \InvalidArgumentException(
                "Invalid DetailLevel '{$detailLevel}'. Allowed: "
                . implode(', ', $allowedLevels)
            );
        }

        $token    = $this->accessToken();
        $endpoint = $this->xmlEndpoint();

        $xml  = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<GetMyMessagesRequest xmlns="urn:ebay:apis:eBLBaseComponents">';
        $xml .= '<Version>1209</Version>';
        $xml .= '<DetailLevel>' . $this->xmlEscape($detailLevel) . '</DetailLevel>';
        $xml .= '<FolderID>' . (int) $folderId . '</FolderID>';

        if (!empty($messageIds)) {
            $xml .= '<MessageIDs>';
            foreach (array_slice($messageIds, 0, 10) as $id) {
                $xml .= '<MessageID>' . $this->xmlEscape((string) $id) . '</MessageID>';
            }
            $xml .= '</MessageIDs>';
        }

        $xml .= '<Pagination>';
        $xml .= '<EntriesPerPage>' . (int) $perPage . '</EntriesPerPage>';
        $xml .= '<PageNumber>' . (int) $page . '</PageNumber>';
        $xml .= '</Pagination>';
        $xml .= '</GetMyMessagesRequest>';

        $headers = [
            'X-EBAY-API-IAF-TOKEN: ' . $token,
            'X-EBAY-API-CALL-NAME: GetMyMessages',
            'X-EBAY-API-SITEID: ' . $this->siteId(),
            'X-EBAY-API-COMPATIBILITY-LEVEL: 1209',
            'Content-Type: text/xml;charset=UTF-8',
            'Accept: text/xml',
        ];

        $xmlResponse = $this->postXml($endpoint, $xml, $headers, 'GetMyMessages');

        // Parse messages
        $messages = [];
        if (isset($xmlResponse->Messages->Message)) {
            foreach ($xmlResponse->Messages->Message as $msg) {
                $messages[] = [
                    'message_id'    => (string) $msg->MessageID,
                    'external_id'   => (string) ($msg->ExternalMessageID ?? ''),
                    'sender'        => (string) $msg->Sender,
                    'recipient'     => (string) ($msg->RecipientUserID ?? ''),
                    'subject'       => (string) ($msg->Subject ?? ''),
                    'content'       => (string) ($msg->Content ?? ''),
                    'read'          => ((string) $msg->Read) === 'true',
                    'replied'       => ((string) $msg->Replied) === 'true',
                    'receive_date'  => (string) ($msg->ReceiveDate ?? ''),
                    'item_id'       => (string) ($msg->ItemID ?? ''),
                    'item_title'    => (string) ($msg->ItemTitle ?? ''),
                    'message_type'  => (string) ($msg->MessageType ?? ''),
                    'question_type' => (string) ($msg->QuestionType ?? ''),
                ];
            }
        }

        return [
            'messages'       => $messages,
            'total_messages' => (int) ($xmlResponse->Summary->TotalMessageCount ?? 0),
            'new_messages'   => (int) ($xmlResponse->Summary->NewMessageCount ?? 0),
            'page'           => $page,
            'per_page'       => $perPage,
        ];
    }

    public function fetchMemberMessages(
        string $itemId = '',
        string $mailMessageType = 'AskSellerQuestion',
        string $messageStatus = '',
        int $page = 1,
        int $perPage = 25
    ): array {
        $token    = $this->accessToken();
        $endpoint = $this->xmlEndpoint();

        $xml  = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<GetMemberMessagesRequest xmlns="urn:ebay:apis:eBLBaseComponents">';
        $xml .= '<Version>1209</Version>';
        $xml .= '<MailMessageType>' . $this->xmlEscape($mailMessageType) . '</MailMessageType>';

        if ($itemId !== '') {
            $xml .= '<ItemID>' . $this->xmlEscape($itemId) . '</ItemID>';
        }
        if ($messageStatus !== '') {
            $xml .= '<MessageStatus>' . $this->xmlEscape($messageStatus) . '</MessageStatus>';
        }

        $xml .= '<Pagination>';
        $xml .= '<EntriesPerPage>' . (int) $perPage . '</EntriesPerPage>';
        $xml .= '<PageNumber>' . (int) $page . '</PageNumber>';
        $xml .= '</Pagination>';
        $xml .= '</GetMemberMessagesRequest>';

        $headers = [
            'X-EBAY-API-IAF-TOKEN: ' . $token,
            'X-EBAY-API-CALL-NAME: GetMemberMessages',
            'X-EBAY-API-SITEID: ' . $this->siteId(),
            'X-EBAY-API-COMPATIBILITY-LEVEL: 1209',
            'Content-Type: text/xml;charset=UTF-8',
            'Accept: text/xml',
        ];

        $xmlResponse = $this->postXml($endpoint, $xml, $headers, 'GetMemberMessages');

        $messages = [];
        if (isset($xmlResponse->MemberMessage->MemberMessageExchange)) {
            foreach ($xmlResponse->MemberMessage->MemberMessageExchange as $exchange) {
                $msg = $exchange->Message ?? null;

                $messages[] = [
                    'message_id'    => (string) ($msg->MessageID ?? ''),
                    'sender'        => (string) ($msg->Sender ?? ''),
                    'body'          => (string) ($msg->Body ?? ''),
                    'item_id'       => (string) ($exchange->ItemID ?? ''),
                    'item_title'    => (string) ($exchange->ItemTitle ?? ''),
                    'status'        => (string) ($exchange->MessageStatus ?? ''),
                    'creation_date' => (string) ($exchange->CreationDate ?? ''),
                ];
            }
        }

        // eBay sometimes nests pagination info under PaginationResult.
        $hasMore = false;
        if (isset($xmlResponse->HasMoreItems)) {
            $hasMore = ((string) $xmlResponse->HasMoreItems) === 'true';
        } elseif (isset($xmlResponse->PaginationResult->TotalNumberOfPages)) {
            $totalPages = (int) $xmlResponse->PaginationResult->TotalNumberOfPages;
            $hasMore    = $page < $totalPages;
        }

        return [
            'messages' => $messages,
            'has_more' => $hasMore,
        ];
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function oauth(): EbayOAuth
    {
        return new EbayOAuth(
            (string) ($this->creds['client_id']     ?? ''),
            (string) ($this->creds['client_secret'] ?? ''),
            (string) ($this->creds['ru_name']       ?? ''),
            $this->sandbox,
        );
    }

    /**
     * Marketplace ID from channel settings, falling back to UK.
     */
    private function marketplace(): string
    {
        $mp = (string) ($this->channel->settings['marketplace'] ?? 'EBAY_GB');
        return $mp !== '' ? $mp : 'EBAY_GB';
    }

    /**
     * Map marketplace ID to eBay XML API numeric site id.
     */
    private function siteId(): int
    {
        return match ($this->marketplace()) {
            'EBAY_US' => 0,
            'EBAY_GB' => 3,
            'EBAY_DE' => 77,
            'EBAY_AU' => 15,
            default   => 3,
        };
    }

    private function xmlEndpoint(): string
    {
        return $this->sandbox
            ? 'https://api.sandbox.ebay.com/ws/api.dll'
            : 'https://api.ebay.com/ws/api.dll';
    }

    /**
     * XML-escape a value with explicit flags and encoding.
     */
    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * POST an XML request to the eBay XML API and return the parsed response.
     *
     * Handles cURL, transport errors, invalid XML, and the Ack=Failure check.
     *
     * @param array<int, string> $headers
     */
    private function postXml(
        string $endpoint,
        string $xml,
        array $headers,
        string $callName,
        int $timeout = 30,
    ): \SimpleXMLElement {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, EbayCurl::withSsl([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]));

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("eBay {$callName} cURL error: {$err}");
        }

        $xmlResponse = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        if ($xmlResponse === false) {
            throw new RuntimeException("eBay {$callName} returned invalid XML (HTTP {$code}).");
        }

        $ack = (string) $xmlResponse->Ack;
        if ($ack === 'Failure') {
            $errorMessage = 'Unknown eBay error';
            if (isset($xmlResponse->Errors)) {
                $errorMessage = (string) (
                    $xmlResponse->Errors->LongMessage
                    ?? $xmlResponse->Errors->ShortMessage
                );
            }
            throw new RuntimeException("eBay {$callName} failed (HTTP {$code}): {$errorMessage}");
        }

        return $xmlResponse;
    }

    /**
     * Extract a human-readable error message from an eBay REST error body.
     *
     * @param array<string, mixed> $decoded
     */
    private function extractEbayErrorMessage(array $decoded): string
    {
        if (isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
            return $decoded['message'];
        }

        $errors = $decoded['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $first = reset($errors);
            if (is_array($first)) {
                foreach (['longMessage', 'message'] as $key) {
                    if (isset($first[$key]) && is_string($first[$key]) && $first[$key] !== '') {
                        return $first[$key];
                    }
                }
            }
        }

        return 'Unknown error';
    }

    // =========================================================================
    // Messaging — REST (conversations)
    // =========================================================================

    /**
     * Fetch a single conversation thread (Commerce Message API).
     *
     * @return array{
     *     messages: array<int, array<string, mixed>>,
     *     conversationId?: string,
     *     conversationType?: string
     * }
     */
    public function fetchConversation(string $conversationId, string $conversationType = 'FROM_MEMBERS'): array
    {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            throw new \InvalidArgumentException('Conversation ID is required.');
        }
        if (!in_array($conversationType, ['FROM_EBAY', 'FROM_MEMBERS'], true)) {
            $conversationType = 'FROM_MEMBERS';
        }

        $token       = $this->accessToken();
        $marketplace = $this->marketplace();

        $base = $this->sandbox
            ? 'https://api.sandbox.ebay.com/commerce/message/v1'
            : 'https://api.ebay.com/commerce/message/v1';

        $url = $base . '/conversation/' . rawurlencode($conversationId)
            . '?conversation_type=' . rawurlencode($conversationType);

        $ch = curl_init($url);
        curl_setopt_array($ch, EbayCurl::withSsl([
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'X-EBAY-C-MARKETPLACE-ID: ' . $marketplace,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]));

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("eBay conversation API cURL error: {$err}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("eBay conversation API returned non-JSON (HTTP {$code}).");
        }
        if ($code < 200 || $code >= 300) {
            $msg = $this->extractEbayErrorMessage($decoded);
            throw new RuntimeException("eBay conversation API failed (HTTP {$code}): {$msg}");
        }

        $decoded['messages'] = is_array($decoded['messages'] ?? null)
            ? array_values(array_filter($decoded['messages'], 'is_array'))
            : [];

        return $decoded;
    }

    /**
     * Search eBay Post-Order support cases.
     *
     * @return array{members: list<array<string, mixed>>, total: int, offset: int, limit: int}
     */
    public function searchSupportCases(
        string $statusFilter = 'OPEN',
        int $limit = 50,
        int $offset = 0,
        int $lookbackDays = 90,
    ): array {
        return $this->postOrder()->searchCases($statusFilter, $limit, $offset, $lookbackDays);
    }

    /** @return array<string, mixed> */
    public function getSupportCase(string $caseId): array
    {
        return $this->postOrder()->getCase($caseId);
    }

    /** @return array<string, mixed> */
    public function provideSupportCaseShipmentInfo(
        string $caseId,
        string $shippingCarrierName,
        string $trackingNumber,
    ): array {
        return $this->postOrder()->provideCaseShipmentInfo($caseId, $shippingCarrierName, $trackingNumber);
    }

    /** @return array<string, mixed> */
    public function issueSupportCaseRefund(string $caseId, string $comments = ''): array
    {
        return $this->postOrder()->issueCaseRefund($caseId, $comments);
    }

    /** @return array<string, mixed> */
    public function appealSupportCase(string $caseId, string $comments): array
    {
        return $this->postOrder()->appealCase($caseId, $comments);
    }

    /**
     * @param array<string, string> $address
     * @return array<string, mixed>
     */
    public function provideSupportCaseReturnAddress(string $caseId, array $address): array
    {
        return $this->postOrder()->provideCaseReturnAddress($caseId, $address);
    }

    /**
     * Search eBay Post-Order INR inquiries.
     *
     * @return array{members: list<array<string, mixed>>, total: int, offset: int, limit: int}
     */
    public function searchSupportInquiries(
        string $statusFilter = 'OPEN',
        int $limit = 50,
        int $offset = 0,
        int $lookbackDays = 90,
    ): array {
        return $this->postOrder()->searchInquiries($statusFilter, $limit, $offset, $lookbackDays);
    }

    /** @return array<string, mixed> */
    public function getSupportInquiry(string $inquiryId): array
    {
        return $this->postOrder()->getInquiry($inquiryId);
    }

    /** @return array<string, mixed> */
    public function replySupportInquiry(string $inquiryId, string $message): array
    {
        return $this->postOrder()->sendInquiryMessage($inquiryId, $message);
    }

    /** @return array<string, mixed> */
    public function provideSupportInquiryShipmentInfo(
        string $inquiryId,
        string $shippingCarrierName,
        string $trackingNumber,
        string $comments = '',
    ): array {
        return $this->postOrder()->provideInquiryShipmentInfo(
            $inquiryId,
            $shippingCarrierName,
            $trackingNumber,
            $comments,
        );
    }

    /** @return array<string, mixed> */
    public function issueSupportInquiryRefund(string $inquiryId, string $comments = ''): array
    {
        return $this->postOrder()->issueInquiryRefund($inquiryId, $comments);
    }

    /** @return array<string, mixed> */
    public function escalateSupportInquiry(
        string $inquiryId,
        string $reason,
        string $comments = '',
    ): array {
        return $this->postOrder()->escalateInquiry($inquiryId, $reason, $comments);
    }

    /**
     * Contact a buyer about a listing (used for escalated cases with no Post-Order reply).
     *
     * @return array<string, mixed>
     */
    public function contactBuyerAboutItem(string $buyerUsername, string $itemId, string $message): array
    {
        $buyerUsername = trim($buyerUsername);
        $itemId = trim($itemId);
        $message = trim($message);
        if ($buyerUsername === '') {
            throw new \InvalidArgumentException('Buyer username is required.');
        }
        if ($message === '') {
            throw new \InvalidArgumentException('Message cannot be empty.');
        }
        if (mb_strlen($message) > 2000) {
            throw new \InvalidArgumentException('Message exceeds 2000 characters.');
        }

        $token = $this->accessToken();
        $marketplace = $this->marketplace();
        $base = $this->sandbox
            ? 'https://api.sandbox.ebay.com/commerce/message/v1'
            : 'https://api.ebay.com/commerce/message/v1';

        $payload = [
            'otherPartyUsername' => $buyerUsername,
            'messageText'        => $message,
        ];
        if ($itemId !== '') {
            $payload['reference'] = [
                'referenceType' => 'LISTING',
                'referenceId'   => $itemId,
            ];
        }

        $ch = curl_init($base . '/send_message');
        curl_setopt_array($ch, EbayCurl::withSsl([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'X-EBAY-C-MARKETPLACE-ID: ' . $marketplace,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]));

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("eBay contact buyer cURL error: {$err}");
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("eBay contact buyer returned non-JSON (HTTP {$code}).");
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException(
                'eBay contact buyer failed (HTTP ' . $code . '): ' . $this->extractEbayErrorMessage($decoded)
            );
        }
        return $decoded;
    }

    private function postOrder(): EbayPostOrderClient
    {
        return new EbayPostOrderClient(
            $this->accessToken(),
            $this->sandbox,
            $this->marketplace(),
        );
    }

    /**
     * Fetch a page of conversations (Commerce Message API).
     *
     * @return array{
     *     conversations: array<int, array<string, mixed>>,
     *     limit: int,
     *     offset: int,
     *     total: int,
     *     href?: string,
     *     next?: string
     * }
     */
    public function fetchConversations(
        int $limit = 25,
        int|string $offset = 0,
        string $conversationType = 'FROM_MEMBERS',
        string $status = '',
    ): array {
        $limit  = max(1, min(50, $limit));
        $offset = max(0, is_int($offset) ? $offset : (int) $offset);

        if (!in_array($conversationType, ['FROM_EBAY', 'FROM_MEMBERS'], true)) {
            $conversationType = 'FROM_MEMBERS';
        }

        $token       = $this->accessToken();
        $marketplace = $this->marketplace();

        $base = $this->sandbox
            ? 'https://api.sandbox.ebay.com/commerce/message/v1'
            : 'https://api.ebay.com/commerce/message/v1';

        $query = [
            'conversation_type' => $conversationType,
            'limit'             => $limit,
            'offset'            => $offset,
        ];
        if ($status !== '') {
            $query['conversation_status'] = $status;
        }

        $url = $base . '/conversation?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $attempts = 0;
        $maxTries = 2;
        $body     = '';
        $code     = 0;
        $err      = '';

        do {
            $attempts++;

            $ch = curl_init($url);
            curl_setopt_array($ch, EbayCurl::withSsl([
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token,
                    'X-EBAY-C-MARKETPLACE-ID: ' . $marketplace,
                    'User-Agent: Store/1.0',
                ],
            ]));

            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                if ($attempts < $maxTries) {
                    usleep(200_000 * $attempts);
                    continue;
                }
                throw new RuntimeException("eBay message API cURL error: {$err}");
            }

            if (($code === 429 || $code >= 500) && $attempts < $maxTries) {
                usleep(500_000 * $attempts);
                continue;
            }

            break;
        } while (true);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("eBay message API returned non-JSON (HTTP {$code}).");
        }

        if ($code < 200 || $code >= 300) {
            $msg = $this->extractEbayErrorMessage($decoded);
            throw new RuntimeException("eBay message API failed (HTTP {$code}): {$msg}");
        }

        $conversations = is_array($decoded['conversations'] ?? null)
            ? array_values(array_filter($decoded['conversations'], 'is_array'))
            : [];

        return [
            'conversations' => $conversations,
            'limit'         => (int) ($decoded['limit']  ?? $limit),
            'offset'        => (int) ($decoded['offset'] ?? $offset),
            'total'         => (int) ($decoded['total']  ?? count($conversations)),
            'href'          => isset($decoded['href']) ? (string) $decoded['href'] : null,
            'next'          => isset($decoded['next']) ? (string) $decoded['next'] : null,
        ];
    }

    /**
     * Fetch GetMyMessages and return the raw XML response body.
     *
     * Same request shape as fetchMyMessages(), but returns the raw response
     * as a string — for the debug page. Do not use in production code paths.
     *
     * @param array<int, string> $messageIds
     */
    public function fetchMyMessagesRaw(
        int $folderId = 0,
        string $detailLevel = 'ReturnHeaders',
        array $messageIds = [],
        int $page = 1,
        int $perPage = 25
    ): string {
        $allowedLevels = ['ReturnSummary', 'ReturnHeaders', 'ReturnMessages', 'ReturnAll'];
        if (!in_array($detailLevel, $allowedLevels, true)) {
            throw new \InvalidArgumentException(
                "Invalid DetailLevel '{$detailLevel}'. Allowed: "
                . implode(', ', $allowedLevels)
            );
        }

        $token    = $this->accessToken();
        $endpoint = $this->xmlEndpoint();

        $xml  = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<GetMyMessagesRequest xmlns="urn:ebay:apis:eBLBaseComponents">';
        $xml .= '<Version>1209</Version>';
        $xml .= '<DetailLevel>' . $this->xmlEscape($detailLevel) . '</DetailLevel>';
        $xml .= '<FolderID>' . (int) $folderId . '</FolderID>';

        if (!empty($messageIds)) {
            $xml .= '<MessageIDs>';
            foreach (array_slice($messageIds, 0, 10) as $id) {
                $xml .= '<MessageID>' . $this->xmlEscape((string) $id) . '</MessageID>';
            }
            $xml .= '</MessageIDs>';
        }

        $xml .= '<Pagination>';
        $xml .= '<EntriesPerPage>' . (int) $perPage . '</EntriesPerPage>';
        $xml .= '<PageNumber>' . (int) $page . '</PageNumber>';
        $xml .= '</Pagination>';
        $xml .= '</GetMyMessagesRequest>';

        $headers = [
            'X-EBAY-API-IAF-TOKEN: ' . $token,
            'X-EBAY-API-CALL-NAME: GetMyMessages',
            'X-EBAY-API-SITEID: ' . $this->siteId(),
            'X-EBAY-API-COMPATIBILITY-LEVEL: 1209',
            'Content-Type: text/xml;charset=UTF-8',
            'Accept: text/xml',
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, EbayCurl::withSsl([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]));

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("eBay GetMyMessages cURL error: {$err}");
        }

        // For debugging we want the raw body even if HTTP status was non-2xx.
        return (string) $body;
    }

    // =========================================================================
    // Conversations — write operations
    // =========================================================================

    /**
     * Send a message in a conversation.
     *
     * @return array<string, mixed> The API response (contains the new messageId).
     */
    public function sendMessage(
        string $conversationId,
        string $conversationType,
        string $body,
    ): array {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            throw new \InvalidArgumentException('Conversation ID is required.');
        }

        $token       = $this->accessToken();
        $marketplace = $this->marketplace();

        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('Message body cannot be empty.');
        }
        if (mb_strlen($body) > 2000) {
            throw new \InvalidArgumentException('Message body exceeds 2000 characters.');
        }

        $base = $this->sandbox
            ? 'https://api.sandbox.ebay.com/commerce/message/v1'
            : 'https://api.ebay.com/commerce/message/v1';

        $url = $base . '/send_message';

        // conversationType is kept on the method signature for callers, but is
        // not part of eBay's send_message request body.
        $payload = [
            'conversationId' => $conversationId,
            'messageText'    => $body,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, EbayCurl::withSsl([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'X-EBAY-C-MARKETPLACE-ID: ' . $marketplace,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]));

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("eBay send_message cURL error: {$err}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("eBay send_message returned non-JSON (HTTP {$code}).");
        }
        if ($code < 200 || $code >= 300) {
            $msg = $this->extractEbayErrorMessage($decoded);
            throw new RuntimeException("eBay send_message failed (HTTP {$code}): {$msg}");
        }

        return $decoded;
    }

    /**
     * Mark a conversation as read via update_conversation.
     */
    public function markConversationRead(string $conversationId, string $conversationType): void
    {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            throw new \InvalidArgumentException('Conversation ID is required.');
        }
        if (!in_array($conversationType, ['FROM_EBAY', 'FROM_MEMBERS'], true)) {
            $conversationType = 'FROM_MEMBERS';
        }

        $token       = $this->accessToken();
        $marketplace = $this->marketplace();

        $base = $this->sandbox
            ? 'https://api.sandbox.ebay.com/commerce/message/v1'
            : 'https://api.ebay.com/commerce/message/v1';

        $url = $base . '/update_conversation';

        $payload = [
            'conversationId'   => $conversationId,
            'conversationType' => $conversationType,
            'read'             => true,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, EbayCurl::withSsl([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'X-EBAY-C-MARKETPLACE-ID: ' . $marketplace,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]));

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("eBay update_conversation cURL error: {$err}");
        }

        // eBay returns 204 No Content on success.
        if ($code < 200 || $code >= 300) {
            $decoded = json_decode($body, true);
            $msg = is_array($decoded)
                ? $this->extractEbayErrorMessage($decoded)
                : substr($body, 0, 200);
            throw new RuntimeException("eBay update_conversation failed (HTTP {$code}): {$msg}");
        }
    }
}