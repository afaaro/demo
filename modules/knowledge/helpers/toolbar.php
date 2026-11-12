<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'knowledge' => [
        [
            'label'      => 'Knowledge',
            'icon'       => 'journal-bookmark',
            'permission' => 'knowledge.view',
            'children'   => [
                ['label' => 'Entries', 'url' => $url->to('knowledge/admin/entries'), 'icon' => 'list-ul', 'route' => 'knowledge/admin/entries'],
                ['divider' => true],
                ['label' => 'Domains', 'url' => $url->to('knowledge/admin/domains'), 'icon' => 'tags', 'route' => 'knowledge/admin/domains'],
            ],
        ],
    ],
];
