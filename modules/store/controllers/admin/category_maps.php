<?php
declare(strict_types=1);

use Module\Store\Services\CategoryMapRepository;
use Module\Store\Services\CategoryRepository;
use Module\Store\Services\ChannelRepository;
use System\Engine\Controller;

class StoreAdminCategoryMaps extends Controller
{
    private CategoryMapRepository $maps;
    private ChannelRepository $channels;
    private CategoryRepository $categories;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->maps = new CategoryMapRepository($this->db);
        $this->channels = new ChannelRepository($this->db);
        $this->categories = new CategoryRepository($this->db);
    }

    public function indexAction(): void
    {
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $rows = $this->maps->all($channelId > 0 ? $channelId : null);
        $channels = $this->channels->enabled();
        $cats = $this->categories->all();

        echo $this->view->inline(function () use ($rows, $channels, $cats, $channelId) {
            $this->view->assign('title', 'Category maps');
            $this->view->block('right_sidebar', 'Integrations');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="mb-3"><h1 class="h3 mb-1">Channel category mapping</h1>'
               . '<p class="text-muted small mb-0">Map store categories → external channel category IDs (preferred over product ebay_category_id).</p></div>';

            echo '<form method="get" class="mb-3 row g-2" action="'
               . $e($this->url->to('store/admin/category-maps')) . '">';
            echo '<div class="col-auto"><select name="channel_id" class="form-select form-select-sm" onchange="this.form.submit()">'
               . '<option value="0">All channels</option>';
            foreach ($channels as $ch) {
                $sel = $channelId === (int) $ch->id ? ' selected' : '';
                echo '<option value="' . (int) $ch->id . '"' . $sel . '>'
                   . $e($ch->label !== '' ? $ch->label : $ch->name) . '</option>';
            }
            echo '</select></div></form>';

            echo '<div class="card border-0 shadow-sm mb-4"><div class="card-body">';
            echo '<form method="post" action="' . $e($this->url->to('store/admin/category-maps/save'))
               . '" class="row g-2 align-items-end">';
            echo $this->request->csrfField();
            echo '<div class="col-md-3"><label class="form-label small">Store category</label>'
               . '<select name="store_category_id" class="form-select form-select-sm" required>';
            foreach ($cats as $cat) {
                echo '<option value="' . (int) $cat['id'] . '">' . $e((string) $cat['name']) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-3"><label class="form-label small">Channel</label>'
               . '<select name="channel_id" class="form-select form-select-sm" required>';
            foreach ($channels as $ch) {
                echo '<option value="' . (int) $ch->id . '">'
                   . $e($ch->label !== '' ? $ch->label : $ch->name) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-3"><label class="form-label small">External category ID</label>'
               . '<input class="form-control form-control-sm" name="external_category_id" required></div>';
            echo '<div class="col-md-2"><label class="form-label small">Aspects JSON (opt)</label>'
               . '<input class="form-control form-control-sm" name="aspects_json" placeholder="{}"></div>';
            echo '<div class="col-md-1"><button class="btn btn-sm btn-success w-100">Save</button></div>';
            echo '</form></div></div>';

            if ($rows === []) {
                echo '<div class="alert alert-secondary">No maps yet.</div>';
                return;
            }
            echo '<div class="table-responsive"><table class="table table-sm bg-white border">'
               . '<thead class="table-light"><tr><th>Store cat</th><th>Channel</th><th>External ID</th><th></th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr>';
                echo '<td>' . $e((string) $row['store_category_name']) . '</td>';
                echo '<td>' . $e((string) $row['channel_name']) . '</td>';
                echo '<td><code>' . $e((string) $row['external_category_id']) . '</code></td>';
                echo '<td class="text-end"><form method="post" action="'
                   . $e($this->url->to('store/admin/category-maps/delete', ['id' => (int) $row['id']])) . '">'
                   . $this->request->csrfField()
                   . '<button class="btn btn-sm btn-outline-danger">Delete</button></form></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/category-maps');
            return;
        }
        try {
            $this->request->checkToken();
            $aspectsRaw = trim((string) $this->request->post('aspects_json', 'string', ''));
            $aspects = null;
            if ($aspectsRaw !== '') {
                $decoded = json_decode($aspectsRaw, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('Aspects must be valid JSON object.');
                }
                $aspects = $decoded;
            }
            $this->maps->upsert(
                (int) $this->request->post('store_category_id', 'int', 0),
                (int) $this->request->post('channel_id', 'int', 0),
                (string) $this->request->post('external_category_id', 'string', ''),
                $aspects
            );
            $this->notify->success('Category map saved.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/category-maps');
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/category-maps');
            return;
        }
        try {
            $this->request->checkToken();
            $this->maps->delete((int) $this->request->get('id', 'int', 0));
            $this->notify->success('Deleted.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/category-maps');
    }
}
