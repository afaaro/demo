<?php
declare(strict_types=1);

return [
    'type'       => 'evri',
    'label'      => 'Evri',
    'category'   => 'courier',
    'client'     => \Module\StoreFeeder\Services\Channels\Courier\Evri\EvriChannelClient::class,
    'uses_oauth' => false,

    'credentials' => [
        'client_id'     => ['label' => 'Client ID',     'type' => 'text'],
        'client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
    ],

    'settings' => [
        'sandbox' => [
            'label'   => 'Use sandbox / test endpoints',
            'type'    => 'checkbox',
            'default' => false,
            'section' => 'provider',
        ],
        'default_weight_kg' => [
            'label'   => 'Default weight (kg)',
            'type'    => 'text',
            'default' => '1',
            'section' => 'provider',
        ],
        'parcel_type' => [
            'label'   => 'Parcel type',
            'type'    => 'select',
            'default' => 'STANDARD',
            'section' => 'provider',
            'options' => [
                'STANDARD' => 'Standard',
                'POSTABLE' => 'Postable (<1kg)',
            ],
        ],
        'label_format' => [
            'label'   => 'Label format',
            'type'    => 'select',
            'default' => 'DEFAULT',
            'section' => 'provider',
            'options' => [
                'DEFAULT'      => 'Default (A4)',
                'THERMAL'      => 'Thermal',
                'TWO_PER_PAGE' => 'Two per page',
            ],
        ],
        'compensation_pounds' => [
            'label'   => 'Compensation (£)',
            'type'    => 'text',
            'default' => '0',
            'section' => 'provider',
        ],
        'token_url' => [
            'label'   => 'OAuth token URL (advanced)',
            'type'    => 'text',
            'default' => 'https://auth.backoffice.parcel2go.com/connect/token',
            'section' => 'advanced',
        ],
        'api_base' => [
            'label'   => 'API base URL (advanced)',
            'type'    => 'text',
            'default' => 'https://api.business.evri.com',
            'section' => 'advanced',
        ],
    ],
];
