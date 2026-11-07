<?php
declare(strict_types=1);

$common = include dirname(__DIR__, 4) . '/helpers/channel_common_settings.php';
if (!is_array($common)) {
    $common = [];
}

return [
    'type'       => 'shopify',
    'label'      => 'Shopify',
    'category'   => 'webstore',
    'client'     => \Module\Store\Services\Channels\Marketplace\Shopify\ShopifyChannelClient::class,
    'uses_oauth' => false,
    'credentials' => [
        'shop_domain'  => ['label' => 'Shop domain',            'type' => 'text'],
        'access_token' => ['label' => 'Admin API access token', 'type' => 'password'],
    ],
    'settings' => array_merge($common, [
        'shop_timezone' => [
            'label'   => 'Shop timezone',
            'type'    => 'text',
            'default' => 'Europe/London',
            'section' => 'provider',
        ],
        'shopify_location_id' => [
            'label'   => 'Location ID (inventory)',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
            'help'    => 'Optional. Leave blank to use the first active Shopify location.',
        ],
    ]),
];
