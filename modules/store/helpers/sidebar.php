<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'store' => [
        'section' => 'modules',
        'label'   => 'Store',
        'icon'    => 'box-seam',
        'items'   => [
            ['label' => 'Dashboard', 'url' => $url->to('store/admin'), 'icon' => 'speedometer2', 'active' => 'store/admin', 'exact' => true],

            ['header' => 'Orders & fulfilment'],
            [
                'label' => 'Orders',
                'icon'  => 'receipt',
                'active'=> 'store/admin/orders',
                'items' => [
                    ['label' => 'All orders', 'url' => $url->to('store/admin/orders'), 'icon' => 'list-ul', 'active' => 'store/admin/orders', 'exact' => true],
                    ['label' => 'Ship-by queue', 'url' => $url->to('store/admin/orders/ship-by'), 'icon' => 'calendar-x', 'active' => 'store/admin/orders/ship-by'],
                    ['label' => 'Unmapped SKUs', 'url' => $url->to('store/admin/orders/unmapped'), 'icon' => 'link-45deg', 'active' => 'store/admin/orders/unmapped'],
                    ['label' => 'Despatch exceptions', 'url' => $url->to('store/admin/orders/exceptions'), 'icon' => 'exclamation-octagon', 'active' => 'store/admin/orders/exceptions'],
                    ['label' => 'Order rules', 'url' => $url->to('store/admin/order-rules'), 'icon' => 'funnel', 'active' => 'store/admin/order-rules'],
                ],
            ],
            ['label' => 'Picking', 'url' => $url->to('store/admin/pickwaves'), 'icon' => 'clipboard-check', 'active' => 'store/admin/pickwaves'],
            [
                'label' => 'Returns',
                'icon'  => 'arrow-return-left',
                'active'=> 'store/admin/returns',
                'items' => [
                    ['label' => 'All returns', 'url' => $url->to('store/admin/returns'), 'icon' => 'list-ul', 'active' => 'store/admin/returns', 'exact' => true],
                    ['label' => 'Returns inbound', 'url' => $url->to('store/admin/returns/inbound'), 'icon' => 'box-arrow-in-down', 'active' => 'store/admin/returns/inbound'],
                ],
            ],

            ['header' => 'Customer service'],
            ['label' => 'Messages', 'url' => $url->to('store/admin/messages/conversations'), 'icon' => 'chat', 'active' => 'store/admin/messages', 'route' => 'store/admin/messages/conversations'],
            ['label' => 'Support cases', 'url' => $url->to('store/admin/cases'), 'icon' => 'life-preserver', 'active' => 'store/admin/cases'],
            ['label' => 'Overdue hub', 'url' => $url->to('store/admin/overdue'), 'icon' => 'alarm', 'active' => 'store/admin/overdue'],
            ['label' => 'Reply macros', 'url' => $url->to('store/admin/reply-templates'), 'icon' => 'chat-quote', 'active' => 'store/admin/reply-templates'],
            ['label' => 'Customers', 'url' => $url->to('store/admin/customers'), 'icon' => 'people', 'active' => 'store/admin/customers'],

            ['header' => 'Catalog'],
            ['label' => 'Products', 'url' => $url->to('store/admin/products'), 'icon' => 'box', 'active' => 'store/admin/products'],
            ['label' => 'Categories', 'url' => $url->to('store/admin/categories'), 'icon' => 'diagram-3', 'active' => 'store/admin/categories'],
            ['label' => 'Brands', 'url' => $url->to('store/admin/brands'), 'icon' => 'award', 'active' => 'store/admin/brands'],
            ['label' => 'Attributes', 'url' => $url->to('store/admin/attributes'), 'icon' => 'tag', 'active' => 'store/admin/attributes'],
            ['label' => 'Listing health', 'url' => $url->to('store/admin/listing-health'), 'icon' => 'clipboard2-pulse', 'active' => 'store/admin/listing-health'],
            ['label' => 'Category maps', 'url' => $url->to('store/admin/category-maps'), 'icon' => 'diagram-3', 'active' => 'store/admin/category-maps'],

            ['header' => 'Inventory & warehouse'],
            [
                'label' => 'Inventory',
                'icon'  => 'boxes',
                'active'=> 'store/admin/inventory',
                'items' => [
                    ['label' => 'Stock', 'url' => $url->to('store/admin/inventory'), 'icon' => 'boxes', 'active' => 'store/admin/inventory', 'exact' => true],
                    ['label' => 'Planner', 'url' => $url->to('store/admin/inventory/planner'), 'icon' => 'clipboard-data', 'active' => 'store/admin/inventory/planner'],
                    ['label' => 'Bulk adjust', 'url' => $url->to('store/admin/inventory/bulk'), 'icon' => 'sliders', 'active' => 'store/admin/inventory/bulk'],
                    ['label' => 'Missing pick bins', 'url' => $url->to('store/admin/inventory/missing-bins'), 'icon' => 'geo', 'active' => 'store/admin/inventory/missing-bins'],
                    ['label' => 'Warehouse transfer', 'url' => $url->to('store/admin/inventory/transfer'), 'icon' => 'arrow-left-right', 'active' => 'store/admin/inventory/transfer'],
                    ['label' => 'Reserved integrity', 'url' => $url->to('store/admin/inventory/reserved-integrity'), 'icon' => 'shield-exclamation', 'active' => 'store/admin/inventory/reserved-integrity'],
                ],
            ],
            ['label' => 'Stocktakes', 'url' => $url->to('store/admin/stocktakes'), 'icon' => 'clipboard2-check', 'active' => 'store/admin/stocktakes'],
            ['label' => 'Print station', 'url' => $url->to('store/admin/print-station'), 'icon' => 'printer', 'active' => 'store/admin/print-station'],
            ['label' => 'Warehouses', 'url' => $url->to('store/admin/warehouses'), 'icon' => 'building', 'active' => 'store/admin/warehouses'],

            ['header' => 'Purchasing'],
            ['label' => 'Suppliers', 'url' => $url->to('store/admin/suppliers'), 'icon' => 'truck', 'active' => 'store/admin/suppliers'],
            [
                'label' => 'Deliveries',
                'icon'  => 'box-seam',
                'active'=> 'store/admin/purchase-orders',
                'items' => [
                    ['label' => 'Purchase orders', 'url' => $url->to('store/admin/purchase-orders'), 'icon' => 'list-ul', 'active' => 'store/admin/purchase-orders', 'exact' => true],
                    ['label' => 'Inbound calendar', 'url' => $url->to('store/admin/purchase-orders/calendar'), 'icon' => 'calendar3', 'active' => 'store/admin/purchase-orders/calendar'],
                    ['label' => 'Supplier claims', 'url' => $url->to('store/admin/supplier-claims'), 'icon' => 'exclamation-triangle', 'active' => 'store/admin/supplier-claims'],
                ],
            ],

            ['header' => 'Shipping'],
            [
                'label' => 'Shipping configuration',
                'icon'  => 'truck',
                'items' => [
                    [
                        'label'  => 'Shipping methods',
                        'url'    => $url->to('store/admin/shipping-methods'),
                        'icon'   => 'box-seam',
                        'active' => 'store/admin/shipping-methods',
                    ],
                    [
                        'label'  => 'Shipping regions',
                        'url'    => $url->to('store/admin/shipping-regions'),
                        'icon'   => 'geo-alt',
                        'active' => 'store/admin/shipping-regions',
                    ],
                    [
                        'label'  => 'Shipping rules',
                        'icon'   => 'funnel',
                        'active' => 'store/admin/shipping-rules',
                        'items'  => [
                            [
                                'label'  => 'Rules list',
                                'url'    => $url->to('store/admin/shipping-rules'),
                                'icon'   => 'list-ul',
                                'active' => 'store/admin/shipping-rules',
                            ],
                            [
                                'label'  => 'Rules tester',
                                'url'    => $url->to('store/admin/shipping-rules/tester'),
                                'icon'   => 'play',
                                'active' => 'store/admin/shipping-rules/tester',
                            ],
                            [
                                'label'  => 'Rules import',
                                'url'    => $url->to('store/admin/shipping-rules/import'),
                                'icon'   => 'upload',
                                'active' => 'store/admin/shipping-rules/import',
                            ],
                        ],
                    ],
                    [
                        'label'  => 'Packaging sizes',
                        'url'    => $url->to('store/admin/packaging-sizes'),
                        'icon'   => 'bounding-box',
                        'active' => 'store/admin/packaging-sizes',
                    ],
                ],
            ],

            ['header' => 'Analytics'],
            ['label' => 'SKU margin', 'url' => $url->to('store/admin/analytics/sku-margin'), 'icon' => 'graph-up', 'active' => 'store/admin/analytics/sku-margin'],
            ['label' => 'Supplier OTIF', 'url' => $url->to('store/admin/analytics/supplier-otif'), 'icon' => 'truck', 'active' => 'store/admin/analytics/supplier-otif'],
            ['label' => 'Pick / pack hub', 'url' => $url->to('store/admin/analytics/pick-pack'), 'icon' => 'stopwatch', 'active' => 'store/admin/analytics/pick-pack'],

            ['header' => 'Integrations & platform'],
            ['label' => 'Integrations', 'url' => $url->to('store/admin/channels'), 'icon' => 'plug', 'active' => 'store/admin/channels'],
            ['label' => 'Automations', 'url' => $url->to('store/admin/automations'), 'icon' => 'lightning', 'active' => 'store/admin/automations'],
            ['label' => 'Sync health', 'url' => $url->to('store/admin/sync-health'), 'icon' => 'activity', 'active' => 'store/admin/sync-health'],
            [
                'label'  => 'Platform / tenants',
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
        ],
    ],
];
