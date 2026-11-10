<?php
declare(strict_types=1);

namespace Module\Store\Services;

use Module\Store\Services\Channels\Courier\AbstractCourierChannelClient;
use Module\Store\Services\Channels\Courier\Evri\EvriChannelClient;
use RuntimeException;

/**
 * Create courier labels and persist shipment + PDF path.
 */
final class CourierShipmentService
{
    /** @var list<string> */
    public const COURIER_TYPES = ['evri', 'royalmail', 'parcelforce', 'dpd'];

    public function __construct(
        private ChannelRepository    $channels,
        private ChannelClientFactory $factory,
        private ShipmentRepository   $shipments,
        private string               $storageRoot,
        private ?\System\Library\Database $db = null,
    ) {}

    /** Prefer connected+enabled Evri (legacy helper). */
    public function findDefaultEvriChannel(): ?Channel
    {
        return $this->channels->findConnectedByType('evri');
    }

    /**
     * First connected+enabled courier, optionally preferring a type.
     *
     * @param list<string>|string|null $preferTypes
     */
    public function findDefaultCourierChannel(array|string|null $preferTypes = null): ?Channel
    {
        $prefer = is_string($preferTypes)
            ? [$preferTypes]
            : (is_array($preferTypes) ? $preferTypes : self::COURIER_TYPES);

        foreach ($prefer as $type) {
            $ch = $this->channels->findConnectedByType($type);
            if ($ch !== null && $ch->enabled) {
                return $ch;
            }
        }

        foreach ($this->channels->enabled() as $ch) {
            if (in_array($ch->type, self::COURIER_TYPES, true) && $ch->isConnected()) {
                return $ch;
            }
        }

        return null;
    }

    /**
     * @return list<Channel>
     */
    public function connectedCouriers(): array
    {
        $out = [];
        foreach ($this->channels->enabled() as $ch) {
            if (in_array($ch->type, self::COURIER_TYPES, true) && $ch->isConnected()) {
                $out[] = $ch;
            }
        }
        return $out;
    }

    /**
     * Rate shop connected couriers; fall back to shipping_methods.cost by weight.
     *
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $items
     * @return list<array{
     *   courier: string,
     *   channel_id: int,
     *   service: string,
     *   cost: float,
     *   currency: string,
     *   source: string
     * }>
     */
    public function quoteRates(array $order, array $items): array
    {
        $rates = [];
        $weightKg = (float) ($order['order_weight_kg'] ?? 0);
        if ($weightKg <= 0) {
            $grams = 0;
            foreach ($items as $item) {
                $grams += max(0, (int) ($item['weight_grams'] ?? 0))
                    * max(1, (int) ($item['qty'] ?? $item['order_qty'] ?? 1));
            }
            $weightKg = $grams > 0 ? round($grams / 1000, 3) : 1.0;
        }

        foreach ($this->connectedCouriers() as $courier) {
            try {
                $client = $this->factory->make($courier);
                if (method_exists($client, 'quoteRates')) {
                    $quoted = $client->quoteRates($order, $items);
                    if (is_array($quoted)) {
                        foreach ($quoted as $q) {
                            if (!is_array($q)) {
                                continue;
                            }
                            $rates[] = [
                                'courier'    => $courier->type,
                                'channel_id' => (int) $courier->id,
                                'service'    => (string) ($q['service'] ?? $q['name'] ?? $courier->type),
                                'cost'       => round((float) ($q['cost'] ?? $q['price'] ?? 0), 2),
                                'currency'   => (string) ($q['currency'] ?? 'GBP'),
                                'source'     => 'api',
                            ];
                        }
                        continue;
                    }
                }
                if (method_exists($client, 'getRates')) {
                    $quoted = $client->getRates($order, $items);
                    if (is_array($quoted)) {
                        foreach ($quoted as $q) {
                            if (!is_array($q)) {
                                continue;
                            }
                            $rates[] = [
                                'courier'    => $courier->type,
                                'channel_id' => (int) $courier->id,
                                'service'    => (string) ($q['service'] ?? $q['name'] ?? $courier->type),
                                'cost'       => round((float) ($q['cost'] ?? $q['price'] ?? 0), 2),
                                'currency'   => (string) ($q['currency'] ?? 'GBP'),
                                'source'     => 'api',
                            ];
                        }
                        continue;
                    }
                }
            } catch (\Throwable) {
                // Fall through to estimate.
            }

            $est = $this->estimateFromShippingMethods($courier->type, $weightKg);
            $rates[] = [
                'courier'    => $courier->type,
                'channel_id' => (int) $courier->id,
                'service'    => ($courier->label !== '' ? $courier->label : $courier->name) . ' (estimate)',
                'cost'       => $est,
                'currency'   => 'GBP',
                'source'     => 'estimate',
            ];
        }

        if ($rates === [] && $this->db !== null) {
            try {
                $methods = new ShippingMethodRepository($this->db);
                foreach ($methods->all() as $method) {
                    if (empty($method['enabled'])) {
                        continue;
                    }
                    $cost = round((float) ($method['cost'] ?? 0), 2);
                    if ($cost <= 0) {
                        continue;
                    }
                    $rates[] = [
                        'courier'    => (string) ($method['carrier_code'] ?? 'other'),
                        'channel_id' => 0,
                        'service'    => trim((string) ($method['company'] ?? '') . ' — ' . (string) ($method['method'] ?? '')),
                        'cost'       => $cost,
                        'currency'   => 'GBP',
                        'source'     => 'shipping_method',
                    ];
                }
            } catch (\Throwable) {
            }
        }

        usort($rates, static fn(array $a, array $b): int => $a['cost'] <=> $b['cost']);
        return $rates;
    }

