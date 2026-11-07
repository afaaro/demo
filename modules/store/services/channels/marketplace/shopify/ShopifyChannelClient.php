<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Shopify;

use Module\Store\Services\Channel;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\ChannelSettings;
use Module\Store\Services\Channels\ChannelClientInterface;
use Module\Store\Services\Channels\NotSupportedException;
use Module\Store\Services\CredentialsVault;
use RuntimeException;

/**
 * Shopify Admin API — orders, fulfillment, product push / inventory.
 */
final class ShopifyChannelClient implements ChannelClientInterface
{
    /** @param array<string, string> $creds */
    public function __construct(
        private Channel $channel,
        private array $creds,
        private CredentialsVault $vault,
        private ChannelRepository $channels,
    ) {}

    public function testConnection(): array
    {
        try {
            $shop = $this->api()->getShop();
            $name = trim((string) ($shop['name'] ?? $shop['myshopify_domain'] ?? ''));

            $this->channels->update((int) $this->channel->id, [
                'status'     => 'connected',
                'last_error' => '',
            ], $this->vault);

            return [
                'ok'   => true,
                'shop' => $name !== '' ? $name : $this->api()->shopDomain(),
            ];
        } catch (\Throwable $e) {
            error_log('Shopify testConnection failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function consentUrl(string $state): ?string
    {
        return null;
    }

    public function handleCallback(string $code): array
    {
        throw new NotSupportedException('Shopify does not use OAuth in this integration yet.');
    }

    public function accessToken(): string
    {
        return trim((string) ($this->creds['access_token'] ?? ''));
    }

    /**
     * Create or update a Shopify product/variant by SKU. Returns Shopify product id.
     *
     * @param array<string, mixed> $variantData
     */
    public function pushVariant(int $variantId, array $variantData): string
    {
        unset($variantId);
        $sku = trim((string) ($variantData['sku'] ?? ''));
        if ($sku === '') {
            throw new RuntimeException('SKU is required to push a Shopify product.');
        }

        $title = trim((string) ($variantData['title'] ?? $sku));
        if ($title === '') {
            $title = $sku;
        }
        $price = number_format(max(0, (float) ($variantData['price'] ?? 0)), 2, '.', '');
        $qty = max(0, (int) ($variantData['quantity'] ?? 0));
        $barcode = trim((string) ($variantData['barcode'] ?? ''));
        $bodyHtml = trim((string) ($variantData['description'] ?? ''));

        $api = $this->api();
        $existing = $api->findProductBySku($sku);

        $variantPayload = [
            'sku'              => $sku,
            'price'            => $price,
            'barcode'          => $barcode !== '' ? $barcode : null,
            'inventory_management' => 'shopify',
            'inventory_policy' => 'deny',
        ];
        $variantPayload = array_filter(
            $variantPayload,
            static fn($v) => $v !== null && $v !== ''
        );

        if ($existing !== null) {
            $productId = (string) ($existing['product']['id'] ?? '');
            $variantIdRemote = (int) ($existing['variant']['id'] ?? 0);
            if ($productId === '' || $variantIdRemote <= 0) {
                throw new RuntimeException('Shopify product/variant ids missing for SKU ' . $sku);
            }
            $variantPayload['id'] = $variantIdRemote;
            $api->updateProduct($productId, [
                'title'     => $title,
                'body_html' => $bodyHtml,
                'status'    => 'active',
                'variants'  => [$variantPayload],
            ]);
            $inventoryItemId = (int) ($existing['variant']['inventory_item_id'] ?? 0);
            if ($inventoryItemId > 0) {
                $this->setInventoryForItem($api, $inventoryItemId, $qty);
            }
            return $productId;
        }

        $images = [];
        $imageUrls = is_array($variantData['imageUrls'] ?? null) ? $variantData['imageUrls'] : [];
        foreach (array_slice($imageUrls, 0, 5) as $url) {
            $url = trim((string) $url);
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $images[] = ['src' => $url];
            }
        }

        $variantPayload['inventory_quantity'] = $qty;
        $created = $api->createProduct([
            'title'     => $title,
            'body_html' => $bodyHtml,
            'status'    => 'active',
            'variants'  => [$variantPayload],
            'images'    => $images,
        ]);

        $productId = (string) ($created['id'] ?? '');
        $variants = is_array($created['variants'] ?? null) ? $created['variants'] : [];
        $inventoryItemId = (int) ($variants[0]['inventory_item_id'] ?? 0);
        if ($inventoryItemId > 0) {
            // inventory_quantity on create is deprecated on some shops — set explicitly.
            try {
                $this->setInventoryForItem($api, $inventoryItemId, $qty);
            } catch (\Throwable $e) {
                error_log('Shopify inventory after create [' . $sku . ']: ' . $e->getMessage());
            }
        }

        return $productId;
    }

    public function syncInventory(int $variantId, int $quantity, string $sku = ''): void
    {
        unset($variantId);
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('Shopify inventory sync requires the product SKU.');
        }

        $api = $this->api();
        $existing = $api->findProductBySku($sku);
        if ($existing === null) {
            throw new RuntimeException("No Shopify product found for SKU {$sku}. Push the listing first.");
        }
        $inventoryItemId = (int) ($existing['variant']['inventory_item_id'] ?? 0);
        if ($inventoryItemId <= 0) {
            throw new RuntimeException("Shopify variant for SKU {$sku} has no inventory_item_id.");
        }
        $this->setInventoryForItem($api, $inventoryItemId, max(0, $quantity));
    }

    /**
     * @return array{orders: array<int, array<string, mixed>>, next: ?string}
     */
    public function fetchOrders(int $days = 30, int $limit = 50, ?string $offset = null): array
    {
        $api = $this->api();
        $limit = max(1, min(100, $limit));
        $createdAfter = gmdate('c', time() - max(1, $days) * 86400);
        $pageInfo = ($offset !== null && trim($offset) !== '') ? trim($offset) : null;

        $page = $api->listOrders($createdAfter, $limit, $pageInfo);
        $normalized = [];
        foreach ($page['orders'] as $raw) {
            $mapped = $this->normalizeOrder($raw);
            if ($mapped !== null) {
                $normalized[] = $mapped;
            }
        }

        return [
            'orders' => $normalized,
            'next'   => $page['next'],
        ];
    }

    public function fetchConversations(
        int $limit = 25,
        int|string $offset = 0,
        string $conversationType = 'FROM_MEMBERS',
        string $status = '',
    ): array {
        unset($limit, $offset, $conversationType, $status);
        throw new NotSupportedException('Shopify messaging is not implemented yet.');
    }

    /**
     * Create Shopify fulfillment with tracking (FulfillmentOrders API).
     *
     * @param list<array{lineItemId: string, quantity: int}> $lineItems
     * @return array<string, mixed>
     */
    public function shipOrder(
        string $orderId,
        array $lineItems,
        string $trackingNumber,
        string $carrierCode,
        ?string $shippedDate = null,
    ): array {
        unset($shippedDate);
        $orderId = trim($orderId);
        $trackingNumber = trim($trackingNumber);
        $carrierCode = trim($carrierCode);
        if ($orderId === '') {
            throw new RuntimeException('Shopify order id is required.');
        }
        if ($trackingNumber === '') {
            throw new RuntimeException('Tracking number is required.');
        }
        if ($lineItems === []) {
            throw new RuntimeException('At least one line item is required.');
        }

        $want = [];
        foreach ($lineItems as $li) {
            $id = trim((string) ($li['lineItemId'] ?? ''));
            if ($id === '') {
                continue;
            }
            $want[$id] = max(1, (int) ($li['quantity'] ?? 1));
        }
        if ($want === []) {
            throw new RuntimeException('Missing Shopify line item IDs. Sync orders again, then retry.');
        }

        $api = $this->api();
        $fulfillmentOrders = $api->listFulfillmentOrders($orderId);
        $byFo = [];

        foreach ($fulfillmentOrders as $fo) {
            $status = strtoupper((string) ($fo['status'] ?? ''));
            if (in_array($status, ['CLOSED', 'CANCELLED', 'INCOMPLETE'], true)) {
                continue;
            }
            $foId = (int) ($fo['id'] ?? 0);
            if ($foId <= 0) {
                continue;
            }
            $foLines = is_array($fo['line_items'] ?? null) ? $fo['line_items'] : [];
            $pick = [];
            foreach ($foLines as $fol) {
                if (!is_array($fol)) {
                    continue;
                }
                $orderLineId = (string) ($fol['line_item_id'] ?? '');
                if ($orderLineId === '' || !isset($want[$orderLineId])) {
                    continue;
                }
                $folId = (int) ($fol['id'] ?? 0);
                if ($folId <= 0) {
                    continue;
                }
                $fulfillable = (int) ($fol['fulfillable_quantity'] ?? $fol['quantity'] ?? 0);
                $qty = min($want[$orderLineId], max(0, $fulfillable));
                if ($qty <= 0) {
                    continue;
                }
                $pick[] = ['id' => $folId, 'quantity' => $qty];
            }
            if ($pick !== []) {
                $byFo[] = [
                    'fulfillment_order_id'         => $foId,
                    'fulfillment_order_line_items' => $pick,
                ];
            }
        }

        if ($byFo === []) {
            throw new RuntimeException(
                'No open Shopify fulfillment orders match these line items. '
                . 'The order may already be fulfilled.'
            );
        }

        $notify = ChannelSettings::bool($this->channel, 'send_despatch_notifications', true);
        $result = $api->createFulfillment(
            $byFo,
            $trackingNumber,
            $this->shopifyCarrier($carrierCode),
            $notify,
        );

        return [
            'order_id'        => $orderId,
            'tracking_number' => $trackingNumber,
            'carrier'         => $carrierCode,
            'status'          => (string) ($result['status'] ?? 'success'),
            'fulfillment_id'  => (string) ($result['id'] ?? ''),
        ];
    }

    // -------------------------------------------------------------------------

    private function setInventoryForItem(ShopifyAdminApi $api, int $inventoryItemId, int $qty): void
    {
        $locationId = ChannelSettings::int($this->channel, 'shopify_location_id', 0);
        if ($locationId <= 0) {
            $locations = $api->listLocations();
            $locationId = (int) ($locations[0]['id'] ?? 0);
        }
        if ($locationId <= 0) {
            throw new RuntimeException(
                'No active Shopify location found. Set shopify_location_id on the channel, '
                . 'or enable a location in Shopify Admin.'
            );
        }
        $api->setInventoryLevel($inventoryItemId, $locationId, $qty);
    }

    private function api(): ShopifyAdminApi
    {
        $token = $this->accessToken();
        $shop = trim((string) ($this->creds['shop_domain'] ?? ''));
        if ($token === '' || $shop === '') {
            throw new RuntimeException('Shop domain and access token are required.');
        }
        return new ShopifyAdminApi($token, $shop);
    }

    private function shopifyCarrier(string $carrier): string
    {
        $c = strtoupper(trim($carrier));
        if ($c === '') {
            return 'Other';
        }
        return match (true) {
            str_contains($c, 'ROYAL') || $c === 'RM' || $c === 'ROYAL_MAIL' => 'Royal Mail',
            str_contains($c, 'EVRI') || str_contains($c, 'HERMES') => 'Evri',
            str_contains($c, 'DPD') => 'DPD',
            str_contains($c, 'DHL') => 'DHL Express',
            str_contains($c, 'UPS') => 'UPS',
            str_contains($c, 'FEDEX') => 'FedEx',
            str_contains($c, 'YODEL') => 'Yodel',
            default => trim($carrier),
        };
    }

    /**
     * Map Shopify order → Store / eBay-shaped order for OrderSyncService.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeOrder(array $raw): ?array
    {
        $orderId = trim((string) ($raw['id'] ?? ''));
        if ($orderId === '') {
            return null;
        }

        $financial = strtolower(trim((string) ($raw['financial_status'] ?? '')));
        $fulfillmentRaw = strtolower(trim((string) ($raw['fulfillment_status'] ?? '')));
        $cancelled = !empty($raw['cancelled_at']);

        $fulfillment = match (true) {
            $cancelled => 'CANCELLED',
            $fulfillmentRaw === 'fulfilled' => 'FULFILLED',
            $fulfillmentRaw === 'partial' => 'IN_PROGRESS',
            default => 'NOT_STARTED',
        };

        $paymentStatus = match ($financial) {
            'paid', 'partially_refunded', 'partially_paid' => 'PAID',
            'refunded', 'voided' => 'CANCELLED',
            default => 'PENDING',
        };
        if ($cancelled && $paymentStatus === 'PENDING') {
            $paymentStatus = 'CANCELLED';
        }

        $currency = strtoupper(trim((string) (
            $raw['currency']
            ?? $raw['presentment_currency']
            ?? ChannelSettings::string($this->channel, 'currency', '')
        )));
        if ($currency === '') {
            $currency = 'GBP';
        }

        $total = (float) ($raw['total_price'] ?? 0);
        $shippingTotal = 0.0;
        $shippingLines = is_array($raw['shipping_lines'] ?? null) ? $raw['shipping_lines'] : [];
        $shipService = '';
        foreach ($shippingLines as $sl) {
            if (!is_array($sl)) {
                continue;
            }
            $shippingTotal += (float) ($sl['price'] ?? 0);
            if ($shipService === '') {
                $shipService = trim((string) ($sl['title'] ?? $sl['code'] ?? ''));
            }
        }

        $shipAddr = is_array($raw['shipping_address'] ?? null) ? $raw['shipping_address'] : [];
        $customer = is_array($raw['customer'] ?? null) ? $raw['customer'] : [];
        $fullName = trim((string) (
            $shipAddr['name']
            ?? trim((string) (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')))
        ));
        $email = trim((string) ($raw['email'] ?? $customer['email'] ?? ''));
        $phone = trim((string) ($shipAddr['phone'] ?? $raw['phone'] ?? $customer['phone'] ?? ''));
        $buyerName = $fullName !== '' ? $fullName : (
            trim((string) ($raw['name'] ?? '')) !== ''
                ? (string) $raw['name']
                : $orderId
        );

        $lineItems = [];
        $rawLines = is_array($raw['line_items'] ?? null) ? $raw['line_items'] : [];
        foreach ($rawLines as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Skip tip / removed lines.
            if (!empty($item['gift_card'])) {
                continue;
            }
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $unit = (float) ($item['price'] ?? 0);
            $lineItems[] = [
                'lineItemId' => (string) ($item['id'] ?? ''),
                'sku'        => trim((string) ($item['sku'] ?? '')),
                'title'      => (string) ($item['title'] ?? $item['name'] ?? ''),
                'quantity'   => $qty,
                'lineItemCost' => [
                    'value'    => $unit,
                    'currency' => $currency,
                ],
                '_unit_priced' => true,
                'soldFormat'   => 'FIXED_PRICE',
            ];
        }

        return [
            'orderId'                => $orderId,
            'creationDate'           => (string) ($raw['created_at'] ?? ''),
            'orderFulfillmentStatus' => $fulfillment,
            'orderPaymentStatus'     => $paymentStatus,
            'buyer'                  => [
                'username' => $buyerName,
                'email'    => $email,
            ],
            'pricingSummary' => [
                'total' => [
                    'value'    => $total,
                    'currency' => $currency,
                ],
                'deliveryCost' => [
                    'value'    => $shippingTotal,
                    'currency' => $currency,
                ],
            ],
            'fulfillmentStartInstructions' => [[
                'shippingStep' => [
                    'shippingServiceCode' => $shipService,
                    'shipTo' => [
                        'fullName' => $fullName !== '' ? $fullName : $buyerName,
                        'email'    => $email,
                        'primaryPhone' => [
                            'phoneNumber' => $phone,
                        ],
                        'contactAddress' => [
                            'addressLine1'    => (string) ($shipAddr['address1'] ?? ''),
                            'addressLine2'    => (string) ($shipAddr['address2'] ?? ''),
                            'city'            => (string) ($shipAddr['city'] ?? ''),
                            'stateOrProvince' => (string) ($shipAddr['province'] ?? $shipAddr['province_code'] ?? ''),
                            'postalCode'      => (string) ($shipAddr['zip'] ?? ''),
                            'countryCode'     => (string) ($shipAddr['country_code'] ?? $shipAddr['country'] ?? ''),
                        ],
                    ],
                ],
            ]],
            'lineItems'  => $lineItems,
            '_source'    => 'shopify_admin',
            '_raw_name'  => (string) ($raw['name'] ?? ''),
            '_raw_state' => $financial . '/' . $fulfillmentRaw,
        ];
    }
}
