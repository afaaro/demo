<?php
declare(strict_types=1);

return [
    // =========================================================================
    // Entry points
    // =========================================================================
    'store/admin' => [
        'controller' => 'store/admin/home',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],

    // =========================================================================
    // Products
    // =========================================================================
    'store/admin/products' => [
        'controller' => 'store/admin/products',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/products/add' => [
        'controller' => 'store/admin/products',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'store/admin/products/edit' => [
        'controller' => 'store/admin/products',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/delete' => [
        'controller' => 'store/admin/products',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/archive' => [
        'controller' => 'store/admin/products',
        'action'     => 'archive',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/archive-variant' => [
        'controller' => 'store/admin/products',
        'action'     => 'archiveVariant',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/export' => [
        'controller' => 'store/admin/products',
        'action'     => 'export',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/products/import' => [
        'controller' => 'store/admin/products',
        'action'     => 'import',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'store/admin/products/push' => [
        'controller' => 'store/admin/products',
        'action'     => 'push',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/sync-inventory' => [
        'controller' => 'store/admin/products',
        'action'     => 'syncInventory',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/update-listings' => [
        'controller' => 'store/admin/products',
        'action'     => 'updateListings',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/bulk-listings' => [
        'controller' => 'store/admin/products',
        'action'     => 'bulkListings',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/add-bundle-component' => [
        'controller' => 'store/admin/products',
        'action'     => 'addBundleComponent',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/delete-bundle-component' => [
        'controller' => 'store/admin/products',
        'action'     => 'deleteBundleComponent',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/update-bundle-component' => [
        'controller' => 'store/admin/products',
        'action'     => 'updateBundleComponent',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/update-kit-settings' => [
        'controller' => 'store/admin/products',
        'action'     => 'updateKitSettings',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/upload-document' => [
        'controller' => 'store/admin/products',
        'action'     => 'uploadDocument',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/download-document' => [
        'controller' => 'store/admin/products',
        'action'     => 'downloadDocument',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/products/delete-document' => [
        'controller' => 'store/admin/products',
        'action'     => 'deleteDocument',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/products/bulk' => [
        'controller' => 'store/admin/products',
        'action'     => 'bulk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/products/clear' => [
        'controller' => 'store/admin/products',
        'action'     => 'clear',
        'auth'       => true,
        'methods'    => ['POST'],
        'permission' => 'store.admin.products.delete',
    ],
    'store/admin/products/save-filter' => [
        'controller' => 'store/admin/products',
        'action'     => 'saveFilter',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/products/delete-filter' => [
        'controller' => 'store/admin/products',
        'action'     => 'deleteFilter',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // Inventory
    // =========================================================================
    'store/admin/inventory' => [
        'controller' => 'store/admin/inventory',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/inventory/product' => [
        'controller' => 'store/admin/inventory',
        'action'     => 'product',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'store/admin/inventory/adjust' => [
        'controller' => 'store/admin/inventory',
        'action'     => 'adjust',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'store/admin/inventory/low' => [
        'controller' => 'store/admin/inventory',
        'action'     => 'low',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/inventory/create-po' => [
        'controller' => 'store/admin/inventory',
        'action'     => 'createPo',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/inventory/bulk' => [
        'controller' => 'store/admin/inventory',
        'action'     => 'bulk',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'store/admin/inventory/planner' => [
        'controller' => 'store/admin/inventory_planner',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/inventory/planner/raise' => [
        'controller' => 'store/admin/inventory_planner',
        'action'     => 'raise',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // Attributes
    // =========================================================================
    'store/admin/attributes' => [
        'controller' => 'store/admin/attributes',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/attributes/add' => [
        'controller' => 'store/admin/attributes',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/attributes/setup' => [
        'controller' => 'store/admin/attributes',
        'action'     => 'setup',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/attributes/delete' => [
        'controller' => 'store/admin/attributes',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/attributes/bulk-delete' => [
        'controller' => 'store/admin/attributes',
        'action'     => 'bulkDelete',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/attributes/cleanup' => [
        'controller' => 'store/admin/attributes',
        'action'     => 'cleanup',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/attributes/value/add' => [
        'controller' => 'store/admin/attributes',
        'action'     => 'valueAdd',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/attributes/value/quick' => [
        'controller' => 'store/admin/attributes',
        'action'     => 'valueQuick',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/attributes/quick' => [
        'controller' => 'store/admin/attributes',
        'action'     => 'quick',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/attributes/value/delete' => [
        'controller' => 'store/admin/attributes',
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
    'store/admin/brands' => [
        'controller' => 'store/admin/brands',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/brands/add' => [
        'controller' => 'store/admin/brands',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/brands/edit' => [
        'controller' => 'store/admin/brands',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'store/admin/brands/delete' => [
        'controller' => 'store/admin/brands',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Suppliers
    // =========================================================================
    'store/admin/suppliers' => [
        'controller' => 'store/admin/suppliers',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/suppliers/add' => [
        'controller' => 'store/admin/suppliers',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/suppliers/edit' => [
        'controller' => 'store/admin/suppliers',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'store/admin/suppliers/delete' => [
        'controller' => 'store/admin/suppliers',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Purchase orders
    // =========================================================================
    'store/admin/purchase-orders' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/purchase-orders/add' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'store/admin/purchase-orders/view' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/update' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'update',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/add-line' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'addLine',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/delete-line' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'deleteLine',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id', 'line_id'],
    ],
    'store/admin/purchase-orders/confirm' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'confirm',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/cancel' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'cancel',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/delete' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/receive-line' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'receiveLine',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id', 'line_id'],
    ],
    'store/admin/purchase-orders/receive-all' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'receiveAll',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/book-delivery' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'bookDelivery',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/book-delivery-confirm' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'bookDeliveryConfirm',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/split' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'split',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/book-in-bulk' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'bookInBulk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/purchase-orders/ad-hoc' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'adHoc',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/purchase-orders/goods-received' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'goodsReceived',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/booking-sheet' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'bookingSheet',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/update-expected' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'updateExpected',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/purchase-orders/update-money' => [
        'controller' => 'store/admin/purchase_orders',
        'action'     => 'updateMoney',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Returns
    // =========================================================================
    'store/admin/returns' => [
        'controller' => 'store/admin/returns',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/returns/view' => [
        'controller' => 'store/admin/returns',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/returns/create' => [
        'controller' => 'store/admin/returns',
        'action'     => 'create',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['order_id'],
    ],
    'store/admin/returns/update-status' => [
        'controller' => 'store/admin/returns',
        'action'     => 'updateStatus',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Tenants (multi-tenant)
    // =========================================================================
    'store/admin/tenants' => [
        'controller' => 'store/admin/tenants',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/tenants/create' => [
        'controller' => 'store/admin/tenants',
        'action'     => 'create',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/tenants/status' => [
        'controller' => 'store/admin/tenants',
        'action'     => 'status',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/tenants/select' => [
        'controller' => 'store/admin/tenants',
        'action'     => 'select',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/tenants/create-token' => [
        'controller' => 'store/admin/tenants',
        'action'     => 'createToken',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/tenants/revoke-token' => [
        'controller' => 'store/admin/tenants',
        'action'     => 'revokeToken',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/tenants/add-user' => [
        'controller' => 'store/admin/tenants',
        'action'     => 'addUser',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/tenants/set-owner' => [
        'controller' => 'store/admin/tenants',
        'action'     => 'setOwner',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/tenants/remove-user' => [
        'controller' => 'store/admin/tenants',
        'action'     => 'removeUser',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/channels/select-tenant' => [
        'controller' => 'store/admin/channels',
        'action'     => 'selectTenant',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // Customers
    // =========================================================================
    'store/admin/customers' => [
        'controller' => 'store/admin/customers',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/customers/add' => [
        'controller' => 'store/admin/customers',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/customers/edit' => [
        'controller' => 'store/admin/customers',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'store/admin/customers/delete' => [
        'controller' => 'store/admin/customers',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/customers/bulk' => [
        'controller' => 'store/admin/customers',
        'action'     => 'bulk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/customers/export' => [
        'controller' => 'store/admin/customers',
        'action'     => 'export',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/customers/sync' => [
        'controller' => 'store/admin/customers',
        'action'     => 'sync',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // Warehouses
    // =========================================================================
    'store/admin/warehouses' => [
        'controller' => 'store/admin/warehouses',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/warehouses/add' => [
        'controller' => 'store/admin/warehouses',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/warehouses/edit' => [
        'controller' => 'store/admin/warehouses',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'store/admin/warehouses/delete' => [
        'controller' => 'store/admin/warehouses',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Shipping Configuration
    // =========================================================================
    'store/admin/shipping-methods' => [
        'controller' => 'store/admin/shipping_methods',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/shipping-methods/new' => [
        'controller' => 'store/admin/shipping_methods',
        'action'     => 'new',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/shipping-methods/edit' => [
        'controller' => 'store/admin/shipping_methods',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/shipping-methods/save' => [
        'controller' => 'store/admin/shipping_methods',
        'action'     => 'save',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/shipping-methods/default' => [
        'controller' => 'store/admin/shipping_methods',
        'action'     => 'default',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/shipping-methods/delete' => [
        'controller' => 'store/admin/shipping_methods',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    'store/admin/shipping-regions' => [
        'controller' => 'store/admin/shipping_regions',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/shipping-regions/new' => [
        'controller' => 'store/admin/shipping_regions',
        'action'     => 'new',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/shipping-regions/edit' => [
        'controller' => 'store/admin/shipping_regions',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/shipping-regions/save' => [
        'controller' => 'store/admin/shipping_regions',
        'action'     => 'save',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/shipping-regions/delete' => [
        'controller' => 'store/admin/shipping_regions',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    'store/admin/shipping-rules' => [
        'controller' => 'store/admin/shipping_rules',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/shipping-rules/new' => [
        'controller' => 'store/admin/shipping_rules',
        'action'     => 'new',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/shipping-rules/edit' => [
        'controller' => 'store/admin/shipping_rules',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/shipping-rules/save' => [
        'controller' => 'store/admin/shipping_rules',
        'action'     => 'save',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/shipping-rules/delete' => [
        'controller' => 'store/admin/shipping_rules',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/shipping-rules/tester' => [
        'controller' => 'store/admin/shipping_rules',
        'action'     => 'tester',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'store/admin/shipping-rules/import' => [
        'controller' => 'store/admin/shipping_rules',
        'action'     => 'import',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],

    'store/admin/packaging-sizes' => [
        'controller' => 'store/admin/packaging_sizes',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/packaging-sizes/new' => [
        'controller' => 'store/admin/packaging_sizes',
        'action'     => 'new',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/packaging-sizes/create' => [
        'controller' => 'store/admin/packaging_sizes',
        'action'     => 'create',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/packaging-sizes/save' => [
        'controller' => 'store/admin/packaging_sizes',
        'action'     => 'save',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/packaging-sizes/delete' => [
        'controller' => 'store/admin/packaging_sizes',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    'store/admin/categories' => [
        'controller' => 'store/admin/categories',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/categories/add' => [
        'controller' => 'store/admin/categories',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'store/admin/categories/edit' => [
        'controller' => 'store/admin/categories',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'store/admin/categories/delete' => [
        'controller' => 'store/admin/categories',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Channels
    // =========================================================================
    'store/admin/channels' => [
        'controller' => 'store/admin/channels',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/channels/add' => [
        'controller' => 'store/admin/channels',
        'action'     => 'add',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'store/admin/channels/edit' => [
        'controller' => 'store/admin/channels',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['id'],
    ],
    'store/admin/channels/delete' => [
        'controller' => 'store/admin/channels',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/channels/toggle' => [
        'controller' => 'store/admin/channels',
        'action'     => 'toggle',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // OAuth connect — GET with id in the URL
    'store/admin/channels/connect' => [
        'controller' => 'store/admin/channels',
        'action'     => 'connect',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],

    // OAuth callback from the provider is a GET (browser redirect)
    'store/admin/channels/callback' => [
        'controller' => 'store/admin/channels',
        'action'     => 'callback',
        'auth'       => true,
        'methods'    => ['GET'],
    ],

    // Test connection
    'store/admin/channels/test' => [
        'controller' => 'store/admin/channels',
        'action'     => 'test',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Disconnect (clear OAuth tokens)
    'store/admin/channels/disconnect' => [
        'controller' => 'store/admin/channels',
        'action'     => 'disconnect',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Sync one channel
    'store/admin/channels/sync' => [
        'controller' => 'store/admin/channels',
        'action'     => 'sync',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Sync all enabled channels
    'store/admin/channels/sync-all' => [
        'controller' => 'store/admin/channels',
        'action'     => 'syncAll',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // Refresh eBay business policies + locations cache
    'store/admin/channels/refresh-policies' => [
        'controller' => 'store/admin/channels',
        'action'     => 'refreshPolicies',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // eBay category suggestions (JSON)
    'store/admin/channels/product-attributes' => [
        'controller' => 'store/admin/channels',
        'action'     => 'productAttributes',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/channels/category-suggest' => [
        'controller' => 'store/admin/channels',
        'action'     => 'categorySuggest',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/channels/category-aspects' => [
        'controller' => 'store/admin/channels',
        'action'     => 'categoryAspects',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],

    // Refresh eBay store categories cache
    'store/admin/channels/refresh-store-categories' => [
        'controller' => 'store/admin/channels',
        'action'     => 'refreshStoreCategories',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Refresh / relist channel listings
    'store/admin/channels/import-listings' => [
        'controller' => 'store/admin/channels',
        'action'     => 'importListings',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/channels/refresh-listings' => [
        'controller' => 'store/admin/channels',
        'action'     => 'refreshListings',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Print invoice for an order
    'store/admin/orders/invoice' => [
        'controller' => 'store/admin/orders',
        'action'     => 'invoice',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],

    // Listing templates (per channel)
    'store/admin/listing-templates' => [
        'controller' => 'store/admin/listing_templates',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['channel_id'],
    ],
    'store/admin/listing-templates/edit' => [
        'controller' => 'store/admin/listing_templates',
        'action'     => 'edit',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
        'params'     => ['channel_id'],
    ],
    'store/admin/listing-templates/delete' => [
        'controller' => 'store/admin/listing_templates',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id', 'channel_id'],
    ],
    'store/admin/listing-templates/set-default' => [
        'controller' => 'store/admin/listing_templates',
        'action'     => 'setDefault',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id', 'channel_id'],
    ],

    // =========================================================================
    // Messages (Conversations-first; Mail is secondary)
    // =========================================================================
    'store/admin/messages' => [
        'controller' => 'store/admin/messages',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/messages/mail' => [
        'controller' => 'store/admin/messages',
        'action'     => 'mail',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/messages/view' => [
        'controller' => 'store/admin/messages',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/messages/sync' => [
        'controller' => 'store/admin/messages',
        'action'     => 'sync',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/messages/fetch-body' => [
        'controller' => 'store/admin/messages',
        'action'     => 'fetchBody',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/messages/debug' => [
        'controller' => 'store/admin/messages',
        'action'     => 'debug',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/messages/conversations' => [
        'controller' => 'store/admin/messages',
        'action'     => 'conversations',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/messages/conversations/sync' => [
        'controller' => 'store/admin/messages',
        'action'     => 'syncConversations',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/messages/conversation' => [
        'controller' => 'store/admin/messages',
        'action'     => 'conversation',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/messages/conversation/reply' => [
        'controller' => 'store/admin/messages',
        'action'     => 'reply',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/messages/conversation/refresh' => [
        'controller' => 'store/admin/messages',
        'action'     => 'refreshConversation',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // eBay Support Cases (Post-Order)
    // =========================================================================
    'store/admin/cases' => [
        'controller' => 'store/admin/cases',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/cases/sync' => [
        'controller' => 'store/admin/cases',
        'action'     => 'sync',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/cases/view' => [
        'controller' => 'store/admin/cases',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/cases/refresh' => [
        'controller' => 'store/admin/cases',
        'action'     => 'refresh',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/cases/reply' => [
        'controller' => 'store/admin/cases',
        'action'     => 'reply',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/cases/action' => [
        'controller' => 'store/admin/cases',
        'action'     => 'action',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // =========================================================================
    // Orders
    // =========================================================================
    'store/admin/orders' => [
        'controller' => 'store/admin/orders',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/orders/new' => [
        'controller' => 'store/admin/orders',
        'action'     => 'new',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'store/admin/orders/bulk' => [
        'controller' => 'store/admin/orders',
        'action'     => 'bulk',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/orders/save-filter' => [
        'controller' => 'store/admin/orders',
        'action'     => 'saveFilter',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/orders/delete-filter' => [
        'controller' => 'store/admin/orders',
        'action'     => 'deleteFilter',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/orders/view' => [
        'controller' => 'store/admin/orders',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/orders/update' => [
        'controller' => 'store/admin/orders',
        'action'     => 'update',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/orders/clone' => [
        'controller' => 'store/admin/orders',
        'action'     => 'clone',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/orders/delete' => [
        'controller' => 'store/admin/orders',
        'action'     => 'delete',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/orders/sync' => [
        'controller' => 'store/admin/orders',
        'action'     => 'sync',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/orders/ship' => [
        'controller' => 'store/admin/orders',
        'action'     => 'ship',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/orders/message' => [
        'controller' => 'store/admin/orders',
        'action'     => 'message',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/orders/label' => [
        'controller' => 'store/admin/orders',
        'action'     => 'label',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],

    // =========================================================================
    // Pickwaves
    // =========================================================================
    'store/admin/pickwaves' => [
        'controller' => 'store/admin/pickwaves',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET'],
    ],
    'store/admin/pickwaves/create' => [
        'controller' => 'store/admin/pickwaves',
        'action'     => 'create',
        'auth'       => true,
        'methods'    => ['POST'],
    ],
    'store/admin/pickwaves/view' => [
        'controller' => 'store/admin/pickwaves',
        'action'     => 'view',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/pickwaves/picking-list' => [
        'controller' => 'store/admin/pickwaves',
        'action'     => 'pickingList',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/pickwaves/packing-list' => [
        'controller' => 'store/admin/pickwaves',
        'action'     => 'packingList',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/pickwaves/despatch' => [
        'controller' => 'store/admin/pickwaves',
        'action'     => 'despatch',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/pickwaves/update' => [
        'controller' => 'store/admin/pickwaves',
        'action'     => 'update',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],
    'store/admin/pickwaves/labels' => [
        'controller' => 'store/admin/pickwaves',
        'action'     => 'labels',
        'auth'       => true,
        'methods'    => ['GET'],
        'params'     => ['id'],
    ],
    'store/admin/pickwaves/remove-order' => [
        'controller' => 'store/admin/pickwaves',
        'action'     => 'removeOrder',
        'auth'       => true,
        'methods'    => ['POST'],
        'params'     => ['id'],
    ],

    // Admin status / tester for marketplace account deletion
    'store/admin/account-deletion' => [
        'controller' => 'store/admin/account_deletion',
        'action'     => 'index',
        'auth'       => true,
        'methods'    => ['GET', 'POST'],
    ],
    'store/admin/account-deletion/generate-token' => [
        'controller' => 'store/admin/account_deletion',
        'action'     => 'generateToken',
        'auth'       => true,
        'methods'    => ['POST'],
    ],

    // Public eBay webhook (no auth, signed by eBay — no session CSRF)
    'store/ebay/account-deletion' => [
        'controller' => 'store/admin/account_deletion',
        'action'     => 'handle',
        'auth'       => false,
        'csrf'       => false,
        'methods'    => ['GET', 'POST'],
    ],
    'store/ebay/account-deletion/notify' => [
        'controller' => 'store/admin/account_deletion',
        'action'     => 'notify',
        'auth'       => false,
        'csrf'       => false,
        'methods'    => ['POST'],
    ],
];