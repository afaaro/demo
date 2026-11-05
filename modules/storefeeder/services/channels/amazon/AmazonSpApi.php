<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\Amazon;

use Module\StoreFeeder\Services\Channels\CurlSsl;
use RuntimeException;

/**
 * Minimal Selling Partner API HTTP client (LWA access token auth).
 *
 * Docs: https://developer-docs.amazon.com/sp-api/docs/connecting-to-the-selling-partner-api
 */
final class AmazonSpApi
{
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 45;
    private const MAX_TRIES       = 3;

    public function __construct(
        private string $accessToken,
        private string $region = 'eu',
        private bool   $sandbox = false,
    ) {}

    /**
     * @return array{payload: array<int, array<string, mixed>>}
     */
    public function getMarketplaceParticipations(): array
    {
        $decoded = $this->get('/sellers/v1/marketplaceParticipations');
        $payload = $decoded['payload'] ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }

        return ['payload' => array_values(array_filter($payload, 'is_array'))];
    }

    /**
     * Listings Items API — create or fully replace a listing.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function putListingsItem(
        string $sellerId,
        string $sku,
        string $marketplaceId,
        array $body,
        string $issueLocale = 'en_GB',
    ): array {
        $sellerId = trim($sellerId);
        $sku = trim($sku);
        $marketplaceId = trim($marketplaceId);
        if ($sellerId === '' || $sku === '' || $marketplaceId === '') {
            throw new \InvalidArgumentException('sellerId, sku, and marketplaceId are required.');
        }

        $path = '/listings/2021-08-01/items/'
            . rawurlencode($sellerId) . '/'
            . rawurlencode($sku);
        $query = [
            'marketplaceIds' => $marketplaceId,
            'issueLocale'    => $issueLocale !== '' ? $issueLocale : 'en_GB',
        ];

        return $this->request(
            'PUT',
            $this->url($path, $query),
            $body,
        );
    }

    /**
     * Listings Items API — partial update (inventory, price, …).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function patchListingsItem(
        string $sellerId,
        string $sku,
        string $marketplaceId,
        array $body,
        string $issueLocale = 'en_GB',
    ): array {
        $sellerId = trim($sellerId);
        $sku = trim($sku);
        $marketplaceId = trim($marketplaceId);
        if ($sellerId === '' || $sku === '' || $marketplaceId === '') {
            throw new \InvalidArgumentException('sellerId, sku, and marketplaceId are required.');
        }

        $path = '/listings/2021-08-01/items/'
            . rawurlencode($sellerId) . '/'
            . rawurlencode($sku);
        $query = [
            'marketplaceIds' => $marketplaceId,
            'issueLocale'    => $issueLocale !== '' ? $issueLocale : 'en_GB',
        ];

        return $this->request(
            'PATCH',
            $this->url($path, $query),
            $body,
        );
    }

    /**
     * Orders API v0 — list orders.
     *
     * @return array{orders: list<array<string, mixed>>, nextToken: ?string}
     */
    public function getOrders(
        string $marketplaceId,
        string $createdAfter,
        int $maxResults = 50,
        ?string $nextToken = null,
    ): array {
        $marketplaceId = trim($marketplaceId);
        if ($marketplaceId === '') {
            throw new \InvalidArgumentException('marketplaceId is required.');
        }

        $query = [];
        if ($nextToken !== null && trim($nextToken) !== '') {
            $query['NextToken'] = trim($nextToken);
        } elseif ($this->sandbox) {
            // Sandbox only accepts the static fixtures in the Orders model, not real ISO dates.
            $query = [
                'MarketplaceIds' => $marketplaceId,
                'CreatedAfter'   => 'TEST_CASE_200',
            ];
        } else {
            $query = [
                'MarketplaceIds'    => $marketplaceId,
                'CreatedAfter'      => $this->iso8601($createdAfter),
                'MaxResultsPerPage' => (string) max(1, min(100, $maxResults)),
            ];
        }

        try {
            $decoded = $this->get('/orders/v0/orders', $query);
        } catch (RuntimeException $e) {
            $decoded = $this->retryOrders($query, $marketplaceId, $createdAfter, $maxResults, $e);
        }
        $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
        $orders = $payload['Orders'] ?? [];
        if (!is_array($orders)) {
            $orders = [];
        }
        $list = [];
        foreach ($orders as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }

        $token = $payload['NextToken'] ?? null;
        return [
            'orders'    => $list,
            'nextToken' => is_string($token) && $token !== '' ? $token : null,
        ];
    }

    /**
     * Orders API v0 — order line items (one page; Amazon rarely needs more for sync pages).
     *
     * @return list<array<string, mixed>>
     */
    public function getOrderItems(string $amazonOrderId, ?string $nextToken = null): array
    {
        $amazonOrderId = trim($amazonOrderId);
        if ($amazonOrderId === '') {
            throw new \InvalidArgumentException('Amazon order id is required.');
        }

        $path = '/orders/v0/orders/' . rawurlencode($amazonOrderId) . '/orderItems';
        $query = [];
        if ($nextToken !== null && trim($nextToken) !== '') {
            $query['NextToken'] = trim($nextToken);
        }

        $all = [];
        $token = $nextToken;
        for ($page = 0; $page < 5; $page++) {
            $q = [];
            if ($token !== null && trim($token) !== '') {
                $q['NextToken'] = trim($token);
            }
            $decoded = $this->get($path, $q);
            $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
            $items = $payload['OrderItems'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $all[] = $item;
                    }
                }
            }
            $token = is_string($payload['NextToken'] ?? null) ? (string) $payload['NextToken'] : null;
            if ($token === null || $token === '') {
                break;
            }
        }

        return $all;
    }

    /**
     * Orders API v0 — confirmShipment (MFN tracking).
     * Success is HTTP 204 with an empty body.
     *
     * @param list<array{orderItemId: string, quantity: int}> $orderItems
     * @return array<string, mixed>
     */
    public function confirmShipment(
        string $amazonOrderId,
        string $marketplaceId,
        string $trackingNumber,
        string $carrierCode,
        array $orderItems,
        string $shipDateIso = '',
        string $carrierName = '',
        string $packageReferenceId = '1',
    ): array {
        $amazonOrderId = trim($amazonOrderId);
        $marketplaceId = trim($marketplaceId);
        $trackingNumber = trim($trackingNumber);
        $carrierCode = trim($carrierCode);
        if ($amazonOrderId === '' || $marketplaceId === '') {
            throw new \InvalidArgumentException('orderId and marketplaceId are required.');
        }
        if ($trackingNumber === '') {
            throw new \InvalidArgumentException('trackingNumber is required.');
        }
        if ($carrierCode === '') {
            throw new \InvalidArgumentException('carrierCode is required.');
        }
        if ($orderItems === []) {
            throw new \InvalidArgumentException('At least one order item is required.');
        }

        $items = [];
        foreach ($orderItems as $item) {
            $id = trim((string) ($item['orderItemId'] ?? ''));
            if ($id === '') {
                continue;
            }
            $items[] = [
                'orderItemId' => $id,
                'quantity'    => max(1, (int) ($item['quantity'] ?? 1)),
            ];
        }
        if ($items === []) {
            throw new \InvalidArgumentException('At least one order item id is required.');
        }

        $ref = preg_replace('/\D+/', '', $packageReferenceId) ?: '1';
        $shipDate = trim($shipDateIso);
        if ($shipDate === '') {
            $shipDate = gmdate('Y-m-d\TH:i:s\Z');
        }

        $package = [
            'packageReferenceId' => $ref,
            'carrierCode'        => $carrierCode,
            'trackingNumber'     => $trackingNumber,
            'shipDate'           => $shipDate,
            'orderItems'         => $items,
        ];
        $carrierName = trim($carrierName);
        if ($carrierName !== '' || strcasecmp($carrierCode, 'Others') === 0) {
            $package['carrierName'] = $carrierName !== '' ? $carrierName : $carrierCode;
        }

        $path = '/orders/v0/orders/' . rawurlencode($amazonOrderId) . '/shipmentConfirmation';
        return $this->request('POST', $this->url($path), [
            'marketplaceId' => $marketplaceId,
            'packageDetail' => $package,
        ]);
    }

    /**
     * Reports API v2021-06-30 — create a report.
     *
     * @param list<string> $marketplaceIds
     * @return array{reportId: string}
     */
    public function createReport(
        string $reportType,
        array $marketplaceIds,
        ?string $dataStartTime = null,
        ?string $dataEndTime = null,
    ): array {
        $reportType = trim($reportType);
        if ($reportType === '') {
            throw new \InvalidArgumentException('reportType is required.');
        }
        $ids = [];
        foreach ($marketplaceIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            throw new \InvalidArgumentException('At least one marketplaceId is required.');
        }

        $body = [
            'reportType'     => $reportType,
            'marketplaceIds' => array_values($ids),
        ];
        if ($dataStartTime !== null && trim($dataStartTime) !== '') {
            $body['dataStartTime'] = trim($dataStartTime);
        }
        if ($dataEndTime !== null && trim($dataEndTime) !== '') {
            $body['dataEndTime'] = trim($dataEndTime);
        }

        $decoded = $this->request('POST', $this->url('/reports/2021-06-30/reports'), $body);
        $reportId = trim((string) ($decoded['reportId'] ?? ''));
        if ($reportId === '') {
            throw new RuntimeException('Amazon createReport did not return a reportId.');
        }

        return ['reportId' => $reportId];
    }

    /**
     * Reports API — get report status.
     *
     * @return array<string, mixed>
     */
    public function getReport(string $reportId): array
    {
        $reportId = trim($reportId);
        if ($reportId === '') {
            throw new \InvalidArgumentException('reportId is required.');
        }

        return $this->get('/reports/2021-06-30/reports/' . rawurlencode($reportId));
    }

    /**
     * Reports API — get download metadata for a completed report.
     *
     * @return array{reportDocumentId: string, url: string, compressionAlgorithm?: string}
     */
    public function getReportDocument(string $reportDocumentId): array
    {
        $reportDocumentId = trim($reportDocumentId);
        if ($reportDocumentId === '') {
            throw new \InvalidArgumentException('reportDocumentId is required.');
        }

        $decoded = $this->get(
            '/reports/2021-06-30/documents/' . rawurlencode($reportDocumentId)
        );
        $url = trim((string) ($decoded['url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('Amazon report document URL missing.');
        }

        return [
            'reportDocumentId'     => (string) ($decoded['reportDocumentId'] ?? $reportDocumentId),
            'url'                  => $url,
            'compressionAlgorithm' => isset($decoded['compressionAlgorithm'])
                ? (string) $decoded['compressionAlgorithm']
                : '',
        ];
    }

    /**
     * Download a presigned report document URL (optionally GZIP).
     */
    public function downloadReportDocument(string $url, string $compressionAlgorithm = ''): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('Report document URL is required.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize report download cURL.');
        }

        curl_setopt_array($ch, CurlSsl::withSsl([
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: StoreFeeder/1.0 (Language=PHP)',
            ],
        ]));

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Amazon report download cURL error: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Amazon report download failed (HTTP ' . $code . ').');
        }

        $body = (string) $raw;
        if (strtoupper(trim($compressionAlgorithm)) === 'GZIP') {
            $decoded = @gzdecode($body);
            if ($decoded === false) {
                throw new RuntimeException('Unable to gunzip Amazon report document.');
            }
            return $decoded;
        }

        return $body;
    }

    /**
     * Create a report, wait until DONE, return decompressed document body.
     *
     * @param list<string> $marketplaceIds
     */
    public function fetchReportContent(
        string $reportType,
        array $marketplaceIds,
        ?string $dataStartTime = null,
        ?string $dataEndTime = null,
        int $maxWaitSeconds = 120,
    ): string {
        $created = $this->createReport($reportType, $marketplaceIds, $dataStartTime, $dataEndTime);
        $reportId = $created['reportId'];
        $deadline = time() + max(15, $maxWaitSeconds);
        $documentId = '';

        while (time() < $deadline) {
            $report = $this->getReport($reportId);
            $status = strtoupper(trim((string) ($report['processingStatus'] ?? '')));
            if ($status === 'DONE') {
                $documentId = trim((string) ($report['reportDocumentId'] ?? ''));
                break;
            }
            if (in_array($status, ['CANCELLED', 'FATAL'], true)) {
                throw new RuntimeException(
                    'Amazon report ' . $reportId . ' ended with status ' . $status . '.'
                );
            }
            sleep(4);
        }

        if ($documentId === '') {
            throw new RuntimeException(
                'Amazon report ' . $reportId . ' not ready within ' . $maxWaitSeconds . 's — try Sync again shortly.'
            );
        }

        $doc = $this->getReportDocument($documentId);
        return $this->downloadReportDocument(
            $doc['url'],
            (string) ($doc['compressionAlgorithm'] ?? '')
        );
    }

    /**
     * Finances API v0 — list financial events (A-to-Z / SAFE-T / chargebacks live here).
     *
     * @return array{
     *     events: array<string, mixed>,
     *     nextToken: ?string
     * }
     */
    public function listFinancialEvents(
        ?string $postedAfter = null,
        ?string $postedBefore = null,
        int $maxResults = 100,
        ?string $nextToken = null,
    ): array {
        $query = [];
        if ($nextToken !== null && trim($nextToken) !== '') {
            $query['NextToken'] = trim($nextToken);
        } else {
            $query['MaxResultsPerPage'] = (string) max(1, min(100, $maxResults));
            if ($postedAfter !== null && trim($postedAfter) !== '') {
                $query['PostedAfter'] = trim($postedAfter);
            }
            if ($postedBefore !== null && trim($postedBefore) !== '') {
                $query['PostedBefore'] = trim($postedBefore);
            }
        }

        $decoded = $this->get('/finances/v0/financialEvents', $query);
        $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
        $events = is_array($payload['FinancialEvents'] ?? null) ? $payload['FinancialEvents'] : [];
        $token = $payload['NextToken'] ?? null;

        return [
            'events'    => $events,
            'nextToken' => is_string($token) && $token !== '' ? $token : null,
        ];
    }

    /**
     * Messaging API v1 — available buyer message types for an order.
     *
     * @return array<string, mixed> HAL document (_links.actions, …)
     */
    public function getMessagingActionsForOrder(string $amazonOrderId, string $marketplaceId): array
    {
        $amazonOrderId = trim($amazonOrderId);
        $marketplaceId = trim($marketplaceId);
        if ($amazonOrderId === '' || $marketplaceId === '') {
            throw new \InvalidArgumentException('orderId and marketplaceId are required.');
        }

        $path = '/messaging/v1/orders/' . rawurlencode($amazonOrderId);
        return $this->request('GET', $this->url($path, [
            'marketplaceIds' => $marketplaceId,
        ]));
    }

    /**
     * Messaging API v1 — send a free-text order message (confirmOrderDetails, …).
     *
     * @return array<string, mixed>
     */
    public function createOrderMessage(
        string $amazonOrderId,
        string $marketplaceId,
        string $messageType,
        string $text,
    ): array {
        $amazonOrderId = trim($amazonOrderId);
        $marketplaceId = trim($marketplaceId);
        $messageType = trim($messageType);
        $text = trim($text);
        if ($amazonOrderId === '' || $marketplaceId === '') {
            throw new \InvalidArgumentException('orderId and marketplaceId are required.');
        }
        if ($messageType === '') {
            throw new \InvalidArgumentException('messageType is required.');
        }
        if ($text === '') {
            throw new \InvalidArgumentException('Message text is required.');
        }
        if (mb_strlen($text) > 2000) {
            throw new \InvalidArgumentException('Message text exceeds 2000 characters.');
        }

        // Allow bare type or trailing path segment from HAL href.
        $messageType = basename(str_replace('\\', '/', $messageType));
        $messageType = preg_replace('/[^a-zA-Z0-9_-]/', '', $messageType) ?? '';
        if ($messageType === '') {
            throw new \InvalidArgumentException('Invalid message type.');
        }

        $path = '/messaging/v1/orders/'
            . rawurlencode($amazonOrderId)
            . '/messages/'
            . rawurlencode($messageType);

        return $this->request(
            'POST',
            $this->url($path, ['marketplaceIds' => $marketplaceId]),
            ['text' => $text],
        );
    }

    /**
     * @param array<string, scalar|list<string>> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $this->url($path, $query));
    }

    /**
     * @param array<string, scalar|list<string>> $query
     */
    private function url(string $path, array $query = []): string
    {
        $path = '/' . ltrim($path, '/');
        $url  = $this->baseUrl() . $path;
        if ($query === []) {
            return $url;
        }

        $parts = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            $encoded = rawurlencode((string) $value);
            // Orders API date params fail with %3A-encoded colons.
            $encoded = str_replace(['%3A', '%2B'], [':', '+'], $encoded);
            $parts[] = rawurlencode((string) $key) . '=' . $encoded;
        }

        return $url . '?' . implode('&', $parts);
    }

    /**
     * @param array<string, scalar|list<string>> $query
     * @return array<string, mixed>
     */
    private function retryOrders(
        array $query,
        string $marketplaceId,
        string $createdAfter,
        int $maxResults,
        RuntimeException $first,
    ): array {
        if (!str_contains($first->getMessage(), 'InvalidInput')) {
            throw $first;
        }

        if ($this->sandbox) {
            $query['CreatedAfter'] = 'TEST_CASE_200';
            $query['MarketplaceIds'] = 'ATVPDKIKX0DER';
            unset($query['MaxResultsPerPage'], $query['LastUpdatedAfter']);
            return $this->get('/orders/v0/orders', $query);
        }

        if (isset($query['NextToken'])) {
            throw $first;
        }

        $retry = [
            'MarketplaceIds'    => $marketplaceId,
            'LastUpdatedAfter'  => $this->iso8601($createdAfter),
            'MaxResultsPerPage' => (string) max(1, min(100, $maxResults)),
        ];
        try {
            return $this->get('/orders/v0/orders', $retry);
        } catch (RuntimeException $second) {
            throw new RuntimeException(
                $second->getMessage()
                . ' Marketplace ' . $marketplaceId
                . ' was rejected. Confirm Amazon UK is selected, sandbox is off, '
                . 'and this LWA app is authorized for the EU / Amazon.co.uk selling partner.',
                0,
                $second
            );
        }
    }

    private function iso8601(string $value): string
    {
        $value = trim($value);
        $ts = $value !== '' ? strtotime($value) : false;
        if ($ts === false) {
            $ts = time() - 30 * 86400;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $payload = null): array
    {
        $attempts = 0;
        $body     = '';
        $code     = 0;
        $err      = '';

        do {
            $attempts++;

            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Unable to initialize Amazon SP-API cURL.');
            }

            try {
                $headers = [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'x-amz-access-token: ' . $this->accessToken,
                    'x-amz-date: ' . gmdate('Ymd\THis\Z'),
                    'User-Agent: StoreFeeder/1.0 (Language=PHP)',
                ];

                $opts = [
                    CURLOPT_CUSTOMREQUEST  => $method,
                    CURLOPT_RETURNTRANSFER => true,
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
                throw new RuntimeException("Amazon SP-API cURL error: {$err}");
            }

            if (($code === 429 || $code >= 500) && $attempts < self::MAX_TRIES) {
                usleep(1_000_000 * $attempts);
                continue;
            }

            break;
        } while (true);

        // confirmShipment and similar ops return 204 No Content.
        if ($code === 204 || trim((string) $body) === '') {
            if ($code >= 200 && $code < 300) {
                return [];
            }
            throw new RuntimeException('Amazon SP-API error (HTTP ' . $code . '): empty response.');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Amazon SP-API returned non-JSON (HTTP {$code}).");
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException(
                'Amazon SP-API error (HTTP ' . $code . '): ' . $this->extractError($decoded)
            );
        }

        return $decoded;
    }

    private function baseUrl(): string
    {
        $region = strtolower($this->region);
        if (!in_array($region, ['na', 'eu', 'fe'], true)) {
            $region = 'eu';
        }

        if ($this->sandbox) {
            return match ($region) {
                'na'    => 'https://sandbox.sellingpartnerapi-na.amazon.com',
                'fe'    => 'https://sandbox.sellingpartnerapi-fe.amazon.com',
                default => 'https://sandbox.sellingpartnerapi-eu.amazon.com',
            };
        }

        return match ($region) {
            'na'    => 'https://sellingpartnerapi-na.amazon.com',
            'fe'    => 'https://sellingpartnerapi-fe.amazon.com',
            default => 'https://sellingpartnerapi-eu.amazon.com',
        };
    }

    /** @param array<string, mixed> $decoded */
    private function extractError(array $decoded): string
    {
        if (isset($decoded['errors']) && is_array($decoded['errors']) && $decoded['errors'] !== []) {
            $first = $decoded['errors'][0] ?? null;
            if (is_array($first)) {
                $parts = array_filter([
                    (string) ($first['code'] ?? ''),
                    (string) ($first['message'] ?? ''),
                    (string) ($first['details'] ?? ''),
                ], static fn($v) => $v !== '');
                if ($parts !== []) {
                    return implode(' — ', $parts);
                }
            }
        }

        // Listings Items often returns issues instead of errors on 200; keep for HTTP errors too.
        if (isset($decoded['issues']) && is_array($decoded['issues'])) {
            $msgs = [];
            foreach ($decoded['issues'] as $issue) {
                if (!is_array($issue)) {
                    continue;
                }
                $msgs[] = trim((string) ($issue['message'] ?? $issue['code'] ?? ''));
            }
            $msgs = array_values(array_filter($msgs));
            if ($msgs !== []) {
                return implode('; ', array_slice($msgs, 0, 5));
            }
        }

        foreach (['message', 'error_description', 'error'] as $key) {
            if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                return $decoded[$key];
            }
        }

        return 'Unknown error';
    }
}
