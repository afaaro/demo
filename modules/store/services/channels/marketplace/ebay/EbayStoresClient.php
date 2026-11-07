<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Ebay;

use RuntimeException;

/**
 * eBay Sell Stores API — custom store category tree.
 *
 * Docs: https://developer.ebay.com/api-docs/sell/stores/overview.html
 */
final class EbayStoresClient
{
    private const BASE_URL_SANDBOX    = 'https://api.sandbox.ebay.com';
    private const BASE_URL_PRODUCTION = 'https://api.ebay.com';
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;
    private const MAX_TRIES       = 3;

    public function __construct(
        private string $accessToken,
        private bool   $sandbox = false,
    ) {}

    /**
     * Flattened store categories with full path for offer.storeCategoryNames.
     *
     * @return array<int, array{id: string, name: string, path: string, level: int}>
     */
    public function storeCategories(): array
    {
        $url = $this->baseUrl() . '/sell/stores/v1/store/categories';
        $decoded = $this->getJson($url);

        $roots = is_array($decoded['storeCategories'] ?? null)
            ? $decoded['storeCategories']
            : (is_array($decoded['categories'] ?? null) ? $decoded['categories'] : []);

        $out = [];
        $this->walk($roots, '', $out);
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['path'], $b['path']));
        return $out;
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<int, array{id: string, name: string, path: string, level: int}> $out
     */
    private function walk(array $nodes, string $parentPath, array &$out): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $id   = trim((string) ($node['categoryId'] ?? $node['storeCategoryId'] ?? ''));
            $name = trim((string) ($node['categoryName'] ?? $node['name'] ?? ''));
            if ($name === '' && $id === '') {
                continue;
            }

            $path = $parentPath . '/' . ($name !== '' ? $name : $id);
            $level = (int) ($node['level'] ?? (substr_count($path, '/')));

            $children = $node['childrenCategories']
                ?? $node['childCategoryTreeNodes']
                ?? $node['children']
                ?? [];

            $hasChildren = is_array($children) && $children !== [];

            // Prefer leaf (or any node) — sellers can assign leaf store categories.
            $out[] = [
                'id'    => $id !== '' ? $id : $path,
                'name'  => $name !== '' ? $name : $id,
                'path'  => $path,
                'level' => $level,
            ];

            if ($hasChildren) {
                $this->walk($children, $path, $out);
            }
        }
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
                throw new RuntimeException("eBay stores API cURL error: {$err}");
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
            throw new RuntimeException("eBay stores API failed (HTTP {$code}): {$message}");
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
