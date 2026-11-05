<?php
declare(strict_types=1);

return [
    // =========================================================================
    // Entry points
    // =========================================================================
    'storefeeder/admin' => [
        'controller' => 'storefeeder/admin/home',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],

    // =========================================================================
    // Products
    // =========================================================================
    'storefeeder/admin/products' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/products/add' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/admin/products/edit' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/delete' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/archive' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'archive',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/archive-variant' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'archiveVariant',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/export' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'export',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/products/import' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'import',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/admin/products/push' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'push',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/sync-inventory' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'syncInventory',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/update-listings' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'updateListings',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/bulk-listings' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'bulkListings',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/add-bundle-component' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'addBundleComponent',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/delete-bundle-component' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'deleteBundleComponent',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/update-bundle-component' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'updateBundleComponent',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/update-kit-settings' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'updateKitSettings',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/upload-document' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'uploadDocument',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/download-document' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'downloadDocument',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/delete-document' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'deleteDocument',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/products/bulk' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'bulk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/products/clear' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'clear',
        'auth'       => true,
        'methods'    => ['POST'],
        'permission' => 'storefeeder.admin.products.delete',
    ],
    'storefeeder/admin/products/save-filter' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'saveFilter',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/products/delete-filter' => [
        'controller' => 'storefeeder/admin/products',
        'action'     => 'deleteFilter',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // Inventory
    // =========================================================================
    'storefeeder/admin/inventory' => [
        'controller' => 'storefeeder/admin/inventory',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/inventory/product' => [
        'controller' => 'storefeeder/admin/inventory',
        'action'     => 'product',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/admin/inventory/adjust' => [
        'controller' => 'storefeeder/admin/inventory',
        'action'     => 'adjust',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/inventory/low' => [
        'controller' => 'storefeeder/admin/inventory',
        'action'     => 'low',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/inventory/create-po' => [
        'controller' => 'storefeeder/admin/inventory',
        'action'     => 'createPo',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/inventory/bulk' => [
        'controller' => 'storefeeder/admin/inventory',
        'action'     => 'bulk',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/admin/inventory/planner' => [
        'controller' => 'storefeeder/admin/inventory_planner',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/inventory/planner/raise' => [
        'controller' => 'storefeeder/admin/inventory_planner',
        'action'     => 'raise',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // Attributes
    // =========================================================================
    'storefeeder/admin/attributes' => [
        'controller' => 'storefeeder/admin/attributes',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/attributes/add' => [
        'controller' => 'storefeeder/admin/attributes',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/attributes/setup' => [
        'controller' => 'storefeeder/admin/attributes',
        'action'     => 'setup',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/attributes/delete' => [
        'controller' => 'storefeeder/admin/attributes',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/attributes/bulk-delete' => [
        'controller' => 'storefeeder/admin/attributes',
        'action'     => 'bulkDelete',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/attributes/cleanup' => [
        'controller' => 'storefeeder/admin/attributes',
        'action'     => 'cleanup',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/attributes/value/add' => [
        'controller' => 'storefeeder/admin/attributes',
        'action'     => 'valueAdd',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/attributes/value/quick' => [
        'controller' => 'storefeeder/admin/attributes',
        'action'     => 'valueQuick',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/attributes/quick' => [
        'controller' => 'storefeeder/admin/attributes',
        'action'     => 'quick',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/attributes/value/delete' => [
        'controller' => 'storefeeder/admin/attributes',
        'action'     => 'valueDelete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Categories
    // =========================================================================
    // =========================================================================
    // Brands
    // =========================================================================
    'storefeeder/admin/brands' => [
        'controller' => 'storefeeder/admin/brands',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/brands/add' => [
        'controller' => 'storefeeder/admin/brands',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/brands/edit' => [
        'controller' => 'storefeeder/admin/brands',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/brands/delete' => [
        'controller' => 'storefeeder/admin/brands',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Suppliers
    // =========================================================================
    'storefeeder/admin/suppliers' => [
        'controller' => 'storefeeder/admin/suppliers',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/suppliers/add' => [
        'controller' => 'storefeeder/admin/suppliers',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/suppliers/edit' => [
        'controller' => 'storefeeder/admin/suppliers',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/suppliers/delete' => [
        'controller' => 'storefeeder/admin/suppliers',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Purchase orders
    // =========================================================================
    'storefeeder/admin/purchase-orders' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/purchase-orders/add' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/admin/purchase-orders/view' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/update' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'update',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/add-line' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'addLine',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/delete-line' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'deleteLine',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id', 'line_id'],
    ],
    'storefeeder/admin/purchase-orders/confirm' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'confirm',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/cancel' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'cancel',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/delete' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/receive-line' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'receiveLine',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id', 'line_id'],
    ],
    'storefeeder/admin/purchase-orders/receive-all' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'receiveAll',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/book-delivery' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'bookDelivery',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/book-delivery-confirm' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'bookDeliveryConfirm',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/split' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'split',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/book-in-bulk' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'bookInBulk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/purchase-orders/ad-hoc' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'adHoc',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/purchase-orders/goods-received' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'goodsReceived',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/booking-sheet' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'bookingSheet',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/update-expected' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'updateExpected',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/purchase-orders/update-money' => [
        'controller' => 'storefeeder/admin/purchase_orders',
        'action'     => 'updateMoney',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Returns
    // =========================================================================
    'storefeeder/admin/returns' => [
        'controller' => 'storefeeder/admin/returns',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/returns/view' => [
        'controller' => 'storefeeder/admin/returns',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/returns/create' => [
        'controller' => 'storefeeder/admin/returns',
        'action'     => 'create',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['order_id'],
    ],
    'storefeeder/admin/returns/update-status' => [
        'controller' => 'storefeeder/admin/returns',
        'action'     => 'updateStatus',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Tenants (multi-tenant)
    // =========================================================================
    'storefeeder/admin/tenants' => [
        'controller' => 'storefeeder/admin/tenants',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/tenants/create' => [
        'controller' => 'storefeeder/admin/tenants',
        'action'     => 'create',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/tenants/status' => [
        'controller' => 'storefeeder/admin/tenants',
        'action'     => 'status',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/tenants/select' => [
        'controller' => 'storefeeder/admin/tenants',
        'action'     => 'select',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/tenants/create-token' => [
        'controller' => 'storefeeder/admin/tenants',
        'action'     => 'createToken',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/tenants/revoke-token' => [
        'controller' => 'storefeeder/admin/tenants',
        'action'     => 'revokeToken',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/tenants/add-user' => [
        'controller' => 'storefeeder/admin/tenants',
        'action'     => 'addUser',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/tenants/set-owner' => [
        'controller' => 'storefeeder/admin/tenants',
        'action'     => 'setOwner',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/tenants/remove-user' => [
        'controller' => 'storefeeder/admin/tenants',
        'action'     => 'removeUser',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/channels/select-tenant' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'selectTenant',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // Customers
    // =========================================================================
    'storefeeder/admin/customers' => [
        'controller' => 'storefeeder/admin/customers',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/customers/add' => [
        'controller' => 'storefeeder/admin/customers',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/customers/edit' => [
        'controller' => 'storefeeder/admin/customers',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/customers/delete' => [
        'controller' => 'storefeeder/admin/customers',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/customers/bulk' => [
        'controller' => 'storefeeder/admin/customers',
        'action'     => 'bulk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/customers/export' => [
        'controller' => 'storefeeder/admin/customers',
        'action'     => 'export',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/customers/sync' => [
        'controller' => 'storefeeder/admin/customers',
        'action'     => 'sync',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // Warehouses
    // =========================================================================
    'storefeeder/admin/warehouses' => [
        'controller' => 'storefeeder/admin/warehouses',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/warehouses/add' => [
        'controller' => 'storefeeder/admin/warehouses',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/warehouses/edit' => [
        'controller' => 'storefeeder/admin/warehouses',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/warehouses/delete' => [
        'controller' => 'storefeeder/admin/warehouses',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Shipping Configuration
    // =========================================================================
    'storefeeder/admin/shipping-methods' => [
        'controller' => 'storefeeder/admin/shipping_methods',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/shipping-methods/new' => [
        'controller' => 'storefeeder/admin/shipping_methods',
        'action'     => 'new',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/shipping-methods/edit' => [
        'controller' => 'storefeeder/admin/shipping_methods',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/shipping-methods/save' => [
        'controller' => 'storefeeder/admin/shipping_methods',
        'action'     => 'save',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/shipping-methods/default' => [
        'controller' => 'storefeeder/admin/shipping_methods',
        'action'     => 'default',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/shipping-methods/delete' => [
        'controller' => 'storefeeder/admin/shipping_methods',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    'storefeeder/admin/shipping-regions' => [
        'controller' => 'storefeeder/admin/shipping_regions',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/shipping-regions/new' => [
        'controller' => 'storefeeder/admin/shipping_regions',
        'action'     => 'new',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/shipping-regions/edit' => [
        'controller' => 'storefeeder/admin/shipping_regions',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/shipping-regions/save' => [
        'controller' => 'storefeeder/admin/shipping_regions',
        'action'     => 'save',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/shipping-regions/delete' => [
        'controller' => 'storefeeder/admin/shipping_regions',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    'storefeeder/admin/shipping-rules' => [
        'controller' => 'storefeeder/admin/shipping_rules',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/shipping-rules/new' => [
        'controller' => 'storefeeder/admin/shipping_rules',
        'action'     => 'new',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/shipping-rules/edit' => [
        'controller' => 'storefeeder/admin/shipping_rules',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/shipping-rules/save' => [
        'controller' => 'storefeeder/admin/shipping_rules',
        'action'     => 'save',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/shipping-rules/delete' => [
        'controller' => 'storefeeder/admin/shipping_rules',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/shipping-rules/tester' => [
        'controller' => 'storefeeder/admin/shipping_rules',
        'action'     => 'tester',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/admin/shipping-rules/import' => [
        'controller' => 'storefeeder/admin/shipping_rules',
        'action'     => 'import',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],

    'storefeeder/admin/packaging-sizes' => [
        'controller' => 'storefeeder/admin/packaging_sizes',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/packaging-sizes/new' => [
        'controller' => 'storefeeder/admin/packaging_sizes',
        'action'     => 'new',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/packaging-sizes/create' => [
        'controller' => 'storefeeder/admin/packaging_sizes',
        'action'     => 'create',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/packaging-sizes/save' => [
        'controller' => 'storefeeder/admin/packaging_sizes',
        'action'     => 'save',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/packaging-sizes/delete' => [
        'controller' => 'storefeeder/admin/packaging_sizes',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    'storefeeder/admin/categories' => [
        'controller' => 'storefeeder/admin/categories',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/categories/add' => [
        'controller' => 'storefeeder/admin/categories',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/admin/categories/edit' => [
        'controller' => 'storefeeder/admin/categories',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/categories/delete' => [
        'controller' => 'storefeeder/admin/categories',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Channels
    // =========================================================================
    'storefeeder/admin/channels' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/channels/add' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/admin/channels/edit' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/channels/delete' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/channels/toggle' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'toggle',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // OAuth connect — GET with id in the URL
    'storefeeder/admin/channels/connect' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'connect',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],

    // OAuth callback from the provider is a GET (browser redirect)
    'storefeeder/admin/channels/callback' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'callback',
        'auth'       => true,
        'methods'    => ['GET'],
    ],

    // Test connection
    'storefeeder/admin/channels/test' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'test',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Disconnect (clear OAuth tokens)
    'storefeeder/admin/channels/disconnect' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'disconnect',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Sync one channel
    'storefeeder/admin/channels/sync' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'sync',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Sync all enabled channels
    'storefeeder/admin/channels/sync-all' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'syncAll',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // Refresh eBay business policies + locations cache
    'storefeeder/admin/channels/refresh-policies' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'refreshPolicies',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // eBay category suggestions (JSON)
    'storefeeder/admin/channels/product-attributes' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'productAttributes',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/channels/category-suggest' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'categorySuggest',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/channels/category-aspects' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'categoryAspects',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],

    // Refresh eBay store categories cache
    'storefeeder/admin/channels/refresh-store-categories' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'refreshStoreCategories',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Refresh / relist channel listings
    'storefeeder/admin/channels/import-listings' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'importListings',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/channels/refresh-listings' => [
        'controller' => 'storefeeder/admin/channels',
        'action'     => 'refreshListings',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Print invoice for an order
    'storefeeder/admin/orders/invoice' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'invoice',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],

    // Listing templates (per channel)
    'storefeeder/admin/listing-templates' => [
        'controller' => 'storefeeder/admin/listing_templates',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['channel_id'],
    ],
    'storefeeder/admin/listing-templates/edit' => [
        'controller' => 'storefeeder/admin/listing_templates',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['channel_id'],
    ],
    'storefeeder/admin/listing-templates/delete' => [
        'controller' => 'storefeeder/admin/listing_templates',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id', 'channel_id'],
    ],
    'storefeeder/admin/listing-templates/set-default' => [
        'controller' => 'storefeeder/admin/listing_templates',
        'action'     => 'setDefault',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id', 'channel_id'],
    ],

    // =========================================================================
    // Messages (Conversations-first; Mail is secondary)
    // =========================================================================
    'storefeeder/admin/messages' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/messages/mail' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'mail',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/messages/view' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/messages/sync' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'sync',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/messages/fetch-body' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'fetchBody',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/messages/debug' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'debug',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/messages/conversations' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'conversations',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/messages/conversations/sync' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'syncConversations',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/messages/conversation' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'conversation',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/messages/conversation/reply' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'reply',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/messages/conversation/refresh' => [
        'controller' => 'storefeeder/admin/messages',
        'action'     => 'refreshConversation',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // eBay Support Cases (Post-Order)
    // =========================================================================
    'storefeeder/admin/cases' => [
        'controller' => 'storefeeder/admin/cases',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/cases/sync' => [
        'controller' => 'storefeeder/admin/cases',
        'action'     => 'sync',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/cases/view' => [
        'controller' => 'storefeeder/admin/cases',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/cases/refresh' => [
        'controller' => 'storefeeder/admin/cases',
        'action'     => 'refresh',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/cases/reply' => [
        'controller' => 'storefeeder/admin/cases',
        'action'     => 'reply',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/cases/action' => [
        'controller' => 'storefeeder/admin/cases',
        'action'     => 'action',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // Orders
    // =========================================================================
    'storefeeder/admin/orders' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/orders/new' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'new',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/admin/orders/bulk' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'bulk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/orders/save-filter' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'saveFilter',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/orders/delete-filter' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'deleteFilter',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/orders/view' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/orders/update' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'update',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/orders/clone' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'clone',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/orders/delete' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/orders/sync' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'sync',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/orders/ship' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'ship',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/orders/message' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'message',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/orders/label' => [
        'controller' => 'storefeeder/admin/orders',
        'action'     => 'label',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Pickwaves
    // =========================================================================
    'storefeeder/admin/pickwaves' => [
        'controller' => 'storefeeder/admin/pickwaves',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'storefeeder/admin/pickwaves/create' => [
        'controller' => 'storefeeder/admin/pickwaves',
        'action'     => 'create',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'storefeeder/admin/pickwaves/view' => [
        'controller' => 'storefeeder/admin/pickwaves',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/pickwaves/picking-list' => [
        'controller' => 'storefeeder/admin/pickwaves',
        'action'     => 'pickingList',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/pickwaves/packing-list' => [
        'controller' => 'storefeeder/admin/pickwaves',
        'action'     => 'packingList',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/pickwaves/despatch' => [
        'controller' => 'storefeeder/admin/pickwaves',
        'action'     => 'despatch',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/pickwaves/update' => [
        'controller' => 'storefeeder/admin/pickwaves',
        'action'     => 'update',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/pickwaves/labels' => [
        'controller' => 'storefeeder/admin/pickwaves',
        'action'     => 'labels',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'storefeeder/admin/pickwaves/remove-order' => [
        'controller' => 'storefeeder/admin/pickwaves',
        'action'     => 'removeOrder',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Admin status / tester for marketplace account deletion
    'storefeeder/admin/account-deletion' => [
        'controller' => 'storefeeder/admin/account_deletion',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/admin/account-deletion/generate-token' => [
        'controller' => 'storefeeder/admin/account_deletion',
        'action'     => 'generateToken',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // Public eBay webhook (no auth, signed by eBay — no session CSRF)
    'storefeeder/ebay/account-deletion' => [
        'controller' => 'storefeeder/admin/account_deletion',
        'action'     => 'handle',
        'auth'       => false,
        'csrf'       => false,
        'methods'    => ['GET', 'POST'],
    ],
    'storefeeder/ebay/account-deletion/notify' => [
        'controller' => 'storefeeder/admin/account_deletion',
        'action'     => 'notify',
        'auth'       => false,
        'csrf'       => false,
        'methods'    => ['POST'],
    ],
];