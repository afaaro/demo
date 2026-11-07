<?php

return [
    'quran_group' => [
        'title' => 'Quran',
        'icon'  => 'fa-book-quran',
        'url'   => '#',
        'order' => 40,
    ],
    'quran_import' => [
        'parent' => 'quran_group',
        'title'  => 'Import',
        'url'    => 'quran/admin/import',
        'order'  => 1,
    ],
    'quran_translations' => [
        'parent' => 'quran_group',
        'title'  => 'Translations',
        'url'    => 'quran/admin/import/translations',
        'order'  => 2,
    ],
];
