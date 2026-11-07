<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\Marketplace\Ebay;

use RuntimeException;

/**
 * eBay Post-Order API — case management + INR inquiries (seller support cases).
 */
final class EbayPostOrderClient
{
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 45;

    public function __construct(
        private string $accessToken,
        private bool   $sandbox = false,
        private string $marketplace = 'EBAY_GB',
    ) {}

    /**
     * Search escalated support cases.
     *
     * @return array{members: list<array<string, mixed>>, total: int, offset: int, limit: int}
     */
    public function searchCases(
        string $statusFilter = 'OPEN',
        int $limit = 50,
        int $offset = 0,
        int $lookbackDays = 90,
    ): array {
        $to = gmdate('Y-m-d\TH:i:s\Z');
        $from = gmdate('Y-m-d\TH:i:s\Z', time() - max(1, $lookbackDays) * 86400);

        $query = [
            'case_status_filter'            => $statusFilter,
            'case_creation_date_range_from' => $from,
            'case_creation_date_range_to'   => $to,
            'fieldgroups'                   => 'DEFAULT',
            'limit'                         => (string) max(1, min(200, $limit)),
            'offset'                        => (string) max(0, $offset),
        ];

        $path = '/post-order/v2/casemanagement/search?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $decoded = $this->request('GET', $path);

        return $this->normalizeSearchPage($decoded, $limit, $offset);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCase(string $caseId): array
    {
        $caseId = trim($caseId);
        if ($caseId === '') {
            throw new \InvalidArgumentException('Case ID is required.');
        }
        return $this->request('GET', '/post-order/v2/casemanagement/' . rawurlencode($caseId));
    }

    /**
     * Provide shipment / tracking info on an escalated case.
     *
     * @return array<string, mixed>
     */
    public function provideCaseShipmentInfo(
        string $caseId,
        string $shippingCarrierName,
        string $trackingNumber,
    ): array {
        $caseId = $this->requireId($caseId, 'Case ID');
        $shippingCarrierName = trim($shippingCarrierName);
        $trackingNumber = trim($trackingNumber);
        if ($shippingCarrierName === '' || $trackingNumber === '') {
            throw new \InvalidArgumentException('Carrier and tracking number are required.');
        }

        return $this->request(
            'POST',
            '/post-order/v2/casemanagement/' . rawurlencode($caseId) . '/provide_shipment_info',
            [
                'shippingCarrierName' => $shippingCarrierName,
                'trackingNumber'      => $trackingNumber,
            ],
        );
    }

    /**
     * Issue a full refund against an escalated case.
     *
     * @return array<string, mixed>
     */
    public function issueCaseRefund(string $caseId, string $comments = ''): array
    {
        $caseId = $this->requireId($caseId, 'Case ID');
        $payload = null;
        $comments = trim($comments);
        if ($comments !== '') {
            $payload = ['content' => $comments];
        }

        return $this->request(
            'POST',
            '/post-order/v2/casemanagement/' . rawurlencode($caseId) . '/issue_refund',
            $payload,
        );
    }

    /**
     * Appeal a case decision (requires comments).
     *
     * @return array<string, mixed>
     */
    public function appealCase(string $caseId, string $comments): array
    {
        $caseId = $this->requireId($caseId, 'Case ID');
        $comments = trim($comments);
        if ($comments === '') {
            throw new \InvalidArgumentException('Appeal comments are required.');
        }

        return $this->request(
            'POST',
            '/post-order/v2/casemanagement/' . rawurlencode($caseId) . '/appeal',
            ['comments' => ['content' => $comments]],
        );
    }

    /**
     * Provide a return address for an escalated case.
     *
     * @param array{
     *   firstName?: string,
     *   lastName?: string,
     *   RMA?: string,
     *   addressLine1?: string,
     *   addressLine2?: string,
     *   city?: string,
     *   stateOrProvince?: string,
     *   postalCode?: string,
     *   country?: string
     * } $address
     * @return array<string, mixed>
     */
    public function provideCaseReturnAddress(string $caseId, array $address): array
    {
        $caseId = $this->requireId($caseId, 'Case ID');

        $payload = [];
        foreach (['firstName', 'lastName', 'RMA'] as $key) {
            $val = trim((string) ($address[$key] ?? ''));
            if ($val !== '') {
                $payload[$key] = $val;
            }
        }

        $returnAddress = [];
        foreach ([
            'addressLine1', 'addressLine2', 'city', 'stateOrProvince', 'postalCode', 'country',
        ] as $key) {
            $val = trim((string) ($address[$key] ?? ''));
            if ($val !== '') {
                $returnAddress[$key] = $val;
            }
        }
        if ($returnAddress !== []) {
            $payload['returnAddress'] = $returnAddress;
        }
        if ($payload === []) {
            throw new \InvalidArgumentException('Return address details are required.');
        }

        return $this->request(
            'POST',
            '/post-order/v2/casemanagement/' . rawurlencode($caseId) . '/provide_return_address',
            $payload,
        );
    }

    /**
     * Search INR inquiries (pre-escalation support cases).
     *
     * @return array{members: list<array<string, mixed>>, total: int, offset: int, limit: int}
     */
    public function searchInquiries(
        string $statusFilter = 'OPEN',
        int $limit = 50,
        int $offset = 0,
        int $lookbackDays = 90,
    ): array {
        $to = gmdate('Y-m-d\TH:i:s\Z');
        $from = gmdate('Y-m-d\TH:i:s\Z', time() - max(1, $lookbackDays) * 86400);

        $query = [
            'inquiry_status'                  => $statusFilter,
            'inquiry_creation_date_range_from'=> $from,
            'inquiry_creation_date_range_to'  => $to,
            'fieldgroups'                     => 'DEFAULT',
            'limit'                           => (string) max(1, min(200, $limit)),
            'offset'                          => (string) max(0, $offset),
        ];

        $path = '/post-order/v2/inquiry/search?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $decoded = $this->request('GET', $path);

        return $this->normalizeSearchPage($decoded, $limit, $offset);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInquiry(string $inquiryId): array
    {
        $inquiryId = trim($inquiryId);
        if ($inquiryId === '') {
            throw new \InvalidArgumentException('Inquiry ID is required.');
        }
        return $this->request('GET', '/post-order/v2/inquiry/' . rawurlencode($inquiryId));
    }

    /**
     * Seller reply on an INR inquiry thread.
     *
     * @return array<string, mixed>
     */
    public function sendInquiryMessage(string $inquiryId, string $content): array
    {
        $inquiryId = trim($inquiryId);
        $content = trim($content);
        if ($inquiryId === '') {
            throw new \InvalidArgumentException('Inquiry ID is required.');
        }
        if ($content === '') {
            throw new \InvalidArgumentException('Message cannot be empty.');
        }
        if (mb_strlen($content) > 1000) {
            throw new \InvalidArgumentException('Message exceeds 1000 characters.');
        }

        return $this->request(
            'POST',
            '/post-order/v2/inquiry/' . rawurlencode($inquiryId) . '/send_message',
            ['message' => ['content' => $content]],
        );
    }

    /**
     * Provide shipment / tracking info on an INR inquiry.
     *
     * @return array<string, mixed>
     */
    public function provideInquiryShipmentInfo(
        string $inquiryId,
        string $shippingCarrierName,
        string $trackingNumber,
        string $comments = '',
    ): array {
        $inquiryId = $this->requireId($inquiryId, 'Inquiry ID');
        $shippingCarrierName = trim($shippingCarrierName);
        $trackingNumber = trim($trackingNumber);
        if ($shippingCarrierName === '' || $trackingNumber === '') {
            throw new \InvalidArgumentException('Carrier and tracking number are required.');
        }

        $payload = [
            'shippingCarrierName' => $shippingCarrierName,
            'trackingNumber'      => $trackingNumber,
            'shippedWithTracking' => true,
        ];
        $comments = trim($comments);
        if ($comments !== '') {
            $payload['sellerComments'] = ['content' => $comments];
        }

        return $this->request(
            'POST',
            '/post-order/v2/inquiry/' . rawurlencode($inquiryId) . '/provide_shipment_info',
            $payload,
        );
    }

    /**
     * Issue a voluntary full refund on an INR inquiry.
     *
     * @return array<string, mixed>
     */
    public function issueInquiryRefund(string $inquiryId, string $comments = ''): array
    {
        $inquiryId = $this->requireId($inquiryId, 'Inquiry ID');
        $payload = null;
        $comments = trim($comments);
        if ($comments !== '') {
            $payload = ['comments' => ['content' => $comments]];
        }

        return $this->request(
            'POST',
            '/post-order/v2/inquiry/' . rawurlencode($inquiryId) . '/issue_refund',
            $payload,
        );
    }

    /**
     * Escalate an INR inquiry to an eBay support case.
     *
     * @return array<string, mixed>
     */
    public function escalateInquiry(
        string $inquiryId,
        string $reason,
        string $comments = '',
    ): array {
        $inquiryId = $this->requireId($inquiryId, 'Inquiry ID');
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Escalate reason is required.');
        }

        $payload = ['escalateInquiryReason' => $reason];
        $comments = trim($comments);
        if ($comments !== '') {
            $payload['comments'] = ['content' => $comments];
        }

        return $this->request(
            'POST',
            '/post-order/v2/inquiry/' . rawurlencode($inquiryId) . '/escalate',
            $payload,
        );
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{members: list<array<string, mixed>>, total: int, offset: int, limit: int}
     */
    private function normalizeSearchPage(array $decoded, int $limit, int $offset): array
    {
        $members = $decoded['members'] ?? $decoded['cases'] ?? $decoded['inquiries'] ?? [];
        if (!is_array($members)) {
            $members = [];
        }
        $list = [];
        foreach ($members as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }

        $pagination = is_array($decoded['paginationOutput'] ?? null)
            ? $decoded['paginationOutput']
            : [];

        return [
            'members' => $list,
            'total'   => (int) ($pagination['totalEntries'] ?? $decoded['total'] ?? count($list)),
            'offset'  => (int) ($pagination['offset'] ?? $offset),
            'limit'   => (int) ($pagination['limit'] ?? $limit),
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->baseUrl() . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        // Post-Order API uses TOKEN auth (legacy) — Bearer also accepted on many hosts.
        $headers = [
            'Authorization: TOKEN ' . $this->accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
            'X-EBAY-C-MARKETPLACE-ID: ' . $this->marketplace,
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
        if ($method !== 'GET' && $payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("eBay Post-Order cURL error: {$err}");
        }

        $decoded = json_decode((string) $body, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded)
                ? (string) ($decoded['errorMessage'] ?? $decoded['message']
                    ?? ($decoded['errors'][0]['message'] ?? substr((string) $body, 0, 240)))
                : substr((string) $body, 0, 240);
            throw new RuntimeException("eBay Post-Order API failed (HTTP {$code}): {$msg}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function requireId(string $id, string $label): string
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException("{$label} is required.");
        }
        return $id;
    }

    private function baseUrl(): string
    {
        return $this->sandbox
            ? 'https://api.sandbox.ebay.com'
            : 'https://api.ebay.com';
    }
}
