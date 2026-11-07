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
     *   tracking_number: string,
     *   carrier_code: string,
     *   barcode: string,
     *   label_path: string
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

        $existing = $this->shipments->findLatestForOrder($orderId);
        if ($existing !== null && trim((string) ($existing['barcode'] ?? '')) !== '') {
            return [
                'shipment'        => $existing,
                'tracking_number' => (string) ($existing['tracking_number'] ?: $existing['barcode']),
                'carrier_code'    => (string) ($existing['carrier_code'] ?: 'OTHER'),
                'barcode'         => (string) $existing['barcode'],
                'label_path'      => (string) ($existing['label_path'] ?? ''),
            ];
        }

        $courier ??= $this->findDefaultCourierChannel();
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

        $clientUid = 'sf-' . $orderId . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $phone = '';
        $rawPhone = $addr['primaryPhone'] ?? null;
        if (is_array($rawPhone)) {
            $phone = (string) ($rawPhone['phoneNumber'] ?? '');
        } elseif (is_string($rawPhone)) {
            $phone = $rawPhone;
        }

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
            'item_description'    => $description,
            'delivery_reference'  => (string) ($order['external_order_id'] ?? $orderId),
            'estimated_value'     => (float) ($order['total'] ?? 0),
        ]);

        $barcode = trim((string) ($created['barcode'] ?? ''));
        $tracking = trim((string) ($created['tracking_number'] ?? $barcode));
        if ($barcode === '') {
            throw new RuntimeException('Courier createParcel returned an empty barcode.');
        }
        if ($tracking === '') {
            $tracking = $barcode;
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

        $labelPath = '';
        try {
            $label = $client->downloadLabel($barcode);
            $labelPath = $this->storeLabelPdf($orderId, $barcode, (string) ($label['binary'] ?? ''), $courier->type);
        } catch (\Throwable $e) {
            error_log($courier->type . ' label download failed for order #' . $orderId . ': ' . $e->getMessage());
        }

        $shipment = $this->shipments->create([
            'tenant_id'          => (int) ($order['tenant_id'] ?? TenantContext::get()->id()),
            'order_id'           => $orderId,
            'courier_channel_id' => (int) $courier->id,
            'carrier_code'       => $carrierCode,
            'barcode'            => $barcode,
            'tracking_number'    => $tracking,
            'client_uid'         => (string) ($created['client_uid'] ?? $clientUid),
            'label_path'         => $labelPath,
            'status'             => 'created',
            'raw_response'       => json_encode($created['raw'] ?? $created, JSON_UNESCAPED_SLASHES),
        ]);

        return [
            'shipment'        => $shipment,
            'tracking_number' => $tracking,
            'carrier_code'    => $carrierCode,
            'barcode'         => $barcode,
            'label_path'      => $labelPath,
        ];
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
