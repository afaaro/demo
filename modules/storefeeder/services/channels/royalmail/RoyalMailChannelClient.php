<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\RoyalMail;

use Module\StoreFeeder\Services\ChannelSettings;
use Module\StoreFeeder\Services\Channels\AbstractCourierChannelClient;
use RuntimeException;

/**
 * Royal Mail Pro Shipping v4 — labels via OAuth client-credentials.
 */
final class RoyalMailChannelClient extends AbstractCourierChannelClient
{
    private ?string $cachedAccessToken = null;
    private int $cachedAccessTokenUntil = 0;

    public function testConnection(): array
    {
        try {
            $token = $this->accessToken();
            try {
                $this->api()->listShippingAccounts($token);
            } catch (\Throwable) {
                // Token validity is enough if accounts list is restricted.
            }
            $this->channels->update((int) $this->channel->id, [
                'status'     => 'connected',
                'last_error' => '',
            ], $this->vault);
            return ['ok' => true, 'detail' => 'Royal Mail Pro Shipping authenticated.'];
        } catch (\Throwable $e) {
            error_log('Royal Mail testConnection failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function accessToken(): string
    {
        if ($this->cachedAccessToken !== null && time() < $this->cachedAccessTokenUntil) {
            return $this->cachedAccessToken;
        }

        $fresh = $this->channels->find((int) $this->channel->id);
        if ($fresh !== null
            && $fresh->accessExpiresAt !== null
            && strtotime($fresh->accessExpiresAt) > time() + 120
            && $fresh->accessToken !== ''
        ) {
            $decoded = $this->vault->open($fresh->accessToken);
            $token = (string) ($decoded['token'] ?? '');
            if ($token !== '') {
                $this->cachedAccessToken = $token;
                $this->cachedAccessTokenUntil = time() + 60;
                return $token;
            }
        }

        $tokens = $this->api()->fetchAccessToken();
        $access = (string) ($tokens['access_token'] ?? '');
        if ($access === '') {
            throw new RuntimeException('Royal Mail token response missing access_token.');
        }

        if ($fresh !== null) {
            $this->channels->storeTokens(
                (int) $fresh->id,
                '',
                $access,
                (int) ($tokens['expires_in'] ?? 3600),
                0,
                $this->vault,
            );
        }

        $this->cachedAccessToken = $access;
        $this->cachedAccessTokenUntil = time() + 60;
        return $access;
    }

    public function marketplaceCarrierCode(): string
    {
        return 'ROYAL_MAIL';
    }

    /**
     * @param array<string, mixed> $parcel
     * @return array{barcode: string, tracking_number: string, client_uid: string, status: string, raw: array<string, mixed>}
     */
    public function createParcel(array $parcel): array
    {
        $accountId = trim(ChannelSettings::string($this->channel, 'shipping_account_id', ''));
        $locationId = trim(ChannelSettings::string($this->channel, 'shipping_location_id', ''));
        $service = trim(ChannelSettings::string($this->channel, 'service_code', 'CRL24'));
        if ($accountId === '') {
            throw new RuntimeException('Royal Mail shipping_account_id is required.');
        }
        if ($service === '') {
            $service = 'CRL24';
        }

        $weightG = (int) ChannelSettings::string($this->channel, 'default_weight_g', '1000');
        if (isset($parcel['weight_kg'])) {
            $weightG = (int) round(((float) $parcel['weight_kg']) * 1000);
        }
        if ($weightG <= 0) {
            $weightG = 1000;
        }

        $shipperPostcode = ChannelSettings::string($this->channel, 'shipper_postcode', '');
        $shipperLine1 = ChannelSettings::string($this->channel, 'shipper_line1', '');
        if ($shipperPostcode === '' || $shipperLine1 === '') {
            throw new RuntimeException('Royal Mail needs shipper address (line1 + postcode) in channel settings.');
        }

        $first = trim((string) ($parcel['first_name'] ?? ''));
        $last = trim((string) ($parcel['last_name'] ?? 'Customer'));
        $destName = trim($first . ' ' . $last);
        if ($destName === '') {
            $destName = 'Customer';
        }

        $body = [
            'ShipmentInformation' => [
                'Action'                => 'Process',
                'LabelFormat'           => 'PDF',
                'ContentType'           => 'NDX',
                'ServiceCode'           => $service,
                'DescriptionOfGoods'    => mb_substr(trim((string) ($parcel['item_description'] ?? 'Goods')), 0, 70),
                'ShipmentDate'          => gmdate('Y-m-d'),
                'WeightInGrams'         => $weightG,
                'CurrencyCode'          => 'GBP',
                'Value'                 => isset($parcel['estimated_value']) ? (float) $parcel['estimated_value'] : 0,
            ],
            'Shipper' => [
                'ShippingAccountId'  => $accountId,
                'ShippingLocationId' => $locationId !== '' ? $locationId : null,
                'Address'            => array_filter([
                    'CompanyName'  => ChannelSettings::string($this->channel, 'shipper_company', '') ?: null,
                    'AddressLine1' => $shipperLine1,
                    'City'         => ChannelSettings::string($this->channel, 'shipper_city', ''),
                    'Postcode'     => strtoupper($shipperPostcode),
                    'CountryCode'  => strtoupper(ChannelSettings::string($this->channel, 'shipper_country', 'GB') ?: 'GB'),
                ], static fn($v) => $v !== null && $v !== ''),
                'Contact' => array_filter([
                    'PhoneNumber' => ChannelSettings::string($this->channel, 'shipper_phone', '') ?: null,
                    'Email'       => ChannelSettings::string($this->channel, 'shipper_email', '') ?: null,
                ], static fn($v) => $v !== null && $v !== ''),
            ],
            'Destination' => [
                'Address' => array_filter([
                    'ContactName'  => $destName,
                    'AddressLine1' => trim((string) ($parcel['line1'] ?? '')),
                    'AddressLine2' => trim((string) ($parcel['line2'] ?? '')) ?: null,
                    'City'         => trim((string) ($parcel['line3'] ?? '')),
                    'County'       => trim((string) ($parcel['line4'] ?? '')) ?: null,
                    'Postcode'     => strtoupper(trim((string) ($parcel['postcode'] ?? ''))),
                    'CountryCode'  => 'GB',
                ], static fn($v) => $v !== null && $v !== ''),
                'Contact' => array_filter([
                    'PhoneNumber' => trim((string) ($parcel['telephone'] ?? '')) ?: null,
                    'Email'       => trim((string) ($parcel['email'] ?? '')) ?: null,
                ], static fn($v) => $v !== null && $v !== ''),
            ],
            'Packages' => [[
                'PackageType'   => 'Parcel',
                'WeightInGrams' => $weightG,
                'PackageOccurrence' => 1,
            ]],
        ];

        $response = $this->api()->createShipment($this->accessToken(), $body);
        $shipmentId = trim((string) (
            $response['ShipmentId']
            ?? $response['shipmentId']
            ?? $response['Packages'][0]['ShipmentId']
            ?? $response['PackageResponse'][0]['ShipmentId']
            ?? ''
        ));
        $tracking = trim((string) (
            $response['TrackingNumber']
            ?? $response['trackingNumber']
            ?? $response['Packages'][0]['TrackingNumber']
            ?? $response['PackageResponse'][0]['TrackingNumber']
            ?? $shipmentId
        ));

        if ($shipmentId === '' && $tracking === '') {
            throw new RuntimeException('Royal Mail createShipment returned no shipment/tracking id.');
        }
        if ($shipmentId === '') {
            $shipmentId = $tracking;
        }
        if ($tracking === '') {
            $tracking = $shipmentId;
        }

        return [
            'barcode'         => $shipmentId,
            'tracking_number' => $tracking,
            'client_uid'      => (string) ($parcel['client_uid'] ?? $shipmentId),
            'status'          => 'CREATED',
            'raw'             => $response,
        ];
    }

    public function downloadLabel(string $barcode): array
    {
        return $this->api()->printLabel($this->accessToken(), $barcode);
    }

    private function api(): RoyalMailApi
    {
        $clientId = trim((string) ($this->creds['client_id'] ?? ''));
        $secret = trim((string) ($this->creds['client_secret'] ?? ''));
        if ($clientId === '' || $secret === '') {
            throw new RuntimeException('Royal Mail Client ID and Client Secret are required.');
        }
        $tokenUrl = ChannelSettings::string(
            $this->channel,
            'token_url',
            'https://authentication.proshipping.net/connect/token'
        );
        $apiBase = ChannelSettings::string(
            $this->channel,
            'api_base',
            'https://api.proshipping.net'
        );

        return new RoyalMailApi($clientId, $secret, $tokenUrl, $apiBase);
    }
}
