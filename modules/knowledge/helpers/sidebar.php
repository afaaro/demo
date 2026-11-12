<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'knowledge' => [
        'section' => 'modules',
        'label'   => 'Knowledge',
        'icon'    => 'journal-bookmark',
        'items'   => [
            [
                'label'  => 'Entries',
                'url'    => $url->to('knowledge/admin/entries'),
                'icon'   => 'list-ul',
                'active' => 'knowledge/admin/entries',
            ],
            [
                'label'  => 'Domains',
                'url'    => $url->to('knowledge/admin/domains'),
                'icon'   => 'tags',
                'active' => 'knowledge/admin/domains',
            ],
        ],
    ],
];
