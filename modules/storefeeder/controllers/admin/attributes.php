<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\AttributeRepository;
use Module\StoreFeeder\Services\CatalogSetupService;
use System\Engine\Controller;

class StoreFeederAdminAttributes extends Controller
{
    private AttributeRepository $attributes;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->attributes = new AttributeRepository($this->db);
        // Permissions: Action.php only.
    }

    public function indexAction(): void
    {
        $attributes = $this->attributes->all();

        echo $this->view->inline(function () use ($attributes) {
            $this->view->assign('title', 'Attributes');

            echo '<div class="d-flex justify-content-between align-items-center mb-4">';
            echo '<h1 class="h3 mb-0">Attributes</h1>';
            echo '<div class="d-flex gap-2">';
            echo '<form method="post" action="' . $this->view->e($this->url->to('storefeeder/admin/attributes/cleanup')) . '"'
               . ' onsubmit="return confirm(\'Delete ID, Order ID and other imported identifier attributes?\');">';
            echo $this->request->csrfField();
            echo '<button class="btn btn-outline-danger" type="submit">Delete ID / order attributes</button>';
            echo '</form>';
            echo '<form method="post" action="' . $this->view->e($this->url->to('storefeeder/admin/attributes/setup')) . '">';
            echo $this->request->csrfField();
            echo '<button class="btn btn-outline-primary" type="submit">Set up pack catalog</button>';
            echo '</form>';
            echo '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#new-attr-modal">';
            echo '<i class="bi bi-plus-lg me-1"></i>New attribute</button>';
            echo '</div></div>';

            echo '<div class="alert alert-light border mb-4">';
            echo '<p class="mb-2"><strong>How to set up products and variants</strong></p>';
            echo '<ol class="mb-2 small">';
            echo '<li>Keep <strong>one product</strong> per listing (gripper, underlay, pipe insulation).</li>';
            echo '<li>On the product, tick the attributes for that type, then <strong>Generate variants</strong>.</li>';
            echo '<li>Count stock only on the <strong>1 × / 1m</strong> row for each identity group.</li>';
            echo '</ol>';
            echo '<p class="small mb-0 text-muted">Pack Size → 1×/8× packs. Thickness + Size → underlay rolls. '
               . 'Pipe Diameter + Insulation Thickness + Metres → pipe lengths. '
               . '<strong>Set up pack catalog</strong> creates those attributes and values.</p>';
            echo '</div>';

            if (empty($attributes)) {
                echo '<div class="alert alert-info">No attributes defined. '
                   . 'Attributes describe variant options like size, colour, or material.</div>';
            } else {
                echo '<div class="d-flex justify-content-between align-items-center mb-3">';
                echo '<div class="small text-muted">Tick the ones that are not real options (ID, Order ID, …) then delete.</div>';
                echo '<button class="btn btn-sm btn-danger" type="button" id="attr-bulk-btn">Delete selected</button>';
                echo '</div>';
                foreach ($attributes as $a) {
                    echo '<div class="card mb-3"><div class="card-body">';
                    echo '<div class="d-flex justify-content-between align-items-start mb-2">';
                    echo '<div class="d-flex align-items-start gap-2">';
                    echo '<input type="checkbox" class="form-check-input mt-1 attr-pick" value="' . (int) $a->id . '"'
                       . (AttributeRepository::isSystemName($a->code) || AttributeRepository::isSystemName($a->name) ? ' checked' : '')
                       . '>';
                    echo '<div>';
                    echo '<h5 class="mb-1">' . $this->view->e($a->name)
                       . ' <code class="text-muted small">' . $this->view->e($a->code) . '</code></h5>';
                    echo '<p class="text-muted small mb-0">Type: ' . $this->view->e($a->type) . '</p>';
                    echo '</div></div>';
                    echo '<form method="post" action="' . $this->view->e($this->url->to('storefeeder/admin/attributes/delete', ['id' => $a->id])) . '"'
                       . ' onsubmit="return confirm(\'Delete this attribute? All values and variant assignments will be removed.\');">';
                    echo $this->request->csrfField();
                    echo '<button class="btn btn-sm btn-outline-danger">Delete</button>';
                    echo '</form>';
                    echo '</div>';

                    if (empty($a->values)) {
                        echo '<p class="text-muted small">No values yet.</p>';
                    } else {
                        echo '<div class="d-flex flex-wrap gap-1 mb-2">';
                        foreach ($a->values as $v) {
                            echo '<span class="badge text-bg-light border">'
                               . $this->view->e($v->labelOrValue())
                               . ' <code class="text-muted">' . $this->view->e($v->value) . '</code>'
                               . ' <a href="#" class="text-danger ms-1 attr-value-del"'
                               . ' data-url="' . $this->view->e($this->url->to('storefeeder/admin/attributes/value/delete', ['id' => $v->id])) . '"'
                               . ' data-csrf="' . $this->view->e($this->request->csrfToken()) . '"'
                               . ' data-label="' . $this->view->e($v->labelOrValue()) . '">×</a>'
                               . '</span>';
                        }
                        echo '</div>';
                    }

                    echo '<form method="post" action="' . $this->view->e($this->url->to('storefeeder/admin/attributes/value/add', ['id' => $a->id])) . '"'
                       . ' class="row g-2 align-items-center">';
                    echo $this->request->csrfField();
                    echo '<div class="col-auto"><input type="text" name="value" class="form-control form-control-sm" placeholder="Value (e.g. M)" required></div>';
                    echo '<div class="col-auto"><input type="text" name="label" class="form-control form-control-sm" placeholder="Label (optional)"></div>';
                    echo '<div class="col-auto"><button class="btn btn-sm btn-outline-primary">Add value</button></div>';
                    echo '</form>';

                    echo '</div></div>';
                }
            }

            echo '<div class="modal fade" id="new-attr-modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">';
            echo '<form method="post" action="' . $this->view->e($this->url->to('storefeeder/admin/attributes/add')) . '">';
            echo $this->request->csrfField();
            echo '<div class="modal-header"><h5 class="modal-title">New attribute</h5>'
               . '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>';
            echo '<div class="modal-body">';
            echo '<div class="mb-3"><label class="form-label">Name</label>'
               . '<input type="text" name="name" class="form-control" required></div>';
            echo '<div class="mb-3"><label class="form-label">Code</label>'
               . '<input type="text" name="code" class="form-control" placeholder="optional — from name">'
               . '<div class="form-text">Leave blank to generate from the name. Spaces become pack_size.</div></div>';
            echo '<div class="mb-3"><label class="form-label">Type</label>'
               . '<select name="type" class="form-select">'
               . '<option value="select">Select (list of values)</option>'
               . '<option value="text">Text</option>'
               . '<option value="number">Number</option>'
               . '</select></div>';
            echo '</div>';
            echo '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>'
               . '<button type="submit" class="btn btn-primary">Create</button></div>';
            echo '</form>';
            echo '</div></div></div>';

            echo '<script>
            document.addEventListener("click", function (e) {
                const el = e.target.closest(".attr-value-del");
                if (!el) return;
                e.preventDefault();
                if (!confirm("Delete value \"" + el.dataset.label + "\"?")) return;
                const form = document.createElement("form");
                form.method = "POST";
                form.action = el.dataset.url;
                const csrf = document.createElement("input");
                csrf.type = "hidden";
                csrf.name = "_csrf_token";
                csrf.value = el.dataset.csrf;
                form.appendChild(csrf);
                document.body.appendChild(form);
                form.submit();
            });
            const bulkBtn = document.getElementById("attr-bulk-btn");
            if (bulkBtn) {
                bulkBtn.addEventListener("click", function () {
                    const ids = Array.from(document.querySelectorAll(".attr-pick:checked")).map(function (el) { return el.value; });
                    if (ids.length === 0) {
                        alert("Tick at least one attribute.");
                        return;
                    }
                    if (!confirm("Delete the selected attributes? Values will be removed from products.")) return;
                    const form = document.createElement("form");
                    form.method = "POST";
                    form.action = ' . json_encode($this->url->to('storefeeder/admin/attributes/bulk-delete')) . ';
                    const csrf = document.createElement("input");
                    csrf.type = "hidden";
                    csrf.name = "_csrf_token";
                    csrf.value = ' . json_encode($this->request->csrfToken()) . ';
                    form.appendChild(csrf);
                    ids.forEach(function (id) {
                        const inp = document.createElement("input");
                        inp.type = "hidden";
                        inp.name = "ids[]";
                        inp.value = id;
                        form.appendChild(inp);
                    });
                    document.body.appendChild(form);
                    form.submit();
                });
            }
            </script>';
        });
    }

    public function addAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/attributes');
            return;
        }

        try {
            $this->attributes->create(
                (string) $this->request->post('code', 'string', ''),
                (string) $this->request->post('name', 'string', ''),
                (string) $this->request->post('type', 'string', 'select'),
            );
            $this->notify->success('Attribute created.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect_to('storefeeder/admin/attributes');
    }

    public function setupAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/attributes');
            return;
        }

        try {
            $codes = (new CatalogSetupService($this->db, $this->attributes))->ensure();
            $this->notify->success(
                'Pack catalog ready: ' . implode(', ', $codes) . '. Tick these on a product, then generate variants.'
            );
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect_to('storefeeder/admin/attributes');
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/attributes');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        if ($id > 0) {
            $attr = $this->attributes->find($id);
            if ($attr !== null) {
                $this->attributes->delete($id);
                $this->notify->success("Attribute '{$attr->name}' deleted.");
            } else {
                $this->notify->error('Attribute not found.');
            }
        }
        redirect_to('storefeeder/admin/attributes');
    }

    public function bulkDeleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/attributes');
            return;
        }
        $ids = array_map('intval', (array) $this->request->post('ids', 'raw', []));
        $n = $this->attributes->deleteMany($ids);
        if ($n > 0) {
            $this->notify->success($n === 1 ? 'Attribute deleted.' : $n . ' attributes deleted.');
        } else {
            $this->notify->error('Tick at least one attribute to delete.');
        }
        redirect_to('storefeeder/admin/attributes');
    }

    public function cleanupAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/attributes');
            return;
        }
        $n = $this->attributes->deleteSystemImported();
        if ($n > 0) {
            $this->notify->success($n === 1 ? 'ID / order attribute deleted.' : $n . ' ID / order attributes deleted.');
        } else {
            $this->notify->success('No ID / order attributes left to delete.');
        }
        redirect_to('storefeeder/admin/attributes');
    }

    public function valueAddAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/attributes');
            return;
        }

        $attributeId = (int) $this->request->get('id', 'int', 0);
        $value = trim((string) $this->request->post('value', 'string', ''));
        $label = trim((string) $this->request->post('label', 'string', ''));

        try {
            if ($attributeId <= 0) {
                throw new \InvalidArgumentException('Invalid attribute.');
            }
            $this->attributes->addValue($attributeId, $value, $label);
            $this->notify->success("Value '{$value}' added.");
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect_to('storefeeder/admin/attributes');
    }

    public function quickAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->request->isPost()) {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            return;
        }
        $name = trim((string) $this->request->post('name', 'string', ''));
        try {
            $attr = $this->attributes->ensureByName($name);
            echo json_encode([
                'ok'     => true,
                'id'     => (int) $attr->id,
                'code'   => $attr->code,
                'name'   => $attr->name,
                'values' => array_map(
                    static fn($v) => [
                        'id'    => (int) $v->id,
                        'value' => $v->value,
                        'label' => $v->labelOrValue(),
                    ],
                    $attr->values
                ),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function valueQuickAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->request->isPost()) {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            return;
        }
        $attributeId = (int) $this->request->post('attribute_id', 'int', 0);
        $value = trim((string) $this->request->post('value', 'string', ''));
        $label = trim((string) $this->request->post('label', 'string', ''));
        try {
            if ($attributeId <= 0 || $value === '') {
                throw new \InvalidArgumentException('Attribute and value are required.');
            }
            $row = $this->attributes->ensureValue($attributeId, $value, $label);
            if ($row === null || $row->id === null) {
                throw new \RuntimeException('Could not save value.');
            }
            echo json_encode([
                'ok'    => true,
                'id'    => (int) $row->id,
                'value' => $row->value,
                'label' => $row->labelOrValue(),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function valueDeleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/attributes');
            return;
        }
        $id = (int) $this->request->get('id', 'int', 0);
        if ($id > 0) {
            $this->attributes->deleteValue($id);
            $this->notify->success('Value deleted.');
        }
        redirect_to('storefeeder/admin/attributes');
    }
}