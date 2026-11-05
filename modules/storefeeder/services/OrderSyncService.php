<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;
use System\Library\Database;

final class OrderSyncService
{
    use TenantScoped;

    /** Safety cap so a runaway account cannot loop forever. */
    private const MAX_PAGES = 20;
    private const PAGE_SIZE = 50;
    private const MERGE_WINDOW_HOURS = 48;

    public function __construct(
        private Database             $db,
        private ChannelRepository    $channels,
        private ChannelClientFactory $factory,
        private ?KitExpansionService $kits = null,
    ) {
        $this->kits = $kits ?? new KitExpansionService($db, new ProductBundleRepository($db));
    }

    /**
     * @return array{created: int, updated: int, skipped: int, truncated: bool}
     */
    public function syncChannel(Channel $channel, int $days = 30): array
    {
        if (!$this->channelTenantAllowed($channel)) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'truncated' => false];
        }

        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }

        if ($this->factory->isCourier($channel)) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'truncated' => false];
        }

        if (!ChannelSettings::bool($channel, 'import_orders', true)) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'truncated' => false];
        }

        $client = $this->factory->make($channel);

        $created   = 0;
        $updated   = 0;
        $skipped   = 0;
        $offset    = null;
        $pages     = 0;
        $truncated = false;

        do {
            $page = $client->fetchOrders($days, self::PAGE_SIZE, $offset);

            foreach ($page['orders'] as $order) {
                if (!$this->shouldImportOrder($channel, $order)) {
                    $skipped++;
                    continue;
                }

                [$status] = $this->upsertOrder($channel, $order);
                if ($status === 'created' || $status === 'merged') {
                    $created++;
                } elseif ($status === 'updated') {
                    $updated++;
                } elseif ($status === 'skipped') {
                    $skipped++;
                }
            }

            $offset = $page['next'];
            $pages++;

            if ($offset !== null && $pages >= self::MAX_PAGES) {
                $truncated = true;
                break;
            }
        } while ($offset !== null);

        $this->channels->touchLastSynced((int) $channel->id);

        // Open order lines reserve stock (Products grid "On Back Order").
        (new InventoryService($this->db))->rebuildReservedFromOpenOrders();

        return [
            'created'   => $created,
            'updated'   => $updated,
            'skipped'   => $skipped,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array<string, mixed> $order
     */
    private function shouldImportOrder(Channel $channel, array $order): bool
    {
        // Unpaid — when off, only import clearly paid orders.
        if (!ChannelSettings::bool($channel, 'import_unpaid_orders', true)) {
            $payment = strtoupper((string) ($order['orderPaymentStatus'] ?? ''));
            if ($payment !== 'PAID') {
                return false;
            }
        }

        // Auction line items
        if (!ChannelSettings::bool($channel, 'download_auction_orders', true)) {
            $lineItems = is_array($order['lineItems'] ?? null) ? $order['lineItems'] : [];
            $hasFixed = false;
            $hasAuction = false;
            foreach ($lineItems as $item) {
                $format = strtoupper((string) ($item['soldFormat'] ?? $item['listingType'] ?? ''));
                if ($format === 'AUCTION' || $format === 'CHINESE') {
                    $hasAuction = true;
                } else {
                    $hasFixed = true;
                }
            }
            // Skip pure auction orders when toggle is off.
            if ($hasAuction && !$hasFixed) {
                return false;
            }
        }

        // Marketplace filter
        if (!ChannelSettings::bool($channel, 'download_orders_all_marketplaces', true)) {
            $wanted = strtoupper(ChannelSettings::string($channel, 'marketplace', 'EBAY_GB'));
            if ($wanted !== '' && !$this->orderMatchesMarketplace($order, $wanted)) {
                return false;
            }
        }

        // Promotion / discounted sales — when off, skip promo/discounted orders.
        if (!ChannelSettings::bool($channel, 'promotion_sales', true)) {
            if ($this->orderLooksPromotional($order)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function orderLooksPromotional(array $order): bool
    {
        $summary = is_array($order['pricingSummary'] ?? null) ? $order['pricingSummary'] : [];
        foreach (['priceDiscount', 'adjustment', 'deliveryDiscount'] as $key) {
            $val = (float) ($summary[$key]['value'] ?? 0);
            if ($val > 0) {
                return true;
            }
        }
        if (!empty($order['salesRecordReference']) && is_array($order['lineItems'] ?? null)) {
            foreach ($order['lineItems'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!empty($item['appliedPromotions']) || !empty($item['ebayCollectAndRemitTaxes'])) {
                    // promotions array present
                    if (!empty($item['appliedPromotions'])) {
                        return true;
                    }
                }
                $disc = (float) ($item['discountedLineItemCost']['value'] ?? 0);
                $cost = (float) ($item['lineItemCost']['value'] ?? 0);
                if ($disc > 0 && $cost > 0 && $disc < $cost) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function orderMatchesMarketplace(array $order, string $wantedMarketplace): bool
    {
        $lineItems = is_array($order['lineItems'] ?? null) ? $order['lineItems'] : [];
        $seen = false;
        foreach ($lineItems as $item) {
            $mp = strtoupper((string) (
                $item['listingMarketplaceId']
                ?? $item['marketplaceId']
                ?? ''
            ));
            if ($mp === '') {
                continue;
            }
            $seen = true;
            if ($mp === $wantedMarketplace) {
                return true;
            }
        }

        // If API omitted marketplace ids, keep the order (don't over-filter).
        return !$seen;
    }

    /**
     * Upsert one order and its line items atomically.
     *
     * @param array<string, mixed> $order
     * @return array{0: string, 1: int}  [status, order_id]
     */
    private function upsertOrder(Channel $channel, array $order): array
    {
        $channelId  = (int) $channel->id;
        $externalId = (string) ($order['orderId'] ?? '');
        if ($externalId === '') {
            return ['skipped', 0];
        }

        $tenantId = $this->channelTenantId($channel);

        $existing = $this->db->first('sf_orders', [
            'tenant_id'         => $tenantId,
            'channel_id'        => $channelId,
            'external_order_id' => $externalId,
        ]);

        $lineItems = is_array($order['lineItems'] ?? null) ? $order['lineItems'] : [];

        $shipStep = $order['fulfillmentStartInstructions'][0]['shippingStep'] ?? [];
        if (!is_array($shipStep)) {
            $shipStep = [];
        }
        $shipTo = is_array($shipStep['shipTo'] ?? null) ? $shipStep['shipTo'] : [];
        $address = is_array($shipTo['contactAddress'] ?? null) ? $shipTo['contactAddress'] : [];
        $shippingPayload = array_merge($address, array_filter([
            'fullName'     => $shipTo['fullName'] ?? null,
            'email'        => $shipTo['email'] ?? null,
            'primaryPhone' => $shipTo['primaryPhone']['phoneNumber']
                ?? $shipTo['primaryPhone']
                ?? null,
        ], static fn($v) => $v !== null && $v !== ''));

        $shippingMethod = $this->extractShippingMethod($order, $shipStep);

        $buyerEmail = (string) (
            $shipTo['email']
            ?? $order['buyer']['buyerRegistrationAddress']['email']
            ?? $order['buyer']['email']
            ?? ''
        );

        $buyerUsername = (string) ($order['buyer']['username'] ?? '');
        $shippingName  = (string) ($shipTo['fullName'] ?? '');
        $money         = $this->extractMoney($channel, $order);
        $total         = $money['total'];
        $currency      = $money['currency'];
        $createdAt     = $this->parseEbayDate($order['creationDate'] ?? null);

        $handlingDays = ChannelSettings::int($channel, 'handling_time_days', 1);
        $shippingPayload['_handling_days'] = $handlingDays;
        if ($createdAt !== null) {
            $shipBy = strtotime($createdAt . ' +' . max(0, $handlingDays) . ' days');
            if ($shipBy !== false) {
                $shippingPayload['_ship_by'] = date('Y-m-d', $shipBy);
            }
        }

        $data = [
            'tenant_id'         => $tenantId,
            'channel_id'        => $channelId,
            'external_order_id' => $externalId,
            'status'            => (string) ($order['orderFulfillmentStatus'] ?? 'unknown'),
            'buyer_username'    => $buyerUsername,
            'buyer_email'       => mb_substr($buyerEmail, 0, 255),
            'total'             => $total,
            'shipping_cost'     => $money['shipping_cost'],
            'discount_amount'   => $money['discount_amount'],
            'channel_fee'       => $money['channel_fee'],
            'shipping_tax'      => $money['shipping_tax'],
            'currency'          => $currency,
            'shipping_name'     => $shippingName,
            'shipping_address'  => json_encode($shippingPayload, JSON_UNESCAPED_SLASHES),
            'shipping_method'   => mb_substr($shippingMethod, 0, 128),
            'synced_at'         => date('Y-m-d H:i:s'),
        ];

        if ($createdAt !== null) {
            $data['created_at'] = $createdAt;
        }

        // Merge into an open sibling order when toggle is on and this is a new external id.
        if ($existing === [] && ChannelSettings::bool($channel, 'merge_orders', true)) {
            $mergeInto = $this->findMergeCandidate(
                $channelId,
                $tenantId,
                $buyerUsername,
                $shippingName,
                $shippingPayload,
            );
            if ($mergeInto !== null) {
                return $this->mergeIntoExisting($mergeInto, $data, $lineItems, $externalId, $total);
            }
        }

        $isNew = ($existing === []);

        $tenantId = (int) ($data['tenant_id'] ?? 0);

        return $this->db->transaction(function (Database $db) use ($data, $existing, $lineItems, $isNew, $tenantId) {
            if ($isNew) {
                $orderId = $db->insert('sf_orders', $data);
            } else {
                $orderId = (int) $existing['id'];
                unset($data['created_at']);
                $db->update('sf_orders', $data, [
                    'id'         => $orderId,
                    'tenant_id'  => $tenantId,
                ]);
                $db->query(
                    'DELETE FROM `#__sf_order_items` WHERE `order_id` = ? AND `tenant_id` = ?',
                    [$orderId, $tenantId]
                );
            }

            $this->insertLineItems($db, (int) $orderId, $lineItems, $tenantId);

            return [$isNew ? 'created' : 'updated', (int) $orderId];
        });
    }

    /**
     * @param array<string, mixed> $shippingPayload
     * @return array<string, mixed>|null
     */
    private function findMergeCandidate(
        int $channelId,
        int $tenantId,
        string $buyerUsername,
        string $shippingName,
        array $shippingPayload,
    ): ?array {
        if ($tenantId <= 0 || ($buyerUsername === '' && $shippingName === '')) {
            return null;
        }

        $since = date('Y-m-d H:i:s', time() - self::MERGE_WINDOW_HOURS * 3600);
        $addrKey = $this->addressKey($shippingPayload);

        $rows = $this->db->query(
            "SELECT * FROM `#__sf_orders`
             WHERE `channel_id` = ?
               AND `tenant_id` = ?
               AND `created_at` >= ?
               AND UPPER(`status`) NOT IN ('FULFILLED', 'CANCELLED', 'CANCELED')
             ORDER BY `id` DESC
             LIMIT 25",
            [$channelId, $tenantId, $since]
        )->rows;

        foreach ($rows as $row) {
            $sameBuyer = $buyerUsername !== ''
                && strcasecmp((string) $row['buyer_username'], $buyerUsername) === 0;
            $sameName = $shippingName !== ''
                && strcasecmp((string) $row['shipping_name'], $shippingName) === 0;
            if (!$sameBuyer && !$sameName) {
                continue;
            }

            $raw = $row['shipping_address'] ?? '';
            $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            if ($addrKey !== '' && $this->addressKey($decoded) === $addrKey) {
                return $row;
            }
            // Fall back: same buyer + same shipping name within window.
            if ($sameBuyer && $sameName) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @param array<int, array<string, mixed>> $lineItems
     * @return array{0: string, 1: int}
     */
    private function mergeIntoExisting(
        array $existing,
        array $incoming,
        array $lineItems,
        string $externalId,
        float $addTotal,
    ): array {
        return $this->db->transaction(function (Database $db) use (
            $existing,
            $incoming,
            $lineItems,
            $externalId,
            $addTotal,
        ) {
            $orderId = (int) $existing['id'];

            $raw = $existing['shipping_address'] ?? '';
            $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $merged = is_array($decoded['_merged_external_ids'] ?? null)
                ? $decoded['_merged_external_ids']
                : [];
            if (!in_array($externalId, $merged, true)
                && $externalId !== (string) $existing['external_order_id']
            ) {
                $merged[] = $externalId;
            }
            $decoded['_merged_external_ids'] = $merged;

            $tenantId = (int) ($existing['tenant_id'] ?? ($incoming['tenant_id'] ?? 0));
            $db->update('sf_orders', [
                'total'            => (float) $existing['total'] + $addTotal,
                'shipping_address' => json_encode($decoded, JSON_UNESCAPED_SLASHES),
                'synced_at'        => date('Y-m-d H:i:s'),
                'buyer_email'      => $incoming['buyer_email'] !== ''
                    ? $incoming['buyer_email']
                    : $existing['buyer_email'],
            ], [
                'id'        => $orderId,
                'tenant_id' => $tenantId,
            ]);

            $this->insertLineItems(
                $db,
                $orderId,
                $lineItems,
                $tenantId
            );

            return ['merged', $orderId];
        });
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     */
    private function insertLineItems(Database $db, int $orderId, array $lineItems, int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }
        $kits = $this->kits ?? new KitExpansionService($db, new ProductBundleRepository($db));

        foreach ($lineItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Prefer eBay-normalized quantity when present.
            if (isset($item['_ebay_quantity']) && is_numeric($item['_ebay_quantity'])) {
                $item['quantity'] = max(1, (int) $item['_ebay_quantity']);
            } elseif (!isset($item['quantity']) || $item['quantity'] === '' || $item['quantity'] === null) {
                $item['quantity'] = KitExpansionService::extractQuantity($item);
            } else {
                $item['quantity'] = max(1, (int) $item['quantity']);
            }
            foreach ($kits->expandLineItem($item) as $row) {
                $db->insert('sf_order_items', [
                    'tenant_id'             => $tenantId,
                    'order_id'              => $orderId,
                    'external_line_item_id' => (string) ($row['external_line_item_id'] ?? ''),
                    'variant_id'            => $row['variant_id'] ?? null,
                    'external_sku'          => (string) ($row['external_sku'] ?? ''),
                    'kit_sku'               => (string) ($row['kit_sku'] ?? ''),
                    'title'                 => (string) ($row['title'] ?? ''),
                    'qty'                   => max(1, (int) ($row['qty'] ?? $item['quantity'] ?? 1)),
                    'price'                 => (float) ($row['price'] ?? 0),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $address
     */
    private function addressKey(array $address): string
    {
        $parts = [
            strtolower(trim((string) ($address['addressLine1'] ?? $address['address1'] ?? ''))),
            strtolower(trim((string) ($address['postalCode'] ?? $address['zip'] ?? ''))),
            strtolower(trim((string) ($address['city'] ?? ''))),
        ];
        $key = implode('|', $parts);
        return $key === '||' ? '' : $key;
    }

    /**
     * @param array<string, mixed> $order
     * @return array{
     *   total: float,
     *   currency: string,
     *   shipping_cost: float,
     *   discount_amount: float,
     *   channel_fee: float,
     *   shipping_tax: float
     * }
     */
    private function extractMoney(Channel $channel, array $order): array
    {
        $summary = is_array($order['pricingSummary'] ?? null) ? $order['pricingSummary'] : [];
        $total = (float) ($summary['total']['value'] ?? 0);
        $currency = (string) ($summary['total']['currency']
            ?? ChannelSettings::string($channel, 'currency', 'GBP'));

        $shipping = (float) (
            $summary['deliveryCost']['value']
            ?? $summary['shippingCost']['value']
            ?? 0
        );
        $discount = (float) (
            $summary['priceDiscount']['value']
            ?? $summary['adjustment']['value']
            ?? 0
        );

        // When "Deduct discounts" is off, show pre-discount channel total.
        if ($discount > 0 && !ChannelSettings::bool($channel, 'deduct_discounts_from_channel', true)) {
            $total += $discount;
        }

        $feePct = (float) ChannelSettings::string($channel, 'channel_percentage_fee', '');
        $fee = $feePct > 0 ? round($total * ($feePct / 100), 2) : 0.0;

        $shippingTax = 0.0;
        if ($shipping > 0 && ChannelSettings::bool($channel, 'calculate_shipping_tax', false)) {
            // Default UK VAT 20% on shipping when toggle is on.
            $shippingTax = round($shipping * 0.20, 2);
        }

        return [
            'total'            => $total,
            'currency'         => $currency !== '' ? $currency : 'GBP',
            'shipping_cost'    => $shipping,
            'discount_amount'  => $discount,
            'channel_fee'      => $fee,
            'shipping_tax'     => $shippingTax,
        ];
    }

    private function parseEbayDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $ts = strtotime((string) $raw);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * Buyer-selected shipping service from eBay (e.g. UK_HermesDoorToDoorService → HERMES…).
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $shipStep
     */
    private function extractShippingMethod(array $order, array $shipStep): string
    {
        $candidates = [
            (string) ($shipStep['shippingServiceCode'] ?? ''),
            (string) ($shipStep['shippingCarrierCode'] ?? ''),
        ];

        $lineItems = is_array($order['lineItems'] ?? null) ? $order['lineItems'] : [];
        foreach ($lineItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $liStep = $item['lineItemFulfillmentInstructions']['shippingStep'] ?? null;
            if (!is_array($liStep)) {
                $liStep = $item['deliveryCost'] ?? null;
            }
            if (is_array($liStep)) {
                $candidates[] = (string) ($liStep['shippingServiceCode'] ?? '');
                $candidates[] = (string) ($liStep['shippingCarrierCode'] ?? '');
            }
            // Some payloads put the service name on the line item.
            $candidates[] = (string) ($item['shippingServiceCode'] ?? '');
            $candidates[] = (string) ($item['legacyVariationId'] ?? '');
        }

        foreach ($candidates as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            return $this->formatShippingMethod($raw);
        }

        return '';
    }

    private function formatShippingMethod(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        // Already human-readable (StoreFeeder-style).
        if (str_contains($code, ' ') || preg_match('/[a-z]/', $code)) {
            return mb_substr($code, 0, 128);
        }

        // UK_HermesDoorToDoorService → HERMES DOOR TO DOOR SERVICE
        $pretty = preg_replace('/^(UK_|US_|DE_|AU_|EBAY_)/i', '', $code) ?? $code;
        $pretty = preg_replace('/([a-z])([A-Z])/', '$1 $2', $pretty) ?? $pretty;
        $pretty = strtoupper(str_replace('_', ' ', $pretty));
        $pretty = preg_replace('/\s+/', ' ', $pretty) ?? $pretty;

        return mb_substr(trim($pretty), 0, 128);
    }

    private function channelTenantAllowed(Channel $channel): bool
    {
        $tid = (int) $channel->tenantId;
        if ($tid <= 0) {
            return false;
        }
        if (TenantContext::get()->has() && TenantContext::get()->id() !== $tid) {
            return false;
        }
        return true;
    }

    private function channelTenantId(Channel $channel): int
    {
        $tid = (int) $channel->tenantId;
        if ($tid <= 0) {
            throw new RuntimeException("Channel '{$channel->name}' has no tenant.");
        }
        if (TenantContext::get()->has() && TenantContext::get()->id() !== $tid) {
            throw new RuntimeException('Channel does not belong to the current tenant.');
        }
        return $tid;
    }
}
