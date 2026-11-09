<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Community\Services\PostRepository;
use System\Engine\Controller;
use System\Library\Dataset;

class CommunityAdmin extends Controller
{
    public function indexAction(): void
    {
        $repo = new PostRepository($this->db);
        $csrf = $this->request->csrfToken();
        $e = fn(string|int|float $v): string => $this->view->e((string) $v);

        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->heading('Community posts')
            ->searchPlaceholder('Body or author…')
            ->filter('status', 'select', [
                ''        => 'All statuses',
                'visible' => 'Visible',
                'deleted' => 'Deleted',
            ])
            ->query(function ($db, array $state) use ($repo): array {
                $result = $repo->adminSearch(
                    $state['q'],
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
                    if ($body === '') {
                        $body = '(photo post)';
                    }
                    if (mb_strlen($body) > 140) {
                        $body = mb_substr($body, 0, 139) . '…';
                    }
                    $id = (int) $row['_row']['id'];
                    return '<div class="sf-title">' . $e($body) . '</div>'
                        . '<span class="sf-sub"><a href="' . $e(url('community/post', ['id' => $id])) . '">#'
                        . $id . '</a></span>';
                },
                'author' => function (array $row) use ($e): string {
                    return $e((string) ($row['_row']['author_name'] ?? 'User'));
                },
                'visibility' => function (array $row) use ($e): string {
                    return $e(ucfirst((string) ($row['_row']['visibility'] ?? '')));
                },
                'status' => function (array $row) use ($e): string {
                    $status = (string) $row['_row']['status'];
                    $cls = $status === 'visible' ? 'is-on' : 'is-off';
                    return '<span class="sf-pill ' . $cls . '">' . $e(ucfirst($status)) . '</span>';
                },
                'when' => function (array $row) use ($e): string {
                    return $e(community_relative_time($row['_row']['created_at'] ?? null));
                },
                'actions' => function (array $row) use ($e, $csrf): string {
                    $id = (int) $row['_row']['id'];
                    $status = (string) $row['_row']['status'];
                    if ($status === 'visible') {
                        return '<form method="post" action="' . $e(url('community/admin/hide')) . '" style="display:inline">'
                            . '<input type="hidden" name="_csrf_token" value="' . $e($csrf) . '">'
                            . '<input type="hidden" name="id" value="' . $id . '">'
                            . '<button type="submit" class="btn btn-sm btn-outline-danger">Hide</button></form>';
                    }
                    return '<form method="post" action="' . $e(url('community/admin/restore')) . '" style="display:inline">'
                        . '<input type="hidden" name="_csrf_token" value="' . $e($csrf) . '">'
                        . '<input type="hidden" name="id" value="' . $id . '">'
                        . '<button type="submit" class="btn btn-sm btn-outline-success">Restore</button></form>';
                },
            ]);

        $this->view->assign('title', 'Community moderation');
        $this->view->setTitle('Community moderation');
        echo $this->view->inline(function () use ($dataset): void {
            $this->view->block('right_sidebar', 'Community');
            echo $dataset->render();
        });
    }

    public function hideAction(): void
    {
        $id = (int) $this->request->post('id', 'int', 0);
        $repo = new PostRepository($this->db);
        try {
            $repo->softDelete($id, (int) ($this->auth->id() ?? 0), true);
            $this->notify->success('Post hidden.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('community/admin');
    }

    public function restoreAction(): void
    {
        $id = (int) $this->request->post('id', 'int', 0);
        $repo = new PostRepository($this->db);
        try {
            $repo->restore($id);
            $this->notify->success('Post restored.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('community/admin');
    }
}
