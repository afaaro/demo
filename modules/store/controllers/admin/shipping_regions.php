<?php
declare(strict_types=1);

use Module\Store\Services\ShippingRegionRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreAdminShippingRegions extends Controller
{
    private ShippingRegionRepository $regions;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->regions = new ShippingRegionRepository($this->db);
    }

    public function indexAction(): void
    {
        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->searchPlaceholder('Name, country, postcode…')
            ->sortable(['name'])
            ->defaultSort('name', 'asc')
            ->columns([
                'name'       => 'Region',
                'countries'  => 'Countries',
                'postcodes'  => 'Postcodes',
                'notes'      => 'Notes',
                'actions'    => '',
            ])
            ->query(function ($db, array $state): array {
                $rows = $this->regions->all();
                $q = strtolower($state['q']);
                if ($q !== '') {
                    $rows = array_values(array_filter($rows, static function (array $row) use ($q): bool {
                        $hay = strtolower(
                            (string) ($row['name'] ?? '') . ' '
                            . (string) ($row['countries'] ?? '') . ' '
                            . (string) ($row['postcodes'] ?? '')
                        );
                        return str_contains($hay, $q);
                    }));
                }
                usort($rows, static function (array $a, array $b) use ($state): int {
                    $mult = strtolower($state['dir']) === 'desc' ? -1 : 1;
                    return $mult * strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                });
                $total = count($rows);
                $slice = array_slice($rows, $state['offset'], $state['per_page']);

                return [
                    'rows'  => array_map(static fn(array $row): array => ['_row' => $row], $slice),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $dataset->columns([
            'name' => function (array $row) use ($e): string {
                return '<span class="fw-semibold">' . $e((string) $row['_row']['name']) . '</span>';
            },
            'countries' => function (array $row) use ($e): string {
                $countries = trim((string) ($row['_row']['countries'] ?? ''));
                return $countries !== ''
                    ? '<span class="small">' . $e($this->previewList($countries)) . '</span>'
                    : '<span class="text-muted">All / ROW</span>';
            },
            'postcodes' => function (array $row) use ($e): string {
                $postcodes = trim((string) ($row['_row']['postcodes'] ?? ''));
                return $postcodes !== ''
                    ? '<span class="small">' . $e($this->previewList($postcodes)) . '</span>'
                    : '<span class="text-muted">—</span>';
            },
            'notes' => function (array $row) use ($e): string {
                $notes = trim((string) ($row['_row']['notes'] ?? ''));
                if ($notes === '') {
                    return '<span class="small text-muted">—</span>';
                }
                $text = mb_strlen($notes) > 60 ? mb_substr($notes, 0, 60) . '…' : $notes;
                return '<span class="small text-muted">' . $e($text) . '</span>';
            },
            'actions' => function (array $row) use ($e): string {
                return '<span class="text-end text-nowrap d-block"><a class="btn btn-sm btn-outline-secondary" href="'
                    . $e($this->url->to('store/admin/shipping-regions/edit', ['id' => (int) $row['_row']['id']]))
                    . '">Edit</a></span>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset) {
            $this->view->assign('title', 'Shipping Regions');
            $this->view->block('right_sidebar', 'Shipping');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1">Shipping Regions</h1>'
               . '<p class="text-muted small mb-0">Country / postcode groups used by shipping rules.</p></div>';
            echo '<a class="btn btn-primary btn-sm" href="'
               . $e($this->url->to('store/admin/shipping-regions/new')) . '">'
               . '<i class="bi bi-plus-lg me-1"></i>New region</a>';
            echo '</div>';

            echo $dataset->render();
        });
    }

    private function previewList(string $csv): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $csv))));
        if ($parts === []) {
            return '';
        }
        $shown = array_slice($parts, 0, 4);
        $extra = count($parts) - count($shown);
        return implode(', ', $shown) . ($extra > 0 ? ' +' . $extra : '');
    }

    public function newAction(): void
    {
        $this->renderForm(null);
    }

    public function editAction(): void
    {
        $row = $this->regions->find((int) $this->request->get('id', 'int', 0));
        if ($row === null) {
            throw new NotFoundException('Shipping region not found.');
        }
        $this->renderForm($row);
    }

    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/shipping-regions');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired.');
            redirect_to('store/admin/shipping-regions');
            return;
        }

        $id = (int) $this->request->post('id', 'int', 0);
        $data = [
            'name'      => (string) $this->request->post('name', 'string', ''),
            'countries' => (string) $this->request->post('countries', 'string', ''),
            'postcodes' => (string) $this->request->post('postcodes', 'string', ''),
            'notes'     => (string) $this->request->post('notes', 'string', ''),
        ];

        try {
            if ($id > 0) {
                $this->regions->update($id, $data);
                $this->notify->success('Shipping region updated.');
            } else {
                $id = $this->regions->create(
                    $data['name'],
                    $data['countries'],
                    $data['postcodes'],
                    $data['notes'],
                );
                $this->notify->success('Shipping region created.');
            }
            redirect_to('store/admin/shipping-regions/edit', ['id' => $id]);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
            redirect_to('store/admin/shipping-regions');
        }
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/shipping-regions');
            return;
        }
        try {
            $this->request->checkToken();
            $this->regions->delete((int) $this->request->get('id', 'int', 0));
            $this->notify->success('Shipping region deleted.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/shipping-regions');
    }

    /** @param array<string, mixed>|null $row */
    private function renderForm(?array $row): void
    {
        echo $this->view->inline(function () use ($row) {
            $this->view->assign('title', $row ? 'View Shipping Region' : 'New Shipping Region');
            $this->view->block('right_sidebar', 'Shipping');
            $e = fn(string $s): string => $this->view->e($s);
            $id = (int) ($row['id'] ?? 0);

            echo '<div class="mb-3"><a class="text-decoration-none small" href="'
               . $e($this->url->to('store/admin/shipping-regions')) . '">'
               . '&larr; Shipping Regions</a></div>';
            echo '<h1 class="h3 mb-4">' . ($row ? 'View Shipping Region' : 'New Shipping Region') . '</h1>';

            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/shipping-regions/save'))
               . '" class="card border-0 shadow-sm"><div class="card-body">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<div class="mb-3"><label class="form-label">Region name</label>'
               . '<input type="text" name="name" class="form-control" required maxlength="128" value="'
               . $e((string) ($row['name'] ?? '')) . '"></div>';
            echo '<div class="mb-3"><label class="form-label">Countries (ISO2, comma-separated)</label>'
               . '<textarea name="countries" class="form-control" rows="3" placeholder="GB,IE,FR">'
               . $e((string) ($row['countries'] ?? '')) . '</textarea>'
               . '<div class="form-text">Leave empty for catch-all / rest-of-world regions.</div></div>';
            echo '<div class="mb-3"><label class="form-label">Postcode prefixes (optional)</label>'
               . '<textarea name="postcodes" class="form-control" rows="2" placeholder="BT,IM,GY">'
               . $e((string) ($row['postcodes'] ?? '')) . '</textarea></div>';
            echo '<div class="mb-3"><label class="form-label">Notes</label>'
               . '<input type="text" name="notes" class="form-control" maxlength="255" value="'
               . $e((string) ($row['notes'] ?? '')) . '"></div>';
            echo '<div class="d-flex gap-2">';
            echo '<button type="submit" class="btn btn-primary">Save</button>';
            echo '<a class="btn btn-outline-secondary" href="'
               . $e($this->url->to('store/admin/shipping-regions')) . '">Cancel</a>';
            if ($id > 0) {
                echo '<button type="submit" formaction="'
                   . $e($this->url->to('store/admin/shipping-regions/delete', ['id' => $id]))
                   . '" class="btn btn-outline-danger ms-auto"'
                   . ' onclick="return confirm(\'Delete this region?\');">Delete</button>';
            }
            echo '</div></div></form>';
        });
    }
}
