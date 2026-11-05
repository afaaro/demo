<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\Bnq;

use Module\StoreFeeder\Services\Channel;
use Module\StoreFeeder\Services\ChannelRepository;
use Module\StoreFeeder\Services\ChannelSettings;
use Module\StoreFeeder\Services\Channels\ChannelClientInterface;
use Module\StoreFeeder\Services\Channels\NotSupportedException;
use Module\StoreFeeder\Services\CredentialsVault;
use RuntimeException;

/**
 * B&Q marketplace via Kingfisher Mirakl MMP API.
 *
 * Offers: OF24 push / inventory / price.
 * Orders: OR11 sync · OR21 accept · OR23 tracking · OR24 ship.
 * Messages: M11/M10 inbox · M12 reply.
 */
final class BnqChannelClient implements ChannelClientInterface
{
    /** @param array<string, string> $creds */
    public function __construct(
        private Channel $channel,
        private array $creds,
        private CredentialsVault $vault,
        private ChannelRepository $channels,
    ) {}

    /**
     * @return array{ok: bool, error?: string, shop?: string}
     */
    public function testConnection(): array
    {
        try {
            $apiKey = trim((string) ($this->creds['api_key'] ?? ''));
            if ($apiKey === '') {
                return ['ok' => false, 'error' => 'Mirakl API key is required.'];
            }

            $client = $this->mirakl();
            $account = $client->getAccount();

            $shopName = trim((string) (
                $account['shop_name']
                ?? $account['shopName']
                ?? $account['name']
                ?? ''
            ));
            $shopId = trim((string) (
                $account['shop_id']
                ?? $account['shopId']
                ?? $account['id']
                ?? ''
            ));

            $this->channels->update((int) $this->channel->id, [
                'status'     => 'connected',
                'last_error' => '',
            ], $this->vault);

            $label = $shopName !== '' ? $shopName : ($shopId !== '' ? 'Shop ' . $shopId : 'B&Q Mirakl');
            return ['ok' => true, 'shop' => $label];
        } catch (\Throwable $e) {
            error_log('B&Q testConnection failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function consentUrl(string $state): ?string
    {
        return null;
    }

    public function handleCallback(string $code): array
    {
        throw new NotSupportedException('B&Q uses a Mirakl API key (no OAuth callback).');
    }

    public function accessToken(): string
    {
        return trim((string) ($this->creds['api_key'] ?? ''));
    }

    /**
     * Push / update a Mirakl offer (OF24). Returns shop_sku as external id.
     *
     * @param array<string, mixed> $variantData
     */
    public function pushVariant(int $variantId, array $variantData): string
    {
        $sku = trim((string) ($variantData['sku'] ?? ''));
        if ($sku === '') {
            throw new RuntimeException('SKU is required to push a B&Q offer.');
        }

        $productId = $this->resolveProductId($variantData);
        $productIdType = strtoupper(ChannelSettings::string(
            $this->channel,
            'product_id_type',
            'EAN'
        ));
        if ($productId === '') {
            throw new RuntimeException(
                'B&Q offer push needs a product identifier (set product EAN/barcode, or a channel attribute'
                . " named product_id / {$productIdType})."
            );
        }

        $price = round((float) ($variantData['price'] ?? 0), 2);
        if ($price <= 0) {
            throw new RuntimeException('Price must be greater than zero for B&Q offer push.');
        }

        $qty = max(0, (int) ($variantData['quantity'] ?? 0));
        $stateCode = ChannelSettings::string($this->channel, 'state_code', '11');
        if ($stateCode === '') {
            $stateCode = '11';
        }

        $offer = [
            'shop_sku'        => $sku,
            'product_id'      => $productId,
            'product_id_type' => $productIdType,
            'price'           => $price,
            'quantity'        => $qty,
            'state_code'      => $stateCode,
            'update_delete'   => 'update',
            'description'     => mb_substr(
                trim(strip_tags((string) ($variantData['description'] ?? $variantData['title'] ?? ''))),
                0,
                2000
            ),
        ];

        $lead = ChannelSettings::int($this->channel, 'handling_time_days', 0);
        if ($lead > 0) {
            $offer['leadtime_to_ship'] = $lead;
        }

        $logistic = trim(ChannelSettings::string($this->channel, 'logistic_class', ''));
        if ($logistic !== '') {
            $offer['logistic_class'] = $logistic;
        }

        $extra = $this->offerAdditionalFields($variantData);
        if ($extra !== []) {
            $offer['offer_additional_fields'] = $extra;
        }

        // OF24 returns an async import_id; StoreFeeder tracks the offer by shop_sku.
        $this->mirakl()->upsertOffers([$offer]);

        return $sku;
    }

    public function syncInventory(int $variantId, int $quantity, string $sku = ''): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('B&Q inventory sync requires the offer shop SKU.');
        }

        $offer = $this->requireOffer($sku);
        $payload = $this->offerUpdateFromExisting($offer, [
            'quantity' => max(0, $quantity),
        ]);
        $this->mirakl()->upsertOffers([$payload]);
    }

    public function syncPrice(string $sku, float $price, string $currency = 'GBP'): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('B&Q price sync requires the offer shop SKU.');
        }
        if ($price <= 0) {
            throw new RuntimeException('Price must be greater than zero.');
        }

