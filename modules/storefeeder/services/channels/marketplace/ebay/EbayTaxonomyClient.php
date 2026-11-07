<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\Marketplace\Ebay;

use RuntimeException;

/**
 * eBay Commerce Taxonomy API — category tree + suggestions.
 *
 * Docs: https://developer.ebay.com/api-docs/commerce/taxonomy/overview.html
 */
final class EbayTaxonomyClient
{
    private const BASE_URL_SANDBOX    = 'https://api.sandbox.ebay.com';
    private const BASE_URL_PRODUCTION = 'https://api.ebay.com';
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;
    private const MAX_TRIES       = 3;

    /** @var array<string, string> marketplace => tree id */
    private static array $treeIdCache = [];

    public function __construct(
        private string $accessToken,
        private bool   $sandbox     = false,
        private string $marketplace = 'EBAY_GB',
    ) {}

    public function defaultCategoryTreeId(): string
    {
        $mp = strtoupper($this->marketplace);
        $cacheKey = ($this->sandbox ? 's:' : 'p:') . $mp;
        if (isset(self::$treeIdCache[$cacheKey])) {
            return self::$treeIdCache[$cacheKey];
        }

        $url = $this->baseUrl()
            . '/commerce/taxonomy/v1/get_default_category_tree_id?'
            . http_build_query(['marketplace_id' => $mp], '', '&', PHP_QUERY_RFC3986);

        $decoded = $this->getJson($url);
        $treeId  = trim((string) ($decoded['categoryTreeId'] ?? ''));
        if ($treeId === '') {
            throw new RuntimeException('eBay did not return a category tree id for ' . $mp);
        }

        return self::$treeIdCache[$cacheKey] = $treeId;
    }

    /**
     * @return array<int, array{id: string, name: string, path: string, leaf: bool}>
     */
    public function suggestCategories(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $treeId = $this->defaultCategoryTreeId();
        $url = $this->baseUrl()
            . '/commerce/taxonomy/v1/category_tree/'
            . rawurlencode($treeId)
            . '/get_category_suggestions?'
            . http_build_query(['q' => $query], '', '&', PHP_QUERY_RFC3986);

        $decoded = $this->getJson($url);
        $rows = is_array($decoded['categorySuggestions'] ?? null)
            ? $decoded['categorySuggestions']
            : [];

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cat = is_array($row['category'] ?? null) ? $row['category'] : [];
            $id  = trim((string) ($cat['categoryId'] ?? ''));
            $name = trim((string) ($cat['categoryName'] ?? ''));
            if ($id === '') {
                continue;
            }

            $pathParts = [];
            $ancestors = is_array($row['categoryTreeNodeAncestors'] ?? null)
                ? $row['categoryTreeNodeAncestors']
                : [];
            // Ancestors are usually root→parent; reverse if needed by level.
            usort($ancestors, static function (array $a, array $b): int {
                return ((int) ($a['categoryTreeNodeLevel'] ?? 0))
                    <=> ((int) ($b['categoryTreeNodeLevel'] ?? 0));
            });
            foreach ($ancestors as $anc) {
                if (!is_array($anc)) {
                    continue;
                }
                $ancName = trim((string) ($anc['categoryName'] ?? ''));
                if ($ancName !== '') {
                    $pathParts[] = $ancName;
                }
            }
            if ($name !== '') {
                $pathParts[] = $name;
            }

            $out[] = [
                'id'   => $id,
                'name' => $name !== '' ? $name : $id,
                'path' => implode(' › ', $pathParts),
                'leaf' => true,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Item Specifics (aspects) required/recommended for a leaf category.
     *
     * @return list<array{
     *   name: string,
     *   required: bool,
     *   mode: string,
     *   cardinality: string,
     *   values: list<string>
     * }>
     */
    public function getItemAspectsForCategory(string $categoryId, int $maxValuesPerAspect = 40): array
    {
        $categoryId = trim($categoryId);
        if ($categoryId === '') {
            throw new RuntimeException('Category id is required.');
        }

        $treeId = $this->defaultCategoryTreeId();
        $url = $this->baseUrl()
            . '/commerce/taxonomy/v1/category_tree/'
            . rawurlencode($treeId)
            . '/get_item_aspects_for_category?'
            . http_build_query(['category_id' => $categoryId], '', '&', PHP_QUERY_RFC3986);

        $decoded = $this->getJson($url);
        $rows = is_array($decoded['aspects'] ?? null) ? $decoded['aspects'] : [];
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['localizedAspectName'] ?? ''));
            if ($name === '') {
                continue;
            }
            $constraint = is_array($row['aspectConstraint'] ?? null)
                ? $row['aspectConstraint']
                : [];
            $required = !empty($constraint['aspectRequired']);
            $mode = strtoupper(trim((string) ($constraint['aspectMode'] ?? 'FREE_TEXT')));
            if ($mode === '') {
                $mode = 'FREE_TEXT';
            }
            $cardinality = strtoupper(trim((string) ($constraint['itemToAspectCardinality'] ?? 'SINGLE')));
            if ($cardinality === '') {
                $cardinality = 'SINGLE';
            }

            $values = [];
            $rawValues = is_array($row['aspectValues'] ?? null) ? $row['aspectValues'] : [];
            foreach ($rawValues as $av) {
                if (!is_array($av)) {
                    continue;
                }
                $v = trim((string) ($av['localizedValue'] ?? ''));
                if ($v !== '' && !in_array($v, $values, true)) {
                    $values[] = $v;
                }
                if (count($values) >= $maxValuesPerAspect) {
                    break;
                }
            }

            $out[] = [
                'name'        => $name,
                'required'    => $required,
                'mode'        => $mode,
                'cardinality' => $cardinality,
                'values'      => $values,
            ];
        }

        // Required first, then alphabetical.
        usort($out, static function (array $a, array $b): int {
            if ($a['required'] !== $b['required']) {
                return $a['required'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

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
                        'User-Agent: StoreFeeder/1.0',
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
                throw new RuntimeException("eBay taxonomy cURL error: {$err}");
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
            throw new RuntimeException("eBay taxonomy failed (HTTP {$code}): {$message}");
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
