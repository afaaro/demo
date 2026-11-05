<?php
declare(strict_types=1);

$common = include dirname(__DIR__, 3) . '/helpers/channel_common_settings.php';
if (!is_array($common)) {
    $common = [];
}

return [
    'type'       => 'bnq',
    'label'      => 'B&Q',
    'category'   => 'marketplace',
    'client'     => \Module\StoreFeeder\Services\Channels\Bnq\BnqChannelClient::class,
    'uses_oauth' => false,

    'credentials' => [
        'api_key' => [
            'label' => 'Mirakl API key',
            'type'  => 'password',
            'help'  => 'From B&Q Mirakl back office → your name → API Key.',
        ],
    ],

    'settings' => array_merge($common, [
        'api_base' => [
            'label'   => 'API base URL',
            'type'    => 'select',
            'default' => 'https://marketplace.kingfisher.com',
            'section' => 'provider',
            'options' => [
                'https://marketplace.kingfisher.com' => 'Production (marketplace.kingfisher.com)',
                'https://mp-api-preprod.kingfisher.com' => 'Sandbox / preprod',
            ],
            'help' => 'Kingfisher Mirakl seller API host (no trailing /api).',
        ],
        'shop_id' => [
            'label'   => 'Shop ID (optional)',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
            'help'    => 'Mirakl shop id if you manage multiple shops under one key.',
        ],
        'currency' => [
            'label'   => 'Currency',
            'type'    => 'select',
            'default' => 'GBP',
            'section' => 'provider',
            'options' => [
                'GBP' => 'GBP',
                'EUR' => 'EUR',
            ],
        ],
        'product_id_type' => [
            'label'   => 'Product ID type',
            'type'    => 'select',
            'default' => 'EAN',
            'section' => 'provider',
            'options' => [
                'EAN'  => 'EAN',
                'UPC'  => 'UPC',
                'SKU'  => 'SKU',
                'MPN'  => 'MPN',
                'SHOP_SKU' => 'SHOP_SKU',
            ],
            'help' => 'Mirakl product_id_type for offer create (usually EAN for B&Q).',
        ],
        'state_code' => [
            'label'   => 'Offer condition code',
            'type'    => 'text',
            'default' => '11',
            'section' => 'provider',
            'help'    => 'Mirakl offer state_code (11 = new on most platforms).',
        ],
        'logistic_class' => [
            'label'   => 'Logistic class',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
            'help'    => 'Optional Mirakl logistic_class code for offers.',
        ],
    ]),
];

