<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Ebay;

use RuntimeException;

/**
 * eBay Sell Account API — business policies + inventory locations.
 *
 * Docs: https://developer.ebay.com/api-docs/sell/account/overview.html
 */
final class EbayAccountClient
{
    private const BASE_URL_SANDBOX    = 'https://api.sandbox.ebay.com';
    private const BASE_URL_PRODUCTION = 'https://api.ebay.com';
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;
    private const MAX_TRIES       = 3;

    public function __construct(
        private string $accessToken,
        private bool   $sandbox     = false,
        private string $marketplace = 'EBAY_GB',
    ) {}

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function fulfillmentPolicies(): array
    {
        return $this->listPolicies('fulfillment_policy', 'fulfillmentPolicies');
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function paymentPolicies(): array
    {
        return $this->listPolicies('payment_policy', 'paymentPolicies');
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function returnPolicies(): array
    {
        return $this->listPolicies('return_policy', 'returnPolicies');
    }

    /**
     * Inventory locations (merchantLocationKey).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function inventoryLocations(): array
    {
        $url = $this->baseUrl() . '/sell/inventory/v1/location?limit=100';
        $decoded = $this->getJson($url);
        $rows = is_array($decoded['locations'] ?? null) ? $decoded['locations'] : [];
        $out  = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['merchantLocationKey'] ?? ''));
            if ($key === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $key));
            $status = strtoupper((string) ($row['merchantLocationStatus'] ?? ''));
            if ($status !== '' && $status !== 'ENABLED') {
                $name .= ' (' . $status . ')';
            }
            $out[] = ['id' => $key, 'name' => $name !== '' ? $name : $key];
        }

        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * @return array{
     *   fulfillment: array<int, array{id: string, name: string}>,
     *   payment: array<int, array{id: string, name: string}>,
     *   return: array<int, array{id: string, name: string}>,
     *   locations: array<int, array{id: string, name: string}>
     * }
     */
    public function allBusinessPolicies(): array
    {
        return [
            'fulfillment' => $this->fulfillmentPolicies(),
            'payment'     => $this->paymentPolicies(),
            'return'      => $this->returnPolicies(),
            'locations'   => $this->inventoryLocations(),
        ];
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function listPolicies(string $path, string $listKey): array
    {
        $url = $this->baseUrl()
            . '/sell/account/v1/' . $path
            . '?' . http_build_query([
                'marketplace_id' => $this->marketplace,
            ], '', '&', PHP_QUERY_RFC3986);

        $decoded = $this->getJson($url);
        $rows = is_array($decoded[$listKey] ?? null) ? $decoded[$listKey] : [];
        $out  = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['fulfillmentPolicyId']
                ?? $row['paymentPolicyId']
                ?? $row['returnPolicyId']
                ?? $row['policyId']
                ?? ''));
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $id));
            $out[] = ['id' => $id, 'name' => $name !== '' ? $name : $id];
        }

        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
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
                    CURLOPT_HTTPGET        => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                    CURLOPT_HTTPHEADER     => [
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
                throw new RuntimeException("eBay account API cURL error: {$err}");
            }

            if (($code === 429 || $code >= 500) && $attempts < self::MAX_TRIES) {
                usleep(1_000_000 * $attempts);
                continue;
            }
            break;
        } while (true);

        if ($code < 200 || $code >= 300) {
            $decoded = json_decode((string) $body, true);
            $message = is_array($decoded)
                ? $this->extractError($decoded)
                : substr((string) $body, 0, 200);
            throw new RuntimeException("eBay account API failed (HTTP {$code}): {$message}");
        }

        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractError(array $decoded): string
    {
        $errors = $decoded['errors'] ?? null;
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            $msg = (string) ($errors[0]['message'] ?? '');
            $long = (string) ($errors[0]['longMessage'] ?? '');
            return $long !== '' ? $long : ($msg !== '' ? $msg : 'Unknown error');
        }
        return 'Unknown error';
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }
}
