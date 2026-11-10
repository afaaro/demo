<?php
declare(strict_types=1);

use Module\Store\Services\OrderRuleRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreAdminOrderRules extends Controller
{
    private OrderRuleRepository $rules;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->rules = new OrderRuleRepository($this->db);
    }

    public function indexAction(): void
    {
        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->sortable(['name', 'type', 'action', 'enabled'])
            ->defaultSort('id', 'asc')
            ->columns([
                'name'      => 'Name',
                'type'      => 'Type',
                'threshold' => 'Threshold',
                'action'    => 'Action',
                'enabled'   => 'Enabled',
                'actions'   => '',
            ])
            ->query(function ($db, array $state): array {
                $rows = $this->rules->all();
                $total = count($rows);
                $slice = array_slice($rows, $state['offset'], $state['per_page']);
                return [
                    'rows'  => array_map(static fn(array $row): array => ['_row' => $row], $slice),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $dataset->columns([
            'name' => fn(array $row): string => $e((string) ($row['_row']['name'] ?: '—')),
            'type' => fn(array $row): string => $e((string) $row['_row']['type']),
            'threshold' => fn(array $row): string => number_format((float) $row['_row']['threshold'], 2),
            'action' => fn(array $row): string => $e((string) $row['_row']['action']),
            'enabled' => fn(array $row): string => !empty($row['_row']['enabled']) ? 'Yes' : 'No',
            'actions' => function (array $row) use ($e): string {
                $id = (int) $row['_row']['id'];
                return '<span class="text-end d-block"><a href="'
                    . $e($this->url->to('store/admin/order-rules/edit', ['id' => $id]))
                    . '">Edit</a></span>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e) {
            $this->view->assign('title', 'Order rules');
            $this->view->block('right_sidebar', 'Orders');
            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-funnel me-1"></i>Order hold / attention rules</h1>'
               . '<p class="text-muted small mb-0">Applied after order sync (unpaid, bad address, high value, unmapped).</p></div>';
            echo '<a class="btn btn-primary btn-sm" href="'
               . $e($this->url->to('store/admin/order-rules/new')) . '">Add rule</a></div>';
            echo $dataset->render();
        });
    }

    public function newAction(): void
    {
        $this->formPage(null);
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $rule = $this->rules->find($id);
        if ($rule === null) {
            throw new NotFoundException('Order rule not found.');
        }
        $this->formPage($rule);
    }

    /** @param array<string, mixed>|null $rule */
    private function formPage(?array $rule): void
    {
        $isEdit = $rule !== null;
        $action = $isEdit
            ? $this->url->to('store/admin/order-rules/save', ['id' => (int) $rule['id']])
            : $this->url->to('store/admin/order-rules/save');

        echo $this->view->inline(function () use ($rule, $isEdit, $action) {
            $this->view->assign('title', $isEdit ? 'Edit order rule' : 'New order rule');
            $this->view->block('right_sidebar', 'Orders');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $name = (string) ($rule['name'] ?? '');
            $type = (string) ($rule['type'] ?? 'unpaid');
            $threshold = (float) ($rule['threshold'] ?? 0);
            $actionVal = (string) ($rule['action'] ?? 'hold');
            $enabled = $rule === null || !empty($rule['enabled']);

            echo '<div class="mb-2"><a class="small" href="'
               . $e($this->url->to('store/admin/order-rules')) . '">&lt;&lt; Back</a></div>';
            echo '<h1 class="h3 mb-3">' . ($isEdit ? 'Edit rule' : 'New rule') . '</h1>';
            echo '<form method="post" action="' . $e($action) . '" class="card border-0 shadow-sm">'
               . '<div class="card-body row g-3">';
            echo $this->request->csrfField();
            if ($isEdit) {
                echo '<input type="hidden" name="id" value="' . (int) $rule['id'] . '">';
            }
            echo '<div class="col-md-6"><label class="form-label">Name</label>'
               . '<input type="text" name="name" class="form-control" maxlength="128" value="'
               . $e($name) . '" required></div>';
            echo '<div class="col-md-3"><label class="form-label">Type</label><select name="type" class="form-select">';
            foreach (OrderRuleRepository::TYPES as $t) {
                $sel = $type === $t ? ' selected' : '';
                echo '<option value="' . $e($t) . '"' . $sel . '>' . $e($t) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-3"><label class="form-label">Action</label><select name="action" class="form-select">';
            foreach (OrderRuleRepository::ACTIONS as $a) {
                $sel = $actionVal === $a ? ' selected' : '';
                echo '<option value="' . $e($a) . '"' . $sel . '>' . $e($a) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-3"><label class="form-label">Threshold (high_value)</label>'
               . '<input type="number" step="0.01" min="0" name="threshold" class="form-control" value="'
               . $e(number_format($threshold, 2, '.', '')) . '"></div>';
            echo '<div class="col-md-3 d-flex align-items-end"><div class="form-check">'
               . '<input class="form-check-input" type="checkbox" name="enabled" value="1" id="enabled"'
               . ($enabled ? ' checked' : '') . '>'
               . '<label class="form-check-label" for="enabled">Enabled</label></div></div>';
            echo '<div class="col-12 d-flex gap-2">'
               . '<button type="submit" class="btn btn-primary">Save</button>';
            if ($isEdit) {
                echo '<button type="submit" name="delete" value="1" class="btn btn-outline-danger"'
                   . ' onclick="return confirm(\'Delete this rule?\');">Delete</button>';
            }
            echo '</div></div></form>';
        });
    }

    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/order-rules');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('store/admin/order-rules');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        if ($id <= 0) {
            $id = (int) $this->request->post('id', 'int', 0);
        }
        if ((string) $this->request->post('delete', 'string', '') === '1' && $id > 0) {
            $this->rules->delete($id);
            $this->notify->success('Rule deleted.');
            redirect_to('store/admin/order-rules');
            return;
        }

        $payload = [
            'name'      => (string) $this->request->post('name', 'string', ''),
            'type'      => (string) $this->request->post('type', 'string', 'unpaid'),
            'action'    => (string) $this->request->post('action', 'string', 'hold'),
            'threshold' => (float) $this->request->post('threshold', 'float', 0),
            'enabled'   => (string) $this->request->post('enabled', 'string', '') === '1',
        ];

        try {
            if ($id > 0) {
                $this->rules->update($id, $payload);
                $this->notify->success('Rule updated.');
            } else {
                $this->rules->create($payload);
                $this->notify->success('Rule created.');
            }
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            if ($id > 0) {
                redirect_to('store/admin/order-rules/edit', ['id' => $id]);
            } else {
                redirect_to('store/admin/order-rules/new');
            }
            return;
        }
        redirect_to('store/admin/order-rules');
    }
}
