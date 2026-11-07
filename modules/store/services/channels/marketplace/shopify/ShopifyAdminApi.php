<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Shopify;

use Module\Store\Services\Channels\CurlSsl;
use RuntimeException;

/**
 * Minimal Shopify Admin REST client (access-token auth).
 *
 * Docs: https://shopify.dev/docs/api/admin-rest
 */
final class ShopifyAdminApi
{
    private const API_VERSION      = '2024-10';
    private const CONNECT_TIMEOUT  = 10;
    private const REQUEST_TIMEOUT  = 45;

    private string $shop;

    public function __construct(
        private string $accessToken,
        string $shopDomain,
    ) {
        $this->accessToken = trim($accessToken);
        $shop = preg_replace('#^https?://#i', '', trim($shopDomain)) ?? '';
        $shop = rtrim($shop, '/');
        if ($shop !== '' && !str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }
        $this->shop = strtolower($shop);
    }

    public function shopDomain(): string
    {
        return $this->shop;
    }

    /**
     * @return array<string, mixed>
     */
    public function getShop(): array
    {
        $res = $this->request('GET', '/shop.json');
        $shop = $res['json']['shop'] ?? null;
        return is_array($shop) ? $shop : [];
    }

    /**
     * List orders (cursor via page_info).
     *
     * @return array{orders: list<array<string, mixed>>, next: ?string}
     */
    public function listOrders(
        string $createdAtMinIso,
        int $limit = 50,
        ?string $pageInfo = null,
    ): array {
        $limit = max(1, min(250, $limit));
        if ($pageInfo !== null && trim($pageInfo) !== '') {
            $query = [
                'limit'     => (string) $limit,
                'page_info' => trim($pageInfo),
            ];
        } else {
            $query = [
                'status'         => 'any',
                'created_at_min' => $createdAtMinIso,
                'limit'          => (string) $limit,
                'order'          => 'created_at asc',
            ];
        }

        $res = $this->request('GET', '/orders.json', $query);
        $orders = $res['json']['orders'] ?? [];
        if (!is_array($orders)) {
            $orders = [];
        }
        $list = [];
        foreach ($orders as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }

        return [
            'orders' => $list,
            'next'   => $this->parseNextPageInfo($res['link'] ?? null),
        ];
    }

