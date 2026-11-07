<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Amazon;

use Module\Store\Services\Channels\CurlSsl;
use RuntimeException;

/**
 * Login with Amazon (LWA) — exchange a refresh token for an SP-API access token.
 *
 * Docs: https://developer-docs.amazon.com/sp-api/docs/connecting-to-the-selling-partner-api
 */
final class AmazonLwa
{
    private const TOKEN_URL = 'https://api.amazon.com/auth/o2/token';
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 20;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
    ) {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('Amazon LWA client ID and client secret are required.');
        }
    }

    /**
     * @return array{access_token: string, expires_in: int, refresh_token: string, token_type: string}
     */
    public function refresh(string $refreshToken): array
    {
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') {
            throw new RuntimeException('Amazon refresh token is required.');
        }

        return $this->request([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
    }

    /**
     * Exchange an authorization code (Seller Central / Appstore redirect) for tokens.
     *
     * @return array{access_token: string, expires_in: int, refresh_token: string, token_type: string}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('Amazon authorization code is required.');
        }

        return $this->request([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
            'client_id'    => $this->clientId,
            'client_secret'=> $this->clientSecret,
        ]);
    }

    /**
     * @param array<string, string> $params
     * @return array{access_token: string, expires_in: int, refresh_token: string, token_type: string}
     */
    private function request(array $params): array
    {
        $ch = curl_init(self::TOKEN_URL);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Amazon LWA cURL request.');
        }

        try {
            curl_setopt_array($ch, CurlSsl::withSsl([
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept: application/json',
                ],
            ]));

            $body = curl_exec($ch);
            if ($body === false) {
                throw new RuntimeException(
                    'Amazon LWA cURL error (' . curl_errno($ch) . '): ' . curl_error($ch)
                );
            }

            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($ch);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Amazon LWA returned non-JSON (HTTP {$code}).");
        }

        if ($code < 200 || $code >= 300) {
            $msg = (string) ($decoded['error_description'] ?? $decoded['error'] ?? 'token request failed');
            throw new RuntimeException("Amazon LWA error (HTTP {$code}): {$msg}");
        }

        $access = trim((string) ($decoded['access_token'] ?? ''));
        if ($access === '') {
            throw new RuntimeException('Amazon LWA response missing access_token.');
        }

        return [
            'access_token'  => $access,
            'expires_in'    => (int) ($decoded['expires_in'] ?? 3600),
            'refresh_token' => (string) ($decoded['refresh_token'] ?? $params['refresh_token'] ?? ''),
            'token_type'    => (string) ($decoded['token_type'] ?? 'bearer'),
        ];
    }
}
