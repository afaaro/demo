<?php
declare(strict_types=1);

use Module\Store\Services\ReplyTemplateRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreAdminReplyTemplates extends Controller
{
    private ReplyTemplateRepository $templates;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->templates = new ReplyTemplateRepository($this->db);
    }

    public function indexAction(): void
    {
        $rows = $this->templates->all();
        echo $this->view->inline(function () use ($rows) {
            $this->view->assign('title', 'Reply macros');
            $this->view->block('right_sidebar', 'Messages');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-chat-quote me-1"></i>Reply macros</h1>'
               . '<p class="text-muted small mb-0">Reusable CS reply templates for Messages and Cases.</p></div>';
            echo '<a class="btn btn-success btn-sm" href="'
               . $e($this->url->to('store/admin/reply-templates/new')) . '">New macro</a></div>';

            if ($rows === []) {
                echo '<div class="alert alert-secondary">No macros yet.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm align-middle bg-white border">';
            echo '<thead class="table-light"><tr><th>Name</th><th>Channel</th><th>Preview</th><th></th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $ch = trim((string) ($row['channel_type'] ?? ''));
                echo '<tr>';
                echo '<td>' . $e((string) $row['name']) . '</td>';
                echo '<td class="small">' . $e($ch !== '' ? $ch : 'Any') . '</td>';
                echo '<td class="small text-muted">' . $e(mb_substr((string) $row['body'], 0, 80)) . '</td>';
                echo '<td class="text-end text-nowrap">';
                echo '<a class="btn btn-sm btn-outline-secondary" href="'
                   . $e($this->url->to('store/admin/reply-templates/edit', ['id' => $id])) . '">Edit</a> ';
                echo '<form method="post" class="d-inline" action="'
                   . $e($this->url->to('store/admin/reply-templates/delete', ['id' => $id])) . '"'
                   . ' onsubmit="return confirm(\'Delete this macro?\');">'
                   . $this->request->csrfField()
                   . '<button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    public function newAction(): void
    {
        $this->renderForm(null);
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $row = $this->templates->find($id);
        if ($row === null) {
            throw new NotFoundException('Template not found.');
        }
        $this->renderForm($row);
    }

    /** @param array<string, mixed>|null $row */
    private function renderForm(?array $row): void
    {
        $id = $row !== null ? (int) $row['id'] : 0;
        if ($this->request->isPost()) {
            try {
                $this->request->checkToken();
                $payload = [
                    'name'         => (string) $this->request->post('name', 'string', ''),
                    'body'         => (string) $this->request->post('body', 'string', ''),
                    'channel_type' => (string) $this->request->post('channel_type', 'string', ''),
                ];
                if ($id > 0) {
                    $this->templates->update($id, $payload);
                    $this->notify->success('Macro updated.');
                } else {
                    $id = $this->templates->create($payload);
                    $this->notify->success('Macro created.');
                }
                redirect_to('store/admin/reply-templates');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($row, $id) {
            $this->view->assign('title', $id > 0 ? 'Edit macro' : 'New macro');
            $this->view->block('right_sidebar', 'Messages');
            $e = fn(string $s): string => $this->view->e($s);
            $action = $id > 0
                ? $this->url->to('store/admin/reply-templates/edit', ['id' => $id])
                : $this->url->to('store/admin/reply-templates/new');

            echo '<h1 class="h3 mb-3">' . ($id > 0 ? 'Edit macro' : 'New macro') . '</h1>';
            echo '<form method="post" action="' . $e($action) . '" class="card border-0 shadow-sm" style="max-width:40rem">';
            echo $this->request->csrfField();
            echo '<div class="card-body">';
            echo '<div class="mb-3"><label class="form-label">Name</label>'
               . '<input class="form-control" name="name" required value="'
               . $e((string) ($row['name'] ?? '')) . '"></div>';
            echo '<div class="mb-3"><label class="form-label">Channel type (optional)</label>'
               . '<select class="form-select" name="channel_type">';
            $cur = strtolower((string) ($row['channel_type'] ?? ''));
            foreach (['' => 'Any channel', 'ebay' => 'eBay', 'amazon' => 'Amazon', 'bnq' => 'B&Q', 'shopify' => 'Shopify'] as $val => $label) {
                $sel = $cur === $val ? ' selected' : '';
                echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="mb-3"><label class="form-label">Body</label>'
               . '<textarea class="form-control" name="body" rows="8" required>'
               . $e((string) ($row['body'] ?? '')) . '</textarea></div>';
            echo '<button class="btn btn-primary" type="submit">Save</button> ';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/reply-templates')) . '">Cancel</a>';
            echo '</div></form>';
        });
    }

    public function deleteAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        if ($this->request->isPost()) {
            try {
                $this->request->checkToken();
                $this->templates->delete($id);
                $this->notify->success('Macro deleted.');
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }
        redirect_to('store/admin/reply-templates');
    }
}
