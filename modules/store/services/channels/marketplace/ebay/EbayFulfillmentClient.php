<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Ebay;

use RuntimeException;

/**
 * eBay Fulfillment API — read orders.
 *
 * Docs: https://developer.ebay.com/api-docs/sell/fulfillment/overview.html
 */
final class EbayFulfillmentClient
{
    private const BASE_URL_SANDBOX    = 'https://api.sandbox.ebay.com';
    private const BASE_URL_PRODUCTION = 'https://api.ebay.com';
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;
    private const MAX_TRIES       = 3;

    public function __construct(
        private string $accessToken,
        private bool   $sandbox     = true,
        private string $marketplace = 'EBAY_GB',
    ) {}

    /**
     * Fetch a page of orders created within the last N days.
     *
     * @return array{
     *     orders: array<int, array<string, mixed>>,
     *     next:   ?string
     * }
     */
    public function fetchOrders(int $days = 30, int $limit = 50, ?string $offset = null): array
    {
        // eBay's "next" is often a full/relative URI, not a bare offset.
        if ($offset !== null && $offset !== '' && $this->looksLikeNextUri($offset)) {
            $url = $this->absoluteApiUrl($offset);
        } else {
            $filter = 'creationdate:['
                . gmdate('Y-m-d\TH:i:s.000\Z', time() - max(1, $days) * 86400)
                . '..]';

            $query = [
                'filter' => $filter,
                'limit'  => max(1, min(200, $limit)),
            ];
            if ($offset !== null && $offset !== '' && ctype_digit($offset)) {
                $query['offset'] = $offset;
            }

            $url = $this->baseUrl()
                . '/sell/fulfillment/v1/order?'
                . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $body = $this->get($url);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('eBay orders response was not valid JSON.');
        }

        $next = null;
        if (!empty($decoded['next']) && is_string($decoded['next'])) {
            $next = $this->absoluteApiUrl($decoded['next']);
        }

        $orders = is_array($decoded['orders'] ?? null) ? $decoded['orders'] : [];
        $normalized = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $normalized[] = $this->normalizeOrder($order);
        }

