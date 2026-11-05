<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\Bnq;

use RuntimeException;

/**
 * Mirakl MMP HTTP client for B&Q (Kingfisher).
 *
 * OR11 orders · OR21 accept · OR23 tracking · OR24 ship · OF24 offers
 * M11/M10 inbox threads · M12 reply.
 */
final class BnqMiraklClient
{
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 60;

    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private ?string $shopId = null,
    ) {
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        if (str_ends_with(strtolower($this->baseUrl), '/api')) {
            $this->baseUrl = substr($this->baseUrl, 0, -4);
        }
        $shopId = trim((string) $shopId);
        $this->shopId = $shopId !== '' ? $shopId : null;
    }

    /**
     * A01 — Get account information (connection test).
     *
     * @return array<string, mixed>
     */
    public function getAccount(): array
    {
        return $this->request('GET', '/api/account');
    }

    /**
     * OR11 — List orders with pagination.
     *
     * @return array{orders: list<array<string, mixed>>, total_count: int, offset: int, limit: int}
     */
    public function listOrders(
        int $max = 50,
        int $offset = 0,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        $query = [
            'max'    => (string) max(1, min(100, $max)),
            'offset' => (string) max(0, $offset),
        ];
        if ($startDate !== null && $startDate !== '') {
            $query['start_date'] = $startDate;
        }
        if ($endDate !== null && $endDate !== '') {
            $query['end_date'] = $endDate;
        }
        if ($this->shopId !== null) {
            $query['shop_id'] = $this->shopId;
        }

        $path = '/api/orders?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $decoded = $this->request('GET', $path);

        $orders = $decoded['orders'] ?? [];
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
            'orders'      => $list,
            'total_count' => (int) ($decoded['total_count'] ?? count($list)),
            'offset'      => $offset,
            'limit'       => max(1, min(100, $max)),
        ];
    }

    /**
     * OF21 — List offers (optionally filter by shop SKU).
     *
     * @return list<array<string, mixed>>
     */
    public function listOffers(?string $shopSku = null, int $max = 10): array
    {
        $query = [
            'max'    => (string) max(1, min(100, $max)),
            'offset' => '0',
        ];
        if ($shopSku !== null && trim($shopSku) !== '') {
            $query['sku'] = trim($shopSku);
        }
        if ($this->shopId !== null) {
            $query['shop_id'] = $this->shopId;
        }

        $path = '/api/offers?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $decoded = $this->request('GET', $path);
        $offers = $decoded['offers'] ?? [];
        if (!is_array($offers)) {
            return [];
        }
        $list = [];
        foreach ($offers as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }
        return $list;
    }

    /**
     * OR21 — Accept (or refuse) order lines in WAITING_ACCEPTANCE.
     *
     * @param list<array{id: string, accepted: bool}> $orderLines
     * @return array<string, mixed>
     */
    public function acceptOrder(string $orderId, array $orderLines): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            throw new \InvalidArgumentException('Order ID is required.');
        }
        if ($orderLines === []) {
            throw new \InvalidArgumentException('At least one order line is required.');
        }

        $path = '/api/orders/' . rawurlencode($orderId) . '/accept';
        if ($this->shopId !== null) {
            $path .= '?shop_id=' . rawurlencode($this->shopId);
        }

        return $this->request('PUT', $path, ['order_lines' => array_values($orderLines)]);
    }

    /**
     * OR23 — Update carrier tracking for an order.
     *
     * @return array<string, mixed>
     */
    public function updateOrderTracking(
        string $orderId,
        string $trackingNumber,
        string $carrierCode = '',
        string $carrierName = '',
    ): array {
        $orderId = trim($orderId);
        $trackingNumber = trim($trackingNumber);
        if ($orderId === '') {
            throw new \InvalidArgumentException('Order ID is required.');
        }
        if ($trackingNumber === '') {
            throw new \InvalidArgumentException('Tracking number is required.');
        }

        $payload = ['tracking_number' => $trackingNumber];
        $carrierCode = trim($carrierCode);
        $carrierName = trim($carrierName);
        if ($carrierCode !== '') {
            $payload['carrier_code'] = $carrierCode;
        }
        if ($carrierName !== '') {
            $payload['carrier_name'] = $carrierName;
        }
        if ($carrierCode === '' && $carrierName === '') {
            $payload['carrier_name'] = 'Other';
        }

        $path = '/api/orders/' . rawurlencode($orderId) . '/tracking';
        if ($this->shopId !== null) {
            $path .= '?shop_id=' . rawurlencode($this->shopId);
        }

        return $this->request('PUT', $path, $payload);
    }

    /**
     * OR24 — Validate shipment (order must be in SHIPPING with tracking).
     *
     * @return array<string, mixed>
     */
    public function confirmOrderShipment(string $orderId): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            throw new \InvalidArgumentException('Order ID is required.');
        }

        $path = '/api/orders/' . rawurlencode($orderId) . '/ship';
        if ($this->shopId !== null) {
            $path .= '?shop_id=' . rawurlencode($this->shopId);
        }

        // Mirakl requires Content-Length: 0 with an empty body.
        return $this->request('PUT', $path, []);
    }

    /**
     * M11 — List inbox threads (seek pagination).
     *
     * @return array{threads: list<array<string, mixed>>, next: ?string}
     */
    public function listThreads(
        int $limit = 50,
        ?string $pageToken = null,
        bool $withMessages = true,
    ): array {
        $limit = max(1, min(100, $limit));
        if ($pageToken !== null && trim($pageToken) !== '') {
            $query = ['page_token' => trim($pageToken)];
        } else {
            $query = [
                'limit'         => (string) $limit,
                'with_messages' => $withMessages ? 'true' : 'false',
            ];
        }
        if ($this->shopId !== null) {
            $query['shop_id'] = $this->shopId;
        }

        $path = '/api/inbox/threads?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $decoded = $this->request('GET', $path);
        $data = $decoded['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }
        $threads = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $threads[] = $row;
            }
        }
        $next = $decoded['next_page_token'] ?? null;

        return [
            'threads' => $threads,
            'next'    => is_string($next) && $next !== '' ? $next : null,
        ];
    }

    /**
     * M10 — Retrieve one thread (with messages).
     *
     * @return array<string, mixed>
     */
    public function getThread(string $threadId): array
    {
        $threadId = trim($threadId);
        if ($threadId === '') {
            throw new \InvalidArgumentException('Thread ID is required.');
        }

        $path = '/api/inbox/threads/' . rawurlencode($threadId);
        if ($this->shopId !== null) {
            $path .= '?shop_id=' . rawurlencode($this->shopId);
        }

        return $this->request('GET', $path);
    }

    /**
     * M12 — Reply to a thread (multipart message_input).
     *
     * @param list<array{type: string, id?: string}> $to
     * @return array{message_id?: string, thread_id?: string}
     */
    public function replyToThread(string $threadId, string $body, array $to): array
    {
        $threadId = trim($threadId);
        $body = trim($body);
        if ($threadId === '') {
            throw new \InvalidArgumentException('Thread ID is required.');
        }
        if ($body === '') {
            throw new \InvalidArgumentException('Message body is required.');
        }
        if ($to === []) {
            throw new \InvalidArgumentException('At least one recipient is required.');
        }

        $messageInput = json_encode([
            'body' => $body,
            'to'   => array_values($to),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($messageInput === false) {
            throw new RuntimeException('Unable to encode Mirakl message_input.');
        }

        $path = '/api/inbox/threads/' . rawurlencode($threadId) . '/message';
        if ($this->shopId !== null) {
            $path .= '?shop_id=' . rawurlencode($this->shopId);
        }

        return $this->multipartRequest('POST', $path, [
            'message_input' => $messageInput,
        ]);
    }

    /**
     * OF24 — Create, update, or delete offers.
     *
     * @param list<array<string, mixed>> $offers
     * @return array{import_id: int}
     */
    public function upsertOffers(array $offers): array
    {
        if ($offers === []) {
            throw new \InvalidArgumentException('At least one offer is required.');
        }

        $path = '/api/offers';
        if ($this->shopId !== null) {
            $path .= '?shop_id=' . rawurlencode($this->shopId);
        }

        $decoded = $this->request('POST', $path, ['offers' => array_values($offers)]);
        $importId = (int) ($decoded['import_id'] ?? 0);
        if ($importId <= 0) {
            throw new RuntimeException('B&Q Mirakl OF24 did not return an import_id.');
        }

        return ['import_id' => $importId];
    }

    /**
     * @param array<string, string> $fields  form fields (values may be JSON strings)
     * @return array<string, mixed>
     */
    private function multipartRequest(string $method, string $path, array $fields): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('B&Q Mirakl API key is required.');
        }
        if ($this->baseUrl === '') {
            throw new RuntimeException('B&Q API base URL is required.');
        }

        $url = $this->baseUrl . (str_starts_with($path, '/') ? $path : '/' . $path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $boundary = '----SfMirakl' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n";
            if ($name === 'message_input') {
                $body .= "Content-Type: application/json\r\n";
            }
            $body .= "\r\n" . $value . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'User-Agent: StoreFeeder/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("B&Q Mirakl cURL error: {$err}");
        }

        if ($code === 201 || $code === 204 || trim((string) $raw) === '') {
            if ($code >= 200 && $code < 300) {
                $decoded = json_decode((string) $raw, true);
                return is_array($decoded) ? $decoded : [];
            }
        }

        $decoded = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded)
                ? (string) ($decoded['message'] ?? $decoded['error'] ?? substr((string) $raw, 0, 240))
                : substr((string) $raw, 0, 240);
            throw new RuntimeException("B&Q Mirakl API failed (HTTP {$code}): {$msg}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('B&Q Mirakl API key is required.');
        }
        if ($this->baseUrl === '') {
            throw new RuntimeException('B&Q API base URL is required.');
        }

        $url = $this->baseUrl . (str_starts_with($path, '/') ? $path : '/' . $path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $headers = [
            'Authorization: ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: StoreFeeder/1.0',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($method !== 'GET') {
            if ($payload === []) {
                // OR24 ship — empty JSON object / zero-length body accepted by Mirakl.
                $opts[CURLOPT_POSTFIELDS] = '';
                $headers[] = 'Content-Length: 0';
                $opts[CURLOPT_HTTPHEADER] = $headers;
            } elseif ($payload !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }

        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("B&Q Mirakl cURL error: {$err}");
        }

        // 204 No Content is success for accept / tracking / ship.
        if ($code === 204 || trim((string) $body) === '') {
            if ($code >= 200 && $code < 300) {
                return [];
            }
        }

        $decoded = json_decode((string) $body, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded)
                ? (string) ($decoded['message']
                    ?? $decoded['error']
                    ?? ($decoded['errors'][0]['message'] ?? substr((string) $body, 0, 240)))
                : substr((string) $body, 0, 240);
            throw new RuntimeException("B&Q Mirakl API failed (HTTP {$code}): {$msg}");
        }

        return is_array($decoded) ? $decoded : [];
    }
}
