<?php
// media/library/menu.php
return [
    'media_group' => [
        'title' => 'Media',
        'icon'  => 'fa-film',
        'url'   => '#', // Parent container
        'order' => 60
    ],
    'media_channels' => [
        'parent' => 'media_group',
        'title'  => 'Channels',
        'url'    => 'media/admin/channel',
        'order'  => 1
    ],
    'media_studio' => [
        'parent' => 'media_group',
        'title'  => 'Studio',
        'url'    => 'media/admin/studio',
        'order'  => 2
    ]
];