        return [
            'orders' => $normalized,
            'next'   => $next,
        ];
    }

    /**
     * Fetch a single order by eBay order id (full line items + quantity).
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            throw new \InvalidArgumentException('Order ID is required.');
        }

        $url = $this->baseUrl()
            . '/sell/fulfillment/v1/order/'
            . rawurlencode($orderId);

        $body = $this->get($url);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new RuntimeException('eBay getOrder response was not valid JSON.');
        }

        return $this->normalizeOrder($decoded);
    }

    /**
     * Ensure every line item carries the exact buyer quantity from eBay.
     * Re-fetches the order when the list payload omits quantity.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function normalizeOrder(array $order, bool $allowRefetch = true): array
    {
        $lineItems = is_array($order['lineItems'] ?? null) ? $order['lineItems'] : [];
        $needsRefetch = false;
        $normalizedLines = [];

        foreach ($lineItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = $this->extractLineQuantity($item);
            if ($qty === null) {
                $needsRefetch = true;
                break;
            }
            $normalizedLines[] = $this->normalizeLineItem($item, $qty);
        }

        if ($needsRefetch && $allowRefetch) {
            $orderId = trim((string) ($order['orderId'] ?? ''));
            if ($orderId !== '') {
                try {
                    return $this->normalizeOrder($this->getOrderRaw($orderId), false);
                } catch (\Throwable) {
                    // Fall through and keep best-effort lines from the list payload.
                }
            }
        }

        if (!$needsRefetch) {
            $order['lineItems'] = $normalizedLines;
            return $order;
        }

        // Refetch failed or not allowed — still coerce whatever we have.
        $fallback = [];
        foreach ($lineItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = $this->extractLineQuantity($item) ?? 1;
            $fallback[] = $this->normalizeLineItem($item, $qty);
        }
        $order['lineItems'] = $fallback;

        return $order;
    }

    /**
     * Raw getOrder without normalize (avoids recursion).
     *
     * @return array<string, mixed>
     */
    private function getOrderRaw(string $orderId): array
    {
        $url = $this->baseUrl()
            . '/sell/fulfillment/v1/order/'
            . rawurlencode($orderId);

        $body = $this->get($url);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new RuntimeException('eBay getOrder response was not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractLineQuantity(array $item): ?int
    {
        foreach (['quantity', 'QuantityPurchased', 'Quantity', 'qty'] as $key) {
            if (!array_key_exists($key, $item) || $item[$key] === null || $item[$key] === '') {
                continue;
            }
            if (is_numeric($item[$key])) {
                return max(1, (int) $item[$key]);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeLineItem(array $item, int $qty): array
    {
        $item['quantity'] = $qty;
        $item['_ebay_quantity'] = $qty;

        // Already converted on a prior normalize pass — keep unit price as-is.
        if (!empty($item['_unit_priced'])) {
            return $item;
        }

        // eBay lineItemCost / discountedLineItemCost are extended (unit × qty).
        // Convert to unit price so OMS line totals = qty × unit match eBay.
        $extended = null;
        if (isset($item['discountedLineItemCost']['value']) && is_numeric($item['discountedLineItemCost']['value'])) {
            $extended = (float) $item['discountedLineItemCost']['value'];
        } elseif (isset($item['lineItemCost']['value']) && is_numeric($item['lineItemCost']['value'])) {
            $extended = (float) $item['lineItemCost']['value'];
        }

        if ($extended !== null && $qty > 0) {
            $unit = round($extended / $qty, 4);
            if (!isset($item['lineItemCost']) || !is_array($item['lineItemCost'])) {
                $item['lineItemCost'] = [];
            }
            $item['lineItemCost']['value'] = $unit;
            $item['_unit_priced'] = true;
            $item['_ebay_extended_cost'] = $extended;
        }

        return $item;
    }

    private function looksLikeNextUri(string $value): bool
    {
        return str_starts_with($value, 'http://')
            || str_starts_with($value, 'https://')
            || str_starts_with($value, '/sell/');
    }

    private function absoluteApiUrl(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }
        if (str_starts_with($value, '/')) {
            return $this->baseUrl() . $value;
        }

        return $this->baseUrl() . '/sell/fulfillment/v1/' . ltrim($value, '/');
    }

    /**
     * Mark an order as shipped with tracking.
     *
     * @param array<int, array{lineItemId: string, quantity: int}> $lineItems
     * @return array<string, mixed>
     */
    public function createShippingFulfillment(
        string $orderId,
        array $lineItems,
        string $trackingNumber,
        string $carrierCode,
        ?string $shippedDate = null,
    ): array {
        $orderId = trim($orderId);
        if ($orderId === '') {
            throw new \InvalidArgumentException('Order ID is required.');
        }
        if ($lineItems === []) {
            throw new \InvalidArgumentException('At least one line item is required.');
        }

        $payload = [
            'lineItems' => array_values(array_map(static function (array $li): array {
                return [
                    'lineItemId' => (string) $li['lineItemId'],
                    'quantity'   => (int) $li['quantity'],
                ];
            }, $lineItems)),
            'shippedDate'         => $shippedDate ?? gmdate('Y-m-d\TH:i:s.000\Z'),
            'shippingCarrierCode' => $carrierCode,
            'trackingNumber'      => $trackingNumber,
        ];

        $url = $this->baseUrl()
            . '/sell/fulfillment/v1/order/'
            . rawurlencode($orderId)
            . '/shipping_fulfillment';

        $body = $this->postJson($url, $payload);
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    // --------------------------------------------------------------

    private function baseUrl(): string
    {
        return $this->sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $url, array $payload): string
    {
        $attempts = 0;
        $body     = '';
        $code     = 0;
        $err      = '';

        do {
            $attempts++;

            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Unable to initialize cURL.');
            }

            try {
                curl_setopt_array($ch, EbayCurl::withSsl([
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Authorization: Bearer ' . $this->accessToken,
                        'X-EBAY-C-MARKETPLACE-ID: ' . $this->marketplace,
                        'Content-Language: en-GB',
                        'User-Agent: Store/1.0',
                    ],
                ]));

                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
            } finally {
                curl_close($ch);
            }

            if ($body === false) {
                if ($attempts < self::MAX_TRIES) {
                    usleep(250_000 * $attempts);
                    continue;
                }
                throw new RuntimeException("eBay fulfillment cURL error: {$err}");
            }

            if (($code === 429 || $code >= 500) && $attempts < self::MAX_TRIES) {
                usleep(1_000_000 * $attempts);
                continue;
            }

            break;
        } while (true);

        // 201 Created / 204 No Content are success.
        if ($code < 200 || $code >= 300) {
            $decoded = json_decode((string) $body, true);
            $message = is_array($decoded)
                ? $this->extractEbayErrorMessage($decoded)
                : substr((string) $body, 0, 200);
            throw new RuntimeException("eBay ship failed (HTTP {$code}): {$message}");
        }

        return (string) $body;
    }

    private function get(string $url): string
    {
        $attempts = 0;
        $body     = '';
        $code     = 0;
        $errno    = 0;
        $err      = '';

        do {
            $attempts++;

            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Unable to initialize cURL.');
            }

            try {
                curl_setopt_array($ch, EbayCurl::withSsl([
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                    CURLOPT_HTTPHEADER     => [
                        'Accept: application/json',
                        'Authorization: Bearer ' . $this->accessToken,
                        'X-EBAY-C-MARKETPLACE-ID: ' . $this->marketplace,
                        'User-Agent: Store/1.0',
                    ],
                ]));

                $body = curl_exec($ch);
                if ($body === false) {
                    $errno = curl_errno($ch);
                    $err   = curl_error($ch);
                }
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            } finally {
                curl_close($ch);
            }

            if ($body === false) {
                // Network-level failure.
                if ($attempts < self::MAX_TRIES) {
                    usleep(250_000 * $attempts);
                    continue;
                }
                throw new RuntimeException("eBay API cURL error ({$errno}): {$err}");
            }

            // Retry on rate limit / transient server errors.
            if (($code === 429 || $code >= 500) && $attempts < self::MAX_TRIES) {
                usleep(1_000_000 * $attempts);
                continue;
            }

            break;
        } while (true);

        if ($code < 200 || $code >= 300) {
            $decoded = json_decode($body, true);
            $message = is_array($decoded)
                ? $this->extractEbayErrorMessage($decoded)
                : substr($body, 0, 200);
            throw new RuntimeException("eBay API error (HTTP {$code}): {$message}");
        }

        return $body;
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
}