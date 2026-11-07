<?php
declare(strict_types=1);

use Module\Comments\Services\CommentRepository;
use System\Engine\Registry;
use System\Library\Database;

if (!function_exists('comments_render')) {
    /**
     * Embed the shared comments widget for a content entity.
     *
     * @param array{title?:string,login_next?:string,login_query?:array<string,string|int>,limit?:int} $opts
     */
    function comments_render(
        string $module,
        string $entityType,
        string $entityId,
        array $opts = [],
    ): string {
        $registry = Registry::getInstance();
        /** @var Database $db */
        $db = $registry->get('db');
        $auth = $registry->get('auth');
        $request = $registry->get('request');
        $view = $registry->get('view');

        $repo = new CommentRepository($db);
        $limit = max(1, min(100, (int) ($opts['limit'] ?? 50)));
        $comments = $repo->forEntity($module, $entityType, $entityId, $limit, true);
        $userId = (int) ($auth->id() ?? 0);
        $csrf = $request->csrfToken();
        $loginQuery = $opts['login_query'] ?? [
            'next' => (string) ($opts['login_next'] ?? 'comments'),
        ];
        $heading = (string) ($opts['title'] ?? 'Comments');

        $view->addModuleStyle('comments', 'comments.css');
        $view->addModuleScript('comments', 'comments.js');
        $src = $view->e($view->moduleAsset('comments', 'comments.js'));
        $view->block('scripts', '<script src="' . $src . '" defer></script>');

        ob_start();
        $e = static fn(string|int|float $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        include get_module_path('comments') . '/views/widget.php';
        return (string) ob_get_clean();
    }
}

if (!function_exists('comments_relative_time')) {
    function comments_relative_time(mixed $when): string
    {
        if ($when === null || $when === '') {
            return '';
        }
        $ts = is_numeric($when) ? (int) $when : strtotime((string) $when);
        if ($ts === false) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            return max(1, (int) floor($diff / 60)) . 'm';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . 'h';
        }
        if ($diff < 172800) {
            return 'Yesterday';
        }
        return date('j M Y', $ts);
    }
}
