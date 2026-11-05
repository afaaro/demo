<?php
declare(strict_types=1);

/**
 * Integration Manager catalog (StoreFeeder-style).
 * `available` types must have a working client under services/channels/{type}/.
 *
 * @return array<string, array{
 *   label: string,
 *   icon: string,
 *   providers: array<string, array{label: string, available: bool, blurb?: string}>
 * }>
 */
return [
    'marketplace' => [
        'label' => 'Marketplace',
        'icon'  => 'shop',
        'providers' => [
            'ebay'   => ['label' => 'eBay',   'available' => true,  'blurb' => 'Listings, orders, messages'],
            'amazon' => ['label' => 'Amazon', 'available' => true,  'blurb' => 'SP-API seller connection'],
            'bnq'    => ['label' => 'B&Q',    'available' => true,  'blurb' => 'Kingfisher Mirakl marketplace'],
            'etsy'   => ['label' => 'Etsy',   'available' => false, 'blurb' => 'Coming soon'],
            'tiktok' => ['label' => 'TikTok', 'available' => false, 'blurb' => 'Coming soon'],
        ],
    ],
    'webstore' => [
        'label' => 'Webstore',
        'icon'  => 'laptop',
        'providers' => [
            'shopify'     => ['label' => 'Shopify',     'available' => true,  'blurb' => 'Admin API'],
            'woocommerce' => ['label' => 'WooCommerce', 'available' => false, 'blurb' => 'Coming soon'],
        ],
    ],
    'courier' => [
        'label' => 'Courier',
        'icon'  => 'truck',
        'providers' => [
            'evri'        => ['label' => 'Evri / Hermes', 'available' => true,  'blurb' => 'Labels & tracking (Classic API)'],
            'royalmail'   => ['label' => 'Royal Mail',    'available' => true,  'blurb' => 'Pro Shipping v4 labels'],
            'parcelforce' => ['label' => 'ParcelForce',   'available' => true,  'blurb' => 'expressLink SOAP labels'],
            'dpd'         => ['label' => 'DPD',           'available' => true,  'blurb' => 'GeoSession API labels'],
            'fedex'       => ['label' => 'FedEx',         'available' => false, 'blurb' => 'Coming soon'],
        ],
    ],
    'accounts' => [
        'label' => 'Accounts',
        'icon'  => 'calculator',
        'providers' => [
            'xero'       => ['label' => 'Xero',        'available' => false, 'blurb' => 'Coming soon'],
            'quickbooks' => ['label' => 'QuickBooks',  'available' => false, 'blurb' => 'Coming soon'],
        ],
    ],
];