    /**
     * Find a product that has a variant with this SKU (first page search).
     *
     * @return array{product: array<string, mixed>, variant: array<string, mixed>}|null
     */
    public function findProductBySku(string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        // REST has no SKU filter — scan recent products (adequate for mid-size catalogs).
        $pageInfo = null;
        for ($page = 0; $page < 10; $page++) {
            $query = ['limit' => '250', 'fields' => 'id,title,variants,status'];
            if ($pageInfo !== null) {
                $query = ['limit' => '250', 'page_info' => $pageInfo];
            }
            $res = $this->request('GET', '/products.json', $query);
            $products = $res['json']['products'] ?? [];
            if (!is_array($products)) {
                break;
            }
            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
                foreach ($variants as $variant) {
                    if (!is_array($variant)) {
                        continue;
                    }
                    if (strcasecmp(trim((string) ($variant['sku'] ?? '')), $sku) === 0) {
                        return ['product' => $product, 'variant' => $variant];
                    }
                }
            }
            $pageInfo = $this->parseNextPageInfo($res['link'] ?? null);
            if ($pageInfo === null) {
                break;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public function createProduct(array $product): array
    {
        $res = $this->request('POST', '/products.json', null, ['product' => $product]);
        $created = $res['json']['product'] ?? null;
        if (!is_array($created) || empty($created['id'])) {
            throw new RuntimeException('Shopify did not return a product id.');
        }
        return $created;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public function updateProduct(string|int $productId, array $product): array
    {
        $productId = trim((string) $productId);
        if ($productId === '') {
            throw new \InvalidArgumentException('Product id is required.');
        }
        $product['id'] = (int) $productId;
        $res = $this->request(
            'PUT',
            '/products/' . rawurlencode($productId) . '.json',
            null,
            ['product' => $product],
        );
        $updated = $res['json']['product'] ?? null;
        return is_array($updated) ? $updated : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLocations(): array
    {
        $res = $this->request('GET', '/locations.json');
        $rows = $res['json']['locations'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['active'])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function setInventoryLevel(int $inventoryItemId, int $locationId, int $available): array
    {
        if ($inventoryItemId <= 0 || $locationId <= 0) {
            throw new \InvalidArgumentException('inventory_item_id and location_id are required.');
        }
        $res = $this->request('POST', '/inventory_levels/set.json', null, [
            'location_id'       => $locationId,
            'inventory_item_id' => $inventoryItemId,
            'available'         => max(0, $available),
        ]);
        $level = $res['json']['inventory_level'] ?? null;
        return is_array($level) ? $level : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFulfillmentOrders(string|int $orderId): array
    {
        $orderId = trim((string) $orderId);
        if ($orderId === '') {
            throw new \InvalidArgumentException('Order id is required.');
        }

        $res = $this->request('GET', '/orders/' . rawurlencode($orderId) . '/fulfillment_orders.json');
        $rows = $res['json']['fulfillment_orders'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Create a fulfillment for open fulfillment orders (tracking + notify).
     *
     * @param list<array{fulfillment_order_id: int|string, fulfillment_order_line_items: list<array{id: int|string, quantity: int}>}> $byFo
     * @return array<string, mixed>
     */
    public function createFulfillment(
        array $byFo,
        string $trackingNumber,
        string $trackingCompany = '',
        bool $notifyCustomer = true,
    ): array {
        if ($byFo === []) {
            throw new \InvalidArgumentException('At least one fulfillment order group is required.');
        }
        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            throw new \InvalidArgumentException('Tracking number is required.');
        }

        $payload = [
            'fulfillment' => [
                'line_items_by_fulfillment_order' => array_values($byFo),
                'tracking_info' => array_filter([
                    'number'  => $trackingNumber,
                    'company' => trim($trackingCompany) !== '' ? trim($trackingCompany) : null,
                ], static fn($v) => $v !== null && $v !== ''),
                'notify_customer' => $notifyCustomer,
            ],
        ];

        $res = $this->request('POST', '/fulfillments.json', null, $payload);
        $fulfillment = $res['json']['fulfillment'] ?? null;
        return is_array($fulfillment) ? $fulfillment : (is_array($res['json']) ? $res['json'] : []);
    }

    /**
     * @param array<string, scalar>|null $query
     * @param array<string, mixed>|null  $payload
     * @return array{json: array<string, mixed>, link: ?string, code: int}
     */
    private function request(
        string $method,
        string $path,
        ?array $query = null,
        ?array $payload = null,
    ): array {
        if ($this->accessToken === '') {
            throw new RuntimeException('Shopify access token is required.');
        }
        if ($this->shop === '') {
            throw new RuntimeException('Shopify shop domain is required.');
        }

        $path = '/' . ltrim($path, '/');
        $url = 'https://' . $this->shop . '/admin/api/' . self::API_VERSION . $path;
        if ($query !== null && $query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Shopify cURL.');
        }

        $headers = [
            'X-Shopify-Access-Token: ' . $this->accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Store/1.0',
        ];

        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($method !== 'GET' && $payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        curl_setopt_array($ch, CurlSsl::withSsl($opts));

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Shopify cURL error: {$err}");
        }

        $headerBlob = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);
        $link = $this->extractLinkHeader($headerBlob);

        if ($code === 204 || trim($body) === '') {
            if ($code >= 200 && $code < 300) {
                return ['json' => [], 'link' => $link, 'code' => $code];
            }
            throw new RuntimeException("Shopify API error (HTTP {$code}): empty response.");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Shopify API returned non-JSON (HTTP {$code}).");
        }

        if ($code < 200 || $code >= 300) {
            $msg = (string) (
                $decoded['errors']
                ?? $decoded['error']
                ?? substr($body, 0, 240)
            );
            if (is_array($decoded['errors'] ?? null)) {
                $msg = json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE) ?: $msg;
            }
            throw new RuntimeException("Shopify API error (HTTP {$code}): {$msg}");
        }

        return ['json' => $decoded, 'link' => $link, 'code' => $code];
    }

    private function extractLinkHeader(string $headers): ?string
    {
        foreach (preg_split('/\r\n|\n|\r/', $headers) ?: [] as $line) {
            if (stripos($line, 'Link:') === 0) {
                return trim(substr($line, 5));
            }
        }
        return null;
    }

    private function parseNextPageInfo(?string $linkHeader): ?string
    {
        if ($linkHeader === null || $linkHeader === '') {
            return null;
        }
        // <https://shop.myshopify.com/admin/api/.../orders.json?page_info=xxx&limit=50>; rel="next"
        if (!preg_match('/<([^>]+)>\s*;\s*rel="next"/i', $linkHeader, $m)) {
            return null;
        }
        $url = $m[1];
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
        parse_str($query, $params);
        $info = trim((string) ($params['page_info'] ?? ''));
        return $info !== '' ? $info : null;
    }
}
