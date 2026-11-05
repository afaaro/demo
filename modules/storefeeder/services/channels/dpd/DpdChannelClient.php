<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\Dpd;

use Module\StoreFeeder\Services\ChannelSettings;
use Module\StoreFeeder\Services\Channels\AbstractCourierChannelClient;
use RuntimeException;

/**
 * DPD UK — GeoSession shipping API (labels + tracking).
 */
final class DpdChannelClient extends AbstractCourierChannelClient
{
    private ?string $cachedSession = null;
    private int $cachedSessionUntil = 0;

    public function testConnection(): array
    {
        try {
            $this->geoSession(true);
            $this->channels->update((int) $this->channel->id, [
                'status'     => 'connected',
                'last_error' => '',
            ], $this->vault);
            return ['ok' => true, 'detail' => 'DPD API authenticated (geoSession).'];
        } catch (\Throwable $e) {
            error_log('DPD testConnection failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function accessToken(): string
    {
        return $this->geoSession();
    }

    public function marketplaceCarrierCode(): string
    {
        return 'DPD';
    }

    /**
     * @param array<string, mixed> $parcel
     * @return array{barcode: string, tracking_number: string, client_uid: string, status: string, raw: array<string, mixed>}
     */
    public function createParcel(array $parcel): array
    {
        $session = $this->geoSession();
        $weight = (float) ($parcel['weight_kg']
            ?? ChannelSettings::string($this->channel, 'default_weight_kg', '1'));
        if ($weight <= 0) {
            $weight = 1.0;
        }

        $network = ChannelSettings::string($this->channel, 'network_code', '1^12');
        if ($network === '') {
            $network = '1^12';
        }

        $collectionDate = gmdate('Y-m-d\T16:00:00');
        $delivery = $this->addressBlock($parcel, false);
        $collection = $this->collectionAddress();

        $payload = [
            'jobId'                => null,
            'collectionOnDelivery' => false,
            'invoice'              => null,
            'collectionDate'       => $collectionDate,
            'consolidate'          => false,
            'consignment'          => [[
                'consignmentNumber' => null,
                'consignmentRef'    => null,
                'parcels'           => [],
                'collectionDetails' => $collection,
                'deliveryDetails'   => $delivery,
                'networkCode'       => $network,
                'numberOfParcels'   => 1,
                'totalWeight'       => $weight,
                'shippingRef1'      => mb_substr(trim((string) ($parcel['delivery_reference'] ?? $parcel['client_uid'] ?? '')), 0, 25),
                'shippingRef2'      => '',
                'shippingRef3'      => '',
                'customsValue'      => null,
                'deliveryInstructions' => '',
                'parcelDescription' => mb_substr(trim((string) ($parcel['item_description'] ?? 'Order')), 0, 50),
                'liabilityValue'    => null,
                'liability'         => false,
            ]],
        ];

        $response = $this->api()->createShipment($session, $payload);
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $shipmentId = trim((string) ($data['shipmentId'] ?? ''));
        if ($shipmentId === '') {
            throw new RuntimeException('DPD createShipment did not return a shipmentId.');
        }

        $tracking = $shipmentId;
        $consignments = $data['consignmentDetail'] ?? $data['consignments'] ?? [];
        if (is_array($consignments) && isset($consignments[0]) && is_array($consignments[0])) {
            $num = trim((string) (
                $consignments[0]['consignmentNumber']
                ?? $consignments[0]['parcelNumbers'][0]
                ?? ''
            ));
            if ($num !== '') {
                $tracking = $num;
            }
        }

        return [
            'barcode'         => $shipmentId, // used for label re-download
            'tracking_number' => $tracking,
            'client_uid'      => (string) ($parcel['client_uid'] ?? $shipmentId),
            'status'          => 'CREATED',
            'raw'             => $response,
        ];
    }

    public function downloadLabel(string $barcode): array
    {
        return $this->api()->getLabel($this->geoSession(), $barcode);
    }

    private function geoSession(bool $force = false): string
    {
        if (!$force && $this->cachedSession !== null && time() < $this->cachedSessionUntil) {
            return $this->cachedSession;
        }
        $session = $this->api()->login();
        $this->cachedSession = $session;
        $this->cachedSessionUntil = time() + 3600;
        return $session;
    }

    /**
     * @return array{contactDetails: array<string, string>, address: array<string, string>, notificationDetails?: array<string, string>}
     */
    private function collectionAddress(): array
    {
        $name = ChannelSettings::string($this->channel, 'collection_name', 'Despatch');
        $phone = ChannelSettings::string($this->channel, 'collection_phone', '00000000000');
        $street = ChannelSettings::string($this->channel, 'collection_street', '');
        $postcode = ChannelSettings::string($this->channel, 'collection_postcode', '');
        if ($street === '' || $postcode === '') {
            throw new RuntimeException(
                'DPD needs collection address settings (street + postcode) under the courier channel.'
            );
        }

        return [
            'contactDetails' => [
                'contactName' => mb_substr($name !== '' ? $name : 'Despatch', 0, 35),
                'telephone'   => mb_substr($phone !== '' ? $phone : '00000000000', 0, 15),
            ],
            'address' => [
                'organisation' => ChannelSettings::string($this->channel, 'collection_organisation', ''),
                'countryCode'  => 'GB',
                'postcode'     => strtoupper($postcode),
                'street'       => $street,
                'locality'     => ChannelSettings::string($this->channel, 'collection_locality', ''),
                'town'         => ChannelSettings::string($this->channel, 'collection_town', ''),
                'county'       => ChannelSettings::string($this->channel, 'collection_county', ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $parcel
     * @return array<string, mixed>
     */
    private function addressBlock(array $parcel, bool $isCollection): array
    {
        unset($isCollection);
        $first = trim((string) ($parcel['first_name'] ?? ''));
        $last = trim((string) ($parcel['last_name'] ?? 'Customer'));
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = 'Customer';
        }
        $phone = trim((string) ($parcel['telephone'] ?? '00000000000'));
        if ($phone === '') {
            $phone = '00000000000';
        }

        $block = [
            'contactDetails' => [
                'contactName' => mb_substr($name, 0, 35),
                'telephone'   => mb_substr($phone, 0, 15),
            ],
            'address' => [
                'organisation' => '',
                'countryCode'  => 'GB',
                'postcode'     => strtoupper(trim((string) ($parcel['postcode'] ?? ''))),
                'street'       => trim((string) ($parcel['line1'] ?? '')),
                'locality'     => trim((string) ($parcel['line2'] ?? '')),
                'town'         => trim((string) ($parcel['line3'] ?? '')),
                'county'       => trim((string) ($parcel['line4'] ?? '')),
            ],
        ];

        $email = trim((string) ($parcel['email'] ?? ''));
        if ($email !== '') {
            $block['notificationDetails'] = [
                'email'  => mb_substr($email, 0, 100),
                'mobile' => '',
            ];
        }

        return $block;
    }

    private function api(): DpdApi
    {
        $user = trim((string) ($this->creds['username'] ?? ''));
        $pass = trim((string) ($this->creds['password'] ?? ''));
        $account = trim(ChannelSettings::string($this->channel, 'account_number', ''));
        if ($user === '' || $pass === '' || $account === '') {
            throw new RuntimeException('DPD username, password, and account number are required.');
        }
        $base = ChannelSettings::string($this->channel, 'api_base', 'https://api.dpd.co.uk');
        return new DpdApi($user, $pass, $account, $base !== '' ? $base : 'https://api.dpd.co.uk');
    }
}
