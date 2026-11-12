<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'family' => [
        [
            'label'      => 'Family',
            'icon'       => 'diagram-3',
            'permission' => 'family.view',
            'children'   => [
                ['label' => 'People', 'url' => $url->to('family/admin/people'), 'icon' => 'people', 'route' => 'family/admin/people'],
                ['divider' => true],
                ['label' => 'Marriages', 'url' => $url->to('family/admin/marriages'), 'icon' => 'heart', 'route' => 'family/admin/marriages'],
            ],
        ],
    ],
];
