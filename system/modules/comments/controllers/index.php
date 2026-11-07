<?php
declare(strict_types=1);

use Module\Comments\Services\CommentRepository;
use System\Engine\Controller;

class CommentsIndex extends Controller
{
    public function indexAction(): void
    {
        require_once get_module_path('comments') . '/helpers/functions.php';

        $repo = new CommentRepository($this->db);
        $module = trim((string) $this->request->get('module', 'string', ''));
        $rows = $repo->recent(40, $module !== '' ? $module : null);

        $items = [];
        foreach ($rows as $row) {
            $entity = $repo->resolveEntity(
                (string) $row['module'],
                (string) $row['entity_type'],
                (string) $row['entity_id'],
            );
            $snippet = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $row['body'])) ?? '');
            if (mb_strlen($snippet) > 220) {
                $snippet = mb_substr($snippet, 0, 219) . '…';
            }
            $items[] = [
                'id'       => (int) $row['id'],
                'username' => (string) ($row['username'] ?? 'User'),
                'body'     => $snippet,
                'when'     => comments_relative_time($row['created_at'] ?? null),
                'module'   => (string) $row['module'],
                'title'    => $entity['title'],
                'url'      => $entity['url'],
            ];
        }

        $title = 'Comments';
        $this->view->assign('title', $title);
        $this->view->setTitle($title);
        $this->view->addModuleStyle('comments', 'comments.css');

        echo $this->view->inline(function () use ($items, $module): void {
            include get_module_path('comments') . '/views/index.php';
        }, 'main', [
            'title'  => $title,
            'items'  => $items,
            'module' => $module,
        ]);
    }
}
