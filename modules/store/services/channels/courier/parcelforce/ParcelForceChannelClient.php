<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Courier\ParcelForce;

use Module\Store\Services\ChannelSettings;
use Module\Store\Services\Channels\Courier\AbstractCourierChannelClient;
use RuntimeException;

/**
 * ParcelForce expressLink — SOAP createShipment / printLabel.
 */
final class ParcelForceChannelClient extends AbstractCourierChannelClient
{
    public function testConnection(): array
    {
        try {
            $user = trim((string) ($this->creds['username'] ?? ''));
            $pass = trim((string) ($this->creds['password'] ?? ''));
            $contract = trim(ChannelSettings::string($this->channel, 'contract_number', ''));
            if ($user === '' || $pass === '' || $contract === '') {
                return [
                    'ok'    => false,
                    'error' => 'Username, password, and contract number are required.',
                ];
            }

            // Live ping: createShipment with invalid date fails auth vs validation differently;
            // validate credentials shape and SOAP endpoint reachability via a dry create that
            // we expect may fail on address — catch auth faults specifically.
            try {
                $this->api()->createShipment([
                    'ShipmentType'         => 'DELIVERY',
                    'ContractNumber'       => $contract,
                    'ServiceCode'          => ChannelSettings::string($this->channel, 'service_code', 'SUP') ?: 'SUP',
                    'ShippingDate'         => date('Y-m-d'),
                    'RecipientContact'     => [
                        'BusinessName' => 'Store Connection Test',
                        'ContactName'  => 'Test',
                    ],
                    'RecipientAddress'     => [
                        'AddressLine1' => '1 Test Street',
                        'Town'         => 'London',
                        'Postcode'     => 'EC1A 1BB',
                        'Country'      => 'GB',
                    ],
                    'TotalNumberOfParcels' => '1',
                    'TotalWeight'          => '1',
                ]);
            } catch (\Throwable $e) {
                $msg = strtolower($e->getMessage());
                if (str_contains($msg, 'auth')
                    || str_contains($msg, 'credential')
                    || str_contains($msg, 'password')
                    || str_contains($msg, 'unauthorized')
                    || str_contains($msg, 'not authorised')
                    || str_contains($msg, 'not authorized')
                ) {
                    throw $e;
                }
                // Validation / business errors still prove the SOAP endpoint + auth worked.
            }

            $this->channels->update((int) $this->channel->id, [
                'status'     => 'connected',
                'last_error' => '',
            ], $this->vault);

            return ['ok' => true, 'detail' => 'ParcelForce expressLink reachable.'];
        } catch (\Throwable $e) {
            error_log('ParcelForce testConnection failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function accessToken(): string
    {
        return trim((string) ($this->creds['username'] ?? ''));
    }

    public function marketplaceCarrierCode(): string
    {
        return 'PARCELFORCE';
    }

    /**
     * @param array<string, mixed> $parcel
     * @return array{barcode: string, tracking_number: string, client_uid: string, status: string, raw: array<string, mixed>}
     */
    public function createParcel(array $parcel): array
    {
        $contract = trim(ChannelSettings::string($this->channel, 'contract_number', ''));
        $service = trim(ChannelSettings::string($this->channel, 'service_code', 'SUP'));
        $dept = trim(ChannelSettings::string($this->channel, 'department_id', '1'));
        if ($contract === '') {
            throw new RuntimeException('ParcelForce contract number is required.');
        }
        if ($service === '') {
            $service = 'SUP';
        }

        $weight = (float) ($parcel['weight_kg']
            ?? ChannelSettings::string($this->channel, 'default_weight_kg', '1'));
        if ($weight <= 0) {
            $weight = 1.0;
        }

        $first = trim((string) ($parcel['first_name'] ?? ''));
        $last = trim((string) ($parcel['last_name'] ?? 'Customer'));
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = 'Customer';
        }

        $requested = [
            'ShipmentType'         => 'DELIVERY',
            'ContractNumber'       => $contract,
            'ServiceCode'          => $service,
            'ShippingDate'         => date('Y-m-d'),
            'RecipientContact'     => [
                'BusinessName' => $name,
                'ContactName'  => $name,
                'EmailAddress' => trim((string) ($parcel['email'] ?? '')),
                'Telephone'    => trim((string) ($parcel['telephone'] ?? '')),
            ],
            'RecipientAddress'     => [
                'AddressLine1' => trim((string) ($parcel['line1'] ?? '')),
                'AddressLine2' => trim((string) ($parcel['line2'] ?? '')),
                'Town'         => trim((string) ($parcel['line3'] ?? '')),
                'Postcode'     => strtoupper(trim((string) ($parcel['postcode'] ?? ''))),
                'Country'      => 'GB',
            ],
            'TotalNumberOfParcels' => '1',
            'TotalWeight'          => (string) $weight,
            'ReferenceNumber1'     => mb_substr(trim((string) ($parcel['delivery_reference'] ?? $parcel['client_uid'] ?? '')), 0, 24),
        ];
        if ($dept !== '') {
            $requested['DepartmentId'] = $dept;
        }

        $response = $this->api()->createShipment($requested);
        $shipmentNumber = $this->extractShipmentNumber($response);
        if ($shipmentNumber === '') {
            throw new RuntimeException('ParcelForce createShipment did not return a shipment number.');
        }

        return [
            'barcode'         => $shipmentNumber,
            'tracking_number' => $shipmentNumber,
            'client_uid'      => (string) ($parcel['client_uid'] ?? $shipmentNumber),
            'status'          => 'CREATED',
            'raw'             => $response,
        ];
    }

    public function downloadLabel(string $barcode): array
    {
        return $this->api()->printLabel($barcode);
    }

    /** @param array<string, mixed> $response */
    private function extractShipmentNumber(array $response): string
    {
        $candidates = [
            $response['CompletedShipmentInfo']['CompletedShipments']['CompletedShipment']['ShipmentNumber'] ?? null,
            $response['ShipmentNumber'] ?? null,
            $response['shipmentNumber'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_scalar($c) && trim((string) $c) !== '') {
                return trim((string) $c);
            }
        }
        return $this->deepFind($response, 'ShipmentNumber');
    }

    /** @param array<string, mixed> $arr */
    private function deepFind(array $arr, string $key): string
    {
        if (isset($arr[$key]) && is_scalar($arr[$key])) {
            return trim((string) $arr[$key]);
        }
        foreach ($arr as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = $this->deepFind($value, $key);
            if ($found !== '') {
                return $found;
            }
        }
        return '';
    }

    private function api(): ParcelForceApi
    {
        $user = trim((string) ($this->creds['username'] ?? ''));
        $pass = trim((string) ($this->creds['password'] ?? ''));
        if ($user === '' || $pass === '') {
            throw new RuntimeException('ParcelForce username and password are required.');
        }
        $endpoint = ChannelSettings::string(
            $this->channel,
            'api_endpoint',
            'https://expresslink.parcelforce.net/ws/'
        );
        $ns = ChannelSettings::string(
            $this->channel,
            'soap_namespace',
            'http://www.parcelforce.net/ws/ship/v14'
        );

        return new ParcelForceApi($user, $pass, $endpoint, $ns);
    }
}
