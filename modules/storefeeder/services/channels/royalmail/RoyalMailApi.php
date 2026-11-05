<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\RoyalMail;

use Module\StoreFeeder\Services\Channels\CurlSsl;
use RuntimeException;

/**
 * Royal Mail Pro Shipping v4 — OAuth client-credentials + shipments/labels.
 */
final class RoyalMailApi
{
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 60;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $tokenUrl = 'https://authentication.proshipping.net/connect/token',
        private string $apiBase  = 'https://api.proshipping.net',
    ) {}

    /**
     * @return array{access_token: string, expires_in: int}
     */
    public function fetchAccessToken(): array
    {
        $ch = curl_init(rtrim($this->tokenUrl, '?'));
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Royal Mail token.');
        }

        curl_setopt_array($ch, CurlSsl::withSsl([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'client_credentials']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_USERPWD        => $this->clientId . ':' . $this->clientSecret,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'User-Agent: StoreFeeder/1.0',
            ],
        ]));

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Royal Mail token cURL error: ' . $err);
        }

        $decoded = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            $msg = is_array($decoded)
                ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? substr((string) $raw, 0, 200))
                : substr((string) $raw, 0, 200);
            throw new RuntimeException("Royal Mail token failed (HTTP {$code}): {$msg}");
        }

        $token = (string) ($decoded['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Royal Mail token response missing access_token.');
        }

        return [
            'access_token' => $token,
            'expires_in'   => (int) ($decoded['expires_in'] ?? 3600),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createShipment(string $accessToken, array $body): array
    {
        return $this->jsonRequest('POST', '/v4/shipments/rm', $accessToken, $body);
    }

    /**
     * @return array{binary: string, content_type: string}
     */
    public function printLabel(string $accessToken, string $shipmentId): array
    {
        $shipmentId = trim($shipmentId);
        if ($shipmentId === '') {
            throw new RuntimeException('Royal Mail shipment id is required.');
        }

        $path = '/v4/shipments/printLabel/rm/' . rawurlencode($shipmentId);
        $url = rtrim($this->apiBase, '/') . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Royal Mail label.');
        }

        curl_setopt_array($ch, CurlSsl::withSsl([
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/pdf, application/json',
                'User-Agent: StoreFeeder/1.0',
            ],
        ]));

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Royal Mail label cURL error: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException(
                'Royal Mail label failed (HTTP ' . $code . '): ' . substr((string) $raw, 0, 200)
            );
        }

        // Some responses wrap PDF as base64 JSON.
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $b64 = (string) (
                $decoded['label']
                ?? $decoded['Label']
                ?? $decoded['document']
                ?? $decoded['LabelData']
                ?? ''
            );
            if ($b64 !== '') {
                $bin = base64_decode($b64, true);
                if ($bin !== false && $bin !== '') {
                    return ['binary' => $bin, 'content_type' => 'application/pdf'];
                }
            }
        }

        return [
            'binary'       => (string) $raw,
            'content_type' => str_contains(strtolower($ctype), 'pdf') ? $ctype : 'application/pdf',
        ];
    }

    /** Lightweight authenticated ping. */
    public function listShippingAccounts(string $accessToken): array
    {
        return $this->jsonRequest('GET', '/v4/shippingAccounts', $accessToken, null);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function jsonRequest(
        string $method,
        string $path,
        string $accessToken,
        ?array $payload,
    ): array {
        $url = rtrim($this->apiBase, '/') . (str_starts_with($path, '/') ? $path : '/' . $path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Royal Mail API.');
        }

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'User-Agent: StoreFeeder/1.0',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, CurlSsl::withSsl($opts));
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Royal Mail API cURL error: ' . $err);
        }

        $decoded = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded)
                ? (string) ($decoded['message'] ?? $decoded['title'] ?? $decoded['error'] ?? substr((string) $raw, 0, 240))
                : substr((string) $raw, 0, 240);
            throw new RuntimeException("Royal Mail API failed (HTTP {$code}): {$msg}");
        }

        return is_array($decoded) ? $decoded : [];
    }
}
