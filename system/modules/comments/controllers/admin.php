<?php
declare(strict_types=1);

use Module\Comments\Services\CommentRepository;
use System\Engine\Controller;
use System\Library\Dataset;

class CommentsAdmin extends Controller
{
    public function indexAction(): void
    {
        $this->guardAdmin();
        require_once get_module_path('comments') . '/helpers/functions.php';

        $repo = new CommentRepository($this->db);

        $csrf = $this->request->csrfToken();
        $e = fn(string|int|float $v): string => $this->view->e((string) $v);

        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->heading('Comments')
            ->searchPlaceholder('Body, user, or entity id…')
            ->filter('module', 'select', [
                ''      => 'All modules',
                'media' => 'Media',
                'news'  => 'News',
            ])
            ->filter('status', 'select', [
                ''        => 'Visible + hidden',
                'visible' => 'Visible',
                'hidden'  => 'Hidden',
                'deleted' => 'Deleted',
            ])
            ->query(function ($db, array $state) use ($repo): array {
                $result = $repo->adminSearch(
                    $state['q'],
                    (string) ($state['filters']['module'] ?? ''),
                    (string) ($state['filters']['status'] ?? ''),
                    $state['per_page'],
                    $state['offset'],
                );
                return [
                    'rows'  => array_map(static fn(array $row): array => ['_row' => $row], $result['rows']),
                    'total' => $result['total'],
                ];
            })
            ->columns([
                'body' => function (array $row) use ($e): string {
                    $body = trim((string) $row['_row']['body']);
                    if (mb_strlen($body) > 160) {
                        $body = mb_substr($body, 0, 159) . '…';
                    }
                    return '<div class="sf-title">' . $e($body) . '</div>'
                        . '<span class="sf-sub">#' . (int) $row['_row']['id'] . '</span>';
                },
                'user' => function (array $row) use ($e): string {
                    return $e((string) ($row['_row']['username'] ?? 'User'));
                },
                'entity' => function (array $row) use ($e, $repo): string {
                    $r = $row['_row'];
                    $entity = $repo->resolveEntity(
                        (string) $r['module'],
                        (string) $r['entity_type'],
                        (string) $r['entity_id'],
                    );
                    return '<a class="sf-title" href="' . $e($entity['url']) . '" target="_blank" rel="noopener">'
                        . $e($entity['title']) . '</a>'
                        . '<span class="sf-sub">' . $e((string) $r['module'] . '/' . (string) $r['entity_type']) . '</span>';
                },
                'status' => function (array $row) use ($e): string {
                    $status = (string) $row['_row']['status'];
                    $cls = match ($status) {
                        'visible' => 'is-on',
                        'hidden'  => 'is-wait',
                        default   => 'is-off',
                    };
                    return '<span class="sf-pill ' . $cls . '">' . $e(ucfirst($status)) . '</span>';
                },
                'when' => function (array $row) use ($e): string {
                    return $e(comments_relative_time($row['_row']['created_at'] ?? null));
                },
                'actions' => function (array $row) use ($csrf): string {
                    $id = (int) $row['_row']['id'];
                    $status = (string) $row['_row']['status'];
                    $html = '';
                    if ($status === 'visible') {
                        $html .= $this->actionForm('comments/admin/hide', $id, $csrf, 'Hide');
                    }
                    if ($status === 'hidden' || $status === 'deleted') {
                        $html .= $this->actionForm('comments/admin/restore', $id, $csrf, 'Restore');
                    }
                    if ($status !== 'deleted') {
                        $html .= $this->actionForm('comments/admin/delete', $id, $csrf, 'Delete', true);
                    }
                    return $html;
                },
            ]);

        echo $this->view->inline(function () use ($dataset): void {
            $this->view->assign('title', 'Comments');
            $this->view->block('right_sidebar', 'Comments');
            echo $dataset->render();
        });
    }

    public function hideAction(): void
    {
        $this->guardAdmin();
        $this->setStatus('hidden', 'Comment hidden.');
    }

    public function restoreAction(): void
    {
        $this->guardAdmin();
        $this->setStatus('visible', 'Comment restored.');
    }

    public function deleteAction(): void
    {
        $this->guardAdmin();
        $this->setStatus('deleted', 'Comment deleted.');
    }

    private function setStatus(string $status, string $ok): void
    {
        if (!$this->request->isPost() || !$this->request->verifyToken()) {
            $this->notify->error('Invalid request.');
            redirect_to('comments/admin');
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $repo = new CommentRepository($this->db);
        if ($id < 1 || $repo->find($id) === null) {
            $this->notify->error('Comment not found.');
            redirect_to('comments/admin');
        }

        $repo->setStatus($id, $status);
        $this->notify->success($ok);
        redirect_to('comments/admin');
    }

    private function guardAdmin(): void
    {
        if (
            $this->auth->can('comments.admin')
            || $this->auth->can('tools.admin.module.index')
            || $this->auth->can('*')
            || $this->auth->canAccessAdminUi()
            || $this->auth->inGroup('sadmin')
            || $this->auth->inGroup('admin')
        ) {
            return;
        }
        $this->notify->error('You do not have permission to moderate comments.');
        redirect_to('comments');
    }

    private function actionForm(string $route, int $id, string $csrf, string $label, bool $confirm = false): string
    {
        $e = fn(string|int|float $v): string => $this->view->e((string) $v);
        $onsubmit = $confirm
            ? ' onsubmit="return confirm(\'Delete this comment?\');"'
            : '';
        return '<form method="post" action="' . $e($this->url->to($route)) . '" class="d-inline"' . $onsubmit . '>'
            . '<input type="hidden" name="_csrf_token" value="' . $e($csrf) . '">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button type="submit" class="sf-btn is-sm">' . $e($label) . '</button>'
            . '</form> ';
    }
}
