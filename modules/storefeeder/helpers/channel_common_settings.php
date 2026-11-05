<?php
declare(strict_types=1);

/**
 * Shared Integration Details settings (Order Processing / Stock / Misc).
 * Merged into each channel type config.
 *
 * @return array<string, array<string, mixed>>
 */
return [
    // Order Processing
    'import_orders' => [
        'label'   => 'Import Orders',
        'type'    => 'checkbox',
        'default' => true,
        'section' => 'order_processing',
    ],
    'send_despatch_notifications' => [
        'label'   => 'Send despatch notifications',
        'type'    => 'checkbox',
        'default' => true,
        'section' => 'order_processing',
    ],
    'update_channel_status' => [
        'label'   => 'Update Channel Status',
        'type'    => 'checkbox',
        'default' => true,
        'section' => 'order_processing',
    ],
    'merge_orders' => [
        'label'   => 'Merge Orders',
        'type'    => 'checkbox',
        'default' => true,
        'section' => 'order_processing',
    ],
    'create_invoice_printing' => [
        'label'   => 'Create Invoice Printing',
        'type'    => 'checkbox',
        'default' => false,
        'section' => 'order_processing',
    ],
    'handling_time_days' => [
        'label'   => 'Handling Time (Days)',
        'type'    => 'text',
        'default' => '1',
        'section' => 'order_processing',
    ],
    'enforce_channel_warehouse' => [
        'label'   => 'Enforce Channel Warehouse',
        'type'    => 'checkbox',
        'default' => false,
        'section' => 'order_processing',
    ],

    // Stock Management
    'automatic_inventory_updates' => [
        'label'   => 'Automatic Inventory Updates',
        'type'    => 'checkbox',
        'default' => true,
        'section' => 'stock_management',
    ],
    'automatic_price_updates' => [
        'label'   => 'Automatic Price Updates',
        'type'    => 'checkbox',
        'default' => true,
        'section' => 'stock_management',
    ],
    'include_out_of_stock' => [
        'label'   => 'Include Out of Stock Inventory?',
        'type'    => 'checkbox',
        'default' => true,
        'section' => 'stock_management',
    ],
    'refresh_listings' => [
        'label'   => 'Refresh Listings',
        'type'    => 'checkbox',
        'default' => true,
        'section' => 'stock_management',
    ],

    // Misc
    'currency' => [
        'label'   => 'Currency',
        'type'    => 'select',
        'default' => 'GBP',
        'section' => 'misc',
        'options' => [
            'GBP' => 'British Pound',
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'AUD' => 'Australian Dollar',
        ],
    ],
    'calculate_shipping_tax' => [
        'label'   => 'Calculate Shipping Tax',
        'type'    => 'checkbox',
        'default' => false,
        'section' => 'misc',
    ],
    'channel_percentage_fee' => [
        'label'   => 'Channel Percentage Fee',
        'type'    => 'text',
        'default' => '',
        'section' => 'misc',
    ],
    'deduct_discounts_from_channel' => [
        'label'   => 'Deduct discounts from channel',
        'type'    => 'checkbox',
        'default' => true,
        'section' => 'misc',
    ],
];
