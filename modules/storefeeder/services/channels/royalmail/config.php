<?php
declare(strict_types=1);

return [
    'type'       => 'royalmail',
    'label'      => 'Royal Mail',
    'category'   => 'courier',
    'client'     => \Module\StoreFeeder\Services\Channels\RoyalMail\RoyalMailChannelClient::class,
    'uses_oauth' => false,

    'credentials' => [
        'client_id'     => ['label' => 'Client ID',     'type' => 'text'],
        'client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
    ],

    'settings' => [
        'shipping_account_id' => [
            'label'   => 'Shipping account ID / alias',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
            'help'    => 'Pro Shipping account id or alias for Royal Mail.',
        ],
        'shipping_location_id' => [
            'label'   => 'Shipping location ID / alias',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'service_code' => [
            'label'   => 'Service code',
            'type'    => 'text',
            'default' => 'CRL24',
            'section' => 'provider',
            'help'    => 'e.g. CRL24 (Tracked 24), CRL48, TPN24 — from your RM contract.',
        ],
        'default_weight_g' => [
            'label'   => 'Default weight (grams)',
            'type'    => 'text',
            'default' => '1000',
            'section' => 'provider',
        ],
        'shipper_company' => [
            'label'   => 'Shipper company name',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'shipper_line1' => [
            'label'   => 'Shipper address line 1',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'shipper_city' => [
            'label'   => 'Shipper city',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'shipper_postcode' => [
            'label'   => 'Shipper postcode',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'shipper_country' => [
            'label'   => 'Shipper country (ISO2)',
            'type'    => 'text',
            'default' => 'GB',
            'section' => 'provider',
        ],
        'shipper_phone' => [
            'label'   => 'Shipper phone',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'shipper_email' => [
            'label'   => 'Shipper email',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'token_url' => [
            'label'   => 'OAuth token URL (advanced)',
            'type'    => 'text',
            'default' => 'https://authentication.proshipping.net/connect/token',
            'section' => 'advanced',
        ],
        'api_base' => [
            'label'   => 'API base URL (advanced)',
            'type'    => 'text',
            'default' => 'https://api.proshipping.net',
            'section' => 'advanced',
        ],
    ],
];
