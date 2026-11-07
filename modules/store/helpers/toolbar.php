<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'store' => [
        [
            'label'      => 'Store',
            'icon'       => 'box-seam',
            'permission' => 'store.view',
            'children'   => [
                ['label' => 'Dashboard',        'url' => $url->to('store/admin'), 'icon' => 'speedometer2', 'route' => 'store/admin'],
                ['label' => 'Orders',           'url' => $url->to('store/admin/orders'), 'icon' => 'receipt', 'route' => 'store/admin/orders'],
                ['label' => 'Picking',          'url' => $url->to('store/admin/pickwaves'),  'icon' => 'clipboard-check', 'route' => 'store/admin/pickwaves'],
                ['label' => 'Messages', 'url' => $url->to('store/admin/messages/conversations'), 'icon' => 'chat', 'route' => 'store/admin/messages/conversations'],
                ['label' => 'Support Cases',    'url' => $url->to('store/admin/cases'), 'icon' => 'life-preserver', 'route' => 'store/admin/cases'],
                ['divider' => true],
                ['label' => 'Products',         'url' => $url->to('store/admin/products'),         'icon' => 'box', 'route' => 'store/admin/products'],
                ['label' => 'Inventory',        'url' => $url->to('store/admin/inventory'),        'icon' => 'boxes', 'route' => 'store/admin/inventory'],
                ['label' => 'Low stock',        'url' => $url->to('store/admin/inventory/low'),    'icon' => 'exclamation-triangle', 'route' => 'store/admin/inventory/low'],
                ['label' => 'Attributes',       'url' => $url->to('store/admin/attributes'), 'icon' => 'tag', 'route' => 'store/admin/attributes'],
                ['label' => 'Categories',       'url' => $url->to('store/admin/categories'), 'icon' => 'diagram-3', 'route' => 'store/admin/categories'],
                ['label' => 'Integrations',     'url' => $url->to('store/admin/channels'), 'icon' => 'plug', 'route' => 'store/admin/channels'],
            ],
        ],
    ],
];