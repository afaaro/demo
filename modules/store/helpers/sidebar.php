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
            ['label' => 'Orders',      'url' => $url->to('store/admin/orders'),     'icon' => 'receipt',   'active' => 'store/admin/orders'],
            ['label' => 'Returns',     'url' => $url->to('store/admin/returns'),    'icon' => 'arrow-return-left', 'active' => 'store/admin/returns'],
            ['label' => 'Picking',     'url' => $url->to('store/admin/pickwaves'),  'icon' => 'clipboard-check', 'active' => 'store/admin/pickwaves'],
            ['label' => 'Messages',    'url' => $url->to('store/admin/messages/conversations'), 'icon' => 'chat', 'active' => 'store/admin/messages', 'route' => 'store/admin/messages/conversations'],
            ['label' => 'Support Cases', 'url' => $url->to('store/admin/cases'), 'icon' => 'life-preserver', 'active' => 'store/admin/cases'],
            ['divider' => true],
            ['label' => 'Products',    'url' => $url->to('store/admin/products'),   'icon' => 'box',       'active' => 'store/admin/products'],
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
            ['label' => 'Bulk adjust', 'url' => $url->to('store/admin/inventory/bulk'), 'icon' => 'sliders', 'active' => 'store/admin/inventory/bulk'],
            ['label' => 'Deliveries', 'url' => $url->to('store/admin/purchase-orders'), 'icon' => 'truck', 'active' => 'store/admin/purchase-orders'],
            
            ['divider' => true],
            ['label' => 'Integrations','url' => $url->to('store/admin/channels'),   'icon' => 'plug',      'active' => 'store/admin/channels'],
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