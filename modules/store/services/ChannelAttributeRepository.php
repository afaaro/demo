<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

/**
 * Per-channel attribute definitions + per-product overrides.
 * Works for any marketplace (eBay, Amazon, TikTok, B&Q, …).
 */
final class ChannelAttributeRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return list<array{name: string, source: string, value: string}>
     */
    public function defsForChannel(int $channelId): array
    {
        if ($channelId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT `name`, `source`, `value`
               FROM `#__store_channel_attributes`
              WHERE `channel_id` = ? AND `tenant_id` = ?
              ORDER BY `sort_order` ASC, `id` ASC",
            [$channelId, $this->tenantId()]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $source = trim((string) ($row['source'] ?? 'fixed'));
            if (!isset(EbayAspectResolver::SOURCES[$source])) {
                $source = 'fixed';
            }
            $out[] = [
                'name'   => $name,
                'source' => $source,
                'value'  => (string) ($row['value'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Replace all attribute definitions for a channel.
     *
     * @param list<array{name?: string, source?: string, value?: string}> $rows
     */
    public function replaceDefs(int $channelId, array $rows): void
    {
        if ($channelId <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $normalized = EbayAspectResolver::normalizeChannelRows([
            'general_attributes' => $rows,
        ]);

        $this->db->query(
            "DELETE FROM `#__store_channel_attributes` WHERE `channel_id` = ? AND `tenant_id` = ?",
            [$channelId, $tid]
        );

        $sort = 0;
        foreach ($normalized as $row) {
            $this->db->query(
                "INSERT INTO `#__store_channel_attributes`
                    (`channel_id`, `name`, `source`, `value`, `sort_order`, `tenant_id`)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $channelId,
                    $row['name'],
                    $row['source'],
                    $row['value'],
                    $sort++,
                    $tid,
                ]
            );
        }
    }

    /**
     * Load defs, or type-aware defaults when the channel has none yet.
     *
     * @return list<array{name: string, source: string, value: string}>
     */
    public function defsOrDefaults(int $channelId, string $type = ''): array
    {
        $defs = $this->defsForChannel($channelId);
        if ($defs !== []) {
            return $defs;
        }
        return EbayAspectResolver::defaultChannelRowsForType($type);
    }

    /**
     * Persist type-aware defaults when `#__store_channel_attributes` is empty.
     *
     * @return list<array{name: string, source: string, value: string}>
     */
    public function ensureDefs(int $channelId, string $type): array
    {
        $defs = $this->defsForChannel($channelId);
        if ($defs !== []) {
            return $defs;
        }
        $seed = EbayAspectResolver::defaultChannelRowsForType($type);
        $this->replaceDefs($channelId, $seed);
        return $seed;
    }

    /**
     * Product overrides for one channel: name => list of values.
     *
     * @return array<string, list<string>>
     */
    public function productOverrides(int $productId, int $channelId): array
    {
        if ($productId <= 0 || $channelId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT `name`, `values_json`
               FROM `#__store_product_channel_attributes`
              WHERE `product_id` = ? AND `channel_id` = ? AND `tenant_id` = ?
              ORDER BY `name` ASC",
            [$productId, $channelId, $this->tenantId()]
        )->rows;

        $map = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $raw = $row['values_json'] ?? null;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
            } elseif (is_array($raw)) {
                $decoded = $raw;
            } else {
                $decoded = [];
            }
            $map[$name] = is_array($decoded) ? $decoded : [];
        }
        return EbayAspectResolver::normalizeProductAspects($map);
    }

    /**
     * All product overrides keyed by channel id.
     *
     * @return array<int, array<string, list<string>>>
     */
    public function productOverridesByChannel(int $productId): array
    {
        if ($productId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT `channel_id`, `name`, `values_json`
               FROM `#__store_product_channel_attributes`
              WHERE `product_id` = ? AND `tenant_id` = ?
              ORDER BY `channel_id` ASC, `name` ASC",
            [$productId, $this->tenantId()]
        )->rows;

        $byChannel = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['channel_id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($cid <= 0 || $name === '') {
                continue;
            }
            $raw = $row['values_json'] ?? null;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
            } elseif (is_array($raw)) {
                $decoded = $raw;
            } else {
                $decoded = [];
            }
            $byChannel[$cid][$name] = is_array($decoded) ? $decoded : [];
        }

        $out = [];
        foreach ($byChannel as $cid => $map) {
            $out[$cid] = EbayAspectResolver::normalizeProductAspects($map);
        }
        return $out;
    }

    /**
     * Replace all overrides for a product on one channel.
     *
     * @param array<string, list<string>>|list<array{name?: string, value?: string}> $attrs
     */
    public function replaceProductOverrides(int $productId, int $channelId, array $attrs): void
    {
        if ($productId <= 0 || $channelId <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $normalized = EbayAspectResolver::normalizeProductAspects($attrs);

        $this->db->query(
            "DELETE FROM `#__store_product_channel_attributes`
              WHERE `product_id` = ? AND `channel_id` = ? AND `tenant_id` = ?",
            [$productId, $channelId, $tid]
        );

        foreach ($normalized as $name => $values) {
            // MariaDB rejects CAST(? AS JSON); insert a JSON string like other repos.
            $json = json_encode(array_values($values), JSON_UNESCAPED_UNICODE) ?: '[]';
            $this->db->query(
                "INSERT INTO `#__store_product_channel_attributes`
                    (`product_id`, `channel_id`, `name`, `values_json`, `tenant_id`)
                 VALUES (?, ?, ?, ?, ?)",
                [$productId, $channelId, $name, $json, $tid]
            );
        }
    }

    /**
     * Merge overrides (import): only fill names that are empty on the product for this channel.
     *
     * @param array<string, list<string>> $attrs
     */
    public function mergeProductOverrides(int $productId, int $channelId, array $attrs): void
    {
        $normalized = EbayAspectResolver::normalizeProductAspects($attrs);
        if ($normalized === []) {
            return;
        }
        $existing = $this->productOverrides($productId, $channelId);
        $merged = $existing;
        foreach ($normalized as $name => $values) {
            if (!isset($merged[$name]) || $merged[$name] === []) {
                $merged[$name] = $values;
            }
        }
        $this->replaceProductOverrides($productId, $channelId, $merged);
    }

    /**
     * Resolve channel defs + product overrides for push.
     *
     * @return array<string, list<string>>
     */
    public function resolveForProduct(Channel $channel, Product $product): array
    {
        $defs = $this->defsForChannel((int) $channel->id);
        if ($defs === []) {
            $defs = EbayAspectResolver::defaultChannelRowsForType((string) $channel->type);
        }
        $overrides = $product->id !== null
            ? $this->productOverrides((int) $product->id, (int) $channel->id)
            : [];

        return EbayAspectResolver::resolve($product, $defs, $overrides);
    }
}
