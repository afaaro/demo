<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'storefeeder' => [
        [
            'label'      => 'StoreFeeder',
            'icon'       => 'box-seam',
            'permission' => 'storefeeder.view',
            'children'   => [
                ['label' => 'Dashboard',        'url' => $url->to('storefeeder/admin'), 'icon' => 'speedometer2', 'route' => 'storefeeder/admin'],
                ['label' => 'Orders',           'url' => $url->to('storefeeder/admin/orders'), 'icon' => 'receipt', 'route' => 'storefeeder/admin/orders'],
                ['label' => 'Picking',          'url' => $url->to('storefeeder/admin/pickwaves'),  'icon' => 'clipboard-check', 'route' => 'storefeeder/admin/pickwaves'],
                ['label' => 'Messages', 'url' => $url->to('storefeeder/admin/messages/conversations'), 'icon' => 'chat', 'route' => 'storefeeder/admin/messages/conversations'],
                ['label' => 'Support Cases',    'url' => $url->to('storefeeder/admin/cases'), 'icon' => 'life-preserver', 'route' => 'storefeeder/admin/cases'],
                ['divider' => true],
                ['label' => 'Products',         'url' => $url->to('storefeeder/admin/products'),         'icon' => 'box', 'route' => 'storefeeder/admin/products'],
                ['label' => 'Inventory',        'url' => $url->to('storefeeder/admin/inventory'),        'icon' => 'boxes', 'route' => 'storefeeder/admin/inventory'],
                ['label' => 'Low stock',        'url' => $url->to('storefeeder/admin/inventory/low'),    'icon' => 'exclamation-triangle', 'route' => 'storefeeder/admin/inventory/low'],
                ['label' => 'Attributes',       'url' => $url->to('storefeeder/admin/attributes'), 'icon' => 'tag', 'route' => 'storefeeder/admin/attributes'],
                ['label' => 'Categories',       'url' => $url->to('storefeeder/admin/categories'), 'icon' => 'diagram-3', 'route' => 'storefeeder/admin/categories'],
                ['label' => 'Integrations',     'url' => $url->to('storefeeder/admin/channels'), 'icon' => 'plug', 'route' => 'storefeeder/admin/channels'],
            ],
        ],
    ],
];