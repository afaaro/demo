<?php
declare(strict_types=1);

$common = include dirname(__DIR__, 4) . '/helpers/channel_common_settings.php';
if (!is_array($common)) {
    $common = [];
}

return [
    'type'       => 'amazon',
    'label'      => 'Amazon',
    'category'   => 'marketplace',
    'client'     => \Module\StoreFeeder\Services\Channels\Marketplace\Amazon\AmazonChannelClient::class,
    'uses_oauth' => true,

    'credentials' => [
        'application_id' => [
            'label'            => 'Application ID',
            'type'             => 'text',
            'platform_managed' => true,
            'help'             => 'amzn1.sp.solution… from Amazon Developer Central. Filled by the platform app.',
        ],
        'client_id'      => [
            'label'            => 'LWA Client ID',
            'type'             => 'text',
            'platform_managed' => true,
        ],
        'client_secret'  => [
            'label'            => 'LWA Client Secret',
            'type'             => 'password',
            'platform_managed' => true,
        ],
        'refresh_token'  => [
            'label' => 'Refresh token',
            'type'  => 'password',
            'help'  => 'Filled automatically after Link account. Only paste if you self-authorized in Seller Central.',
        ],
        'seller_id'      => [
            'label' => 'Seller ID',
            'type'  => 'text',
            'help'  => 'Filled from selling_partner_id after Link account. Required for listing push.',
        ],
    ],

    'settings' => array_merge($common, [
        'marketplace' => [
            'label'   => 'Marketplace',
            'type'    => 'select',
            'default' => 'A1F83G8C2ARO7P',
            'section' => 'provider',
            'options' => [
                'A1F83G8C2ARO7P' => 'Amazon UK',
                'ATVPDKIKX0DER'  => 'Amazon US',
                'A2EUQ1WTGCTBG2' => 'Amazon CA',
                'A1AM78C64UM0Y8' => 'Amazon MX',
                'A1PA6795UKMFR9' => 'Amazon DE',
                'A13V1IB3VIYZZH' => 'Amazon FR',
                'APJ6JRA9NG5V4'  => 'Amazon IT',
                'A1RKKUPIHCS9HS' => 'Amazon ES',
                'A1805IZSGTT6HS' => 'Amazon NL',
                'A2NODRKZP88ZB9' => 'Amazon SE',
                'A1C3SOZRARQ6R3' => 'Amazon PL',
                'AMEN7PMS3EDWL'  => 'Amazon BE',
                'A28R8C7NBKEWEA' => 'Amazon IE',
            ],
        ],
        'region' => [
            'label'   => 'SP-API region',
            'type'    => 'select',
            'default' => 'auto',
            'section' => 'provider',
            'options' => [
                'auto' => 'Auto (from marketplace)',
                'eu'   => 'Europe (EU)',
                'na'   => 'North America (NA)',
                'fe'   => 'Far East (FE)',
            ],
        ],
        'sandbox' => [
            'label'   => 'Use sandbox endpoints',
            'type'    => 'checkbox',
            'default' => false,
            'section' => 'advanced',
        ],
        'custom_amazon_app' => [
            'label'   => 'Use custom Amazon app',
            'type'    => 'checkbox',
            'default' => false,
            'section' => 'advanced',
            'help'    => 'Use this channel’s own Application ID / LWA keys instead of the platform app.',
        ],
        'oauth_draft' => [
            'label'   => 'Draft app (version=beta)',
            'type'    => 'checkbox',
            'default' => true,
            'section' => 'advanced',
            'help'    => 'Required while the SP-API app is unpublished. Turn off after Amazon lists the app.',
        ],
        'product_type' => [
            'label'   => 'Default product type',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
            'help'    => 'Amazon SP-API product type code (e.g. LUGGAGE). Required for full LISTING push; use ASIN + offer-only if listing an existing catalog item.',
        ],
        'listing_requirements' => [
            'label'   => 'Listing requirements',
            'type'    => 'select',
            'default' => '',
            'section' => 'provider',
            'options' => [
                ''                    => 'Auto (LISTING, or OFFER_ONLY when ASIN set)',
                'LISTING'             => 'LISTING (product + offer)',
                'LISTING_OFFER_ONLY'  => 'LISTING_OFFER_ONLY (price/qty against ASIN)',
                'LISTING_PRODUCT_ONLY'=> 'LISTING_PRODUCT_ONLY (catalog data only)',
            ],
            'help' => 'Maps to Listings Items API requirements. Leave Auto unless you know you need offer-only.',
        ],
    ]),
];

