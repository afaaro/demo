<?php
declare(strict_types=1);

return [
    'type'       => 'dpd',
    'label'      => 'DPD',
    'category'   => 'courier',
    'client'     => \Module\StoreFeeder\Services\Channels\Courier\Dpd\DpdChannelClient::class,
    'uses_oauth' => false,

    'credentials' => [
        'username' => ['label' => 'Username', 'type' => 'text'],
        'password' => ['label' => 'Password', 'type' => 'password'],
    ],

    'settings' => [
        'account_number' => [
            'label'   => 'Account number',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'network_code' => [
            'label'   => 'Network code',
            'type'    => 'text',
            'default' => '1^12',
            'section' => 'provider',
            'help'    => 'DPD product code (e.g. 1^12 = DPD Next Day). From your DPD account.',
        ],
        'default_weight_kg' => [
            'label'   => 'Default weight (kg)',
            'type'    => 'text',
            'default' => '1',
            'section' => 'provider',
        ],
        'collection_name' => [
            'label'   => 'Collection contact name',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'collection_phone' => [
            'label'   => 'Collection telephone',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'collection_organisation' => [
            'label'   => 'Collection organisation',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'collection_street' => [
            'label'   => 'Collection street',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'collection_locality' => [
            'label'   => 'Collection locality',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'collection_town' => [
            'label'   => 'Collection town',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'collection_county' => [
            'label'   => 'Collection county',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'collection_postcode' => [
            'label'   => 'Collection postcode',
            'type'    => 'text',
            'default' => '',
            'section' => 'provider',
        ],
        'api_base' => [
            'label'   => 'API base URL (advanced)',
            'type'    => 'text',
            'default' => 'https://api.dpd.co.uk',
            'section' => 'advanced',
        ],
    ],
];
