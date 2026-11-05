<?php
declare(strict_types=1);

$common = include dirname(__DIR__, 3) . '/helpers/channel_common_settings.php';
if (!is_array($common)) {
    $common = [];
}

return [
    'type'        => 'ebay',
    'label'       => 'eBay',
    'category'    => 'marketplace',
    'client'      => \Module\StoreFeeder\Services\Channels\Ebay\EbayChannelClient::class,
    'uses_oauth'  => true,

    // When storefeeder.ebay is configured, these are hidden for tenants
    // (platform-owned app — consent-only Connect). Platform may still override.
    'credentials' => [
        'client_id'     => [
            'label'            => 'Client ID (App ID)',
            'type'             => 'text',
            'platform_managed' => true,
        ],
        'client_secret' => [
            'label'            => 'Client Secret (Cert ID)',
            'type'             => 'password',
            'platform_managed' => true,
        ],
        'ru_name'       => [
            'label'            => 'RuName',
            'type'             => 'text',
            'platform_managed' => true,
        ],
    ],


    'settings' => array_merge($common, [
        // eBay-specific (Integration Details)
        'import_unpaid_orders' => [
            'label'   => 'Import Unpaid Orders',
            'type'    => 'checkbox',
            'default' => true,
            'section' => 'provider',
        ],
        'automatic_relisting' => [
            'label'   => 'Automatic Relisting',
            'type'    => 'checkbox',
            'default' => true,
            'section' => 'provider',
        ],
        'relist_ended_listings' => [
            'label'   => 'Re-list Ended Listings',
            'type'    => 'checkbox',
            'default' => false,
            'section' => 'provider',
        ],
        'ebay_managed_payments' => [
            'label'   => 'eBay Managed Payments',
            'type'    => 'checkbox',
            'default' => true,
            'section' => 'provider',
        ],
        'download_auction_orders' => [
            'label'   => 'Download Auction Orders',
            'type'    => 'checkbox',
            'default' => true,
            'section' => 'provider',
        ],
        'download_channel_messages' => [
            'label'   => 'Download Channel Messages',
            'type'    => 'checkbox',
            'default' => true,
            'section' => 'provider',
        ],
        'send_royal_mail_delivery_confirmation' => [
            'label'   => 'Send Royal Mail Delivery Confirmation Numbers',
            'type'    => 'checkbox',
            'default' => true,
            'section' => 'provider',
        ],
        'download_orders_all_marketplaces' => [
            'label'   => 'Download Orders From All Market Places',
            'type'    => 'checkbox',
            'default' => true,
            'section' => 'provider',
        ],
        'use_selling_manager' => [
            'label'   => 'Use selling manager',
            'type'    => 'checkbox',
            'default' => true,
            'section' => 'provider',
        ],
        'checkout_message_only' => [
            'label'   => 'Checkout Message only',
            'type'    => 'checkbox',
            'default' => false,
            'section' => 'provider',
        ],
        'promotion_sales' => [
            'label'   => 'Promotion Sales',
            'type'    => 'checkbox',
            'default' => true,
            'section' => 'provider',
        ],
        'paypal_default_email' => [
            'label'   => 'PayPal default email',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'paypal_secondary_email' => [
            'label'   => 'PayPal secondary email',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'click_and_collect_type' => [
            'label'   => 'Click And Collect Type',
            'type'    => 'select',
            'default' => 'none',
            'section' => 'provider',
            'options' => [
                'none'  => 'None',
                'argos' => 'Argos Click & Collect',
                'other' => 'Other',
            ],
        ],
        'parent_channel' => [
            'label'   => 'Parent Channel',
            'type'    => 'select',
            'default' => '',
            'section' => 'provider',
            'options' => [
                '' => 'None',
            ],
        ],

        'marketplace' => [
            'label'   => 'eBay Country',
            'type'    => 'select',
            'default' => 'EBAY_GB',
            'section' => 'provider',
            'options' => [
                'EBAY_GB' => 'United Kingdom',
                'EBAY_US' => 'United States',
                'EBAY_DE' => 'Germany',
                'EBAY_AU' => 'Australia',
            ],
        ],
        'sandbox' => [
            'label'   => 'Sandbox mode',
            'type'    => 'checkbox',
            'default' => false,
            'section' => 'provider',
            'help'    => 'Use eBay Sandbox APIs and storefeeder.ebay.sandbox keys.',
        ],
        'custom_ebay_app' => [
            'label'   => 'Use custom eBay app on this channel',
            'type'    => 'checkbox',
            'default' => false,
            'section' => 'advanced',
            'help'    => 'Off (default): use platform storefeeder.ebay keys. On: use App ID/Cert/RuName saved on this channel.',
        ],

        'notification_endpoint_url' => [
            'label'   => 'Account-deletion endpoint URL',
            'type'    => 'text',
            'section' => 'advanced',
            'help'    => 'Must match the Developer Portal URL exactly.',
        ],
        'notification_verification_token' => [
            'label'   => 'Account-deletion verification token',
            'type'    => 'text',
            'section' => 'advanced',
            'help'    => '32–80 chars [A-Za-z0-9_-] — same token as in the eBay Developer Portal',
        ],
        'merchant_location_key' => [
            'label'   => 'Merchant location',
            'type'    => 'select',
            'default' => '',
            'section' => 'business_policies',
            'options' => ['' => '— Select —'],
        ],
        'fulfillment_policy_id' => [
            'label'   => 'Fulfillment policy',
            'type'    => 'select',
            'default' => '',
            'section' => 'business_policies',
            'options' => ['' => '— Select —'],
        ],
        'payment_policy_id' => [
            'label'   => 'Payment policy',
            'type'    => 'select',
            'default' => '',
            'section' => 'business_policies',
            'options' => ['' => '— Select —'],
        ],
        'return_policy_id' => [
            'label'   => 'Return policy',
            'type'    => 'select',
            'default' => '',
            'section' => 'business_policies',
            'options' => ['' => '— Select —'],
        ],
        'default_category_id' => [
            'label'   => 'Default eBay category',
            'type'    => 'text',
            'default' => '',
            'section' => 'ebay_category',
        ],
        'default_category_name' => [
            'label'   => 'Default eBay category name',
            'type'    => 'text',
            'default' => '',
            'section' => 'ebay_category',
        ],

        'store_category_1' => [
            'label'   => 'Primary store category',
            'type'    => 'select',
            'default' => '',
            'section' => 'store_categories',
            'options' => ['' => '— None —'],
        ],
        'store_category_2' => [
            'label'   => 'Secondary store category',
            'type'    => 'select',
            'default' => '',
            'section' => 'store_categories',
            'options' => ['' => '— None —'],
        ],
    ]),
];
