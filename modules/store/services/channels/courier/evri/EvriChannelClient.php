<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Courier\Evri;

use Module\Store\Services\Channel;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\ChannelSettings;
use Module\Store\Services\Channels\ChannelClientInterface;
use Module\Store\Services\Channels\NotSupportedException;
use Module\Store\Services\CredentialsVault;
use RuntimeException;

/**
 * Evri Business (Classic) courier — labels + tracking via client-credentials OAuth.
 */
final class EvriChannelClient implements ChannelClientInterface
{
    private ?string $cachedAccessToken = null;
    private int $cachedAccessTokenUntil = 0;

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
            $token = $this->accessToken();
            $customers = $this->api()->getCustomers($token);
            $this->channels->update((int) $this->channel->id, [
                'status'     => 'connected',
                'last_error' => '',
            ], $this->vault);

            $hint = '';
            if (is_array($customers) && $customers !== []) {
                $hint = ' Customer profile reachable.';
            }

            return ['ok' => true, 'detail' => trim('Evri API authenticated.' . $hint)];
        } catch (\Throwable $e) {
            error_log('Evri testConnection failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function consentUrl(string $state): ?string
    {
        return null;
    }

    public function handleCallback(string $code): array
    {
        throw new NotSupportedException('Evri uses client-credentials OAuth (no browser consent).');
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

        $tokens = $this->api()->fetchAccessToken();
        $access = (string) ($tokens['access_token'] ?? '');
        if ($access === '') {
            throw new RuntimeException('Evri token response missing access_token.');
        }

        $this->channels->storeTokens(
            (int) $fresh->id,
            '',
            $access,
            (int) ($tokens['expires_in'] ?? 3600),
            0,
            $this->vault,
        );

        $this->cachedAccessToken      = $access;
        $this->cachedAccessTokenUntil = time() + 60;
        return $access;
    }

    /**
     * Create one parcel and return the Evri summary for it.
     *
     * @param array{
     *   client_uid: string,
     *   first_name: string,
     *   last_name: string,
     *   email?: string,
     *   telephone?: string,
     *   line1: string,
     *   line2?: string,
     *   line3?: string,
     *   line4?: string,
     *   postcode: string,
     *   weight_kg?: float,
     *   item_description?: string,
     *   delivery_reference?: string,
     *   estimated_value?: float,
     *   compensation?: float,
     *   parcel_type?: string
     * } $parcel
     * @return array{barcode: string, client_uid: string, status: string, raw: array<string, mixed>}
     */
    public function createParcel(array $parcel): array
    {
        $clientUid = trim((string) ($parcel['client_uid'] ?? ''));
        if ($clientUid === '') {
            throw new RuntimeException('client_uid is required.');
        }

        $weight = (float) ($parcel['weight_kg']
            ?? ChannelSettings::string($this->channel, 'default_weight_kg', '1'));
        if ($weight <= 0) {
            $weight = 1.0;
        }

        $parcelType = strtoupper(trim((string) ($parcel['parcel_type']
            ?? ChannelSettings::string($this->channel, 'parcel_type', 'STANDARD'))));
        if ($parcelType !== 'POSTABLE') {
            $parcelType = 'STANDARD';
        }

        $compensation = (float) ($parcel['compensation']
            ?? ChannelSettings::string($this->channel, 'compensation_pounds', '0'));

        $payload = [
            'parcels' => [[
                'clientUID' => mb_substr($clientUid, 0, 255),
                'deliveryDetails' => [
                    'deliveryAddress' => array_filter([
                        'line1'    => mb_substr(trim((string) ($parcel['line1'] ?? '')), 0, 32),
                        'line2'    => mb_substr(trim((string) ($parcel['line2'] ?? '')), 0, 32) ?: null,
                        'line3'    => mb_substr(trim((string) ($parcel['line3'] ?? '')), 0, 32) ?: null,
                        'line4'    => mb_substr(trim((string) ($parcel['line4'] ?? '')), 0, 32) ?: null,
                        'postcode' => mb_substr(trim((string) ($parcel['postcode'] ?? '')), 0, 10),
                    ], static fn($v) => $v !== null && $v !== ''),
                    'firstName' => mb_substr(trim((string) ($parcel['first_name'] ?? '')), 0, 32) ?: null,
                    'lastName'  => mb_substr(trim((string) ($parcel['last_name'] ?? 'Customer')), 0, 32),
                    'email'     => mb_substr(trim((string) ($parcel['email'] ?? '')), 0, 80) ?: null,
                    'telephone' => mb_substr(trim((string) ($parcel['telephone'] ?? '')), 0, 32) ?: null,
                ],
                'parcelDetails' => array_filter([
                    'weightKg'                    => $weight,
                    'itemDescription'             => mb_substr(
                        trim((string) ($parcel['item_description'] ?? 'Order')),
                        0,
                        255
                    ),
                    'deliveryReference'           => mb_substr(
                        trim((string) ($parcel['delivery_reference'] ?? $clientUid)),
                        0,
                        32
                    ),
                    'estimatedParcelValuePounds'  => isset($parcel['estimated_value'])
                        ? (float) $parcel['estimated_value']
                        : null,
                    'compensationRequiredPounds'  => $compensation > 0 ? $compensation : null,
                    'type'                        => $parcelType,
                ], static fn($v) => $v !== null && $v !== ''),
            ]],
        ];

        $response = $this->api()->createParcels($this->accessToken(), $payload);
        $summaries = is_array($response['parcelSummaries'] ?? null)
            ? $response['parcelSummaries']
            : [];
        $summary = is_array($summaries[0] ?? null) ? $summaries[0] : [];

        $status = strtoupper((string) ($summary['status'] ?? ''));
        if ($status !== 'CREATED') {
            $errors = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
            $parts  = [];
            foreach ($errors as $err) {
                if (!is_array($err)) {
                    continue;
                }
                $parts[] = trim(
                    (string) ($err['error_description'] ?? $err['error'] ?? 'validation error')
                );
            }
            $msg = $parts !== []
                ? implode('; ', $parts)
                : (string) ($response['error_description'] ?? $response['error'] ?? 'Parcel not created');
            throw new RuntimeException('Evri parcel failed: ' . $msg);
        }

        $barcode = trim((string) ($summary['barcode'] ?? ''));
        if ($barcode === '') {
            throw new RuntimeException('Evri parcel created but barcode was empty.');
        }

        return [
            'barcode'         => $barcode,
            'tracking_number' => $barcode,
            'client_uid'      => (string) ($summary['clientUID'] ?? $clientUid),
            'status'          => $status,
            'raw'             => $response,
        ];
    }

    public function marketplaceCarrierCode(): string
    {
        return 'HERMES';
    }

    /**
     * @return array{binary: string, content_type: string}
     */
    public function downloadLabel(string $barcode): array
    {
        $format = strtoupper(ChannelSettings::string($this->channel, 'label_format', 'DEFAULT'));
        if (!in_array($format, ['DEFAULT', 'THERMAL', 'TWO_PER_PAGE'], true)) {
            $format = 'DEFAULT';
        }

        return $this->api()->getLabel($this->accessToken(), $barcode, $format);
    }

    public function pushVariant(int $variantId, array $variantData): string
    {
        unset($variantId, $variantData);
        // Soft stub — couriers are skipped by ListingSync; empty id = no listing.
        return '';
    }

    public function syncInventory(int $variantId, int $quantity, string $sku = ''): void
    {
        unset($variantId, $quantity, $sku);
    }

    public function syncPrice(string $sku, float $price, string $currency = 'GBP'): void
    {
        unset($sku, $price, $currency);
    }

    public function fetchOrders(int $days = 30, int $limit = 50, ?string $offset = null): array
    {
        unset($days, $limit, $offset);
        return ['orders' => [], 'next' => null];
    }

    /**
     * @return array{
     *     conversations: array<int, array<string, mixed>>,
     *     limit: int,
     *     offset: int,
     *     total: int,
     *     next?: null
     * }
     */
    public function fetchConversations(
        int $limit = 25,
        int|string $offset = 0,
        string $conversationType = 'FROM_MEMBERS',
        string $status = '',
    ): array {
        unset($conversationType, $status);
        return [
            'conversations' => [],
            'limit'         => max(1, min(100, $limit)),
            'offset'        => is_int($offset) ? $offset : 0,
            'total'         => 0,
            'next'          => null,
        ];
    }

    private function api(): EvriApi
    {
        $clientId = trim((string) ($this->creds['client_id'] ?? ''));
        $secret   = trim((string) ($this->creds['client_secret'] ?? ''));
        if ($clientId === '' || $secret === '') {
            throw new RuntimeException('Evri Client ID and Client Secret are required.');
        }

        $tokenUrl = ChannelSettings::string(
            $this->channel,
            'token_url',
            'https://auth.backoffice.parcel2go.com/connect/token'
        );
        $apiBase = ChannelSettings::string(
            $this->channel,
            'api_base',
            'https://api.business.evri.com'
        );

        // Sandbox flag reserved for future alternate hosts; keep production defaults for now.
        if (ChannelSettings::bool($this->channel, 'sandbox', false)
            && !str_contains(strtolower($apiBase), 'sandbox')
        ) {
            // Evri does not publish a separate public sandbox host in the Classic swagger;
            // credentials themselves gate test vs live. Flag kept for UI parity.
        }

        return new EvriApi($clientId, $secret, $tokenUrl, $apiBase);
    }
}
