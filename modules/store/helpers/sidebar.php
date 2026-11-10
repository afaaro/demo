<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'store' => [
        'section' => 'modules',
        'label'   => 'Store',
        'icon'    => 'box-seam',
        'items'   => [
            ['label' => 'Dashboard',   'url' => $url->to('store/admin'),            'icon' => 'speedometer2', 'active' => 'store/admin', 'exact' => true],
            ['label' => 'Orders',      'url' => $url->to('store/admin/orders'),     'icon' => 'receipt',   'active' => 'store/admin/orders', 'exact' => true],
            ['label' => 'Ship-by queue', 'url' => $url->to('store/admin/orders/ship-by'), 'icon' => 'calendar-x', 'active' => 'store/admin/orders/ship-by'],
            ['label' => 'Unmapped SKUs', 'url' => $url->to('store/admin/orders/unmapped'), 'icon' => 'link-45deg', 'active' => 'store/admin/orders/unmapped'],
            ['label' => 'Despatch exceptions', 'url' => $url->to('store/admin/orders/exceptions'), 'icon' => 'exclamation-octagon', 'active' => 'store/admin/orders/exceptions'],
            ['label' => 'Order rules', 'url' => $url->to('store/admin/order-rules'), 'icon' => 'funnel', 'active' => 'store/admin/order-rules'],
            ['label' => 'Returns',     'url' => $url->to('store/admin/returns'),    'icon' => 'arrow-return-left', 'active' => 'store/admin/returns', 'exact' => true],
            ['label' => 'Returns inbound', 'url' => $url->to('store/admin/returns/inbound'), 'icon' => 'box-arrow-in-down', 'active' => 'store/admin/returns/inbound'],
            ['label' => 'Picking',     'url' => $url->to('store/admin/pickwaves'),  'icon' => 'clipboard-check', 'active' => 'store/admin/pickwaves'],
            ['label' => 'Messages',    'url' => $url->to('store/admin/messages/conversations'), 'icon' => 'chat', 'active' => 'store/admin/messages', 'route' => 'store/admin/messages/conversations'],
            ['label' => 'Reply macros', 'url' => $url->to('store/admin/reply-templates'), 'icon' => 'chat-quote', 'active' => 'store/admin/reply-templates'],
            ['label' => 'Support Cases', 'url' => $url->to('store/admin/cases'), 'icon' => 'life-preserver', 'active' => 'store/admin/cases'],
            ['label' => 'Overdue hub', 'url' => $url->to('store/admin/overdue'), 'icon' => 'alarm', 'active' => 'store/admin/overdue'],
            ['divider' => true],
            ['label' => 'Products',    'url' => $url->to('store/admin/products'),   'icon' => 'box',       'active' => 'store/admin/products'],
            ['label' => 'Listing health', 'url' => $url->to('store/admin/listing-health'), 'icon' => 'clipboard2-pulse', 'active' => 'store/admin/listing-health'],
            ['label' => 'SKU margin', 'url' => $url->to('store/admin/analytics/sku-margin'), 'icon' => 'graph-up', 'active' => 'store/admin/analytics/sku-margin'],
            ['label' => 'Supplier OTIF', 'url' => $url->to('store/admin/analytics/supplier-otif'), 'icon' => 'truck', 'active' => 'store/admin/analytics/supplier-otif'],
            ['label' => 'Pick / pack hub', 'url' => $url->to('store/admin/analytics/pick-pack'), 'icon' => 'stopwatch', 'active' => 'store/admin/analytics/pick-pack'],
            ['label' => 'Categories',  'url' => $url->to('store/admin/categories'), 'icon' => 'diagram-3', 'active' => 'store/admin/categories'],
            ['label' => 'Brands',      'url' => $url->to('store/admin/brands'),     'icon' => 'award',     'active' => 'store/admin/brands'],
            ['label' => 'Suppliers',   'url' => $url->to('store/admin/suppliers'),  'icon' => 'truck',     'active' => 'store/admin/suppliers'],
            ['label' => 'Warehouses',  'url' => $url->to('store/admin/warehouses'), 'icon' => 'building',  'active' => 'store/admin/warehouses'],
            ['label' => 'Attributes',  'url' => $url->to('store/admin/attributes'), 'icon' => 'tag',       'active' => 'store/admin/attributes'],
            [
                'label' => 'Shipping Configuration',
                'icon'  => 'truck',
                'items' => [
                    [
                        'label'  => 'Shipping Methods',
                        'url'    => $url->to('store/admin/shipping-methods'),
                        'icon'   => 'box-seam',
                        'active' => 'store/admin/shipping-methods',
                    ],
                    [
                        'label'  => 'Shipping Regions',
                        'url'    => $url->to('store/admin/shipping-regions'),
                        'icon'   => 'geo-alt',
                        'active' => 'store/admin/shipping-regions',
                    ],
                    [
                        'label'  => 'Shipping Rules',
                        'icon'   => 'funnel',
                        'active' => 'store/admin/shipping-rules',
                        'items'  => [
                            [
                                'label'  => 'Shipping Rules',
                                'url'    => $url->to('store/admin/shipping-rules'),
                                'icon'   => 'list-ul',
                                'active' => 'store/admin/shipping-rules',
                            ],
                            [
                                'label'  => 'Shipping Rules Tester',
                                'url'    => $url->to('store/admin/shipping-rules/tester'),
                                'icon'   => 'play',
                                'active' => 'store/admin/shipping-rules/tester',
                            ],
                            [
                                'label'  => 'Shipping Rules Import',
                                'url'    => $url->to('store/admin/shipping-rules/import'),
                                'icon'   => 'upload',
                                'active' => 'store/admin/shipping-rules/import',
                            ],
                        ],
                    ],
                    [
                        'label'  => 'Packaging Sizes',
                        'url'    => $url->to('store/admin/packaging-sizes'),
                        'icon'   => 'bounding-box',
                        'active' => 'store/admin/packaging-sizes',
                    ],
                ],
            ],
            
            ['divider' => true],
            ['label' => 'Inventory',   'url' => $url->to('store/admin/inventory'),  'icon' => 'boxes',     'active' => 'store/admin/inventory', 'exact' => true],
            ['label' => 'Inventory Planner', 'url' => $url->to('store/admin/inventory/planner'), 'icon' => 'clipboard-data', 'active' => 'store/admin/inventory/planner'],
            ['label' => 'Stocktakes', 'url' => $url->to('store/admin/stocktakes'), 'icon' => 'clipboard2-check', 'active' => 'store/admin/stocktakes'],
            ['label' => 'Missing pick bins', 'url' => $url->to('store/admin/inventory/missing-bins'), 'icon' => 'geo', 'active' => 'store/admin/inventory/missing-bins'],
            ['label' => 'Print station', 'url' => $url->to('store/admin/print-station'), 'icon' => 'printer', 'active' => 'store/admin/print-station'],
            ['label' => 'Warehouse transfer', 'url' => $url->to('store/admin/inventory/transfer'), 'icon' => 'arrow-left-right', 'active' => 'store/admin/inventory/transfer'],
            ['label' => 'Reserved integrity', 'url' => $url->to('store/admin/inventory/reserved-integrity'), 'icon' => 'shield-exclamation', 'active' => 'store/admin/inventory/reserved-integrity'],
            ['label' => 'Bulk adjust', 'url' => $url->to('store/admin/inventory/bulk'), 'icon' => 'sliders', 'active' => 'store/admin/inventory/bulk'],
            ['label' => 'Deliveries', 'url' => $url->to('store/admin/purchase-orders'), 'icon' => 'truck', 'active' => 'store/admin/purchase-orders', 'exact' => true],
            ['label' => 'Supplier claims', 'url' => $url->to('store/admin/supplier-claims'), 'icon' => 'exclamation-triangle', 'active' => 'store/admin/supplier-claims'],
            ['label' => 'Inbound calendar', 'url' => $url->to('store/admin/purchase-orders/calendar'), 'icon' => 'calendar3', 'active' => 'store/admin/purchase-orders/calendar'],
            
            ['divider' => true],
            ['label' => 'Integrations','url' => $url->to('store/admin/channels'),   'icon' => 'plug',      'active' => 'store/admin/channels'],
            ['label' => 'Automations', 'url' => $url->to('store/admin/automations'), 'icon' => 'lightning', 'active' => 'store/admin/automations'],
            ['label' => 'Category maps', 'url' => $url->to('store/admin/category-maps'), 'icon' => 'diagram-3', 'active' => 'store/admin/category-maps'],
            ['label' => 'Sync health', 'url' => $url->to('store/admin/sync-health'), 'icon' => 'activity', 'active' => 'store/admin/sync-health'],
            [
                'label'  => 'Platform / Tenants',
                'url'    => $url->to('store/admin/tenants'),
                'icon'   => 'building',
                'active' => 'store/admin/tenants',
            ],
            [
                'label'  => 'Account deletion',
                'url'    => $url->to('store/admin/account-deletion'),
                'icon'   => 'shield-exclamation',
                'active' => 'store/admin/account-deletion',
            ],
            ['label' => 'Customers',   'url' => $url->to('store/admin/customers'),  'icon' => 'people',    'active' => 'store/admin/customers'],
        ],
    ],
];