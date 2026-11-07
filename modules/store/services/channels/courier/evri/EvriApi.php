<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Courier\Evri;

use Module\Store\Services\Channels\CurlSsl;
use RuntimeException;

/**
 * Evri Business (Classic) API — OAuth client-credentials + parcels/labels.
 */
final class EvriApi
{
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 45;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $tokenUrl = 'https://auth.backoffice.parcel2go.com/connect/token',
        private string $apiBase  = 'https://api.business.evri.com',
    ) {}

    /**
     * @return array{access_token: string, expires_in: int, token_type?: string}
     */
    public function fetchAccessToken(): array
    {
        $ch = curl_init(rtrim($this->tokenUrl, '?'));
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Evri token.');
        }

        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        curl_setopt_array($ch, CurlSsl::withSsl([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'User-Agent: Store/1.0',
            ],
        ]));

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Evri token cURL error: ' . $err);
        }

        $decoded = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            $msg = is_array($decoded)
                ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? substr((string) $raw, 0, 200))
                : substr((string) $raw, 0, 200);
            throw new RuntimeException("Evri token failed (HTTP {$code}): {$msg}");
        }

        $token = (string) ($decoded['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Evri token response missing access_token.');
        }

        return [
            'access_token' => $token,
            'expires_in'   => (int) ($decoded['expires_in'] ?? 3600),
            'token_type'   => (string) ($decoded['token_type'] ?? 'Bearer'),
        ];
    }

    /**
     * @param array{parcels: array<int, array<string, mixed>>} $collection
     * @return array<string, mixed>
     */
    public function createParcels(string $accessToken, array $collection): array
    {
        $url = rtrim($this->apiBase, '/') . '/api/parcels';
        return $this->jsonRequest('POST', $url, $accessToken, $collection, [
            'Accept: application/vnd.myhermes.parcelsummaries-v1+json, application/json',
            'Content-Type: application/json',
        ]);
    }

    /**
     * @return array{binary: string, content_type: string}
     */
    public function getLabel(string $accessToken, string $barcode, string $format = 'DEFAULT'): array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            throw new RuntimeException('Barcode is required for label download.');
        }

        $url = rtrim($this->apiBase, '/') . '/api/labels/' . rawurlencode($barcode)
            . '?' . http_build_query(['format' => $format], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Evri label.');
        }

        curl_setopt_array($ch, CurlSsl::withSsl([
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/pdf',
                'User-Agent: Store/1.0',
            ],
        ]));

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Evri label cURL error: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Evri label failed (HTTP ' . $code . '): ' . substr((string) $raw, 0, 200));
        }

        return [
            'binary'       => (string) $raw,
            'content_type' => $ctype !== '' ? $ctype : 'application/pdf',
        ];
    }

    public function getCustomers(string $accessToken): array
    {
        $url = rtrim($this->apiBase, '/') . '/api/customers';
        return $this->jsonRequest('GET', $url, $accessToken, null, [
            'Accept: application/json',
        ]);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<int, string> $extraHeaders
     * @return array<string, mixed>
     */
    private function jsonRequest(
        string $method,
        string $url,
        string $accessToken,
        ?array $payload,
        array $extraHeaders = [],
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Evri API.');
        }

        $headers = array_merge([
            'Authorization: Bearer ' . $accessToken,
            'User-Agent: Store/1.0',
        ], $extraHeaders);

        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, CurlSsl::withSsl($opts));
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Evri API cURL error: ' . $err);
        }

        $decoded = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded)
                ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? substr((string) $raw, 0, 200))
                : substr((string) $raw, 0, 200);
            throw new RuntimeException("Evri API failed (HTTP {$code}): {$msg}");
        }

        return is_array($decoded) ? $decoded : [];
    }
}
