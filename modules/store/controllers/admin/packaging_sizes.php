<?php
declare(strict_types=1);

use Module\Store\Services\PackagingSizeRepository;
use System\Engine\Controller;

class StoreAdminPackagingSizes extends Controller
{
    private PackagingSizeRepository $sizes;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->sizes = new PackagingSizeRepository($this->db);
    }

    public function indexAction(): void
    {
        $rows = $this->sizes->all();

        echo $this->view->inline(function () use ($rows) {
            $this->view->assign('title', 'Packaging Sizes');
            $this->view->block('right_sidebar', 'Shipping');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">Packaging Sizes</h1>';
            echo '<a class="btn btn-success" href="'
               . $e($this->url->to('store/admin/packaging-sizes/new')) . '">'
               . '<i class="bi bi-plus-lg me-1"></i>New Packaging Size</a>';
            echo '</div>';

            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/packaging-sizes/save')) . '">';
            echo $this->request->csrfField();
            echo '<div class="card border-0 shadow-sm">';
            echo '<div class="card-header bg-dark text-white py-2">Existing Packaging Sizes</div>';
            echo '<div class="table-responsive">';
            echo '<table class="table table-sm align-middle mb-0">';
            echo '<thead class="table-light"><tr>'
               . '<th>Name</th><th>Size</th><th>Packaging Weight (kg)</th>'
               . '<th>Length (mm)</th><th>Width (mm)</th><th>Height/Depth (mm)</th>'
               . '<th>Packaging Cost</th><th>Royal Mail Packaging Type</th>'
               . '<th>Include Weight on Orders</th><th>Weight Capacity (kg)</th><th></th>'
               . '</tr></thead><tbody>';

            if ($rows === []) {
                echo '<tr><td colspan="11" class="text-muted">No packaging sizes yet.</td></tr>';
            }

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                echo '<tr>';
                echo '<td><input type="hidden" name="rows[' . $id . '][id]" value="' . $id . '">'
                   . '<input name="rows[' . $id . '][name]" class="form-control form-control-sm" value="'
                   . $e((string) $row['name']) . '" required></td>';
                echo '<td style="width:5rem"><input type="number" name="rows[' . $id . '][size_code]" class="form-control form-control-sm" value="'
                   . (int) $row['size_code'] . '"></td>';
                echo '<td style="width:7rem"><input type="number" step="0.001" name="rows[' . $id . '][packaging_weight_kg]" class="form-control form-control-sm" value="'
                   . $e((string) $row['packaging_weight_kg']) . '"></td>';
                echo '<td style="width:6rem"><input type="number" name="rows[' . $id . '][length_mm]" class="form-control form-control-sm" value="'
                   . (int) $row['length_mm'] . '"></td>';
                echo '<td style="width:6rem"><input type="number" name="rows[' . $id . '][width_mm]" class="form-control form-control-sm" value="'
                   . (int) $row['width_mm'] . '"></td>';
                echo '<td style="width:7rem"><input type="number" name="rows[' . $id . '][height_mm]" class="form-control form-control-sm" value="'
                   . (int) $row['height_mm'] . '"></td>';
                echo '<td style="width:6rem"><input type="number" step="0.01" name="rows[' . $id . '][packaging_cost]" class="form-control form-control-sm" value="'
                   . $e(number_format((float) $row['packaging_cost'], 2, '.', '')) . '"></td>';
                echo '<td style="width:10rem"><select name="rows[' . $id . '][rm_packaging_type]" class="form-select form-select-sm">';
                foreach (PackagingSizeRepository::RM_TYPES as $val => $label) {
                    $sel = ((string) $row['rm_packaging_type'] === $val) ? ' selected' : '';
                    echo '<option value="' . $e($val) . '"' . $sel . '>' . $e($label) . '</option>';
                }
                echo '</select></td>';
                echo '<td class="text-center"><input type="checkbox" name="rows[' . $id . '][include_weight]" value="1"'
                   . (!empty($row['include_weight']) ? ' checked' : '') . '></td>';
                echo '<td style="width:7rem"><input type="number" step="0.001" name="rows[' . $id . '][weight_capacity_kg]" class="form-control form-control-sm" value="'
                   . $e((string) $row['weight_capacity_kg']) . '"></td>';
                echo '<td><button type="submit" formaction="'
                   . $e($this->url->to('store/admin/packaging-sizes/delete', ['id' => $id]))
                   . '" class="btn btn-link btn-sm text-danger p-0"'
                   . ' onclick="return confirm(\'Delete this packaging size?\');">Delete</button></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '<div class="card-footer bg-white text-end">'
               . '<button type="submit" class="btn btn-primary">Save</button></div>';
            echo '</div></form>';
        });
    }

    public function newAction(): void
    {
        echo $this->view->inline(function () {
            $this->view->assign('title', 'New Packaging Size');
            $this->view->block('right_sidebar', 'Shipping');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="mb-3"><a class="text-decoration-none small" href="'
               . $e($this->url->to('store/admin/packaging-sizes')) . '">&larr; Packaging Sizes</a></div>';
            echo '<h1 class="h3 mb-4">New Packaging Size</h1>';
            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/packaging-sizes/create'))
               . '" class="card border-0 shadow-sm"><div class="card-body row g-3">';
            echo $this->request->csrfField();
            echo '<div class="col-md-6"><label class="form-label">Name</label>'
               . '<input name="name" class="form-control" required maxlength="128"></div>';
            echo '<div class="col-md-3"><label class="form-label">Size</label>'
               . '<input type="number" name="size_code" class="form-control" value="0"></div>';
            echo '<div class="col-md-3"><label class="form-label">Packaging weight (kg)</label>'
               . '<input type="number" step="0.001" name="packaging_weight_kg" class="form-control" value="0"></div>';
            echo '<div class="col-md-4"><label class="form-label">Length (mm)</label>'
               . '<input type="number" name="length_mm" class="form-control" value="0"></div>';
            echo '<div class="col-md-4"><label class="form-label">Width (mm)</label>'
               . '<input type="number" name="width_mm" class="form-control" value="0"></div>';
            echo '<div class="col-md-4"><label class="form-label">Height/Depth (mm)</label>'
               . '<input type="number" name="height_mm" class="form-control" value="0"></div>';
            echo '<div class="col-md-4"><label class="form-label">Packaging cost</label>'
               . '<input type="number" step="0.01" name="packaging_cost" class="form-control" value="0"></div>';
            echo '<div class="col-md-4"><label class="form-label">Royal Mail packaging type</label><select name="rm_packaging_type" class="form-select">';
            foreach (PackagingSizeRepository::RM_TYPES as $val => $label) {
                echo '<option value="' . $e($val) . '">' . $e($label) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-4"><label class="form-label">Weight capacity (kg)</label>'
               . '<input type="number" step="0.001" name="weight_capacity_kg" class="form-control" value="0"></div>';
            echo '<div class="col-12"><div class="form-check">'
               . '<input class="form-check-input" type="checkbox" name="include_weight" value="1" id="pw">'
               . '<label class="form-check-label" for="pw">Include weight on orders</label></div></div>';
            echo '<div class="col-12"><button type="submit" class="btn btn-primary">Create</button></div>';
            echo '</div></form>';
        });
    }

    public function createAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/packaging-sizes');
            return;
        }
        try {
            $this->request->checkToken();
            $this->sizes->create([
                'name'                => (string) $this->request->post('name', 'string', ''),
                'size_code'           => (int) $this->request->post('size_code', 'int', 0),
                'packaging_weight_kg' => (float) $this->request->post('packaging_weight_kg', 'float', 0),
                'length_mm'           => (int) $this->request->post('length_mm', 'int', 0),
                'width_mm'            => (int) $this->request->post('width_mm', 'int', 0),
                'height_mm'           => (int) $this->request->post('height_mm', 'int', 0),
                'packaging_cost'      => (float) $this->request->post('packaging_cost', 'float', 0),
                'rm_packaging_type'   => (string) $this->request->post('rm_packaging_type', 'string', 'Parcel'),
                'include_weight'      => (string) $this->request->post('include_weight', 'string', '') === '1',
                'weight_capacity_kg'  => (float) $this->request->post('weight_capacity_kg', 'float', 0),
            ]);
            $this->notify->success('Packaging size created.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/packaging-sizes');
    }

    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/packaging-sizes');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('store/admin/packaging-sizes');
            return;
        }

        $rows = $_POST['rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }
        $updated = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            try {
                $this->sizes->update($id, [
                    'name'                => (string) ($row['name'] ?? ''),
                    'size_code'           => (int) ($row['size_code'] ?? 0),
                    'packaging_weight_kg' => (float) ($row['packaging_weight_kg'] ?? 0),
                    'length_mm'           => (int) ($row['length_mm'] ?? 0),
                    'width_mm'            => (int) ($row['width_mm'] ?? 0),
                    'height_mm'           => (int) ($row['height_mm'] ?? 0),
                    'packaging_cost'      => (float) ($row['packaging_cost'] ?? 0),
                    'rm_packaging_type'   => (string) ($row['rm_packaging_type'] ?? 'Parcel'),
                    'include_weight'      => !empty($row['include_weight']),
                    'weight_capacity_kg'  => (float) ($row['weight_capacity_kg'] ?? 0),
                    'sort_order'          => (int) ($row['size_code'] ?? 0),
                ]);
                $updated++;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
                redirect_to('store/admin/packaging-sizes');
                return;
            }
        }
        $this->notify->success("Saved {$updated} packaging size(s).");
        redirect_to('store/admin/packaging-sizes');
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/packaging-sizes');
            return;
        }
        try {
            $this->request->checkToken();
            $this->sizes->delete((int) $this->request->get('id', 'int', 0));
            $this->notify->success('Packaging size deleted.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/packaging-sizes');
    }
}
