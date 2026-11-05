<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'storefeeder' => [
        'section' => 'modules',
        'label'   => 'StoreFeeder',
        'icon'    => 'box-seam',
        'items'   => [
            ['label' => 'Dashboard',   'url' => $url->to('storefeeder/admin'),            'icon' => 'speedometer2', 'active' => 'storefeeder/admin', 'exact' => true],
            ['label' => 'Orders',      'url' => $url->to('storefeeder/admin/orders'),     'icon' => 'receipt',   'active' => 'storefeeder/admin/orders'],
            ['label' => 'Returns',     'url' => $url->to('storefeeder/admin/returns'),    'icon' => 'arrow-return-left', 'active' => 'storefeeder/admin/returns'],
            ['label' => 'Picking',     'url' => $url->to('storefeeder/admin/pickwaves'),  'icon' => 'clipboard-check', 'active' => 'storefeeder/admin/pickwaves'],
            ['label' => 'Messages',    'url' => $url->to('storefeeder/admin/messages/conversations'), 'icon' => 'chat', 'active' => 'storefeeder/admin/messages', 'route' => 'storefeeder/admin/messages/conversations'],
            ['label' => 'Support Cases', 'url' => $url->to('storefeeder/admin/cases'), 'icon' => 'life-preserver', 'active' => 'storefeeder/admin/cases'],
            ['divider' => true],
            ['label' => 'Products',    'url' => $url->to('storefeeder/admin/products'),   'icon' => 'box',       'active' => 'storefeeder/admin/products'],
            ['label' => 'Categories',  'url' => $url->to('storefeeder/admin/categories'), 'icon' => 'diagram-3', 'active' => 'storefeeder/admin/categories'],
            ['label' => 'Brands',      'url' => $url->to('storefeeder/admin/brands'),     'icon' => 'award',     'active' => 'storefeeder/admin/brands'],
            ['label' => 'Suppliers',   'url' => $url->to('storefeeder/admin/suppliers'),  'icon' => 'truck',     'active' => 'storefeeder/admin/suppliers'],
            ['label' => 'Warehouses',  'url' => $url->to('storefeeder/admin/warehouses'), 'icon' => 'building',  'active' => 'storefeeder/admin/warehouses'],
            ['label' => 'Attributes',  'url' => $url->to('storefeeder/admin/attributes'), 'icon' => 'tag',       'active' => 'storefeeder/admin/attributes'],
            [
                'label' => 'Shipping Configuration',
                'icon'  => 'truck',
                'items' => [
                    [
                        'label'  => 'Shipping Methods',
                        'url'    => $url->to('storefeeder/admin/shipping-methods'),
                        'icon'   => 'box-seam',
                        'active' => 'storefeeder/admin/shipping-methods',
                    ],
                    [
                        'label'  => 'Shipping Regions',
                        'url'    => $url->to('storefeeder/admin/shipping-regions'),
                        'icon'   => 'geo-alt',
                        'active' => 'storefeeder/admin/shipping-regions',
                    ],
                    [
                        'label'  => 'Shipping Rules',
                        'icon'   => 'funnel',
                        'active' => 'storefeeder/admin/shipping-rules',
                        'items'  => [
                            [
                                'label'  => 'Shipping Rules',
                                'url'    => $url->to('storefeeder/admin/shipping-rules'),
                                'icon'   => 'list-ul',
                                'active' => 'storefeeder/admin/shipping-rules',
                            ],
                            [
                                'label'  => 'Shipping Rules Tester',
                                'url'    => $url->to('storefeeder/admin/shipping-rules/tester'),
                                'icon'   => 'play',
                                'active' => 'storefeeder/admin/shipping-rules/tester',
                            ],
                            [
                                'label'  => 'Shipping Rules Import',
                                'url'    => $url->to('storefeeder/admin/shipping-rules/import'),
                                'icon'   => 'upload',
                                'active' => 'storefeeder/admin/shipping-rules/import',
                            ],
                        ],
                    ],
                    [
                        'label'  => 'Packaging Sizes',
                        'url'    => $url->to('storefeeder/admin/packaging-sizes'),
                        'icon'   => 'bounding-box',
                        'active' => 'storefeeder/admin/packaging-sizes',
                    ],
                ],
            ],
            
            ['divider' => true],
            ['label' => 'Inventory',   'url' => $url->to('storefeeder/admin/inventory'),  'icon' => 'boxes',     'active' => 'storefeeder/admin/inventory', 'exact' => true],
            ['label' => 'Inventory Planner', 'url' => $url->to('storefeeder/admin/inventory/planner'), 'icon' => 'clipboard-data', 'active' => 'storefeeder/admin/inventory/planner'],
            ['label' => 'Bulk adjust', 'url' => $url->to('storefeeder/admin/inventory/bulk'), 'icon' => 'sliders', 'active' => 'storefeeder/admin/inventory/bulk'],
            ['label' => 'Deliveries', 'url' => $url->to('storefeeder/admin/purchase-orders'), 'icon' => 'truck', 'active' => 'storefeeder/admin/purchase-orders'],
            
            ['divider' => true],
            ['label' => 'Integrations','url' => $url->to('storefeeder/admin/channels'),   'icon' => 'plug',      'active' => 'storefeeder/admin/channels'],
            [
                'label'  => 'Platform / Tenants',
                'url'    => $url->to('storefeeder/admin/tenants'),
                'icon'   => 'building',
                'active' => 'storefeeder/admin/tenants',
            ],
            [
                'label'  => 'Account deletion',
                'url'    => $url->to('storefeeder/admin/account-deletion'),
                'icon'   => 'shield-exclamation',
                'active' => 'storefeeder/admin/account-deletion',
            ],
            ['label' => 'Customers',   'url' => $url->to('storefeeder/admin/customers'),  'icon' => 'people',    'active' => 'storefeeder/admin/customers'],
        ],
    ],
];