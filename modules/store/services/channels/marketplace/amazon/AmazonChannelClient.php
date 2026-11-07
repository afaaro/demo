<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Amazon;

use Module\Store\Services\Channel;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\ChannelSettings;
use Module\Store\Services\Channels\ChannelClientInterface;
use Module\Store\Services\CredentialsVault;
use RuntimeException;

/**
 * Amazon Selling Partner API — listings, orders, shipment confirm, Messaging API.
 *
 * Messaging is send-only (no buyer inbox). Threads are order-scoped.
 */
final class AmazonChannelClient implements ChannelClientInterface
{
    private const TEXT_ATTRS = [
        'item_name', 'brand', 'manufacturer', 'product_description',
        'bullet_point', 'generic_keyword', 'style', 'material',
    ];

    /** Free-text Messaging API message types we can send. */
    private const TEXT_MESSAGE_TYPES = [
        'confirmOrderDetails'         => 'Confirm order details',
        'confirmDeliveryDetails'      => 'Confirm delivery details',
        'confirmServiceDetails'       => 'Confirm service details',
        'confirmCustomizationDetails' => 'Confirm customization details',
        'unexpectedProblem'           => 'Unexpected problem',
    ];

    private ?string $cachedAccessToken = null;
    private int     $cachedAccessTokenUntil = 0;

    /** @param array<string, string> $creds */
    public function __construct(
        private Channel $channel,
        private array $creds,
        private CredentialsVault $vault,
        private ChannelRepository $channels,
    ) {}

    /**
     * @return array{ok: bool, error?: string, marketplaces?: array<int, string>, warning?: string}
     */
    public function testConnection(): array
    {
        try {
            $this->assertCredentials();

            $api = $this->api();
            $result = $api->getMarketplaceParticipations();
            $found = $this->participationMarketplaces($result['payload']);
            $names = [];
            foreach ($found as $id => $name) {
                $names[] = $name . ' (' . $id . ')';
            }

            $configured = $this->marketplaceId();
            $this->channels->update((int) $this->channel->id, [
                'status'     => 'connected',
                'last_error' => '',
            ], $this->vault);

            $out = [
                'ok'           => true,
                'marketplaces' => $names,
            ];
            if ($found !== [] && !isset($found[$configured])) {
                $out['warning'] = 'Kept marketplace ' . $configured
                    . '. Seller Central listed: '
                    . implode(', ', $names)
                    . '. Listings and orders still use the Marketplace setting (Amazon UK if selected).';
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('Amazon testConnection failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function consentUrl(string $state): ?string
    {
        $appId = trim((string) ($this->creds['application_id'] ?? ''));
        $redirect = $this->oauthRedirectUri();
        $this->persistOauthRedirectUri($redirect);

        $draft = AmazonPlatformApp::isDraft();
        if (array_key_exists('oauth_draft', $this->channel->settings)) {
            $draft = !empty($this->channel->settings['oauth_draft']);
        }

        return AmazonOAuth::consentUrl(
            $appId,
            $state,
            $this->marketplaceId(),
            $redirect,
            $draft,
        );
    }

    public function handleCallback(string $code): array
    {
        $redirect = $this->oauthRedirectUri();
        if ($redirect === '') {
            throw new RuntimeException(
                'Amazon OAuth callback needs a redirect URI. '
                . 'Set store.amazon.redirect_uri or register the site callback URL.'
            );
        }

        $tokens = $this->lwa()->exchangeCode($code, $redirect);

        $this->channels->storeTokens(
            (int) $this->channel->id,
            (string) $tokens['refresh_token'],
            (string) $tokens['access_token'],
            (int) $tokens['expires_in'],
            0,
            $this->vault,
        );

        $creds = $this->creds;
        if (($tokens['refresh_token'] ?? '') !== '') {
            $creds['refresh_token'] = (string) $tokens['refresh_token'];
        }
        $sellerId = $this->sellingPartnerIdFromRequest();
        if ($sellerId !== '') {
            $creds['seller_id'] = $sellerId;
        }
        $this->channels->update((int) $this->channel->id, [
            'credentials' => $creds,
        ], $this->vault);
        $this->creds = $creds;

        return [
            'access_token'  => (string) $tokens['access_token'],
            'refresh_token' => (string) $tokens['refresh_token'],
            'expires_in'    => (int) $tokens['expires_in'],
        ];
    }

    public function accessToken(): string
    {
        if ($this->cachedAccessToken !== null && time() < $this->cachedAccessTokenUntil) {
            return $this->cachedAccessToken;
        }

        $fresh = $this->channels->find((int) $this->channel->id);
        if ($fresh === null) {
            throw new RuntimeException("Channel #{$this->channel->id} not found.");
        }

        if ($fresh->accessExpiresAt !== null
            && strtotime($fresh->accessExpiresAt) > time() + 120
            && $fresh->accessToken !== ''
        ) {
            $decoded = $this->vault->open($fresh->accessToken);
            $token   = (string) ($decoded['token'] ?? '');
            if ($token !== '') {
                $this->cachedAccessToken      = $token;
                $this->cachedAccessTokenUntil = time() + 60;
                return $token;
            }
        }

        $refresh = $this->channels->readRefreshToken($fresh, $this->vault);
        if ($refresh === null || $refresh === '') {
            $refresh = trim((string) ($this->creds['refresh_token'] ?? ''));
        }
        if ($refresh === '') {
            throw new RuntimeException(
                'No Amazon refresh token. Click Link account to authorize Seller Central.'
            );
        }

        $tokens = $this->lwa()->refresh($refresh);

        $newRefresh = trim((string) ($tokens['refresh_token'] ?? ''));
        if ($newRefresh === '') {
            $newRefresh = $refresh;
        }

        $access = trim((string) ($tokens['access_token'] ?? ''));
        if ($access === '') {
            throw new RuntimeException('Amazon LWA refresh returned an empty access token.');
        }

        $this->channels->storeTokens(
            (int) $fresh->id,
            $newRefresh,
            $access,
            max(60, (int) ($tokens['expires_in'] ?? 3600)),
            0,
            $this->vault,
        );

        if ($newRefresh !== trim((string) ($this->creds['refresh_token'] ?? ''))) {
            $creds = $this->creds;
            $creds['refresh_token'] = $newRefresh;
            $this->channels->update((int) $fresh->id, ['credentials' => $creds], $this->vault);
            $this->creds = $creds;
        }

        $this->cachedAccessToken      = $access;
        $this->cachedAccessTokenUntil = time() + 60;
        return $access;
    }

    /**
     * @param array<string, mixed> $variantData
     */
    public function pushVariant(int $variantId, array $variantData): string
    {
        $sku = trim((string) ($variantData['sku'] ?? ''));
        if ($sku === '') {
            throw new RuntimeException('SKU is required to push an Amazon listing.');
        }

        $sellerId = $this->sellerId();
        $marketplaceId = $this->marketplaceId();
        $aspects = is_array($variantData['aspects'] ?? null) ? $variantData['aspects'] : [];

        $asin = $this->aspectValue($aspects, ['asin', 'ASIN', 'merchant_suggested_asin']);
        $productType = $this->resolveProductType($aspects);
        $requirements = strtoupper(ChannelSettings::string(
            $this->channel,
            'listing_requirements',
            ''
        ));
        if ($requirements === '') {
            $requirements = ($asin !== '' && $productType === 'PRODUCT')
                ? 'LISTING_OFFER_ONLY'
                : 'LISTING';
        }
        if (!in_array($requirements, ['LISTING', 'LISTING_PRODUCT_ONLY', 'LISTING_OFFER_ONLY'], true)) {
            $requirements = 'LISTING';
        }

        if ($productType === '' || ($productType === 'PRODUCT' && $requirements === 'LISTING' && $asin === '')) {
            // Full LISTING needs a real product type unless offering against an existing ASIN.
            if ($asin !== '') {
                $productType = 'PRODUCT';
                $requirements = 'LISTING_OFFER_ONLY';
            } else {
                throw new RuntimeException(
                    'Set Default product type on the Amazon channel (e.g. LUGGAGE), '
                    . 'or provide an ASIN attribute for an offer-only listing.'
                );
            }
        }

        $attributes = $this->buildListingAttributes($variantData, $aspects, $asin, $requirements);
        $body = [
            'productType'  => $productType,
            'requirements' => $requirements,
            'attributes'   => $attributes,
        ];

        $response = $this->api()->putListingsItem(
            $sellerId,
            $sku,
            $marketplaceId,
            $body,
            $this->issueLocale(),
        );

        $this->assertListingAccepted($response, $sku);

        return $sku;
    }

    public function syncInventory(int $variantId, int $quantity, string $sku = ''): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('Amazon inventory sync requires the listing SKU.');
        }

        $body = [
            'productType' => 'PRODUCT',
            'patches'     => [[
                'op'    => 'replace',
                'path'  => '/attributes/fulfillment_availability',
                'value' => [[
                    'fulfillment_channel_code' => 'DEFAULT',
                    'quantity'                 => max(0, $quantity),
                ]],
            ]],
        ];

        $response = $this->api()->patchListingsItem(
            $this->sellerId(),
            $sku,
            $this->marketplaceId(),
            $body,
            $this->issueLocale(),
        );
        $this->assertListingAccepted($response, $sku);
    }

    public function syncPrice(string $sku, float $price, string $currency = 'GBP'): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('Amazon price sync requires the listing SKU.');
        }
        if ($price <= 0) {
            throw new RuntimeException('Price must be greater than zero.');
        }

        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            $currency = $this->defaultCurrency();
        }

