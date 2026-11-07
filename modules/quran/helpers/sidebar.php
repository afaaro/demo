<?php
declare(strict_types=1);

/** @var System\Library\Url $url */

return [
    'quran' => [
        'section' => 'modules',
        'label'   => 'Quran',
        'icon'    => 'book',
        'items'   => [
            [
                'label'  => 'Import',
                'url'    => $url->to('quran/admin/import'),
                'icon'   => 'upload',
                'active' => 'quran/admin/import',
                'exact'  => true,
            ],
            [
                'label'  => 'Translations',
                'url'    => $url->to('quran/admin/import/translations'),
                'icon'   => 'translate',
                'active' => 'quran/admin/import/translations',
            ],
            [
                'label'  => 'Open reader',
                'url'    => $url->to('quran', ['sura' => 1, 'aya' => 0]),
                'icon'   => 'book-half',
                'active' => 'quran',
                'exact'  => true,
            ],
        ],
    ],
];
