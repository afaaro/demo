<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Ebay;

use RuntimeException;

/**
 * Minimal eBay Sell Inventory API client (create/publish offer + qty sync).
 */
final class EbayInventoryClient
{
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 45;

    public function __construct(
        private string $accessToken,
        private bool   $sandbox     = true,
        private string $marketplace = 'EBAY_GB',
    ) {}

    /**
     * Create or replace an inventory item by SKU.
     *
     * @param array<string, mixed> $product  keys: title, description, imageUrls?, aspects?
     */
    public function putInventoryItem(string $sku, array $product, int $quantity, string $condition = 'NEW'): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('SKU is required.');
        }

        $payload = [
            'availability' => [
                'shipToLocationAvailability' => [
                    'quantity' => max(0, $quantity),
                ],
            ],
            'condition' => $condition,
            'product'   => [
                'title'       => mb_substr((string) ($product['title'] ?? 'Item'), 0, 80),
                'description' => (string) ($product['description'] ?? ''),
            ],
        ];

        $images = $product['imageUrls'] ?? [];
        if (is_array($images) && $images !== []) {
            $payload['product']['imageUrls'] = array_values(array_slice($images, 0, 12));
        }

        $aspects = $product['aspects'] ?? null;
        if (is_array($aspects) && $aspects !== []) {
            $clean = [];
            foreach ($aspects as $name => $values) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }
                if (is_string($values) || is_numeric($values)) {
                    $values = [(string) $values];
                }
                if (!is_array($values)) {
                    continue;
                }
                $vals = [];
                foreach ($values as $v) {
                    $v = trim((string) $v);
                    if ($v !== '') {
                        $vals[] = $v;
                    }
                }
                if ($vals !== []) {
                    $clean[$name] = array_values(array_unique($vals));
                }
            }
            if ($clean !== []) {
                $payload['product']['aspects'] = $clean;
            }
        }

        $url = $this->baseUrl() . '/sell/inventory/v1/inventory_item/' . rawurlencode($sku);
        $this->request('PUT', $url, $payload);
    }

    /**
     * Create or replace an inventory item group (multi-variation parent).
     *
     * @param array{
     *   title: string,
     *   description?: string,
     *   imageUrls?: list<string>,
     *   aspects?: array<string, list<string>>,
     *   variantSKUs: list<string>,
     *   variesBy: array{specifications: list<array{name: string, values: list<string>}>}
     * } $group
     */
    public function putInventoryItemGroup(string $groupKey, array $group): void
    {
        $groupKey = $this->sanitizeGroupKey($groupKey);
        if ($groupKey === '') {
            throw new \InvalidArgumentException('Inventory item group key is required.');
        }

        $variantSkus = [];
        foreach ($group['variantSKUs'] ?? [] as $sku) {
            $sku = trim((string) $sku);
            if ($sku !== '') {
                $variantSkus[] = $sku;
            }
        }
        $variantSkus = array_values(array_unique($variantSkus));
        if (count($variantSkus) < 2) {
            throw new RuntimeException('A variation group needs at least two variant SKUs.');
        }

        $specs = [];
        $rawSpecs = is_array($group['variesBy']['specifications'] ?? null)
            ? $group['variesBy']['specifications']
            : [];
        foreach ($rawSpecs as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $name = trim((string) ($spec['name'] ?? ''));
            $values = [];
            foreach ($spec['values'] ?? [] as $v) {
                $v = trim((string) $v);
                if ($v !== '' && !in_array($v, $values, true)) {
                    $values[] = $v;
                }
            }
            if ($name !== '' && $values !== []) {
                $specs[] = ['name' => $name, 'values' => $values];
            }
        }
        if ($specs === []) {
            throw new RuntimeException('Variation group is missing variesBy specifications.');
        }

        $payload = [
            'inventoryItemGroupKey' => $groupKey,
            'variantSKUs'           => $variantSkus,
            'title'                 => mb_substr((string) ($group['title'] ?? 'Item'), 0, 80),
            'description'           => (string) ($group['description'] ?? ''),
            'variesBy'              => ['specifications' => $specs],
        ];

        $images = $group['imageUrls'] ?? [];
        if (is_array($images) && $images !== []) {
            $payload['imageUrls'] = array_values(array_slice($images, 0, 12));
        }

        $aspects = $group['aspects'] ?? null;
        if (is_array($aspects) && $aspects !== []) {
            $clean = [];
            foreach ($aspects as $name => $values) {
                $name = trim((string) $name);
                if ($name === '' || !is_array($values)) {
                    continue;
                }
                $vals = [];
                foreach ($values as $v) {
                    $v = trim((string) $v);
                    if ($v !== '') {
                        $vals[] = $v;
                    }
                }
                if ($vals !== []) {
                    $clean[$name] = array_values(array_unique($vals));
                }
            }
            if ($clean !== []) {
                $payload['aspects'] = $clean;
            }
        }

        $url = $this->baseUrl() . '/sell/inventory/v1/inventory_item_group/' . rawurlencode($groupKey);
        $this->request('PUT', $url, $payload);
    }

    /**
     * Publish all unpublished offers in a variation group as one multi-SKU listing.
     * Returns eBay listing (Item) id.
     */
    public function publishOfferByInventoryItemGroup(string $groupKey): string
    {
        $groupKey = $this->sanitizeGroupKey($groupKey);
        if ($groupKey === '') {
            throw new \InvalidArgumentException('Inventory item group key is required.');
        }

        $url = $this->baseUrl() . '/sell/inventory/v1/offer/publish_by_inventory_item_group';
        $decoded = $this->request('POST', $url, [
            'inventoryItemGroupKey' => $groupKey,
            'marketplaceId'         => $this->marketplace,
        ]);
        $listingId = (string) ($decoded['listingId'] ?? '');
        if ($listingId === '') {
            throw new RuntimeException('eBay publish_by_inventory_item_group returned no listingId.');
        }
        return $listingId;
    }

    /**
     * Create or revise an offer for an inventory SKU. Returns offerId.
     * Existing offers are updated via PUT (safe re-push / revise).
     *
     * @param array<string, mixed> $policies  fulfillment/payment/return policy ids + category + location
     */
    public function createOffer(string $sku, float $price, string $currency, array $policies): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('SKU is required.');
        }

        $this->assertOfferPolicies($policies);
        $payload = $this->buildOfferPayload($sku, $price, $currency, $policies);

        $existing = $this->getOffersBySku($sku);
        if ($existing !== []) {
            $offerId = (string) ($existing[0]['offerId'] ?? '');
            if ($offerId !== '') {
                $this->updateOffer($offerId, $payload);
                return $offerId;
            }
        }

        $url = $this->baseUrl() . '/sell/inventory/v1/offer';
        $decoded = $this->request('POST', $url, $payload);
        $offerId = (string) ($decoded['offerId'] ?? '');
        if ($offerId === '') {
            throw new RuntimeException('eBay createOffer returned no offerId.');
        }
        return $offerId;
    }

    /**
     * Revise an existing offer (price, qty, description, policies, category).
     *
     * @param array<string, mixed> $payload  full offer body (same shape as create)
     */
    public function updateOffer(string $offerId, array $payload): void
    {
        $offerId = trim($offerId);
        if ($offerId === '') {
            throw new \InvalidArgumentException('offerId is required.');
        }

        // sku / marketplaceId / format are immutable on update � omit them.
        unset($payload['sku'], $payload['marketplaceId'], $payload['format']);

        $url = $this->baseUrl() . '/sell/inventory/v1/offer/' . rawurlencode($offerId);
        $this->request('PUT', $url, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOffer(string $offerId): array
    {
        $offerId = trim($offerId);
        if ($offerId === '') {
            return [];
        }
        $url = $this->baseUrl() . '/sell/inventory/v1/offer/' . rawurlencode($offerId);
        return $this->request('GET', $url, []);
    }

    /**
     * Publish when unpublished; if already live, return the existing listingId.
     */
    public function publishOfferIfNeeded(string $offerId): string
    {
        $offerId = trim($offerId);
        if ($offerId === '') {
            throw new \InvalidArgumentException('offerId is required.');
        }

        $offer = $this->getOffer($offerId);
        $listingId = $this->extractListingId($offer);
        $status = strtoupper((string) ($offer['status'] ?? ''));
        if ($listingId !== '' && in_array($status, ['PUBLISHED', 'ACTIVE'], true)) {
            return $listingId;
        }

        try {
            return $this->publishOffer($offerId);
        } catch (RuntimeException $e) {
            // Race / already-published: re-fetch listing id.
            $offer = $this->getOffer($offerId);
            $listingId = $this->extractListingId($offer);
            if ($listingId !== '') {
                return $listingId;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $policies
     * @return array<string, mixed>
     */
    private function buildOfferPayload(string $sku, float $price, string $currency, array $policies): array
    {
        $listingDuration = strtoupper(trim((string) ($policies['listing_duration'] ?? 'GTC')));
        if ($listingDuration === '') {
            $listingDuration = 'GTC';
        }

        $payload = [
            'sku'                   => $sku,
            'marketplaceId'         => $this->marketplace,
            'format'                => 'FIXED_PRICE',
            'availableQuantity'     => max(0, (int) ($policies['quantity'] ?? 1)),
            'categoryId'            => (string) ($policies['category_id'] ?? ''),
            'listingDescription'    => (string) ($policies['description'] ?? ''),
            'listingDuration'       => $listingDuration,
            'listingPolicies'       => [
                'fulfillmentPolicyId' => (string) ($policies['fulfillment_policy_id'] ?? ''),
                'paymentPolicyId'     => (string) ($policies['payment_policy_id'] ?? ''),
                'returnPolicyId'      => (string) ($policies['return_policy_id'] ?? ''),
            ],
            'merchantLocationKey'   => (string) ($policies['merchant_location_key'] ?? ''),
            'pricingSummary'        => [
                'price' => [
                    'value'    => number_format($price, 2, '.', ''),
                    'currency' => $currency !== '' ? $currency : 'GBP',
                ],
            ],
        ];

        $storeCats = [];
        foreach (['store_category_1', 'store_category_2'] as $key) {
            $name = $this->storeCategoryName((string) ($policies[$key] ?? ''));
            if ($name !== '' && !in_array($name, $storeCats, true)) {
                $storeCats[] = $name;
            }
        }
        if ($storeCats !== []) {
            $payload['storeCategoryNames'] = array_slice($storeCats, 0, 2);
        }

        return $payload;
    }

    /** @param array<string, mixed> $policies */
    private function assertOfferPolicies(array $policies): void
    {
        foreach ([
            'category_id'           => $policies['category_id'] ?? '',
            'fulfillment_policy_id' => $policies['fulfillment_policy_id'] ?? '',
            'payment_policy_id'     => $policies['payment_policy_id'] ?? '',
            'return_policy_id'      => $policies['return_policy_id'] ?? '',
            'merchant_location_key' => $policies['merchant_location_key'] ?? '',
        ] as $key => $val) {
            if (trim((string) $val) === '') {
                throw new RuntimeException(
                    "Missing channel setting '{$key}' required to publish an eBay listing."
                );
            }
        }
    }

    /** @param array<string, mixed> $offer */
    private function extractListingId(array $offer): string
    {
        $listing = is_array($offer['listing'] ?? null) ? $offer['listing'] : [];
        $id = (string) ($listing['listingId'] ?? $offer['listingId'] ?? '');
        return trim($id);
    }

    private function sanitizeGroupKey(string $key): string
    {
        $key = trim($key);
        // eBay group keys: letters, digits, hyphen, underscore; keep readable.
        $key = preg_replace('/[^A-Za-z0-9_-]+/', '_', $key) ?? '';
        return mb_substr($key, 0, 50);
    }

    /**
     * Publish an offer. Returns listingId (eBay item id).
     */
    public function publishOffer(string $offerId): string
    {
        $url = $this->baseUrl() . '/sell/inventory/v1/offer/'
            . rawurlencode($offerId) . '/publish';
        $decoded = $this->request('POST', $url, []);
        $listingId = (string) ($decoded['listingId'] ?? '');
        if ($listingId === '') {
            throw new RuntimeException('eBay publishOffer returned no listingId.');
        }
        return $listingId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOffersBySku(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return [];
        }

        $url = $this->baseUrl() . '/sell/inventory/v1/offer?'
            . http_build_query(['sku' => $sku], '', '&', PHP_QUERY_RFC3986);
        $decoded = $this->request('GET', $url, []);
        $offers = $decoded['offers'] ?? [];
        return is_array($offers) ? $offers : [];
    }

    /**
     * Update quantity on an existing inventory item.
     */
    public function updateQuantity(string $sku, int $quantity): void
    {
        $this->bulkUpdatePriceQuantity($sku, $quantity, null, null);
    }

    /**
     * Update offer price (and optionally quantity) via bulk_update_price_quantity.
     */
    public function updatePrice(string $sku, float $price, string $currency = 'GBP'): void
    {
        $this->bulkUpdatePriceQuantity($sku, null, $price, $currency);
    }

    private function bulkUpdatePriceQuantity(
        string $sku,
        ?int $quantity,
        ?float $price,
        ?string $currency,
    ): void {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('SKU is required.');
        }

        $request = ['sku' => $sku];
        if ($quantity !== null) {
            $request['shipToLocationAvailability'] = [
                'quantity' => max(0, $quantity),
            ];
        }
        if ($price !== null) {
            $offer = [
                'price' => [
                    'value'    => number_format($price, 2, '.', ''),
                    'currency' => $currency !== null && $currency !== '' ? $currency : 'GBP',
                ],
            ];
            $offerId = $this->firstOfferId($sku);
            if ($offerId !== '') {
                $offer['offerId'] = $offerId;
            }
            if ($quantity !== null) {
                $offer['availableQuantity'] = max(0, $quantity);
            }
            $request['offers'] = [$offer];
        }

        $url = $this->baseUrl() . '/sell/inventory/v1/bulk_update_price_quantity';
        $this->request('POST', $url, [
            'requests' => [$request],
        ]);
    }

    /**
     * eBay storeCategoryNames is the category name, not an id or "/Parent/Child" path.
     * A path is rejected as HTTP 400 "Unknown error".
     */
    private function storeCategoryName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = array_values(array_filter(explode('/', $value), static fn(string $part): bool => trim($part) !== ''));
        if ($parts === []) {
            return '';
        }
        return trim((string) $parts[array_key_last($parts)]);
    }

    private function firstOfferId(string $sku): string
    {
        try {
            $offers = $this->getOffersBySku($sku);
        } catch (RuntimeException) {
            return '';
        }
        return trim((string) ($offers[0]['offerId'] ?? ''));
    }

    private function baseUrl(): string
    {
        return $this->sandbox
            ? 'https://api.sandbox.ebay.com'
            : 'https://api.ebay.com';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->accessToken,
            'X-EBAY-C-MARKETPLACE-ID: ' . $this->marketplace,
            'Content-Language: ' . $this->contentLanguage(),
            'User-Agent: Store/1.0',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if ($method !== 'GET') {
            // An empty PHP array encodes as [], which eBay rejects on publish
            // (HTTP 400, message "Unknown error"). Object endpoints expect {}.
            $body = $payload === []
                ? '{}'
                : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                throw new RuntimeException('Could not encode the eBay inventory request.');
            }
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("eBay inventory cURL error: {$err}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        if ($code < 200 || $code >= 300) {
            $msg = $decoded !== []
                ? $this->extractError($decoded)
                : trim(substr((string) $body, 0, 300));
            if ($msg === '') {
                $msg = 'empty response';
            }
            throw new RuntimeException("eBay inventory API failed (HTTP {$code}): {$msg}");
        }

        $bulk = $this->extractBulkError($decoded);
        if ($bulk !== '') {
            throw new RuntimeException('eBay inventory API failed: ' . $bulk);
        }

        return $decoded;
    }

    private function contentLanguage(): string
    {
        return match ($this->marketplace) {
            'EBAY_US', 'EBAY_MOTORS_US' => 'en-US',
            'EBAY_AU'                   => 'en-AU',
            'EBAY_CA'                   => 'en-CA',
            'EBAY_DE'                   => 'de-DE',
            'EBAY_FR'                   => 'fr-FR',
            'EBAY_IT'                   => 'it-IT',
            'EBAY_ES'                   => 'es-ES',
            default                     => 'en-GB',
        };
    }

    /**
     * bulk_update_price_quantity can return HTTP 200 with a per-SKU failure.
     *
     * @param array<string, mixed> $decoded
     */
    private function extractBulkError(array $decoded): string
    {
        $responses = $decoded['responses'] ?? null;
        if (!is_array($responses)) {
            return '';
        }
        $parts = [];
        foreach ($responses as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (int) ($row['statusCode'] ?? 200);
            if ($status < 400) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            $detail = $this->extractError($row);
            $parts[] = ($sku !== '' ? $sku . ': ' : '') . $detail;
        }
        return implode('; ', $parts);
    }

    /** @param array<string, mixed> $decoded */
    private function extractError(array $decoded): string
    {
        $candidates = [];
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $candidates = array_is_list($decoded['errors']) ? $decoded['errors'] : [$decoded['errors']];
        }
        $errorMessage = $decoded['errorMessage']['error'] ?? null;
        if (is_array($errorMessage)) {
            $candidates = array_merge($candidates, array_is_list($errorMessage) ? $errorMessage : [$errorMessage]);
        }

        foreach ($candidates as $error) {
            if (!is_array($error)) {
                continue;
            }
            $long = trim((string) ($error['longMessage'] ?? ''));
            $msg = trim((string) ($error['message'] ?? ''));
            $text = $long !== '' && ($msg === '' || strcasecmp($msg, 'Unknown error') === 0) ? $long : $msg;
            if ($text === '' || strcasecmp($text, 'Unknown error') === 0) {
                $text = $long !== '' ? $long : $msg;
            }
            $extra = [];
            $errorId = trim((string) ($error['errorId'] ?? ''));
            if ($errorId !== '') {
                $extra[] = 'error ' . $errorId;
            }
            $params = $error['parameters'] ?? null;
            if (is_array($params)) {
                foreach ($params as $param) {
                    if (!is_array($param)) {
                        continue;
                    }
                    $name = trim((string) ($param['name'] ?? ''));
                    $value = trim((string) ($param['value'] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $extra[] = ($name !== '' ? $name . '=' : '') . $value;
                }
            }
            if ($text === '' && $extra === []) {
                continue;
            }
            if ($text === '' || strcasecmp($text, 'Unknown error') === 0) {
                return $extra !== [] ? implode('; ', $extra) : ($text !== '' ? $text : 'Unknown error');
            }
            return $extra !== [] ? $text . ' (' . implode('; ', $extra) . ')' : $text;
        }

        $description = trim((string) ($decoded['error_description'] ?? ''));
        if ($description !== '') {
            return $description;
        }
        $error = trim((string) ($decoded['error'] ?? ''));
        if ($error !== '') {
            return $error;
        }
        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }
        return 'Unknown error';
    }
}