        $body = [
            'productType' => 'PRODUCT',
            'patches'     => [[
                'op'    => 'replace',
                'path'  => '/attributes/purchasable_offer',
                'value' => [[
                    'audience'  => 'ALL',
                    'currency'  => $currency,
                    'our_price' => [[
                        'schedule' => [[
                            'value_with_tax' => round($price, 2),
                        ]],
                    ]],
                    'marketplace_id' => $this->marketplaceId(),
                ]],
            ]],
        ];

        $response = $this->api()->patchListingsItem(
            $this->sellerId(),
            $sku,
            $this->marketplaceId(),
            $body,
            $this->issueLocale(),
        );
        $this->assertListingAccepted($response, $sku);
    }

    /**
     * @return array{orders: array<int, array<string, mixed>>, next: ?string}
     */
    public function fetchOrders(int $days = 30, int $limit = 50, ?string $offset = null): array
    {
        $api = $this->api();
        $marketplaceId = $this->marketplaceId();
        $createdAfter = gmdate('Y-m-d\TH:i:s\Z', time() - max(1, $days) * 86400);
        $limit = max(1, min(100, $limit));
        $nextToken = ($offset !== null && trim($offset) !== '') ? trim($offset) : null;

        $page = $api->getOrders($marketplaceId, $createdAfter, $limit, $nextToken);
        $normalized = [];
        foreach ($page['orders'] as $raw) {
            $orderId = trim((string) ($raw['AmazonOrderId'] ?? ''));
            if ($orderId === '') {
                continue;
            }
            try {
                $items = $api->getOrderItems($orderId);
            } catch (\Throwable $e) {
                error_log("Amazon getOrderItems [{$orderId}]: " . $e->getMessage());
                $items = [];
            }
            $mapped = $this->normalizeOrder($raw, $items);
            if ($mapped !== null) {
                $normalized[] = $mapped;
            }
        }

        return [
            'orders' => $normalized,
            'next'   => $page['nextToken'],
        ];
    }

    /**
     * Amazon has no GetMyMessages inbox. Map recent orders so message sync
     * and the mail tester do not fatal.
     *
     * @param list<string> $messageIds
     * @return array{
     *     messages: list<array<string, mixed>>,
     *     total_messages: int,
     *     new_messages: int,
     *     page: int,
     *     per_page: int
     * }
     */
    public function fetchMyMessages(
        int $folderId = 0,
        string $detailLevel = 'ReturnHeaders',
        array $messageIds = [],
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $withBody = $detailLevel === 'ReturnMessages';

        if ($folderId === 1) {
            return [
                'messages'       => [],
                'total_messages' => 0,
                'new_messages'   => 0,
                'page'           => $page,
                'per_page'       => $perPage,
            ];
        }

        $wanted = [];
        foreach ($messageIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $wanted[$id] = $id;
            }
        }

        $need = $wanted !== [] ? 100 : min(100, $perPage * $page);
        $pageData = $this->fetchOrders(30, $need, null);
        $orders = is_array($pageData['orders'] ?? null) ? $pageData['orders'] : [];

        $all = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $mapped = $this->orderToMyMessage($order, $withBody);
            if ($mapped === null) {
                continue;
            }
            if ($wanted !== [] && !isset($wanted[(string) $mapped['message_id']])) {
                continue;
            }
            $all[] = $mapped;
        }

        $messages = $wanted !== []
            ? $all
            : array_slice($all, ($page - 1) * $perPage, $perPage);

        return [
            'messages'       => $messages,
            'total_messages' => count($all),
            'new_messages'   => 0,
            'page'           => $page,
            'per_page'       => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    private function orderToMyMessage(array $order, bool $withBody): ?array
    {
        $orderId = trim((string) ($order['orderId'] ?? ''));
        if ($orderId === '') {
            return null;
        }

        $buyer = is_array($order['buyer'] ?? null) ? $order['buyer'] : [];
        $sender = trim((string) ($buyer['username'] ?? $buyer['email'] ?? 'Amazon buyer'));
        $lines = is_array($order['lineItems'] ?? null) ? $order['lineItems'] : [];
        $first = is_array($lines[0] ?? null) ? $lines[0] : [];
        $title = trim((string) ($first['title'] ?? ''));
        $sku = trim((string) ($first['sku'] ?? ''));
        $created = (string) ($order['creationDate'] ?? '');

        $content = '';
        if ($withBody) {
            $content = 'Amazon order ' . $orderId
                . '. Messaging is send-only (no buyer inbox).';
            if ($title !== '') {
                $content .= ' Item: ' . $title . '.';
            }
        }

        return [
            'message_id'    => $orderId,
            'external_id'   => $orderId,
            'sender'        => $sender !== '' ? $sender : 'Amazon',
            'recipient'     => 'seller',
            'subject'       => $title !== ''
                ? ('Order ' . $orderId . ' — ' . $title)
                : ('Amazon order ' . $orderId),
            'content'       => $content,
            'read'          => true,
            'replied'       => false,
            'receive_date'  => $created,
            'item_id'       => $sku,
            'item_title'    => $title,
            'message_type'  => 'AMAZON_ORDER',
            'question_type' => '',
        ];
    }

    /**
     * Amazon has no buyer-message inbox. Surface recent orders as messageable threads.
     *
     * @return array{
     *     conversations: array<int, array<string, mixed>>,
     *     limit: int,
     *     offset: int,
     *     total: int,
     *     href?: null,
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
        $limit = max(1, min(50, $limit));
        $offset = max(0, is_int($offset) ? $offset : (int) $offset);
        // Orders API pages by NextToken, not offset — approximate with day window + skip.
        $page = $this->fetchOrders(30, min(100, $offset + $limit), null);
        $orders = is_array($page['orders'] ?? null) ? $page['orders'] : [];
        $slice = array_slice($orders, $offset, $limit);

        $conversations = [];
        foreach ($slice as $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = trim((string) ($order['orderId'] ?? ''));
            if ($orderId === '') {
                continue;
            }
            $buyer = is_array($order['buyer'] ?? null) ? $order['buyer'] : [];
            $buyerName = trim((string) ($buyer['username'] ?? $buyer['email'] ?? $orderId));
            $created = (string) ($order['creationDate'] ?? '');
            $conversations[] = [
                'conversationId'     => $orderId,
                'conversationType'   => 'AMAZON_ORDER',
                'conversationStatus' => 'ACTIVE',
                'referenceId'        => $orderId,
                'referenceType'      => 'ORDER',
                'unreadCount'        => 0,
                'latestMessage'      => [
                    'messageId'      => 'amazon-order-' . $orderId,
                    'messageBody'    => 'Amazon order ' . $orderId
                        . ' — use Message buyer (Messaging API; no inbox sync).',
                    'senderUsername' => $buyerName,
                    'createdDate'    => $created,
                ],
            ];
        }

        $nextOffset = $offset + $limit;
        $hasMore = count($orders) > $nextOffset
            || ($page['next'] !== null && $page['next'] !== '');

        return [
            'conversations' => $conversations,
            'limit'         => $limit,
            'offset'        => $offset,
            'total'         => $offset + count($conversations) + ($hasMore ? 1 : 0),
            'href'          => null,
            'next'          => $hasMore ? (string) $nextOffset : null,
        ];
    }

    /**
     * Order “thread”: available Messaging API actions (no buyer message history).
     *
     * @return array{messages: list<array<string, mixed>>, actions?: list<array{name: string, label: string}>}
     */
    public function fetchConversation(
        string $conversationId,
        string $conversationType = 'AMAZON_ORDER',
    ): array {
        unset($conversationType);
        $orderId = trim($conversationId);
        if ($orderId === '') {
            throw new RuntimeException('Amazon order id is required.');
        }

        $actions = $this->messagingActions($orderId);
        $labels = [];
        foreach ($actions as $a) {
            $labels[] = $a['label'] . ' (' . $a['name'] . ')';
        }
        $body = $labels === []
            ? 'No Messaging API actions are available for this order right now. '
              . 'Amazon does not expose buyer message history via SP-API.'
            : "Available message types:\n- " . implode("\n- ", $labels)
              . "\n\nAmazon Messaging API is send-only — buyer replies are not synced here.";

        return [
            'messages' => [[
                'messageId'      => 'amazon-actions-' . $orderId,
                'messageBody'    => $body,
                'senderUsername' => 'Amazon',
                'createdDate'    => gmdate('Y-m-d\TH:i:s\Z'),
                'read'           => true,
            ]],
            'actions' => $actions,
        ];
    }

    /**
     * Send a buyer message for an Amazon order (Messaging API).
     *
     * $conversationType may be AMAZON_ORDER or a concrete action name
     * (confirmOrderDetails, unexpectedProblem, …).
     *
     * @return array<string, mixed>
     */
    public function sendMessage(
        string $conversationId,
        string $conversationType,
        string $body,
        string $action = '',
    ): array {
        $orderId = trim($conversationId);
        $body = trim($body);
        if ($orderId === '') {
            throw new RuntimeException('Amazon order id is required.');
        }
        if ($body === '') {
            throw new RuntimeException('Message body cannot be empty.');
        }
        if (mb_strlen($body) > 2000) {
            throw new RuntimeException('Message body exceeds 2000 characters.');
        }

        $action = trim($action);
        if ($action === '') {
            $type = trim($conversationType);
            if ($type !== '' && $type !== 'AMAZON_ORDER' && $type !== 'FROM_MEMBERS') {
                $action = $type;
            }
        }
        if ($action === '' || !isset(self::TEXT_MESSAGE_TYPES[$action])) {
            $available = $this->messagingActions($orderId);
            $action = $available[0]['name'] ?? 'confirmOrderDetails';
        }
        if (!isset(self::TEXT_MESSAGE_TYPES[$action])) {
            throw new RuntimeException(
                "Unsupported Amazon message type '{$action}'. "
                . 'Use confirmOrderDetails, confirmDeliveryDetails, or unexpectedProblem.'
            );
        }

        // Ensure Amazon still allows this action for the order.
        $allowed = array_column($this->messagingActions($orderId), 'name');
        if ($allowed !== [] && !in_array($action, $allowed, true)) {
            throw new RuntimeException(
                "Message type '{$action}' is not available for order {$orderId}. "
                . 'Allowed: ' . implode(', ', $allowed)
            );
        }

        return $this->api()->createOrderMessage(
            $orderId,
            $this->marketplaceId(),
            $action,
            $body,
        );
    }

    /**
     * @return list<array{name: string, label: string}>
     */
    public function messagingActions(string $amazonOrderId): array
    {
        $amazonOrderId = trim($amazonOrderId);
        if ($amazonOrderId === '') {
            return [];
        }

        try {
            $doc = $this->api()->getMessagingActionsForOrder(
                $amazonOrderId,
                $this->marketplaceId(),
            );
        } catch (\Throwable $e) {
            error_log('Amazon getMessagingActionsForOrder [' . $amazonOrderId . ']: ' . $e->getMessage());
            return [];
        }

        $links = is_array($doc['_links'] ?? null) ? $doc['_links'] : [];
        $rawActions = $links['actions'] ?? [];
        if (!is_array($rawActions)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($rawActions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $href = (string) ($row['href'] ?? '');
                if (preg_match('#/messages/([a-zA-Z0-9_-]+)#', $href, $m)) {
                    $name = $m[1];
                }
            }
            if ($name === '' || !isset(self::TEXT_MESSAGE_TYPES[$name]) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = [
                'name'  => $name,
                'label' => self::TEXT_MESSAGE_TYPES[$name],
            ];
        }

        return $out;
    }

    /**
     * MFN returns (Reports) + A-to-Z / SAFE-T (Finances) → support-case shaped rows.
     *
     * Amazon has no Claims API; A-to-Z detail/actions stay in Seller Central.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchReturnsAndClaims(int $lookbackDays = 60, int $reportWaitSeconds = 120): array
    {
        $lookbackDays = max(1, min(60, $lookbackDays)); // returns report max window
        $rows = [];
        $errors = [];

        try {
            foreach ($this->fetchReturnsFromReport($lookbackDays, $reportWaitSeconds) as $row) {
                $rows[] = $row;
            }
        } catch (\Throwable $e) {
            error_log('Amazon returns report: ' . $e->getMessage());
            $errors[] = $e;
        }

        try {
            foreach ($this->fetchClaimsFromFinances($lookbackDays) as $row) {
                $rows[] = $row;
            }
        } catch (\Throwable $e) {
            error_log('Amazon finances claims: ' . $e->getMessage());
            $errors[] = $e;
        }

        if ($rows === [] && $errors !== []) {
            throw $errors[0];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchReturnsFromReport(int $lookbackDays, int $reportWaitSeconds): array
    {
        $end = time() - 120; // Reports/Finances require times ≥ 2 minutes in the past
        $start = $end - ($lookbackDays * 86400);
        $content = $this->api()->fetchReportContent(
            'GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE',
            [$this->marketplaceId()],
            gmdate('Y-m-d\TH:i:s\Z', $start),
            gmdate('Y-m-d\TH:i:s\Z', $end),
            $reportWaitSeconds,
        );

        $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
        if ($lines === [] || trim((string) $lines[0]) === '') {
            return [];
        }

        $headers = str_getcsv((string) array_shift($lines), "\t");
        $headers = array_map(static fn($h) => trim((string) $h), $headers);
        if ($headers === [] || count($headers) < 3) {
            // Some markets use comma; retry first line parse.
            $headers = str_getcsv(implode("\t", $headers), ',');
            $headers = array_map(static fn($h) => trim((string) $h), $headers);
        }

        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line, "\t");
            if (count($cols) < 3) {
                $cols = str_getcsv($line, ',');
            }
            if (count($cols) < count($headers)) {
                $cols = array_pad($cols, count($headers), '');
            }
            $row = [];
            foreach ($headers as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = trim((string) ($cols[$i] ?? ''));
            }

            $mapped = $this->mapReturnReportRow($row);
            if ($mapped !== null) {
                $out[] = $mapped;
            }

            // Flag from returns report — financial A-to-Z events may duplicate; upsert is fine.
            $atozFlag = strtoupper((string) ($row['A-to-z Claim'] ?? $row['A-to-Z Claim'] ?? ''));
            if (in_array($atozFlag, ['Y', 'YES', 'TRUE', '1'], true)) {
                $claim = $this->mapAtozFlagFromReturn($row);
                if ($claim !== null) {
                    $out[] = $claim;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchClaimsFromFinances(int $lookbackDays): array
    {
        // Finances window ≤ 180 days; keep aligned with returns lookback (≤ 60).
        $lookbackDays = max(1, min(180, $lookbackDays));
        $postedBefore = gmdate('Y-m-d\TH:i:s\Z', time() - 120);
        $postedAfter = gmdate('Y-m-d\TH:i:s\Z', time() - 120 - ($lookbackDays * 86400));

        $out = [];
        $token = null;
        for ($page = 0; $page < 20; $page++) {
            $pageData = $this->api()->listFinancialEvents(
                $postedAfter,
                $postedBefore,
                100,
                $token,
            );
            $events = $pageData['events'];

            foreach ($this->mapShipmentEventList(
                $events['GuaranteeClaimEventList'] ?? [],
                'atoz',
                'A_TO_Z_CLAIM',
            ) as $row) {
                $out[] = $row;
            }
            foreach ($this->mapShipmentEventList(
                $events['ChargebackEventList'] ?? [],
                'atoz',
                'CHARGEBACK',
            ) as $row) {
                $out[] = $row;
            }
            foreach ($this->mapSafetEventList($events['SAFETReimbursementEventList'] ?? []) as $row) {
                $out[] = $row;
            }

            $token = $pageData['nextToken'];
            if ($token === null || $token === '') {
                break;
            }
            // Finances rate: 0.5 rps — be gentle between pages.
            usleep(600_000);
        }

        return $out;
    }

    /**
     * @param array<string, string> $row
     * @return array<string, mixed>|null
     */
    private function mapReturnReportRow(array $row): ?array
    {
        $orderId = trim((string) ($row['Order ID'] ?? $row['order-id'] ?? ''));
        $rma = trim((string) ($row['Amazon RMA ID'] ?? $row['amazon-rma-id'] ?? ''));
        $sku = trim((string) ($row['Merchant SKU'] ?? $row['sku'] ?? ''));
        $reqDate = trim((string) ($row['Return request date'] ?? $row['return-request-date'] ?? ''));
        if ($orderId === '' && $rma === '') {
            return null;
        }

        $externalId = $rma !== ''
            ? $rma
            : ('ret-' . substr(sha1($orderId . '|' . $sku . '|' . $reqDate), 0, 40));
        $externalId = mb_substr($externalId, 0, 64);

        $status = strtoupper(trim((string) (
            $row['Return request status']
            ?? $row['return-request-status']
            ?? 'UNKNOWN'
        )));
        $refund = $this->parseMoney(
            (string) ($row['Refunded Amount'] ?? $row['refund-amount'] ?? '')
        );
        $currency = trim((string) ($row['Currency code'] ?? $row['currency-code'] ?? ''));

        return [
            'kind'                => 'return',
            'external_id'         => $externalId,
            'case_type'           => trim((string) ($row['Return type'] ?? $row['Return Reason'] ?? 'RETURN')),
            'status'              => $status !== '' ? $status : 'UNKNOWN',
            'item_id'             => trim((string) ($row['ASIN'] ?? '')),
            'transaction_id'      => trim((string) ($row['Order Item ID'] ?? '')),
            'order_id'            => $orderId,
            'buyer_login'         => '',
            'claim_amount'        => $refund,
            'claim_currency'      => $currency,
            'item_title'          => trim((string) ($row['Item Name'] ?? '')),
            'seller_response_due' => null,
            'creation_date'       => $this->parseFlexibleDate($reqDate),
            'last_modified_date'  => $this->parseFlexibleDate(
                (string) ($row['Return delivery date'] ?? $reqDate)
            ),
            'summary'             => $row,
            'detail'              => $row,
        ];
    }

    /**
     * @param array<string, string> $row
     * @return array<string, mixed>|null
     */
    private function mapAtozFlagFromReturn(array $row): ?array
    {
        $orderId = trim((string) ($row['Order ID'] ?? ''));
        if ($orderId === '') {
            return null;
        }
        $rma = trim((string) ($row['Amazon RMA ID'] ?? ''));
        $externalId = mb_substr('atoz-ret-' . ($rma !== '' ? $rma : $orderId), 0, 64);
        $refund = $this->parseMoney((string) ($row['Refunded Amount'] ?? ''));

        return [
            'kind'                => 'atoz',
            'external_id'         => $externalId,
            'case_type'           => 'A_TO_Z_CLAIM',
            'status'              => 'FLAGGED_ON_RETURN',
            'item_id'             => trim((string) ($row['ASIN'] ?? '')),
            'transaction_id'      => trim((string) ($row['Order Item ID'] ?? '')),
            'order_id'            => $orderId,
            'buyer_login'         => '',
            'claim_amount'        => $refund,
            'claim_currency'      => trim((string) ($row['Currency code'] ?? '')),
            'item_title'          => trim((string) ($row['Item Name'] ?? '')),
            'seller_response_due' => null,
            'creation_date'       => $this->parseFlexibleDate(
                (string) ($row['Return request date'] ?? '')
            ),
            'last_modified_date'  => null,
            'summary'             => $row,
            'detail'              => $row,
        ];
    }

    /**
     * @param mixed $list
     * @return list<array<string, mixed>>
     */
    private function mapShipmentEventList(mixed $list, string $kind, string $caseType): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $event) {
            if (!is_array($event)) {
                continue;
            }
            $orderId = trim((string) ($event['AmazonOrderId'] ?? ''));
            $posted = trim((string) ($event['PostedDate'] ?? ''));
            if ($orderId === '') {
                continue;
            }
            $suffix = $posted !== ''
                ? substr(preg_replace('/[^0-9TZ]/', '', $posted) ?? '', 0, 16)
                : substr(sha1(json_encode($event) ?: $orderId), 0, 10);
            $externalId = mb_substr($kind . '-' . $orderId . '-' . $suffix, 0, 64);
            [$amount, $currency] = $this->sumShipmentEventMoney($event);

            $out[] = [
                'kind'                => $kind,
                'external_id'         => $externalId,
                'case_type'           => $caseType,
                'status'              => 'POSTED',
                'item_id'             => '',
                'transaction_id'      => '',
                'order_id'            => $orderId,
                'buyer_login'         => '',
                'claim_amount'        => $amount,
                'claim_currency'      => $currency,
                'item_title'          => (string) ($event['MarketplaceName'] ?? ''),
                'seller_response_due' => null,
                'creation_date'       => $this->parseFlexibleDate($posted),
                'last_modified_date'  => $this->parseFlexibleDate($posted),
                'summary'             => $event,
                'detail'              => $event,
            ];
        }

        return $out;
    }

    /**
     * @param mixed $list
     * @return list<array<string, mixed>>
     */
    private function mapSafetEventList(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $event) {
            if (!is_array($event)) {
                continue;
            }
            $claimId = trim((string) ($event['SAFETClaimId'] ?? $event['SafETClaimId'] ?? ''));
            $posted = trim((string) ($event['PostedDate'] ?? ''));
            $externalId = $claimId !== ''
                ? mb_substr('safet-' . $claimId, 0, 64)
                : mb_substr('safet-' . substr(sha1(json_encode($event) ?: $posted), 0, 40), 0, 64);

            $amount = null;
            $currency = '';
            $reimb = is_array($event['ReimbursedAmount'] ?? null) ? $event['ReimbursedAmount'] : [];
            if ($reimb !== []) {
                $amount = isset($reimb['CurrencyAmount']) ? (float) $reimb['CurrencyAmount'] : null;
                $currency = (string) ($reimb['CurrencyCode'] ?? '');
            }

            $out[] = [
                'kind'                => 'safet',
                'external_id'         => $externalId,
                'case_type'           => (string) ($event['ReasonCode'] ?? 'SAFE_T'),
                'status'              => 'REIMBURSED',
                'item_id'             => '',
                'transaction_id'      => $claimId,
                'order_id'            => trim((string) ($event['AmazonOrderId'] ?? '')),
                'buyer_login'         => '',
                'claim_amount'        => $amount,
                'claim_currency'      => $currency,
                'item_title'          => '',
                'seller_response_due' => null,
                'creation_date'       => $this->parseFlexibleDate($posted),
                'last_modified_date'  => $this->parseFlexibleDate($posted),
                'summary'             => $event,
                'detail'              => $event,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $event
     * @return array{0: ?float, 1: string}
     */
    private function sumShipmentEventMoney(array $event): array
    {
        $total = 0.0;
        $currency = '';
        $found = false;

        $buckets = [
            $event['OrderChargeList'] ?? null,
            $event['OrderChargeAdjustmentList'] ?? null,
            $event['ShipmentFeeAdjustmentList'] ?? null,
            $event['OrderFeeAdjustmentList'] ?? null,
            $event['DirectPaymentList'] ?? null,
        ];
        foreach ($buckets as $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $charge) {
                if (!is_array($charge)) {
                    continue;
                }
                $money = is_array($charge['ChargeAmount'] ?? null)
                    ? $charge['ChargeAmount']
                    : (is_array($charge['FeeAmount'] ?? null) ? $charge['FeeAmount'] : null);
                if (!is_array($money)) {
                    continue;
                }
                if (isset($money['CurrencyAmount'])) {
                    $total += (float) $money['CurrencyAmount'];
                    $found = true;
                }
                if ($currency === '' && !empty($money['CurrencyCode'])) {
                    $currency = (string) $money['CurrencyCode'];
                }
            }
        }

        $items = $event['ShipmentItemAdjustmentList'] ?? $event['ShipmentItemList'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (['ItemChargeAdjustmentList', 'ItemChargeList', 'ItemFeeAdjustmentList'] as $key) {
                    $list = $item[$key] ?? null;
                    if (!is_array($list)) {
                        continue;
                    }
                    foreach ($list as $charge) {
                        if (!is_array($charge)) {
                            continue;
                        }
                        $money = is_array($charge['ChargeAmount'] ?? null)
                            ? $charge['ChargeAmount']
                            : (is_array($charge['FeeAmount'] ?? null) ? $charge['FeeAmount'] : null);
                        if (!is_array($money) || !isset($money['CurrencyAmount'])) {
                            continue;
                        }
                        $total += (float) $money['CurrencyAmount'];
                        $found = true;
                        if ($currency === '' && !empty($money['CurrencyCode'])) {
                            $currency = (string) $money['CurrencyCode'];
                        }
                    }
                }
            }
        }

        return [$found ? round($total, 2) : null, $currency];
    }

    private function parseMoney(string $raw): ?float
    {
        $raw = trim(str_replace([',', '£', '$', '€'], '', $raw));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return round((float) $raw, 2);
    }

    private function parseFlexibleDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * Confirm MFN shipment via Orders API confirmShipment.
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
        $orderId = trim($orderId);
        $trackingNumber = trim($trackingNumber);
        $carrierCode = trim($carrierCode);
        if ($orderId === '') {
            throw new RuntimeException('Amazon order id is required.');
        }
        if ($trackingNumber === '') {
            throw new RuntimeException('Tracking number is required.');
        }
        if ($lineItems === []) {
            throw new RuntimeException('At least one line item is required.');
        }

        $items = [];
        foreach ($lineItems as $li) {
            $id = trim((string) ($li['lineItemId'] ?? ''));
            if ($id === '') {
                continue;
            }
            $items[] = [
                'orderItemId' => $id,
                'quantity'    => max(1, (int) ($li['quantity'] ?? 1)),
            ];
        }
        if ($items === []) {
            throw new RuntimeException('Missing Amazon order item IDs. Sync orders again, then retry.');
        }

        [$code, $name] = $this->amazonCarrier($carrierCode);
        $shipDate = '';
        if ($shippedDate !== null && trim($shippedDate) !== '') {
            $ts = strtotime($shippedDate);
            $shipDate = $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '';
        }

        $this->api()->confirmShipment(
            $orderId,
            $this->marketplaceId(),
            $trackingNumber,
            $code,
            $items,
            $shipDate,
            $name,
        );

        return [
            'order_id'        => $orderId,
            'tracking_number' => $trackingNumber,
            'carrier'         => $code,
            'status'          => 'Shipped',
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Map Store / courier label → Amazon carrierCode (+ optional carrierName).
     *
     * @return array{0: string, 1: string} [carrierCode, carrierName]
     */
    private function amazonCarrier(string $carrier): array
    {
        $c = strtoupper(trim($carrier));
        if ($c === '') {
            return ['Others', 'Other'];
        }

        return match (true) {
            str_contains($c, 'ROYAL') || $c === 'RM' || $c === 'ROYAL_MAIL'
                => ['Royal Mail', ''],
            str_contains($c, 'EVRI') || str_contains($c, 'HERMES')
                => ['Hermes', ''],
            str_contains($c, 'DPD') => ['DPD', ''],
            str_contains($c, 'DHL') => ['DHL', ''],
            str_contains($c, 'UPS') => ['UPS', ''],
            str_contains($c, 'FEDEX') || str_contains($c, 'FED EX')
                => ['FedEx', ''],
            str_contains($c, 'YODEL') => ['Yodel', ''],
            str_contains($c, 'USPS') => ['USPS', ''],
            str_contains($c, 'AMAZON') => ['Amazon Shipping', ''],
            default => ['Others', trim($carrier)],
        };
    }

    private function api(): AmazonSpApi
    {
        return new AmazonSpApi(
            $this->accessToken(),
            $this->region(),
            $this->sandbox(),
        );
    }

    private function assertCredentials(): void
    {
        foreach (['client_id', 'client_secret'] as $key) {
            if (trim((string) ($this->creds[$key] ?? '')) === '') {
                throw new RuntimeException("Missing credential: {$key}");
            }
        }
        $refresh = $this->channels->readRefreshToken($this->channel, $this->vault)
            ?: trim((string) ($this->creds['refresh_token'] ?? ''));
        if ($refresh === '') {
            throw new RuntimeException(
                'Missing credential: refresh_token. Click Link account to authorize Amazon.'
            );
        }
    }

    private function sellerId(): string
    {
        $id = trim((string) ($this->creds['seller_id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException(
                'Amazon Seller ID is required for listing push. '
                . 'Click Link account — Seller Central returns selling_partner_id automatically.'
            );
        }
        return $id;
    }

    private function oauthRedirectUri(): string
    {
        $uri = AmazonPlatformApp::redirectUri($this->channel->settings);
        if ($uri !== '') {
            return $uri;
        }
        throw new RuntimeException(
            'Amazon OAuth needs a redirect URI. Set store.amazon.redirect_uri '
            . 'or register the site callback URL in the Amazon developer console.'
        );
    }

    private function persistOauthRedirectUri(string $redirect): void
    {
        $redirect = trim($redirect);
        if ($redirect === '') {
            return;
        }
        $settings = $this->channel->settings;
        if (trim((string) ($settings['oauth_redirect_uri'] ?? '')) === $redirect) {
            return;
        }
        $settings['oauth_redirect_uri'] = $redirect;
        $this->channels->update((int) $this->channel->id, [
            'settings' => $settings,
        ], $this->vault);
        $fresh = $this->channels->find((int) $this->channel->id);
        if ($fresh !== null) {
            $this->channel = $fresh;
        }
    }

    private function sellingPartnerIdFromRequest(): string
    {
        try {
            $request = \System\Engine\Registry::getInstance()->get('request');
            if (!is_object($request) || !method_exists($request, 'get')) {
                return '';
            }
            return trim((string) $request->get('selling_partner_id', 'string', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function lwa(): AmazonLwa
    {
        return new AmazonLwa(
            trim((string) ($this->creds['client_id'] ?? '')),
            trim((string) ($this->creds['client_secret'] ?? '')),
        );
    }

    private function marketplaceId(): string
    {
        $id = trim((string) ($this->channel->settings['marketplace'] ?? ''));
        return $id !== '' ? $id : 'A1F83G8C2ARO7P';
    }

    /**
     * @param list<array<string, mixed>> $payload
     * @return array<string, string> marketplaceId => name
     */
    private function participationMarketplaces(array $payload): array
    {
        $out = [];
        foreach ($payload as $row) {
            $mp = is_array($row['marketplace'] ?? null) ? $row['marketplace'] : $row;
            $id = trim((string) ($mp['id'] ?? $row['marketplaceId'] ?? ''));
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($mp['name'] ?? $id));
            $out[$id] = $name !== '' ? $name : $id;
        }
        return $out;
    }

    private function region(): string
    {
        return match ($this->marketplaceId()) {
            'ATVPDKIKX0DER', 'A2EUQ1WTGCTBG2', 'A1AM78C64UM0Y8' => 'na',
            'A39IBJ37TRP1C6', 'A1VC38T7YXB528' => 'fe',
            default => 'eu',
        };
    }

    private function sandbox(): bool
    {
        return !empty($this->channel->settings['sandbox']);
    }

    private function issueLocale(): string
    {
        return match ($this->marketplaceId()) {
            'ATVPDKIKX0DER'  => 'en_US',
            'A1PA6795UKMFR9' => 'de_DE',
            'A13V1IB3VIYZZH' => 'fr_FR',
            'APJ6JRA9NG5V4'  => 'it_IT',
            'A1RKKUPIHCS9HS' => 'es_ES',
            default          => 'en_GB',
        };
    }

    private function languageTag(): string
    {
        return $this->issueLocale();
    }

    private function defaultCurrency(): string
    {
        $configured = ChannelSettings::string($this->channel, 'currency', '');
        if ($configured !== '') {
            return strtoupper($configured);
        }
        return match ($this->marketplaceId()) {
            'ATVPDKIKX0DER' => 'USD',
            'A1F83G8C2ARO7P' => 'GBP',
            default => 'EUR',
        };
    }

    /**
     * @param array<string, mixed> $aspects
     */
    private function resolveProductType(array $aspects): string
    {
        $fromAspect = $this->aspectValue($aspects, ['product_type', 'productType', 'Product Type']);
        if ($fromAspect !== '') {
            return strtoupper(str_replace(' ', '_', $fromAspect));
        }
        $fromSettings = trim(ChannelSettings::string($this->channel, 'product_type', ''));
        return $fromSettings !== '' ? strtoupper(str_replace(' ', '_', $fromSettings)) : '';
    }

    /**
     * @param array<string, mixed> $variantData
     * @param array<string, mixed> $aspects
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildListingAttributes(
        array $variantData,
        array $aspects,
        string $asin,
        string $requirements,
    ): array {
        $mp = $this->marketplaceId();
        $lang = $this->languageTag();
        $attrs = [];

        $condition = $this->mapCondition((string) ($variantData['condition'] ?? 'NEW'));
        $attrs['condition_type'] = [[
            'value'          => $condition,
            'marketplace_id' => $mp,
        ]];

        if ($requirements !== 'LISTING_PRODUCT_ONLY') {
            $price = round((float) ($variantData['price'] ?? 0), 2);
            $currency = strtoupper((string) ($variantData['currency'] ?? $this->defaultCurrency()));
            if ($currency === '') {
                $currency = $this->defaultCurrency();
            }
            if ($price > 0) {
                $attrs['purchasable_offer'] = [[
                    'audience'       => 'ALL',
                    'currency'       => $currency,
                    'our_price'      => [[
                        'schedule' => [[
                            'value_with_tax' => $price,
                        ]],
                    ]],
                    'marketplace_id' => $mp,
                ]];
            }

            $qty = max(0, (int) ($variantData['quantity'] ?? 0));
            $attrs['fulfillment_availability'] = [[
                'fulfillment_channel_code' => 'DEFAULT',
                'quantity'                 => $qty,
            ]];
        }

        if ($asin !== '') {
            $attrs['merchant_suggested_asin'] = [[
                'value'          => $asin,
                'marketplace_id' => $mp,
            ]];
        }

        if ($requirements !== 'LISTING_OFFER_ONLY') {
            $title = trim((string) ($variantData['title'] ?? ''));
            if ($title !== '') {
                $attrs['item_name'] = [[
                    'value'          => $title,
                    'language_tag'   => $lang,
                    'marketplace_id' => $mp,
                ]];
            }

            $brand = $this->aspectValue($aspects, ['brand', 'Brand']);
            if ($brand !== '') {
                $attrs['brand'] = [[
                    'value'          => $brand,
                    'language_tag'   => $lang,
                    'marketplace_id' => $mp,
                ]];
            }

            $desc = trim(strip_tags((string) ($variantData['description'] ?? '')));
            if ($desc !== '') {
                $attrs['product_description'] = [[
                    'value'          => mb_substr($desc, 0, 2000),
                    'language_tag'   => $lang,
                    'marketplace_id' => $mp,
                ]];
            }

            $images = is_array($variantData['imageUrls'] ?? null) ? $variantData['imageUrls'] : [];
            $mainImage = trim((string) ($images[0] ?? ''));
            if ($mainImage !== '' && preg_match('#^https?://#i', $mainImage)) {
                $attrs['main_product_image_locator'] = [[
                    'media_location' => $mainImage,
                    'marketplace_id' => $mp,
                ]];
            }

            $ean = trim((string) ($variantData['barcode'] ?? ''));
            if ($ean === '') {
                $ean = $this->aspectValue($aspects, ['ean', 'EAN', 'gtin', 'GTIN', 'upc', 'UPC']);
            }
            if ($ean !== '') {
                $idType = preg_match('/^\d{12}$/', $ean) ? 'upc'
                    : (preg_match('/^\d{13,14}$/', $ean) ? 'ean' : 'gtin');
                $attrs['externally_assigned_product_identifier'] = [[
                    'type'           => $idType,
                    'value'          => $ean,
                    'marketplace_id' => $mp,
                ]];
            }
        }

        // Channel / product attributes → Amazon listing attributes.
        $reserved = [
            'asin', 'merchant_suggested_asin', 'product_type', 'producttype', 'product type',
            'ean', 'gtin', 'upc', 'barcode', 'brand',
            'condition_type', 'item_name', 'product_description',
            'purchasable_offer', 'fulfillment_availability',
            'main_product_image_locator', 'externally_assigned_product_identifier',
        ];
        foreach ($aspects as $name => $values) {
            $key = $this->normalizeAttrName((string) $name);
            if ($key === '' || in_array(strtolower($key), $reserved, true) || isset($attrs[$key])) {
                continue;
            }
            $list = $this->aspectValuesList($values);
            if ($list === []) {
                continue;
            }
            $attrs[$key] = [];
            foreach ($list as $val) {
                $entry = [
                    'value'          => $val,
                    'marketplace_id' => $mp,
                ];
                if (in_array($key, self::TEXT_ATTRS, true)) {
                    $entry['language_tag'] = $lang;
                }
                $attrs[$key][] = $entry;
            }
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function assertListingAccepted(array $response, string $sku): void
    {
        $status = strtoupper((string) ($response['status'] ?? ''));
        $issues = is_array($response['issues'] ?? null) ? $response['issues'] : [];

        $errors = [];
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $severity = strtoupper((string) ($issue['severity'] ?? 'ERROR'));
            if (in_array($severity, ['ERROR', 'FATAL'], true) || $severity === '') {
                $msg = trim((string) ($issue['message'] ?? $issue['code'] ?? ''));
                if ($msg !== '') {
                    $errors[] = $msg;
                }
            }
        }

        if ($status === 'INVALID' || ($status !== '' && $status !== 'ACCEPTED' && $status !== 'VALID' && $errors !== [])) {
            $detail = $errors !== []
                ? implode('; ', array_slice($errors, 0, 5))
                : ('status=' . ($status !== '' ? $status : 'unknown'));
            throw new RuntimeException("Amazon rejected listing for SKU {$sku}: {$detail}");
        }

        if ($errors !== [] && $status === '') {
            throw new RuntimeException(
                "Amazon listing issues for SKU {$sku}: " . implode('; ', array_slice($errors, 0, 5))
            );
        }
    }

    private function mapCondition(string $condition): string
    {
        $c = strtoupper(trim($condition));
        return match (true) {
            $c === '' || str_contains($c, 'NEW') => 'new_new',
            str_contains($c, 'REFURB') => 'refurbished_refurbished',
            str_contains($c, 'USED') || str_contains($c, 'PRE_OWNED') => 'used_like_new',
            default => 'new_new',
        };
    }

    /**
     * @param array<string, mixed> $aspects
     * @param list<string> $keys
     */
    private function aspectValue(array $aspects, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $aspects)) {
                // Case-insensitive fallback.
                foreach ($aspects as $name => $values) {
                    if (strcasecmp((string) $name, $key) === 0) {
                        $list = $this->aspectValuesList($values);
                        return $list[0] ?? '';
                    }
                }
                continue;
            }
            $list = $this->aspectValuesList($aspects[$key]);
            if ($list !== []) {
                return $list[0];
            }
        }
        return '';
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private function aspectValuesList(mixed $values): array
    {
        if (is_array($values)) {
            $out = [];
            foreach ($values as $v) {
                $s = trim((string) $v);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
            return $out;
        }
        $s = trim((string) $values);
        return $s !== '' ? [$s] : [];
    }

    private function normalizeAttrName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        // Already snake_case Amazon codes.
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            return $name;
        }
        // "Item Name" / "bullet_point" → item_name
        $snake = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name) ?? $name);
        return trim($snake, '_');
    }

    /**
     * Map SP-API Orders v0 → Store / eBay-shaped order for OrderSyncService.
     *
     * @param array<string, mixed> $raw
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function normalizeOrder(array $raw, array $items): ?array
    {
        $orderId = trim((string) ($raw['AmazonOrderId'] ?? ''));
        if ($orderId === '') {
            return null;
        }

        $statusRaw = strtoupper(trim((string) ($raw['OrderStatus'] ?? '')));
        $fulfillment = match ($statusRaw) {
            'SHIPPED', 'INVOICEUNCONFIRMED' => 'FULFILLED',
            'CANCELED', 'CANCELLED', 'UNFULFILLABLE' => 'CANCELLED',
            'UNSHIPPED', 'PARTIALLYSHIPPED' => 'IN_PROGRESS',
            'PENDING', 'PENDINGAVAILABILITY' => 'NOT_STARTED',
            default => $statusRaw !== '' ? $statusRaw : 'NOT_STARTED',
        };

        $paymentStatus = in_array($statusRaw, ['PENDING', 'PENDINGAVAILABILITY', ''], true)
            ? 'PENDING'
            : 'PAID';

        $totalObj = is_array($raw['OrderTotal'] ?? null) ? $raw['OrderTotal'] : [];
        $currency = strtoupper(trim((string) (
            $totalObj['CurrencyCode']
            ?? ChannelSettings::string($this->channel, 'currency', '')
        )));
        if ($currency === '') {
            $currency = $this->defaultCurrency();
        }
        $total = (float) ($totalObj['Amount'] ?? 0);

        $shipAddr = is_array($raw['ShippingAddress'] ?? null) ? $raw['ShippingAddress'] : [];
        $buyerInfo = is_array($raw['BuyerInfo'] ?? null) ? $raw['BuyerInfo'] : [];
        $fullName = trim((string) ($shipAddr['Name'] ?? ''));
        $email = trim((string) (
            $buyerInfo['BuyerEmail']
            ?? $raw['BuyerEmail']
            ?? ''
        ));
        $phone = trim((string) ($shipAddr['Phone'] ?? ''));
        $buyerName = trim((string) ($buyerInfo['BuyerName'] ?? $fullName));

        $shipService = trim((string) (
            $raw['ShipServiceLevel']
            ?? $raw['ShipmentServiceLevelCategory']
            ?? ''
        ));

        $lineItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = max(1, (int) ($item['QuantityOrdered'] ?? $item['QuantityShipped'] ?? 1));
            $priceObj = is_array($item['ItemPrice'] ?? null) ? $item['ItemPrice'] : [];
            $lineTotal = (float) ($priceObj['Amount'] ?? 0);
            $unit = $qty > 0 ? round($lineTotal / $qty, 4) : $lineTotal;
            $lineItems[] = [
                'lineItemId' => (string) ($item['OrderItemId'] ?? ''),
                'sku'        => trim((string) ($item['SellerSKU'] ?? '')),
                'title'      => (string) ($item['Title'] ?? ''),
                'quantity'   => $qty,
                'lineItemCost' => [
                    'value'    => $unit,
                    'currency' => (string) ($priceObj['CurrencyCode'] ?? $currency),
                ],
                '_unit_priced'         => true,
                'soldFormat'           => 'FIXED_PRICE',
                'listingMarketplaceId' => $this->marketplaceId(),
            ];
        }

        return [
            'orderId'                => $orderId,
            'creationDate'           => (string) ($raw['PurchaseDate'] ?? $raw['LastUpdateDate'] ?? ''),
            'orderFulfillmentStatus' => $fulfillment,
            'orderPaymentStatus'     => $paymentStatus,
            'buyer'                  => [
                'username' => $buyerName !== '' ? $buyerName : $orderId,
                'email'    => $email,
            ],
            'pricingSummary' => [
                'total' => [
                    'value'    => $total,
                    'currency' => $currency,
                ],
                'deliveryCost' => [
                    'value'    => 0.0,
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
                            'addressLine1'    => (string) ($shipAddr['AddressLine1'] ?? ''),
                            'addressLine2'    => (string) ($shipAddr['AddressLine2'] ?? ''),
                            'city'            => (string) ($shipAddr['City'] ?? ''),
                            'stateOrProvince' => (string) ($shipAddr['StateOrRegion'] ?? ''),
                            'postalCode'      => (string) ($shipAddr['PostalCode'] ?? ''),
                            'countryCode'     => (string) ($shipAddr['CountryCode'] ?? ''),
                        ],
                    ],
                ],
            ]],
            'lineItems' => $lineItems,
            '_source'   => 'amazon_sp_api',
            '_raw_state'=> $statusRaw,
        ];
    }
}
