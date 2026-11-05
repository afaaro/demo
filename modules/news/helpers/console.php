<?php

use Module\News\Services\NewsRepository;
use System\Engine\Registry;

return [
    'news:publish-scheduled' => static function (Registry $registry, array $args): int {
        $news = new NewsRepository($registry->get('db'));
        $n = $news->publishDue();
        fwrite(STDOUT, $n . " scheduled article" . ($n === 1 ? '' : 's') . " published.\n");
        return 0;
    },
];
