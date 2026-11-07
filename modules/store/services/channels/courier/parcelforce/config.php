<?php
declare(strict_types=1);

return [
    'type'       => 'parcelforce',
    'label'      => 'ParcelForce',
    'category'   => 'courier',
    'client'     => \Module\Store\Services\Channels\Courier\ParcelForce\ParcelForceChannelClient::class,
    'uses_oauth' => false,

    'credentials' => [
        'username' => ['label' => 'expressLink username', 'type' => 'text'],
        'password' => ['label' => 'expressLink password', 'type' => 'password'],
    ],

    'settings' => [
        'contract_number' => [
            'label'   => 'Contract number',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'department_id' => [
            'label'   => 'Department ID',
            'type'    => 'text',
            'default' => '1',
            'section' => 'provider',
        ],
        'service_code' => [
            'label'   => 'Service code',
            'type'    => 'text',
            'default' => 'SUP',
            'section' => 'provider',
            'help'    => 'e.g. SUP (express48), S10 (express10) — from your ParcelForce contract.',
        ],
        'default_weight_kg' => [
            'label'   => 'Default weight (kg)',
            'type'    => 'text',
            'default' => '1',
            'section' => 'provider',
        ],
        'api_endpoint' => [
            'label'   => 'SOAP endpoint URL',
            'type'    => 'text',
            'default' => 'https://expresslink.parcelforce.net/ws/',
            'section' => 'advanced',
            'help'    => 'UAT example: https://expresslink-uat1.neopost-id.com/ws/',
        ],
        'soap_namespace' => [
            'label'   => 'SOAP namespace (advanced)',
            'type'    => 'text',
            'default' => 'http://www.parcelforce.net/ws/ship/v14',
            'section' => 'advanced',
        ],
    ],
];
