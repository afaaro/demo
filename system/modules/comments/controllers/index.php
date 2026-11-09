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

        $viewerId = (int) ($this->auth->id() ?? 0);
        $items = [];
        foreach ($rows as $row) {
            $mod = (string) $row['module'];
            $etype = (string) $row['entity_type'];
            $eid = (string) $row['entity_id'];
            if (!$repo->viewerCanAccess($mod, $etype, $eid, $viewerId > 0 ? $viewerId : null)) {
                continue;
            }
            $entity = $repo->resolveEntity($mod, $etype, $eid);
            $snippet = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $row['body'])) ?? '');
            if (mb_strlen($snippet) > 220) {
                $snippet = mb_substr($snippet, 0, 219) . '…';
            }
            $items[] = [
                'id'       => (int) $row['id'],
                'username' => (string) ($row['username'] ?? 'User'),
                'body'     => $snippet,
                'when'     => comments_relative_time($row['created_at'] ?? null),
                'module'   => $mod,
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
