<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'family' => [
        'section' => 'modules',
        'label'   => 'Family',
        'icon'    => 'diagram-3',
        'items'   => [
            [
                'label'  => 'People',
                'url'    => $url->to('family/admin/people'),
                'icon'   => 'people',
                'active' => 'family/admin/people',
            ],
            [
                'label'  => 'Marriages',
                'url'    => $url->to('family/admin/marriages'),
                'icon'   => 'heart',
                'active' => 'family/admin/marriages',
            ],
            [
                'label'  => 'Relationship',
                'url'    => $url->to('family/admin/tools/relate'),
                'icon'   => 'diagram-2',
                'active' => 'family/admin/tools/relate',
            ],
            [
                'label'  => 'Merge duplicates',
                'url'    => $url->to('family/admin/tools/merge'),
                'icon'   => 'intersect',
                'active' => 'family/admin/tools/merge',
            ],
        ],
    ],
];
