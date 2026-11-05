<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\Dpd;

use Module\StoreFeeder\Services\Channels\CurlSsl;
use RuntimeException;

/**
 * DPD UK GeoSession API — Basic auth login + shipment / label.
 */
final class DpdApi
{
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 60;

    public function __construct(
        private string $username,
        private string $password,
        private string $accountNumber,
        private string $apiBase = 'https://api.dpd.co.uk',
    ) {}

    public function login(): string
    {
        $decoded = $this->request('POST', '/user/?action=login', null, true);
        $session = trim((string) (
            $decoded['data']['geoSession']
            ?? $decoded['geoSession']
            ?? ''
        ));
        if ($session === '') {
            throw new RuntimeException('DPD login did not return a geoSession.');
        }
        return $session;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createShipment(string $geoSession, array $payload): array
    {
        return $this->request('POST', '/shipping/shipment', $payload, false, $geoSession);
    }

    /**
     * @return array{binary: string, content_type: string}
     */
    public function getLabel(string $geoSession, string $shipmentId): array
    {
        $shipmentId = trim($shipmentId);
        if ($shipmentId === '') {
            throw new RuntimeException('DPD shipment id is required for label download.');
        }

        $path = '/shipping/shipment/' . rawurlencode($shipmentId) . '/label/';
        $url = rtrim($this->apiBase, '/') . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for DPD label.');
        }

        curl_setopt_array($ch, CurlSsl::withSsl([
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/pdf',
                'GEOClient: account/' . $this->accountNumber,
                'GEOSession: ' . $geoSession,
                'User-Agent: StoreFeeder/1.0',
            ],
        ]));

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('DPD label cURL error: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('DPD label failed (HTTP ' . $code . '): ' . substr((string) $raw, 0, 200));
        }

        return [
            'binary'       => (string) $raw,
            'content_type' => $ctype !== '' ? $ctype : 'application/pdf',
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $payload,
        bool $withBasicAuth,
        string $geoSession = '',
    ): array {
        $url = rtrim($this->apiBase, '/') . (str_starts_with($path, '/') ? $path : '/' . $path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for DPD API.');
        }

        $headers = [
            'Accept: application/json',
            'GEOClient: account/' . $this->accountNumber,
            'User-Agent: StoreFeeder/1.0',
        ];
        if ($withBasicAuth) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }
        if ($geoSession !== '') {
            $headers[] = 'GEOSession: ' . $geoSession;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

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
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('DPD API cURL error: ' . $err);
        }

        $decoded = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded)
                ? (string) ($decoded['error']['errorMessage']
                    ?? $decoded['error']['message']
                    ?? $decoded['message']
                    ?? substr((string) $raw, 0, 240))
                : substr((string) $raw, 0, 240);
            throw new RuntimeException("DPD API failed (HTTP {$code}): {$msg}");
        }

        return is_array($decoded) ? $decoded : [];
    }
}
