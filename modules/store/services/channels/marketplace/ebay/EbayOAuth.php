<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Ebay;

use RuntimeException;

/**
 * eBay OAuth 2.0 service.
 *
 * Flow:
 *   1. consentUrl()   - Redirect seller to eBay consent.
 *   2. exchangeCode() - Exchange authorization code for tokens.
 *   3. refresh()      - Exchange refresh token for a new access token.
 *
 * Important:
 * - RuName is the eBay Redirect URL Name (alias), not your callback URL.
 * - Store and verify the OAuth state before exchanging the authorization code.
 * - Store refresh tokens securely.
 */
final class EbayOAuth
{
    private const AUTH_URL_SANDBOX =
        'https://auth.sandbox.ebay.com/oauth2/authorize';

    private const AUTH_URL_PRODUCTION =
        'https://auth.ebay.com/oauth2/authorize';

    private const TOKEN_URL_SANDBOX =
        'https://api.sandbox.ebay.com/identity/v1/oauth2/token';

    private const TOKEN_URL_PRODUCTION =
        'https://api.ebay.com/identity/v1/oauth2/token';

    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $ruName,
        private bool $sandbox = true,
    ) {
        if ($this->clientId === '' ||
            $this->clientSecret === '' ||
            $this->ruName === '') {
            throw new RuntimeException(
                'eBay OAuth client ID, client secret and RuName are required.'
            );
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'The PHP cURL extension is not enabled.'
            );
        }
    }

    /**
     * Generate the seller consent URL.
     *
     * Generate a cryptographically random state value and store it
     * in the session or database before redirecting the seller.
     */
    public function consentUrl(string $state): string
    {
        if ($state === '') {
            throw new RuntimeException(
                'OAuth state must not be empty.'
            );
        }

        $base = $this->sandbox
            ? self::AUTH_URL_SANDBOX
            : self::AUTH_URL_PRODUCTION;

        $scopes = implode(' ', [
            'https://api.ebay.com/oauth/api_scope',
            'https://api.ebay.com/oauth/api_scope/sell.inventory',
            'https://api.ebay.com/oauth/api_scope/sell.inventory.readonly',
            'https://api.ebay.com/oauth/api_scope/sell.account',
            'https://api.ebay.com/oauth/api_scope/sell.account.readonly',
            'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
            'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
            'https://api.ebay.com/oauth/api_scope/sell.stores',
            'https://api.ebay.com/oauth/api_scope/sell.stores.readonly',
            'https://api.ebay.com/oauth/api_scope/commerce.message',
        ]);

        return $base . '?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->ruName,
            'response_type' => 'code',
            'scope'         => $scopes,
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array{
     *     refresh_token: string,
     *     access_token: string,
     *     expires_in: int,
     *     refresh_token_expires_in: int
     * }
     */
    public function exchangeCode(string $code): array
    {
        if ($code === '') {
            throw new RuntimeException(
                'The eBay authorization code must not be empty.'
            );
        }

        return $this->requestToken([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->ruName,
        ]);
    }

    /**
     * Refresh an access token.
     *
     * eBay may omit refresh_token from a successful refresh response.
     * In that case, preserve the existing refresh token.
     *
     * @return array{
     *     refresh_token: string,
     *     access_token: string,
     *     expires_in: int,
     *     refresh_token_expires_in: int
     * }
     */
    public function refresh(string $refreshToken): array
    {
        if ($refreshToken === '') {
            throw new RuntimeException(
                'The eBay refresh token must not be empty.'
            );
        }

        $tokens = $this->requestToken([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($tokens['refresh_token'] === '') {
            $tokens['refresh_token'] = $refreshToken;
        }

        return $tokens;
    }

    /**
     * Send an OAuth token request to eBay.
     *
     * @param array<string, string> $params
     *
     * @return array{
     *     refresh_token: string,
     *     access_token: string,
     *     expires_in: int,
     *     refresh_token_expires_in: int
     * }
     */
    private function requestToken(array $params): array
    {
        $url = $this->sandbox
            ? self::TOKEN_URL_SANDBOX
            : self::TOKEN_URL_PRODUCTION;

        $auth = base64_encode(
            $this->clientId . ':' . $this->clientSecret
        );

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException(
                'Unable to initialize the eBay OAuth cURL request.'
            );
        }

        try {
            $configured = curl_setopt_array($ch, EbayCurl::withSsl([
                CURLOPT_POST            => true,
                CURLOPT_POSTFIELDS      => http_build_query(
                    $params,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                ),
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT         => self::REQUEST_TIMEOUT,
                CURLOPT_HTTPHEADER      => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                    'Authorization: Basic ' . $auth,
                ],
            ]));

            if (!$configured) {
                throw new RuntimeException(
                    'Unable to configure the eBay OAuth cURL request: '
                    . curl_error($ch)
                );
            }

            $body = curl_exec($ch);

            if ($body === false) {
                $error = curl_error($ch);
                $errno = curl_errno($ch);

                throw new RuntimeException(
                    "eBay OAuth cURL error ({$errno}): {$error}"
                );
            }

            $code = (int) curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );
        } finally {
            curl_close($ch);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                "Invalid JSON response from eBay OAuth (HTTP {$code})."
            );
        }

        if ($code < 200 || $code >= 300) {
            $message = $decoded['error_description']
                ?? $decoded['error']
                ?? 'Unknown OAuth error';

            if (!is_string($message)) {
                $message = 'Unknown OAuth error';
            }

            $hint = '';
            if ($code === 401) {
                $env = $this->sandbox ? 'Sandbox' : 'Production';
                $hint = " Check {$env} App ID + Cert ID match (same keyset from developer.ebay.com), "
                    . 'and that Sandbox mode on the channel matches those keys. '
                    . 'Stale App ID/Cert on the channel are ignored when store.ebay is set.';
            }

            throw new RuntimeException(
                "eBay token request failed (HTTP {$code}): {$message}.{$hint}"
            );
        }

        $accessToken = $decoded['access_token'] ?? null;

        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException(
                'eBay OAuth response does not contain a valid access token.'
            );
        }

        $expiresIn = filter_var(
            $decoded['expires_in'] ?? null,
            FILTER_VALIDATE_INT
        );

        $refreshExpiresIn = filter_var(
            $decoded['refresh_token_expires_in'] ?? 0,
            FILTER_VALIDATE_INT
        );

        if ($expiresIn === false || $expiresIn <= 0) {
            throw new RuntimeException(
                'eBay OAuth response contains an invalid access-token expiry.'
            );
        }

        if ($refreshExpiresIn === false || $refreshExpiresIn < 0) {
            $refreshExpiresIn = 0;
        }

        $refreshToken = $decoded['refresh_token'] ?? '';

        if (!is_string($refreshToken)) {
            $refreshToken = '';
        }

        return [
            'refresh_token' => $refreshToken,
            'access_token'  => $accessToken,
            'expires_in'    => $expiresIn,
            'refresh_token_expires_in' => $refreshExpiresIn,
        ];
    }
}