        $offer = $this->requireOffer($sku);
        $payload = $this->offerUpdateFromExisting($offer, [
            'price' => round($price, 2),
        ]);
        $this->mirakl()->upsertOffers([$payload]);
    }

    /**
     * @return array{orders: array<int, array<string, mixed>>, next: ?string}
     */
    public function fetchOrders(int $days = 30, int $limit = 50, ?string $offset = null): array
    {
        $offsetInt = max(0, (int) ($offset ?? 0));
        $limit = max(1, min(100, $limit));
        $start = gmdate('Y-m-d\TH:i:s\Z', time() - max(1, $days) * 86400);

        $page = $this->mirakl()->listOrders($limit, $offsetInt, $start);
        $normalized = [];
        foreach ($page['orders'] as $raw) {
            $mapped = $this->normalizeOrder($raw);
            if ($mapped !== null) {
                $normalized[] = $mapped;
            }
        }

        $fetched = count($page['orders']);
        $nextOffset = $offsetInt + $fetched;
        $next = ($fetched > 0 && $nextOffset < (int) $page['total_count'])
            ? (string) $nextOffset
            : null;

        return [
            'orders' => $normalized,
            'next'   => $next,
        ];
    }

    /**
     * @return array{
     *     conversations: array<int, array<string, mixed>>,
     *     limit: int,
     *     offset: int,
     *     total: int,
     *     next?: string|null
     * }
     */
    public function fetchConversations(
        int $limit = 25,
        int|string $offset = 0,
        string $conversationType = 'FROM_MEMBERS',
        string $status = '',
    ): array {
        unset($conversationType, $status);
        $limit = max(1, min(100, $limit));
        $pageToken = is_string($offset) && !ctype_digit($offset) && $offset !== ''
            ? $offset
            : null;

        $page = $this->mirakl()->listThreads($limit, $pageToken, true);
        $conversations = [];
        foreach ($page['threads'] as $thread) {
            $mapped = $this->normalizeThread($thread);
            if ($mapped !== null) {
                $conversations[] = $mapped;
            }
        }

        return [
            'conversations' => $conversations,
            'limit'         => $limit,
            'offset'        => is_int($offset) ? $offset : 0,
            'total'         => count($conversations),
            'next'          => $page['next'],
        ];
    }

    /**
     * @return array{messages: list<array<string, mixed>>}
     */
    public function fetchConversation(
        string $conversationId,
        string $conversationType = 'BNQ_THREAD',
    ): array {
        unset($conversationType);
        $thread = $this->mirakl()->getThread($conversationId);
        $messages = $this->normalizeThreadMessages($thread);

        return ['messages' => $messages];
    }

    /**
     * Reply on a Mirakl inbox thread (M12).
     *
     * @return array<string, mixed>
     */
    public function sendMessage(
        string $conversationId,
        string $conversationType,
        string $body,
    ): array {
        unset($conversationType);
        $conversationId = trim($conversationId);
        $body = trim($body);
        if ($conversationId === '') {
            throw new RuntimeException('B&Q thread id is required.');
        }
        if ($body === '') {
            throw new RuntimeException('Message body cannot be empty.');
        }

        $thread = $this->mirakl()->getThread($conversationId);
        $to = $this->replyRecipients($thread);
        if ($to === []) {
            // Fallback: message the customer participant type.
            $to = [['type' => 'CUSTOMER']];
        }

        return $this->mirakl()->replyToThread($conversationId, $body, $to);
    }

    /**
     * Accept (if needed), set tracking (OR23), and confirm shipment (OR24).
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
        unset($shippedDate); // Mirakl uses server time on confirm.
        $orderId = trim($orderId);
        $trackingNumber = trim($trackingNumber);
        $carrierCode = trim($carrierCode);
        if ($orderId === '') {
            throw new RuntimeException('B&Q order id is required.');
        }
        if ($trackingNumber === '') {
            throw new RuntimeException('Tracking number is required.');
        }
        if ($lineItems === []) {
            throw new RuntimeException('At least one line item is required.');
        }

        $client = $this->mirakl();

        $acceptLines = [];
        foreach ($lineItems as $li) {
            $id = trim((string) ($li['lineItemId'] ?? ''));
            if ($id === '') {
                continue;
            }
            $acceptLines[] = ['id' => $id, 'accepted' => true];
        }

        // OR21 — ignore if already past WAITING_ACCEPTANCE.
        if ($acceptLines !== []) {
            try {
                $client->acceptOrder($orderId, $acceptLines);
            } catch (\Throwable $e) {
                $msg = strtolower($e->getMessage());
                if (!str_contains($msg, 'invalid_state')
                    && !str_contains($msg, 'not in the expected state')
                    && !str_contains($msg, 'order_invalid_state')
                ) {
                    // Still try tracking — some platforms auto-accept.
                    error_log('B&Q OR21 accept [' . $orderId . ']: ' . $e->getMessage());
                }
            }
        }

        // OR23 tracking
        $carrierName = $carrierCode;
        $code = $this->miraklCarrierCode($carrierCode);
        $client->updateOrderTracking(
            $orderId,
            $trackingNumber,
            $code,
            $code === '' ? $carrierName : '',
        );

        // OR24 confirm ship
        try {
            $client->confirmOrderShipment($orderId);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (!str_contains($msg, 'invalid_state') && !str_contains($msg, 'already')) {
                throw $e;
            }
        }

        return [
            'order_id'        => $orderId,
            'tracking_number' => $trackingNumber,
            'carrier'         => $carrierCode,
            'status'          => 'SHIPPED',
        ];
    }

    // ------------------------------------------------------------------

    private function miraklCarrierCode(string $carrier): string
    {
        $c = strtoupper(trim($carrier));
        if ($c === '') {
            return '';
        }
        return match (true) {
            str_contains($c, 'ROYAL') || $c === 'RM' || $c === 'ROYAL_MAIL' => 'ROYAL_MAIL',
            str_contains($c, 'EVRI') || str_contains($c, 'HERMES') => 'HERMES',
            str_contains($c, 'DPD') => 'DPD',
            str_contains($c, 'DHL') => 'DHL',
            str_contains($c, 'UPS') => 'UPS',
            str_contains($c, 'FEDEX') => 'FEDEX',
            str_contains($c, 'YODEL') => 'YODEL',
            default => '', // unregistered → carrier_name used instead
        };
    }

    private function mirakl(): BnqMiraklClient
    {
        $apiKey = trim((string) ($this->creds['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('Mirakl API key is required.');
        }
        $shopId = trim((string) ($this->channel->settings['shop_id'] ?? ''));

        return new BnqMiraklClient($apiKey, $this->apiBase(), $shopId !== '' ? $shopId : null);
    }

    private function apiBase(): string
    {
        $base = trim((string) ($this->channel->settings['api_base'] ?? ''));
        if ($base === '') {
            $base = 'https://marketplace.kingfisher.com';
        }
        return $base;
    }

    /** @return array<string, mixed> */
    private function requireOffer(string $shopSku): array
    {
        $offers = $this->mirakl()->listOffers($shopSku, 1);
        foreach ($offers as $offer) {
            $sku = trim((string) ($offer['shop_sku'] ?? $offer['sku'] ?? ''));
            if ($sku === $shopSku) {
                return $offer;
            }
        }
        if ($offers !== []) {
            return $offers[0];
        }
        throw new RuntimeException("B&Q offer not found for shop SKU '{$shopSku}'.");
    }

    /**
     * Build OF24 update payload from an existing OF21 offer + overrides.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function offerUpdateFromExisting(array $existing, array $overrides): array
    {
        $shopSku = trim((string) ($existing['shop_sku'] ?? $existing['sku'] ?? ''));
        if ($shopSku === '') {
            throw new RuntimeException('Existing offer is missing shop_sku.');
        }

        $productId = trim((string) (
            $existing['product_id']
            ?? $existing['product_sku']
            ?? ''
        ));
        $productIdType = strtoupper(trim((string) (
            $existing['product_id_type']
            ?? ChannelSettings::string($this->channel, 'product_id_type', 'EAN')
        )));
        if ($productIdType === '') {
            $productIdType = 'EAN';
        }

        $payload = [
            'shop_sku'        => $shopSku,
            'product_id'      => $productId,
            'product_id_type' => $productIdType,
            'price'           => (float) ($existing['price'] ?? 0),
            'quantity'        => (int) ($existing['quantity'] ?? 0),
            'state_code'      => (string) ($existing['state_code'] ?? '11'),
            'update_delete'   => 'update',
        ];

        foreach (['description', 'leadtime_to_ship', 'logistic_class', 'price_additional_info'] as $key) {
            if (isset($existing[$key]) && $existing[$key] !== '' && $existing[$key] !== null) {
                $payload[$key] = $existing[$key];
            }
        }

        if (is_array($existing['offer_additional_fields'] ?? null)) {
            $payload['offer_additional_fields'] = $existing['offer_additional_fields'];
        }

        return array_merge($payload, $overrides);
    }

    /**
     * @param array<string, mixed> $variantData
     */
    private function resolveProductId(array $variantData): string
    {
        $aspects = is_array($variantData['aspects'] ?? null) ? $variantData['aspects'] : [];
        foreach (['product_id', 'EAN', 'ean', 'GTIN', 'gtin', 'UPC', 'upc', 'barcode'] as $key) {
            $vals = $aspects[$key] ?? null;
            if (is_array($vals) && isset($vals[0]) && trim((string) $vals[0]) !== '') {
                return trim((string) $vals[0]);
            }
            if (is_scalar($vals) && trim((string) $vals) !== '') {
                return trim((string) $vals);
            }
        }

        return trim((string) ($variantData['barcode'] ?? $variantData['product_id'] ?? ''));
    }

    /**
     * Channel attributes → Mirakl offer_additional_fields.
     *
     * @param array<string, mixed> $variantData
     * @return list<array{code: string, value: string}>
     */
    private function offerAdditionalFields(array $variantData): array
    {
        $aspects = is_array($variantData['aspects'] ?? null) ? $variantData['aspects'] : [];
        $skip = [
            'product_id', 'ean', 'gtin', 'upc', 'barcode', 'sku', 'title', 'description',
        ];
        $out = [];
        foreach ($aspects as $name => $values) {
            $code = trim((string) $name);
            if ($code === '' || in_array(strtolower($code), $skip, true)) {
                continue;
            }
            if (is_array($values)) {
                $value = trim(implode(',', array_map('strval', $values)));
            } else {
                $value = trim((string) $values);
            }
            if ($value === '') {
                continue;
            }
            $out[] = ['code' => $code, 'value' => $value];
        }
        return $out;
    }

    /**
     * Map Mirakl OR11 order → StoreFeeder / eBay-shaped order for OrderSyncService.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeOrder(array $raw): ?array
    {
        $orderId = trim((string) ($raw['order_id'] ?? ''));
        if ($orderId === '') {
            return null;
        }

        $customer = is_array($raw['customer'] ?? null) ? $raw['customer'] : [];
        $shipAddr = is_array($customer['shipping_address'] ?? null)
            ? $customer['shipping_address']
            : [];
        $first = trim((string) ($shipAddr['firstname'] ?? $customer['firstname'] ?? ''));
        $last = trim((string) ($shipAddr['lastname'] ?? $customer['lastname'] ?? ''));
        $fullName = trim($first . ' ' . $last);

        $email = trim((string) (
            $raw['customer_notification_email']
            ?? $customer['email']
            ?? ''
        ));
        $phone = trim((string) ($shipAddr['phone'] ?? $shipAddr['phone_secondary'] ?? ''));
        $buyerId = trim((string) ($customer['customer_id'] ?? ''));

        $currency = strtoupper(trim((string) (
            $raw['currency_iso_code']
            ?? ChannelSettings::string($this->channel, 'currency', 'GBP')
        )));
        if ($currency === '') {
            $currency = 'GBP';
        }

        $total = (float) ($raw['total_price'] ?? 0);
        $shipping = (float) ($raw['shipping_price'] ?? 0);

        $state = strtoupper(trim((string) ($raw['order_state'] ?? '')));
        $fulfillment = match (true) {
            in_array($state, ['CANCELED', 'CANCELLED', 'REFUSED'], true) => 'CANCELLED',
            in_array($state, ['SHIPPED', 'RECEIVED', 'CLOSED'], true) => 'FULFILLED',
            in_array($state, ['SHIPPING', 'TO_COLLECT'], true) => 'IN_PROGRESS',
            default => $state !== '' ? $state : 'NOT_STARTED',
        };

        $debited = !empty($raw['customer_debited_date'])
            || (!empty($raw['customer_debited']) && $raw['customer_debited'] === true)
            || in_array($state, [
                'SHIPPING', 'SHIPPED', 'TO_COLLECT', 'RECEIVED', 'CLOSED', 'WAITING_DEBIT_PAYMENT',
            ], true);
        $paymentStatus = $debited ? 'PAID' : 'PENDING';

        $shippingType = trim((string) (
            $raw['shipping_type_label']
            ?? $raw['shipping_type_code']
            ?? $raw['shipping_company']
            ?? ''
        ));

        $lineItems = [];
        $rawLines = is_array($raw['order_lines'] ?? null) ? $raw['order_lines'] : [];
        foreach ($rawLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $sku = trim((string) (
                $line['offer_sku']
                ?? $line['product_shop_sku']
                ?? $line['product_sku']
                ?? ''
            ));
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $unit = (float) ($line['price_unit'] ?? 0);
            if ($unit <= 0 && $qty > 0) {
                $unit = round(((float) ($line['price'] ?? 0)) / $qty, 4);
            }
            $lineItems[] = [
                'lineItemId' => (string) ($line['order_line_id'] ?? ''),
                'sku'        => $sku,
                'title'      => (string) ($line['product_title'] ?? $sku),
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
            'orderId'                  => $orderId,
            'creationDate'             => (string) ($raw['created_date'] ?? ''),
            'orderFulfillmentStatus'   => $fulfillment,
            'orderPaymentStatus'       => $paymentStatus,
            'buyer'                    => [
                'username' => $buyerId !== '' ? $buyerId : $fullName,
                'email'    => $email,
            ],
            'pricingSummary' => [
                'total' => [
                    'value'    => $total,
                    'currency' => $currency,
                ],
                'deliveryCost' => [
                    'value'    => $shipping,
                    'currency' => $currency,
                ],
            ],
            'fulfillmentStartInstructions' => [[
                'shippingStep' => [
                    'shippingServiceCode' => $shippingType,
                    'shipTo' => [
                        'fullName' => $fullName,
                        'email'    => $email,
                        'primaryPhone' => [
                            'phoneNumber' => $phone,
                        ],
                        'contactAddress' => [
                            'addressLine1' => (string) ($shipAddr['street_1'] ?? ''),
                            'addressLine2' => (string) ($shipAddr['street_2'] ?? ''),
                            'city'         => (string) ($shipAddr['city'] ?? ''),
                            'stateOrProvince' => (string) ($shipAddr['state'] ?? ''),
                            'postalCode'   => (string) ($shipAddr['zip_code'] ?? ''),
                            'countryCode'  => (string) (
                                $shipAddr['country_iso_code']
                                ?? $shipAddr['country']
                                ?? ''
                            ),
                        ],
                    ],
                ],
            ]],
            'lineItems' => $lineItems,
            '_source'   => 'bnq_mirakl',
            '_raw_state'=> $state,
        ];
    }

    /**
     * Map Mirakl inbox thread → StoreFeeder conversation header.
     *
     * @param array<string, mixed> $thread
     * @return array<string, mixed>|null
     */
    private function normalizeThread(array $thread): ?array
    {
        $id = trim((string) ($thread['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $meta = is_array($thread['metadata'] ?? null) ? $thread['metadata'] : [];
        $lastSender = is_array($meta['last_sender'] ?? null) ? $meta['last_sender'] : [];
        $topic = is_array($thread['topic'] ?? null) ? $thread['topic'] : [];
        $topicValue = trim((string) ($topic['value'] ?? ''));

        $messages = is_array($thread['messages'] ?? null) ? $thread['messages'] : [];
        $lastMsg = [];
        if ($messages !== []) {
            $lastMsg = is_array($messages[array_key_last($messages)] ?? null)
                ? $messages[array_key_last($messages)]
                : [];
        }

        $body = trim((string) ($lastMsg['body'] ?? ''));
        if ($body === '' && $topicValue !== '') {
            $body = $topicValue;
        }

        $sender = $this->participantLabel($lastSender);
        if ($sender === '' && is_array($lastMsg['from'] ?? null)) {
            $sender = $this->participantLabel($lastMsg['from']);
        }

        $created = (string) (
            $lastMsg['date_created']
            ?? $meta['last_message_date']
            ?? $thread['date_updated']
            ?? $thread['date_created']
            ?? ''
        );

        $msgId = trim((string) ($lastMsg['id'] ?? ''));
        if ($msgId === '') {
            $msgId = 'bnq-thread-' . $id;
        }

        $entity = $this->primaryEntity($thread);
        $unread = !empty($meta['shop_reply_needed_since']) ? 1 : 0;

        return [
            'conversationId'     => $id,
            'conversationType'   => 'BNQ_THREAD',
            'conversationStatus' => 'ACTIVE',
            'referenceId'        => (string) ($entity['id'] ?? ''),
            'referenceType'      => (string) ($entity['type'] ?? ''),
            'unreadCount'        => $unread,
            'latestMessage'      => [
                'messageId'      => $msgId,
                'messageBody'    => $body !== '' ? $body : '(no preview)',
                'senderUsername' => $sender !== '' ? $sender : 'B&Q',
                'createdDate'    => $created,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $thread
     * @return list<array<string, mixed>>
     */
    private function normalizeThreadMessages(array $thread): array
    {
        $messages = is_array($thread['messages'] ?? null) ? $thread['messages'] : [];
        $out = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $id = trim((string) ($msg['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $from = is_array($msg['from'] ?? null) ? $msg['from'] : [];
            $toList = is_array($msg['to'] ?? null) ? $msg['to'] : [];
            $recipient = '';
            if (isset($toList[0]) && is_array($toList[0])) {
                $recipient = $this->participantLabel($toList[0]);
            }

            $out[] = [
                'messageId'         => $id,
                'messageBody'       => (string) ($msg['body'] ?? ''),
                'senderUsername'    => $this->participantLabel($from),
                'recipientUsername' => $recipient,
                'createdDate'       => (string) ($msg['date_created'] ?? ''),
                'readStatus'        => true,
            ];
        }

        return $out;
    }

    /**
     * Recipients for M12 reply — everyone authorized except the shop itself.
     *
     * @param array<string, mixed> $thread
     * @return list<array{type: string, id?: string}>
     */
    private function replyRecipients(array $thread): array
    {
        $parts = $thread['authorized_participants'] ?? null;
        if (!is_array($parts) || $parts === []) {
            $parts = $thread['current_participants'] ?? [];
        }
        if (!is_array($parts)) {
            return [];
        }

        $to = [];
        $seen = [];
        foreach ($parts as $p) {
            if (!is_array($p)) {
                continue;
            }
            $type = strtoupper(trim((string) ($p['type'] ?? '')));
            if ($type === '' || $type === 'SHOP') {
                continue;
            }
            $id = trim((string) ($p['id'] ?? ''));
            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $entry = ['type' => $type];
            if ($id !== '') {
                $entry['id'] = $id;
            }
            $to[] = $entry;
        }

        return $to;
    }

    /**
     * @param array<string, mixed> $participant
     */
    private function participantLabel(array $participant): string
    {
        $type = strtoupper(trim((string) ($participant['type'] ?? '')));
        if ($type === 'SHOP' || $type === 'SHOP_USER') {
            return 'SHOP';
        }

        $name = trim((string) ($participant['display_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $org = is_array($participant['organization_details'] ?? null)
            ? $participant['organization_details']
            : [];
        $orgName = trim((string) ($org['display_name'] ?? ''));
        if ($orgName !== '') {
            return $orgName;
        }

        return $type !== '' ? $type : '';
    }

    /**
     * @param array<string, mixed> $thread
     * @return array{id?: string, type?: string, label?: string}
     */
    private function primaryEntity(array $thread): array
    {
        $entities = is_array($thread['entities'] ?? null) ? $thread['entities'] : [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $type = strtoupper(trim((string) ($entity['type'] ?? '')));
            if ($type === 'ORDER' || $type === 'MMP_ORDER') {
                return [
                    'id'    => trim((string) ($entity['id'] ?? '')),
                    'type'  => $type,
                    'label' => trim((string) ($entity['label'] ?? '')),
                ];
            }
        }
        if (isset($entities[0]) && is_array($entities[0])) {
            return [
                'id'    => trim((string) ($entities[0]['id'] ?? '')),
                'type'  => strtoupper(trim((string) ($entities[0]['type'] ?? ''))),
                'label' => trim((string) ($entities[0]['label'] ?? '')),
            ];
        }

        return [];
    }
}
