<?php
declare(strict_types=1);

use Module\Store\Services\CustomerRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreAdminCustomers extends Controller
{
    private CustomerRepository $customers;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->customers = new CustomerRepository($this->db);
        // Permissions: Action.php only.
    }

    public function indexAction(): void
    {
        if ($this->customers->count() === 0) {
            $this->customers->syncFromOrders();
        }

        $filters = [
            'reference' => trim((string) $this->request->get('f_reference', 'string', '')),
            'name'      => trim((string) $this->request->get('f_name', 'string', '')),
            'created'   => trim((string) $this->request->get('f_created', 'string', '')),
            'company'   => trim((string) $this->request->get('f_company', 'string', '')),
            'address1'  => trim((string) $this->request->get('f_address1', 'string', '')),
            'city'      => trim((string) $this->request->get('f_city', 'string', '')),
            'postcode'  => trim((string) $this->request->get('f_postcode', 'string', '')),
            'email'     => trim((string) $this->request->get('f_email', 'string', '')),
        ];
        $sort = (string) $this->request->get('sort', 'string', 'created');
        $dir = strtolower((string) $this->request->get('dir', 'string', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($sort, ['reference', 'name', 'created', 'company', 'address1', 'city', 'postcode', 'email'], true)) {
            $sort = 'created';
        }

        $rows = $this->customers->search($filters, $sort, $dir, 200, 0);
        $total = $this->customers->count($filters);
        $orderStats = $this->customers->orderStatsForMany($rows);
        $baseParams = array_filter(
            [
                'f_reference' => $filters['reference'],
                'f_name'      => $filters['name'],
                'f_created'   => $filters['created'],
                'f_company'   => $filters['company'],
                'f_address1'  => $filters['address1'],
                'f_city'      => $filters['city'],
                'f_postcode'  => $filters['postcode'],
                'f_email'     => $filters['email'],
                'sort'        => $sort,
                'dir'         => $dir,
            ],
            static fn($v) => $v !== '' && $v !== null
        );

        echo $this->view->inline(function () use ($rows, $filters, $sort, $dir, $total, $baseParams, $orderStats) {
            $this->view->assign('title', 'Customers');
            $this->view->block('right_sidebar', 'Customers');
            $e = fn(string $s): string => $this->view->e($s);

            $sortLink = function (string $key, string $label) use ($e, $sort, $dir, $baseParams): string {
                $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
                $params = $baseParams;
                $params['sort'] = $key;
                $params['dir'] = $next;
                $icon = 'bi-arrow-down-up';
                $on = '';
                if ($sort === $key) {
                    $icon = $dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
                    $on = ' is-on';
                }
                return '<a class="sf-cust-sort' . $on . '" href="'
                     . $e($this->url->to('store/admin/customers', $params)) . '">'
                     . $e($label) . ' <i class="bi ' . $icon . '"></i></a>';
            };

            echo '<style>
.sf-cust-page{color:#1f2937}
.sf-cust-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1rem;flex-wrap:wrap}
.sf-cust-head h1{margin:0;font-size:1.35rem;font-weight:650;letter-spacing:-.02em}
.sf-cust-head p{margin:.2rem 0 0;color:#6b7280;font-size:.84rem}
.sf-cust-actions{display:flex;flex-wrap:wrap;gap:.45rem}
.sf-cust-btn{display:inline-flex;align-items:center;gap:.4rem;height:2.15rem;padding:0 .85rem;border:1px solid #d1d5db;border-radius:.5rem;background:#fff;color:#374151;font-size:.84rem;font-weight:600}
.sf-cust-btn:hover{background:#f3f4f6;color:#111827}
.sf-cust-btn.is-new{background:#16a34a;border-color:#16a34a;color:#fff}
.sf-cust-btn.is-new:hover{background:#15803d;color:#fff}
.sf-cust-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.sf-cust-toolbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.55rem .85rem;border-bottom:1px solid #eef0f3;flex-wrap:wrap}
.sf-cust-tools,.sf-cust-meta{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.sf-cust-tool{display:inline-flex;align-items:center;height:1.85rem;padding:0 .65rem;border:1px solid #d1d5db;border-radius:.4rem;background:#fff;color:#374151;font-size:.75rem;font-weight:600}
.sf-cust-tool:hover{background:#f3f4f6}
.sf-cust-count{font-size:.78rem;color:#6b7280;font-weight:600}
.sf-cust-table{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
.sf-cust-table thead th{background:#4b5563;color:#fff;font-weight:500;font-size:.72rem;letter-spacing:.02em;border:0;white-space:nowrap;padding:.6rem .7rem;vertical-align:middle}
.sf-cust-sort{color:#fff;text-decoration:none}
.sf-cust-sort:hover,.sf-cust-sort.is-on{color:#dbeafe}
.sf-cust-sort i{font-size:.7rem;opacity:.75}
.sf-cust-table tbody td{border-top:1px solid #f1f5f9;padding:.55rem .7rem;font-size:.8rem;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sf-cust-table tbody tr:hover{background:#f8fafc}
.sf-cust-filters th{background:#f8fafc!important;color:#6b7280!important;padding:.4rem .5rem!important}
.sf-cust-filters input{width:100%;min-width:0;height:1.85rem;border:1px solid #e5e7eb;border-radius:.35rem;padding:.15rem .45rem;font-size:.72rem;background:#fff}
.sf-cust-table .c-check{width:2.4rem;text-align:center}
.sf-cust-table .c-date{width:7.2rem}
.sf-cust-table .c-post{width:6.4rem}
.sf-cust-table .c-orders{width:4.6rem;text-align:right}
.sf-cust-ref{font-weight:650;color:#111827;text-decoration:none}
.sf-cust-ref:hover{color:#2563eb}
.sf-cust-muted{color:#9ca3af}
.sf-cust-biz{display:inline-flex;margin-left:.3rem;padding:.05rem .35rem;border-radius:.3rem;background:#eff6ff;color:#1d4ed8;font-size:.62rem;font-weight:700}
</style>';

            echo '<div class="sf-cust-page">';
            echo '<div class="sf-cust-head">';
            echo '<div><h1>Customers</h1>'
               . '<p>Synced from orders or created manually.</p></div>';
            echo '<div class="sf-cust-actions">';
            echo '<form method="post" action="' . $e($this->url->to('store/admin/customers/sync')) . '">';
            echo $this->request->csrfField();
            echo '<button type="submit" class="sf-cust-btn">'
               . '<i class="bi bi-arrow-repeat"></i>Sync from orders</button>';
            echo '</form>';
            echo '<form method="post" action="' . $e($this->url->to('store/admin/customers/add')) . '">';
            echo $this->request->csrfField();
            echo '<button type="submit" class="sf-cust-btn is-new">'
               . '<i class="bi bi-plus-lg"></i>New customer</button>';
            echo '</form>';
            echo '</div></div>';

            echo '<form id="sf-cust-filters" method="get" action="'
               . $e($this->url->to('store/admin/customers')) . '" class="d-none">';
            echo '<input type="hidden" name="sort" value="' . $e($sort) . '">';
            echo '<input type="hidden" name="dir" value="' . $e($dir) . '">';
            echo '</form>';

            echo '<form method="post" action="' . $e($this->url->to('store/admin/customers/bulk'))
               . '" id="sf-cust-bulk">';
            echo $this->request->csrfField();
            echo '<input type="hidden" name="action" id="sf-cust-bulk-action" value="">';

            echo '<div class="sf-cust-card">';
            echo '<div class="sf-cust-toolbar">';
            echo '<div class="sf-cust-tools">';
            echo '<input type="checkbox" class="form-check-input" id="sf-cust-select-all" title="Select all">';
            echo '<button type="submit" class="sf-cust-tool" data-cust-action="delete"'
               . ' onclick="return sfCustBulk(this)">Delete</button>';
            echo '<button type="submit" class="sf-cust-tool" data-cust-action="clone"'
               . ' onclick="return sfCustBulk(this)">Clone</button>';
            echo '<button type="submit" class="sf-cust-tool" data-cust-action="export"'
               . ' onclick="return sfCustBulk(this)">Export</button>';
            echo '</div>';
            echo '<div class="sf-cust-meta">';
            echo '<a class="sf-cust-tool" href="'
               . $e($this->url->to('store/admin/customers')) . '">Reset filters</a>';
            echo '<span class="sf-cust-count">' . number_format($total) . ' customers</span>';
            echo '</div></div>';

            echo '<div class="table-responsive">';
            echo '<table class="sf-cust-table">';
            echo '<thead><tr>';
            echo '<th class="c-check"></th>';
            echo '<th>' . $sortLink('reference', 'Reference') . '</th>';
            echo '<th>' . $sortLink('name', 'Name') . '</th>';
            echo '<th class="c-date">' . $sortLink('created', 'Created') . '</th>';
            echo '<th>' . $sortLink('company', 'Company') . '</th>';
            echo '<th>' . $sortLink('address1', 'Address') . '</th>';
            echo '<th>' . $sortLink('city', 'City') . '</th>';
            echo '<th class="c-post">' . $sortLink('postcode', 'Postcode') . '</th>';
            echo '<th>' . $sortLink('email', 'Email') . '</th>';
            echo '<th class="c-orders">Orders</th>';
            echo '</tr>';

            echo '<tr class="sf-cust-filters">';
            echo '<th class="c-check"></th>';
            $filterCols = [
                'f_reference' => 'reference',
                'f_name'      => 'name',
                'f_created'   => 'created',
                'f_company'   => 'company',
                'f_address1'  => 'address1',
                'f_city'      => 'city',
                'f_postcode'  => 'postcode',
                'f_email'     => 'email',
            ];
            foreach ($filterCols as $param => $key) {
                $cls = $key === 'created' ? ' c-date' : ($key === 'postcode' ? ' c-post' : '');
                echo '<th class="' . trim($cls) . '"><input type="text" name="'
                   . $e($param) . '" value="' . $e($filters[$key]) . '"'
                   . ' form="sf-cust-filters"></th>';
            }
            echo '<th class="c-orders"></th>';
            echo '</tr></thead><tbody>';

            if ($rows === []) {
                echo '<tr><td colspan="10" class="text-muted text-center py-4">No customers to display.</td></tr>';
            }

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $created = (string) ($row['created_at'] ?? '');
                $createdLabel = $created !== ''
                    ? date('d M H:i', strtotime($created) ?: time())
                    : '—';
                $email = trim((string) ($row['email'] ?? ''));
                $viewUrl = $this->url->to('store/admin/customers/edit', ['id' => $id]);
                $orderCount = (int) ($orderStats[$id]['count'] ?? 0);
                $ordersUrl = $email !== ''
                    ? $this->url->to('store/admin/orders', ['f_email' => $email])
                    : $this->url->to('store/admin/orders', ['q' => (string) $row['reference']]);
                $cell = static function (string $value) use ($e): string {
                    $value = trim($value);
                    return $value === ''
                        ? '<span class="sf-cust-muted">—</span>'
                        : $e($value);
                };

                echo '<tr>';
                echo '<td class="c-check"><input type="checkbox" class="form-check-input sf-cust-row" name="ids[]" value="'
                   . $id . '"></td>';
                echo '<td><a class="sf-cust-ref" href="' . $e($viewUrl) . '" title="'
                   . $e((string) $row['reference']) . '">' . $e((string) $row['reference']) . '</a>';
                if (!empty($row['is_business'])) {
                    echo '<span class="sf-cust-biz">Biz</span>';
                }
                echo '</td>';
                echo '<td title="' . $e((string) ($row['name'] ?? '')) . '">'
                   . $cell((string) ($row['name'] ?? '')) . '</td>';
                echo '<td class="c-date">' . $e($createdLabel) . '</td>';
                echo '<td title="' . $e((string) ($row['bill_company'] ?? '')) . '">'
                   . $cell((string) ($row['bill_company'] ?? '')) . '</td>';
                echo '<td title="' . $e((string) ($row['bill_address1'] ?? '')) . '">'
                   . $cell((string) ($row['bill_address1'] ?? '')) . '</td>';
                echo '<td title="' . $e((string) ($row['bill_city'] ?? '')) . '">'
                   . $cell((string) ($row['bill_city'] ?? '')) . '</td>';
                echo '<td class="c-post">' . $cell((string) ($row['bill_postcode'] ?? '')) . '</td>';
                echo '<td title="' . $e($email) . '">'
                   . ($email !== '' ? $e($email) : '<span class="sf-cust-muted">—</span>') . '</td>';
                echo '<td class="c-orders">';
                if ($orderCount > 0) {
                    echo '<a href="' . $e($ordersUrl) . '">' . $orderCount . '</a>';
                } else {
                    echo '<span class="sf-cust-muted">0</span>';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div></div></form></div>';

            echo <<<'JS'
<script>
(function () {
  const filterForm = document.getElementById('sf-cust-filters');
  if (filterForm) {
    let t = null;
    document.querySelectorAll('.sf-cust-filters input').forEach(function (inp) {
      inp.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () { filterForm.submit(); }, 400);
      });
      inp.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); filterForm.submit(); }
      });
    });
  }
  const selectAll = document.getElementById('sf-cust-select-all');
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      document.querySelectorAll('.sf-cust-row').forEach(function (cb) {
        cb.checked = selectAll.checked;
      });
    });
  }
  window.sfCustBulk = function (btn) {
    const action = btn.getAttribute('data-cust-action') || '';
    document.getElementById('sf-cust-bulk-action').value = action;
    const checked = document.querySelectorAll('.sf-cust-row:checked');
    if (action !== 'export' && checked.length === 0) {
      alert('Select at least one customer.');
      return false;
    }
    if (action === 'delete') {
      return confirm('Delete ' + checked.length + ' customer(s)?');
    }
    return true;
  };
})();
</script>
JS;
        });
    }

    public function addAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/customers');
            return;
        }

        try {
            $created = $this->customers->create();
            $this->notify->success('Customer created.');
            redirect_to('store/admin/customers/edit', ['id' => $created['id']]);
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
            redirect_to('store/admin/customers');
        }
    }

    public function syncAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/customers');
            return;
        }

        try {
            $created = $this->customers->syncFromOrders();
            if ($created > 0) {
                $this->notify->success("Synced {$created} new customer(s) from orders.");
            } else {
                $this->notify->success('No new customers found in recent orders.');
            }
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/customers');
    }

    public function bulkAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/customers');
            return;
        }

        $action = trim((string) $this->request->post('action', 'string', ''));
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->request->post('ids', 'raw', [])),
            static fn(int $id) => $id > 0
        )));

        try {
            if ($action === 'delete') {
                if ($ids === []) {
                    throw new \InvalidArgumentException('Select at least one customer.');
                }
                $n = $this->customers->deleteMany($ids);
                $this->notify->success("Deleted {$n} customer(s).");
            } elseif ($action === 'clone') {
                if ($ids === []) {
                    throw new \InvalidArgumentException('Select at least one customer.');
                }
                $n = 0;
                foreach ($ids as $id) {
                    $this->customers->duplicate($id);
                    $n++;
                }
                $this->notify->success("Cloned {$n} customer(s).");
            } elseif ($action === 'export') {
                $this->streamCsvExport($ids);
                return;
            } else {
                throw new \InvalidArgumentException('Unknown bulk action.');
            }
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/customers');
    }

    public function exportAction(): void
    {
        $this->streamCsvExport([]);
    }

    /** @param list<int> $ids Empty = all matching current filters / all */
    private function streamCsvExport(array $ids): void
    {
        $filters = [];
        if ($ids !== []) {
            $rows = [];
            foreach ($ids as $id) {
                $row = $this->customers->find($id);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        } else {
            $rows = $this->customers->search([], 'created', 'desc', 500, 0);
            // Expand to full rows for export
            $full = [];
            foreach ($rows as $r) {
                $found = $this->customers->find((int) $r['id']);
                if ($found !== null) {
                    $full[] = $found;
                }
            }
            $rows = $full;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="customers-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            throw new \RuntimeException('Unable to open output stream.');
        }
        fputcsv($out, [
            'Customer Reference', 'Name', 'Create Date', 'Company', 'Address Line 1',
            'City', 'Postcode', 'Email Address', 'Phone',
        ]);
        foreach ($rows as $row) {
            $created = (string) ($row['created_at'] ?? '');
            fputcsv($out, [
                (string) ($row['reference'] ?? ''),
                (string) ($row['name'] ?? ''),
                $created !== '' ? date('d/m/Y H:i', strtotime($created) ?: time()) : '',
                (string) ($row['bill_company'] ?? ''),
                (string) ($row['bill_address1'] ?? ''),
                (string) ($row['bill_city'] ?? ''),
                (string) ($row['bill_postcode'] ?? ''),
                (string) (($row['email'] ?? '') !== '' ? $row['email'] : 'N/A'),
                (string) ($row['phone'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $customer = $this->customers->find($id);
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }

        if ($this->request->isPost()) {
            try {
                $customer = $this->customers->update($id, $this->postedCustomer());
                $this->notify->success('Customer saved.');
                redirect_to('store/admin/customers/edit', ['id' => $id]);
                return;
            } catch (\Throwable $ex) {
                $this->notify->error($ex->getMessage());
                $customer = array_merge($customer, $this->postedCustomer());
                $customer['is_business'] = !empty($customer['is_business']);
            }
        }

        $identity = [
            'email'     => (string) ($customer['email'] ?? ''),
            'reference' => (string) ($customer['reference'] ?? ''),
        ];
        $orderStats = $this->customers->orderStats($identity);
        $recentOrders = $this->customers->recentOrders($identity, 12);
        $correspondence = $this->customers->recentCorrespondence($identity, 12);

        $countries = $this->countries();
        echo $this->view->inline(function () use (
            $customer,
            $id,
            $countries,
            $orderStats,
            $recentOrders,
            $correspondence
        ) {
            $this->view->assign('title', 'View Customer');
            $this->view->block('right_sidebar', 'Customers');
            $e = fn(string $s): string => $this->view->e($s);
            $v = fn(string $key): string => (string) ($customer[$key] ?? '');

            $created = $v('created_at');
            $createdLabel = $created !== ''
                ? date('d/m/Y H:i:s', strtotime($created) ?: time())
                : '—';
            $email = trim($v('email'));
            $ordersListUrl = $email !== ''
                ? $this->url->to('store/admin/orders', ['f_email' => $email])
                : $this->url->to('store/admin/orders', ['q' => $v('reference')]);

            echo $this->customerStyles();

            echo '<div class="mb-2"><a class="small text-decoration-none" href="'
               . $e($this->url->to('store/admin/customers')) . '">&lt;&lt; Back to Overview</a></div>';

            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/customers/edit', ['id' => $id]))
               . '" id="sf-customer-form">';
            echo $this->request->csrfField();

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1">View Customer</h1>'
               . '<div class="small text-muted">' . $e($v('reference'));
            if ($orderStats['count'] > 0) {
                echo ' · <a href="' . $e($ordersListUrl) . '">' . (int) $orderStats['count']
                   . ' order' . ($orderStats['count'] === 1 ? '' : 's') . '</a>'
                   . ' · ' . $e(number_format($orderStats['spend'], 2) . ' ' . $orderStats['currency']);
            }
            echo '</div></div>';
            echo '<div class="d-flex flex-wrap gap-2">';
            echo '<a class="btn btn-outline-secondary" href="' . $e($ordersListUrl) . '">'
               . '<i class="bi bi-bag me-1"></i>Orders</a>';
            echo '<button type="submit" class="btn btn-primary">Save</button>';
            echo '</div></div>';

            echo '<div class="row g-3">';

            // Basic Info
            echo '<div class="col-lg-6">';
            echo '<div class="sf-panel"><div class="sf-panel-h">Basic Info</div><div class="sf-panel-b">';
            echo $this->fieldRow('Customer Reference', '<div class="form-control-plaintext py-1">'
               . $e($v('reference')) . '</div>');
            echo $this->fieldRow('Created On', '<div class="form-control-plaintext py-1">'
               . $e($createdLabel) . '</div>');
            echo $this->fieldRow('Name' . $this->req(), $this->textInput('name', $v('name'), true));
            echo $this->fieldRow('Email', $this->textInput('email', $v('email'), false, 'email'));
            echo $this->fieldRow('Email CC (comma-separated)', $this->textInput('email_cc', $v('email_cc')));
            echo $this->fieldRow('Phone', $this->textInput('phone', $v('phone')));
            echo $this->fieldRow('VAT Number', $this->textInput('vat_number', $v('vat_number')));
            echo $this->fieldRow('AIR Number', $this->textInput('air_number', $v('air_number')));
            echo $this->fieldRow('UKIMS Number', $this->textInput('ukims_number', $v('ukims_number')));
            echo $this->fieldRow('EORI Number', $this->textInput('eori_number', $v('eori_number')));
            echo $this->fieldRow(
                'Is a Business Customer',
                '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="is_business" value="1" id="is_business"'
                . (!empty($customer['is_business']) ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="is_business"></label></div>'
            );
            echo $this->fieldRow(
                'Notes',
                '<textarea class="form-control form-control-sm" name="notes" rows="4">'
                . $e($v('notes')) . '</textarea>',
                true
            );
            echo '</div></div></div>';

            // Correspondence
            echo '<div class="col-lg-6">';
            echo '<div class="sf-panel"><div class="sf-panel-h sf-panel-h-muted d-flex justify-content-between align-items-center">';
            echo '<span>Correspondence</span>';
            echo '<a class="small link-light text-decoration-underline" href="'
               . $e($this->url->to('store/admin/messages')) . '">Messages</a>';
            echo '</div><div class="sf-panel-b">';
            if ($correspondence === []) {
                echo '<p class="text-muted small mb-0">No messages or conversations matched this customer yet.</p>';
            } else {
                echo '<div class="list-group list-group-flush sf-corr-list">';
                foreach ($correspondence as $item) {
                    $at = (string) ($item['at'] ?? '');
                    $atLabel = $at !== '' ? date('d/m/Y H:i', strtotime($at) ?: time()) : '';
                    $href = $item['kind'] === 'conversation'
                        ? $this->url->to('store/admin/messages/conversation', [
                            'channel_id' => (int) $item['channel_id'],
                            'id'         => (string) ($item['external_id'] ?? ''),
                        ])
                        : $this->url->to('store/admin/messages/view', [
                            'channel_id' => (int) $item['channel_id'],
                            'id'         => (string) ($item['external_id'] ?? $item['id']),
                        ]);
                    $title = trim((string) ($item['subject'] ?? '')) !== ''
                        ? (string) $item['subject']
                        : ((string) ($item['party'] ?: 'Message'));
                    echo '<a class="list-group-item list-group-item-action px-0 py-2" href="'
                       . $e($href) . '">';
                    echo '<div class="d-flex justify-content-between gap-2">';
                    echo '<span class="fw-semibold small">' . $e($title) . '</span>';
                    if (!empty($item['unread'])) {
                        echo '<span class="badge text-bg-primary">Unread</span>';
                    }
                    echo '</div>';
                    echo '<div class="small text-muted">'
                       . $e((string) ($item['channel_label'] ?? ''))
                       . ($atLabel !== '' ? ' · ' . $e($atLabel) : '')
                       . '</div>';
                    if (($item['preview'] ?? '') !== '') {
                        echo '<div class="small">' . $e((string) $item['preview']) . '</div>';
                    }
                    echo '</a>';
                }
                echo '</div>';
            }
            echo '</div></div></div>';

            // Billing Address
            echo '<div class="col-lg-6">';
            echo '<div class="sf-panel"><div class="sf-panel-h sf-panel-h-muted">Billing Address</div><div class="sf-panel-b">';
            echo $this->addressFields('bill', $customer, $countries);
            echo '</div></div></div>';

            // Shipping Address
            echo '<div class="col-lg-6">';
            echo '<div class="sf-panel"><div class="sf-panel-h sf-panel-h-muted d-flex justify-content-between align-items-center">';
            echo '<span>Shipping Address</span>';
            echo '<a href="#" class="small link-light text-decoration-underline" id="sf-same-as-billing">Same as billing</a>';
            echo '</div><div class="sf-panel-b">';
            echo $this->addressFields('ship', $customer, $countries);
            echo '</div></div></div>';

            // Orders
            echo '<div class="col-12">';
            echo '<div class="sf-panel"><div class="sf-panel-h d-flex justify-content-between align-items-center">';
            echo '<span>Orders</span>';
            echo '<a class="small link-light text-decoration-underline" href="'
               . $e($ordersListUrl) . '">View all</a>';
            echo '</div><div class="sf-panel-b p-0">';
            if ($recentOrders === []) {
                echo '<p class="text-muted small mb-0 p-3">No linked orders yet.</p>';
            } else {
                echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">';
                echo '<thead class="table-light"><tr>'
                   . '<th>Order</th><th>Channel</th><th>Status</th>'
                   . '<th class="text-end">Total</th><th>Date</th>'
                   . '</tr></thead><tbody>';
                foreach ($recentOrders as $order) {
                    $oid = (int) $order['id'];
                    $createdAt = (string) ($order['created_at'] ?? '');
                    $dateLabel = $createdAt !== ''
                        ? date('d/m/Y H:i', strtotime($createdAt) ?: time())
                        : '';
                    $orderUrl = $this->url->to('store/admin/orders/view', ['id' => $oid]);
                    $label = (string) (($order['external_order_id'] ?? '') !== ''
                        ? $order['external_order_id']
                        : '#' . $oid);
                    echo '<tr>';
                    echo '<td><a href="' . $e($orderUrl) . '">' . $e($label) . '</a></td>';
                    echo '<td class="small">' . $e((string) ($order['channel_label'] ?? '')) . '</td>';
                    echo '<td><span class="badge text-bg-secondary">'
                       . $e((string) ($order['status'] ?? '')) . '</span></td>';
                    echo '<td class="text-end text-nowrap">'
                       . $e(number_format((float) $order['total'], 2) . ' ' . $order['currency'])
                       . '</td>';
                    echo '<td class="text-nowrap small">' . $e($dateLabel) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div></div></div>';

            echo '</div>'; // row

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">';
            echo '<div class="small text-muted"><span class="text-danger">*</span> required field</div>';
            echo '<div class="d-flex gap-2">';
            echo '<a class="btn btn-link" href="' . $e($this->url->to('store/admin/customers')) . '">Cancel</a>';
            echo '<button type="submit" class="btn btn-primary">Save</button>';
            echo '</div></div>';

            echo '</form>';
            echo $this->customerScript();
        });
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/customers');
            return;
        }

        $id = (int) $this->request->get('id', 'int', 0);
        $customer = $this->customers->find($id);
        if ($customer === null) {
            $this->notify->error('Customer not found.');
            redirect_to('store/admin/customers');
            return;
        }

        try {
            $this->customers->delete($id);
            $label = (string) ($customer['name'] !== '' ? $customer['name'] : $customer['reference']);
            $this->notify->success("Customer '{$label}' deleted.");
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        redirect_to('store/admin/customers');
    }

    /** @return array<string, mixed> */
    private function postedCustomer(): array
    {
        $keys = [
            'name', 'email', 'email_cc', 'phone', 'vat_number', 'air_number', 'ukims_number', 'eori_number', 'notes',
            'bill_company', 'bill_address1', 'bill_address2', 'bill_city', 'bill_county', 'bill_country', 'bill_postcode',
            'ship_company', 'ship_address1', 'ship_address2', 'ship_city', 'ship_county', 'ship_country', 'ship_postcode',
        ];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (string) $this->request->post($key, 'string', '');
        }
        $out['is_business'] = $this->request->post('is_business', 'string', '') !== '' ? 1 : 0;
        return $out;
    }

    /** @param array<string, string> $countries @param array<string, mixed> $customer */
    private function addressFields(string $prefix, array $customer, array $countries): string
    {
        $e = fn(string $s): string => $this->view->e($s);
        $val = fn(string $suffix): string => (string) ($customer[$prefix . '_' . $suffix] ?? '');

        $html = '';
        $html .= $this->fieldRow('Company', $this->textInput($prefix . '_company', $val('company')));
        $html .= $this->fieldRow('Address 1' . $this->req(), $this->textInput($prefix . '_address1', $val('address1'), true));
        $html .= $this->fieldRow('Address 2', $this->textInput($prefix . '_address2', $val('address2')));
        $html .= $this->fieldRow('City' . $this->req(), $this->textInput($prefix . '_city', $val('city'), true));
        $html .= $this->fieldRow('County', $this->textInput($prefix . '_county', $val('county')));

        $select = '<select class="form-select form-select-sm" name="' . $e($prefix . '_country') . '" id="'
                . $e($prefix . '_country') . '">';
        $current = $val('country') !== '' ? $val('country') : 'GB';
        foreach ($countries as $code => $label) {
            $sel = strcasecmp($current, (string) $code) === 0 ? ' selected' : '';
            $select .= '<option value="' . $e((string) $code) . '"' . $sel . '>' . $e($label) . '</option>';
        }
        $select .= '</select>';
        $html .= $this->fieldRow('Country', $select);

        $post = '<div class="d-flex flex-wrap align-items-center gap-2">'
              . $this->textInput($prefix . '_postcode', $val('postcode'), true)
              . '<a href="#" class="small sf-find-address" data-prefix="' . $e($prefix)
              . '" title="Open map search for this postcode">Find Address</a>'
              . '</div>';
        $html .= $this->fieldRow('Postcode' . $this->req(), $post);

        return $html;
    }

    private function fieldRow(string $label, string $control, bool $topAlign = false): string
    {
        $align = $topAlign ? ' align-items-start' : '';
        return '<div class="sf-field-row' . $align . '"><label>' . $label . '</label><div>' . $control . '</div></div>';
    }

    private function textInput(string $name, string $value, bool $required = false, string $type = 'text'): string
    {
        $e = fn(string $s): string => $this->view->e($s);
        return '<input type="' . $e($type) . '" class="form-control form-control-sm" name="' . $e($name) . '" id="'
             . $e($name) . '" value="' . $e($value) . '"'
             . ($required ? ' required' : '') . '>';
    }

    private function req(): string
    {
        return ' <span class="text-danger">*</span>';
    }

    /** @return array<string, string> */
    private function countries(): array
    {
        return [
            'GB' => 'UNITED KINGDOM',
            'IE' => 'IRELAND',
            'US' => 'UNITED STATES',
            'DE' => 'GERMANY',
            'FR' => 'FRANCE',
            'IT' => 'ITALY',
            'ES' => 'SPAIN',
            'NL' => 'NETHERLANDS',
            'BE' => 'BELGIUM',
            'PL' => 'POLAND',
            'PT' => 'PORTUGAL',
            'SE' => 'SWEDEN',
            'AU' => 'AUSTRALIA',
            'CA' => 'CANADA',
            'CN' => 'CHINA',
            'IN' => 'INDIA',
            'VN' => 'VIETNAM',
            'OTHER' => 'OTHER',
        ];
    }

    private function customerStyles(): string
    {
        return <<<'CSS'
<style>
.sf-panel{border:1px solid #d9dee7;border-radius:.35rem;margin-bottom:0;overflow:hidden;background:#fff}
.sf-panel-h{background:#1e4d8c;color:#fff;padding:.55rem 1rem;font-weight:600;font-size:.95rem}
.sf-panel-h-muted{background:#6c757d}
.sf-panel-b{padding:1rem 1.1rem}
.sf-field-row{display:grid;grid-template-columns:minmax(140px,200px) minmax(160px,1fr);gap:.75rem;align-items:center;padding:.4rem 0;border-bottom:1px solid #f0f2f5}
.sf-field-row:last-child{border-bottom:0}
.sf-field-row label{margin:0;font-size:.9rem}
.sf-corr-list .list-group-item{border-left:0;border-right:0}
.sf-corr-list .list-group-item:first-child{border-top:0}
@media (max-width:900px){.sf-field-row{grid-template-columns:1fr}}
</style>
CSS;
    }

    private function customerScript(): string
    {
        return <<<'JS'
<script>
(function () {
  var same = document.getElementById('sf-same-as-billing');
  if (same) {
    same.addEventListener('click', function (ev) {
      ev.preventDefault();
      ['company','address1','address2','city','county','country','postcode'].forEach(function (f) {
        var from = document.getElementById('bill_' + f);
        var to = document.getElementById('ship_' + f);
        if (from && to) to.value = from.value;
      });
    });
  }
  document.querySelectorAll('.sf-find-address').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      ev.preventDefault();
      var prefix = a.getAttribute('data-prefix') || 'bill';
      var postcodeEl = document.getElementById(prefix + '_postcode');
      var cityEl = document.getElementById(prefix + '_city');
      var countryEl = document.getElementById(prefix + '_country');
      var postcode = postcodeEl ? String(postcodeEl.value || '').trim() : '';
      if (!postcode) {
        alert('Enter a postcode first.');
        if (postcodeEl) postcodeEl.focus();
        return;
      }
      var bits = [postcode];
      if (cityEl && cityEl.value) bits.push(String(cityEl.value).trim());
      if (countryEl && countryEl.value) bits.push(String(countryEl.value).trim());
      var url = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(bits.join(', '));
      window.open(url, '_blank', 'noopener');
    });
  });
})();
</script>
JS;
    }
}
