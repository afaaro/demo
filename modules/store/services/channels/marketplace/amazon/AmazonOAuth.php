<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Amazon;

/**
 * Seller Central website-authorization URL for SP-API.
 *
 * Docs: https://developer-docs.amazon.com/sp-api/docs/website-authorization-workflow
 */
final class AmazonOAuth
{
    /** @var array<string, string> */
    private const HOSTS = [
        'ATVPDKIKX0DER'  => 'sellercentral.amazon.com',
        'A2EUQ1WTGCTBG2' => 'sellercentral.amazon.ca',
        'A1AM78C64UM0Y8' => 'sellercentral.amazon.com.mx',
        'A1F83G8C2ARO7P' => 'sellercentral.amazon.co.uk',
        'A1PA6795UKMFR9' => 'sellercentral.amazon.de',
        'A13V1IB3VIYZZH' => 'sellercentral.amazon.fr',
        'APJ6JRA9NG5V4'  => 'sellercentral.amazon.it',
        'A1RKKUPIHCS9HS' => 'sellercentral.amazon.es',
        'A1805IZSGTT6HS' => 'sellercentral.amazon.nl',
        'A2NODRKZP88ZB9' => 'sellercentral.amazon.se',
        'A1C3SOZRARQ6R3' => 'sellercentral.amazon.pl',
        'AMEN7PMS3EDWL'  => 'sellercentral.amazon.com.be',
        'A28R8C7NBKEWEA' => 'sellercentral.amazon.ie',
    ];

    public static function consentUrl(
        string $applicationId,
        string $state,
        string $marketplaceId,
        string $redirectUri = '',
        bool $draft = true,
    ): string {
        $applicationId = trim($applicationId);
        if ($applicationId === '') {
            throw new \RuntimeException(
                'Amazon Link account needs application_id (amzn1.sp.solution…). '
                . 'Set store.amazon in modules/store/config/amazon.php.'
            );
        }

        $query = [
            'application_id' => $applicationId,
            'state'          => $state,
        ];
        if (trim($redirectUri) !== '') {
            $query['redirect_uri'] = trim($redirectUri);
        }
        if ($draft) {
            $query['version'] = 'beta';
        }

        return 'https://' . self::sellerCentralHost($marketplaceId)
            . '/apps/authorize/consent?'
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function sellerCentralHost(string $marketplaceId): string
    {
        return self::HOSTS[$marketplaceId] ?? 'sellercentral-europe.amazon.com';
    }
}