    private function estimateFromShippingMethods(string $courierType, float $weightKg): float
    {
        if ($this->db === null) {
            return round(max(2.5, 2.5 + ($weightKg * 0.85)), 2);
        }
        try {
            $methods = new ShippingMethodRepository($this->db);
            $prefer = strtolower($courierType);
            foreach ($methods->all() as $method) {
                if (empty($method['enabled'])) {
                    continue;
                }
                $carrier = strtolower(trim((string) ($method['carrier_code'] ?? '')));
                $cost = round((float) ($method['cost'] ?? 0), 2);
                if ($cost <= 0) {
                    continue;
                }
                if ($prefer !== '' && ($carrier === $prefer || str_contains($carrier, $prefer)
                    || str_contains($prefer, $carrier))
                ) {
                    return $cost;
                }
            }
        } catch (\Throwable) {
        }
        return round(max(2.5, 2.5 + ($weightKg * 0.85)), 2);
    }

    /**
     * @param array<string, mixed> $order  store_orders row
     * @param array<int, array<string, mixed>> $items
     * @return array{
     *   shipment: array<string, mixed>,
     *   tracking_number: string,
     *   carrier_code: string,
     *   barcode: string,
     *   label_path: string
     * }
     */
    public function createEvriShipment(array $order, array $items, ?Channel $courier = null): array
    {
        $courier ??= $this->findDefaultEvriChannel();
        return $this->createCourierShipment($order, $items, $courier);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $items
     * @return array{
     *   shipment: array<string, mixed>,
     *   shipments: list<array<string, mixed>>,
     *   tracking_number: string,
     *   carrier_code: string,
     *   barcode: string,
     *   label_path: string,
     *   carrier_cost?: float,
     *   parcel_count: int
     * }
     */
    public function createCourierShipment(array $order, array $items, ?Channel $courier = null): array
    {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            throw new RuntimeException('Invalid order.');
        }
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        if ((int) ($order['tenant_id'] ?? 0) !== TenantContext::get()->id()) {
            throw new RuntimeException('Invalid order.');
        }

        $packageCount = max(1, (int) ($order['package_count'] ?? 1));
        $active = $this->shipments->forOrder($orderId);
        $activeNonVoid = array_values(array_filter(
            $active,
            static fn(array $s): bool => strtolower((string) ($s['status'] ?? '')) !== 'voided'
                && trim((string) ($s['barcode'] ?? '')) !== ''
        ));

        // Already have enough parcels — return primary (first).
        if (count($activeNonVoid) >= $packageCount) {
            $existing = $activeNonVoid[0];
            return [
                'shipment'        => $existing,
                'shipments'       => $activeNonVoid,
                'tracking_number' => (string) ($existing['tracking_number'] ?: $existing['barcode']),
                'carrier_code'    => (string) ($existing['carrier_code'] ?: 'OTHER'),
                'barcode'         => (string) $existing['barcode'],
                'label_path'      => (string) ($existing['label_path'] ?? ''),
                'carrier_cost'    => (float) ($existing['carrier_cost'] ?? 0),
                'parcel_count'    => count($activeNonVoid),
            ];
        }

        // Prefer order.preferred_courier / mapped shipping method when selecting courier.
        if ($courier === null) {
            $prefer = trim((string) ($order['preferred_courier'] ?? ''));
            if ($prefer === '') {
                $preferMethod = trim((string) (
                    $order['mapped_shipping_method']
                    ?? $order['shipping_method']
                    ?? ''
                ));
                $preferList = PickwaveService::preferredCourierTypes($preferMethod);
            } else {
                $preferList = [$prefer];
            }
            $courier = $this->findDefaultCourierChannel($preferList);
        }
        if ($courier === null || !$courier->isConnected()) {
            throw new RuntimeException(
                'No connected courier. Add Evri / Royal Mail / ParcelForce / DPD under Integration Manager and Test Connection.'
            );
        }
        if ((int) $courier->tenantId !== TenantContext::get()->id()) {
            throw new RuntimeException('Courier channel does not belong to the current tenant.');
        }
        if (!in_array($courier->type, self::COURIER_TYPES, true)) {
            throw new RuntimeException('Channel is not a supported courier type.');
        }

        $client = $this->factory->make($courier);
        if (!method_exists($client, 'createParcel') || !method_exists($client, 'downloadLabel')) {
            throw new RuntimeException('Courier client does not support labels.');
        }

        $addr = json_decode((string) ($order['shipping_address'] ?? ''), true);
        if (!is_array($addr)) {
            $addr = [];
        }

        [$first, $last] = $this->splitName(
            (string) ($order['shipping_name'] ?? $addr['fullName'] ?? 'Customer')
        );

        $line1 = trim((string) ($addr['addressLine1'] ?? $addr['address1'] ?? ''));
        $postcode = trim((string) ($addr['postalCode'] ?? $addr['postcode'] ?? ''));
        if ($line1 === '' || $postcode === '') {
            throw new RuntimeException('Order needs a UK delivery address (line1 + postcode) for courier labels.');
        }

        $country = strtoupper(trim((string) ($addr['countryCode'] ?? 'GB')));
        if ($country !== '' && !in_array($country, ['GB', 'UK', 'GG', 'JE', 'IM'], true)) {
            throw new RuntimeException('Courier label APIs in Store currently support UK delivery addresses only.');
        }

        $descParts = [];
        foreach ($items as $item) {
            $sku = trim((string) ($item['external_sku'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $descParts[] = $sku !== '' ? $sku : ($title !== '' ? $title : 'Item');
        }
        $description = $descParts !== []
            ? implode(', ', array_slice($descParts, 0, 3))
            : ('Order #' . $orderId);

        $phone = '';
        $rawPhone = $addr['primaryPhone'] ?? null;
        if (is_array($rawPhone)) {
            $phone = (string) ($rawPhone['phoneNumber'] ?? '');
        } elseif (is_string($rawPhone)) {
            $phone = $rawPhone;
        }

        $carrierCode = 'OTHER';
        if ($client instanceof AbstractCourierChannelClient) {
            $carrierCode = $client->marketplaceCarrierCode();
        } elseif ($client instanceof EvriChannelClient) {
            $carrierCode = 'HERMES';
        } else {
            $carrierCode = match ($courier->type) {
                'evri'        => 'HERMES',
                'royalmail'   => 'ROYAL_MAIL',
                'parcelforce' => 'PARCELFORCE',
                'dpd'         => 'DPD',
                default       => 'OTHER',
            };
        }

        $createdShipments = $activeNonVoid;
        $startIndex = count($activeNonVoid) + 1;
        $primary = $activeNonVoid[0] ?? null;
        $valuePerParcel = round((float) ($order['total'] ?? 0) / $packageCount, 2);

        for ($parcel = $startIndex; $parcel <= $packageCount; $parcel++) {
            $clientUid = 'sf-' . $orderId . '-p' . $parcel . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $parcelDesc = $packageCount > 1
                ? $description . ' (parcel ' . $parcel . '/' . $packageCount . ')'
                : $description;

            $created = $client->createParcel([
                'client_uid'          => $clientUid,
                'first_name'          => $first,
                'last_name'           => $last,
                'email'               => (string) ($order['buyer_email'] ?? $addr['email'] ?? ''),
                'telephone'           => $phone,
                'line1'               => $line1,
                'line2'               => (string) ($addr['addressLine2'] ?? ''),
                'line3'               => (string) ($addr['city'] ?? ''),
                'line4'               => (string) ($addr['stateOrProvince'] ?? ''),
                'postcode'            => $postcode,
                'item_description'    => $parcelDesc,
                'delivery_reference'  => (string) ($order['external_order_id'] ?? $orderId)
                    . ($packageCount > 1 ? '-P' . $parcel : ''),
                'estimated_value'     => $valuePerParcel,
                'parcel_index'        => $parcel,
                'parcel_count'        => $packageCount,
            ]);

            $barcode = trim((string) ($created['barcode'] ?? ''));
            $tracking = trim((string) ($created['tracking_number'] ?? $barcode));
            if ($barcode === '') {
                throw new RuntimeException('Courier createParcel returned an empty barcode.');
            }
            if ($tracking === '') {
                $tracking = $barcode;
            }

            $labelPath = '';
            try {
                $label = $client->downloadLabel($barcode);
                $labelPath = $this->storeLabelPdf(
                    $orderId,
                    $barcode . ($packageCount > 1 ? '_p' . $parcel : ''),
                    (string) ($label['binary'] ?? ''),
                    $courier->type
                );
            } catch (\Throwable $e) {
                error_log($courier->type . ' label download failed for order #' . $orderId
                    . ' parcel ' . $parcel . ': ' . $e->getMessage());
            }

            $carrierCost = $this->resolveCarrierCost($order, $created);
            $shipment = $this->shipments->create([
                'tenant_id'          => (int) ($order['tenant_id'] ?? TenantContext::get()->id()),
                'order_id'           => $orderId,
                'parcel_index'       => $parcel,
                'courier_channel_id' => (int) $courier->id,
                'carrier_code'       => $carrierCode,
                'barcode'            => $barcode,
                'tracking_number'    => $tracking,
                'client_uid'         => (string) ($created['client_uid'] ?? $clientUid),
                'label_path'         => $labelPath,
                'status'             => 'created',
                'carrier_cost'       => $carrierCost,
                'raw_response'       => json_encode($created['raw'] ?? $created, JSON_UNESCAPED_SLASHES),
            ]);
            $createdShipments[] = $shipment;
            if ($primary === null) {
                $primary = $shipment;
            }
        }

        if ($primary === null) {
            throw new RuntimeException('Failed to create courier shipment.');
        }

        // Persist primary tracking on the order when empty.
        if ($this->db !== null) {
            $existingTracking = trim((string) ($order['tracking_number'] ?? ''));
            if ($existingTracking === '') {
                $this->db->update('store_orders', [
                    'tracking_number' => mb_substr((string) ($primary['tracking_number'] ?: $primary['barcode']), 0, 128),
                    'carrier_code'    => mb_substr((string) ($primary['carrier_code'] ?: $carrierCode), 0, 64),
                ], ['id' => $orderId, 'tenant_id' => TenantContext::get()->id()]);
            }
        }

        return [
            'shipment'        => $primary,
            'shipments'       => $createdShipments,
            'tracking_number' => (string) ($primary['tracking_number'] ?: $primary['barcode']),
            'carrier_code'    => (string) ($primary['carrier_code'] ?: $carrierCode),
            'barcode'         => (string) $primary['barcode'],
            'label_path'      => (string) ($primary['label_path'] ?? ''),
            'carrier_cost'    => (float) ($primary['carrier_cost'] ?? 0),
            'parcel_count'    => count($createdShipments),
        ];
    }

    /**
     * Void/cancel a shipment so labels can be recreated.
     *
     * @param array<string, mixed>|null $shipment
     * @return array{voided: bool, remote: bool}
     */
    public function voidShipment(?array $shipment = null, ?int $shipmentId = null): array
    {
        if ($shipment === null && $shipmentId !== null) {
            $shipment = $this->shipments->find($shipmentId);
        }
        if ($shipment === null) {
            throw new RuntimeException('Shipment not found.');
        }
        if (!TenantContext::get()->has()
            || (int) ($shipment['tenant_id'] ?? 0) !== TenantContext::get()->id()
        ) {
            throw new RuntimeException('Invalid shipment.');
        }
        if (strtolower((string) ($shipment['status'] ?? '')) === 'voided') {
            return ['voided' => true, 'remote' => false];
        }

        $remote = false;
        $channel = $this->channels->find((int) ($shipment['courier_channel_id'] ?? 0));
        if ($channel !== null && $channel->isConnected()) {
            try {
                $client = $this->factory->make($channel);
                $barcode = trim((string) ($shipment['barcode'] ?? $shipment['tracking_number'] ?? ''));
                if ($barcode !== '' && method_exists($client, 'voidParcel')) {
                    $client->voidParcel($barcode);
                    $remote = true;
                } elseif ($barcode !== '' && method_exists($client, 'cancelParcel')) {
                    $client->cancelParcel($barcode);
                    $remote = true;
                }
            } catch (\Throwable $e) {
                error_log('Courier void failed shipment #' . ($shipment['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }

        $this->shipments->markVoided((int) $shipment['id']);
        return ['voided' => true, 'remote' => $remote];
    }

    /**
     * Void all active shipments for an order, then recreate labels.
     *
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function recreateLabels(array $order, array $items, ?Channel $courier = null): array
    {
        $orderId = (int) ($order['id'] ?? 0);
        foreach ($this->shipments->forOrder($orderId) as $row) {
            if (strtolower((string) ($row['status'] ?? '')) === 'voided') {
                continue;
            }
            $this->voidShipment($row);
        }
        return $this->createCourierShipment($order, $items, $courier);
    }

    /**
     * Poll courier tracking APIs and update store_shipments.status.
     *
     * @param array<string, mixed>|null $shipment
     * @return array{status: string, raw?: array<string, mixed>}
     */
    public function pollTracking(?array $shipment = null, ?int $shipmentId = null): array
    {
        if ($shipment === null && $shipmentId !== null) {
            $shipment = $this->shipments->find($shipmentId);
        }
        if ($shipment === null) {
            throw new RuntimeException('Shipment not found.');
        }
        if (!TenantContext::get()->has()
            || (int) ($shipment['tenant_id'] ?? 0) !== TenantContext::get()->id()
        ) {
            throw new RuntimeException('Invalid shipment.');
        }

        $tracking = trim((string) ($shipment['tracking_number'] ?? $shipment['barcode'] ?? ''));
        if ($tracking === '') {
            throw new RuntimeException('Shipment has no tracking number.');
        }

        $channel = $this->channels->find((int) ($shipment['courier_channel_id'] ?? 0));
        if ($channel === null || !$channel->isConnected()) {
            throw new RuntimeException('Courier channel not connected.');
        }

        $client = $this->factory->make($channel);
        $raw = [];
        $mapped = 'created';

        $before = strtolower((string) ($shipment['status'] ?? 'created'));

        if (method_exists($client, 'trackParcel')) {
            $raw = $client->trackParcel($tracking);
            $mapped = $this->mapTrackingStatus($raw);
        } elseif (method_exists($client, 'getTracking')) {
            $raw = $client->getTracking($tracking);
            $mapped = $this->mapTrackingStatus($raw);
        } else {
            // Soft fallback: leave created unless already advanced.
            $mapped = $before !== '' ? $before : 'created';
            $raw = ['note' => 'Courier client has no track API; status unchanged.'];
        }

        $this->shipments->updateStatus((int) $shipment['id'], $mapped);

        if ($mapped !== $before && in_array($mapped, ['in_transit', 'delivered', 'exception'], true)) {
            $this->maybeNotifyBuyerOfTracking($shipment, $mapped, $tracking);
        }

        return ['status' => $mapped, 'raw' => is_array($raw) ? $raw : [], 'changed' => $mapped !== $before];
    }

    /**
     * Optionally message the buyer when tracking status advances.
     *
     * @param array<string, mixed> $shipment
     */
    private function maybeNotifyBuyerOfTracking(array $shipment, string $status, string $tracking): void
    {
        if ($this->db === null || !TenantContext::get()->has()) {
            return;
        }
        $orderId = (int) ($shipment['order_id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        try {
            $order = $this->db->query(
                "SELECT * FROM `#__store_orders` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
                [$orderId, TenantContext::get()->id()]
            )->row;
            if ($order === [] || $order === null) {
                return;
            }

            $salesChannel = $this->channels->find((int) ($order['channel_id'] ?? 0));
            if ($salesChannel === null || !$salesChannel->isConnected()) {
                return;
            }
            if (!ChannelSettings::bool($salesChannel, 'tracking_notify', false)) {
                return;
            }

            $templateTag = match ($status) {
                'delivered' => 'tracking_delivered',
                'exception' => 'tracking_exception',
                default     => 'tracking_in_transit',
            };

            $body = $this->resolveTrackingMessageBody($salesChannel, $order, $tracking, $status, $templateTag);
            if ($body === '') {
                return;
            }

            $client = $this->factory->make($salesChannel);
            if (!method_exists($client, 'sendMessage')) {
                (new SyncLogRepository($this->db))->log(
                    (int) $salesChannel->id,
                    'tracking_notify',
                    'skipped',
                    'Channel has no sendMessage for order #' . $orderId,
                    ['order_id' => $orderId, 'status' => $status],
                );
                return;
            }

            $externalId = (string) ($order['external_order_id'] ?? '');
            if ($salesChannel->type === 'amazon') {
                $client->sendMessage($externalId, 'AMAZON_ORDER', $body, 'confirmDeliveryDetails');
            } else {
                // eBay / others: conversation id may be unknown — try order-scoped send.
                $client->sendMessage($externalId, 'ORDER', $body);
            }

            (new SyncLogRepository($this->db))->log(
                (int) $salesChannel->id,
                'tracking_notify',
                'ok',
                'Sent ' . $templateTag . ' for order #' . $orderId,
                ['order_id' => $orderId, 'status' => $status, 'tracking' => $tracking],
            );
        } catch (\Throwable $e) {
            error_log('tracking_notify order #' . $orderId . ': ' . $e->getMessage());
            try {
                if ($this->db !== null) {
                    (new SyncLogRepository($this->db))->log(
                        (int) ($shipment['courier_channel_id'] ?? 0),
                        'tracking_notify',
                        'error',
                        $e->getMessage(),
                        ['order_id' => $orderId, 'status' => $status],
                    );
                }
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    private function resolveTrackingMessageBody(
        Channel $channel,
        array $order,
        string $tracking,
        string $status,
        string $templateTag,
    ): string {
        $templates = new ReplyTemplateRepository($this->db);
        $body = '';
        foreach ($templates->all($channel->type) as $tpl) {
            $name = strtolower(trim((string) ($tpl['name'] ?? '')));
            if ($name === $templateTag || str_contains($name, $templateTag)) {
                $body = (string) ($tpl['body'] ?? '');
                break;
            }
        }
        if ($body === '') {
            $body = match ($status) {
                'delivered' => 'Your order {order_id} has been delivered. Tracking: {tracking}.',
                'exception' => 'There is an update on your shipment for order {order_id}. Tracking: {tracking}. Please contact us if you need help.',
                default     => 'Your order {order_id} is on its way. Tracking: {tracking}.',
            };
        }

        $replacements = [
            '{order_id}'   => (string) ($order['external_order_id'] ?? $order['id'] ?? ''),
            '{tracking}'   => $tracking,
            '{status}'     => $status,
            '{buyer}'      => (string) ($order['shipping_name'] ?? $order['buyer_username'] ?? 'Customer'),
            '{carrier}'    => (string) ($order['carrier_code'] ?? ''),
        ];
        return trim(str_replace(array_keys($replacements), array_values($replacements), $body));
    }

    /**
     * @return array{polled: int, updated: int, errors: int}
     */
    public function pollOpenShipments(int $limit = 40): array
    {
        $stats = ['polled' => 0, 'updated' => 0, 'errors' => 0];
        foreach ($this->shipments->openForTracking($limit) as $row) {
            $stats['polled']++;
            try {
                $before = strtolower((string) ($row['status'] ?? ''));
                $result = $this->pollTracking($row);
                if (($result['status'] ?? '') !== $before) {
                    $stats['updated']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('Tracking poll shipment #' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
        return $stats;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapTrackingStatus(array $raw): string
    {
        $hay = strtolower(json_encode($raw, JSON_UNESCAPED_SLASHES) ?: '');
        $explicit = strtolower(trim((string) (
            $raw['status']
            ?? $raw['trackingStatus']
            ?? $raw['state']
            ?? $raw['eventCode']
            ?? ''
        )));
        $check = $explicit . ' ' . $hay;

        if (preg_match('/deliver|delivered|pod|complete/', $check)) {
            return 'delivered';
        }
        if (preg_match('/exception|fail|return|undeliver|cancel|damage/', $check)) {
            return 'exception';
        }
        if (preg_match('/transit|out.?for.?delivery|collected|accepted|in.?progress|despatch|shipped/', $check)) {
            return 'in_transit';
        }
        return 'created';
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $created
     */
    private function resolveCarrierCost(array $order, array $created): float
    {
        foreach (['cost', 'postage_cost', 'carrier_cost', 'price'] as $key) {
            if (isset($created[$key]) && is_numeric($created[$key])) {
                return round(max(0, (float) $created[$key]), 2);
            }
            $raw = is_array($created['raw'] ?? null) ? $created['raw'] : [];
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                return round(max(0, (float) $raw[$key]), 2);
            }
        }

        if ($this->db === null || !TenantContext::get()->has()) {
            return 0.0;
        }

        try {
            $methods = new ShippingMethodRepository($this->db);
            $prefer = strtolower(trim((string) ($order['preferred_courier'] ?? '')));
            $mapped = strtolower(trim((string) ($order['mapped_shipping_method'] ?? '')));
            foreach ($methods->all() as $method) {
                if (empty($method['enabled'])) {
                    continue;
                }
                $carrier = strtolower(trim((string) ($method['carrier_code'] ?? '')));
                $label = strtolower(trim(
                    (string) ($method['company'] ?? '') . ' — ' . (string) ($method['method'] ?? '')
                ));
                $cost = round((float) ($method['cost'] ?? 0), 2);
                if ($cost <= 0) {
                    continue;
                }
                if ($prefer !== '' && ($carrier === $prefer || str_contains($carrier, $prefer))) {
                    return $cost;
                }
                if ($mapped !== '' && ($mapped === $label || str_contains($label, $mapped) || str_contains($mapped, $label))) {
                    return $cost;
                }
            }
        } catch (\Throwable) {
        }

        return 0.0;
    }

    /**
     * Re-download label PDF for an existing shipment row.
     *
     * @param array<string, mixed> $shipment
     */
    public function ensureLabelFile(array $shipment): string
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        if ((int) ($shipment['tenant_id'] ?? 0) !== TenantContext::get()->id()) {
            throw new RuntimeException('Invalid shipment.');
        }

        $rel = trim((string) ($shipment['label_path'] ?? ''));
        $full = rtrim($this->storageRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
        if ($rel !== '' && is_file($full)) {
            return $rel;
        }

        $barcode = trim((string) ($shipment['barcode'] ?? ''));
        if ($barcode === '') {
            throw new RuntimeException('Shipment has no barcode.');
        }

        $channel = $this->channels->find((int) ($shipment['courier_channel_id'] ?? 0));
        if ($channel === null || !$channel->isConnected()) {
            throw new RuntimeException('Courier channel not connected.');
        }
        if ((int) $channel->tenantId !== TenantContext::get()->id()) {
            throw new RuntimeException('Courier channel not connected.');
        }

        $client = $this->factory->make($channel);
        if (!method_exists($client, 'downloadLabel')) {
            throw new RuntimeException('Courier channel does not support label download.');
        }

        $label = $client->downloadLabel($barcode);
        $rel = $this->storeLabelPdf(
            (int) ($shipment['order_id'] ?? 0),
            $barcode,
            (string) ($label['binary'] ?? ''),
            $channel->type
        );
        $this->shipments->updateLabelPath((int) ($shipment['id'] ?? 0), $rel);
        return $rel;
    }

    private function storeLabelPdf(int $orderId, string $barcode, string $binary, string $courierType = 'courier'): string
    {
        if ($binary === '') {
            throw new RuntimeException('Empty label PDF from courier.');
        }

        $dir = rtrim($this->storageRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'labels';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create labels directory.');
        }

        $safeBarcode = preg_replace('/[^A-Za-z0-9_-]/', '', $barcode) ?: 'label';
        $safeType = preg_replace('/[^A-Za-z0-9_-]/', '', $courierType) ?: 'courier';
        $relative = 'labels/' . $orderId . '_' . $safeType . '_' . $safeBarcode . '.pdf';
        $full = rtrim($this->storageRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (file_put_contents($full, $binary) === false) {
            throw new RuntimeException('Unable to write courier label PDF.');
        }

        return $relative;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/', ' ', $full) ?? $full);
        if ($full === '') {
            return ['Customer', 'Customer'];
        }
        $parts = explode(' ', $full, 2);
        $first = $parts[0];
        $last  = $parts[1] ?? $parts[0];
        return [$first, $last];
    }
}
