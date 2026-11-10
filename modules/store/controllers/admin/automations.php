<?php
declare(strict_types=1);

use Module\Store\Services\AutomationRepository;
use Module\Store\Services\AutomationRunner;
use Module\Store\Services\ChannelClientFactory;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\CredentialsVault;
use Module\Store\Services\SyncLogRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreAdminAutomations extends Controller
{
    private AutomationRepository $automations;
    private ChannelRepository $channels;
    private AutomationRunner $runner;
    private SyncLogRepository $syncLog;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->automations = new AutomationRepository($this->db);
        $this->channels = new ChannelRepository($this->db);
        $this->syncLog = new SyncLogRepository($this->db);
        $vault = CredentialsVault::forTenant(
            (string) $this->config->get('store.credentials_key', '')
        );
        $factory = new ChannelClientFactory($vault, $this->channels);
        $this->runner = new AutomationRunner(
            $this->db,
            $this->channels,
            $factory,
            $this->automations,
            $this->syncLog
        );
    }

    public function indexAction(): void
    {
        $rows = $this->automations->all();
        $errors = $this->syncLog->recentErrors(30, [], 168);
        $channels = $this->channels->enabled();

        echo $this->view->inline(function () use ($rows, $errors, $channels) {
            $this->view->assign('title', 'Automations');
            $this->view->block('right_sidebar', 'Integrations');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1">Automation hub</h1>'
               . '<p class="text-muted small mb-0">Manual run is enough for MVP. External cron can POST '
               . '<code>store/admin/automations/run-due</code>.</p></div>';
            echo '<form method="post" action="' . $e($this->url->to('store/admin/automations/run-due')) . '">'
               . $this->request->csrfField()
               . '<button class="btn btn-outline-primary btn-sm">Run due now</button></form>';
            echo '</div>';

            echo '<div class="card border-0 shadow-sm mb-4"><div class="card-body">';
            echo '<h2 class="h6">Add automation</h2>';
            echo '<form method="post" action="' . $e($this->url->to('store/admin/automations/save'))
               . '" class="row g-2 align-items-end">';
            echo $this->request->csrfField();
            echo '<div class="col-md-3"><label class="form-label small">Name</label>'
               . '<input class="form-control form-control-sm" name="name" required></div>';
            echo '<div class="col-md-3"><label class="form-label small">Job</label><select class="form-select form-select-sm" name="job_type">';
            foreach (AutomationRepository::JOB_TYPES as $jt) {
                echo '<option value="' . $e($jt) . '">' . $e($jt) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-3"><label class="form-label small">Channel</label>'
               . '<select class="form-select form-select-sm" name="channel_id"><option value="0">All connected</option>';
            foreach ($channels as $ch) {
                echo '<option value="' . (int) $ch->id . '">'
                   . $e(($ch->label !== '' ? $ch->label : $ch->name) . ' (' . $ch->type . ')') . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col-md-2"><label class="form-label small">Interval (min)</label>'
               . '<input type="number" min="5" class="form-control form-control-sm" name="interval_minutes" value="60"></div>';
            echo '<div class="col-md-1"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="en" checked>'
               . '<label class="form-check-label small" for="en">On</label></div></div>';
            echo '<div class="col-12"><button class="btn btn-sm btn-success">Save</button></div>';
            echo '</form></div></div>';

            if ($rows === []) {
                echo '<div class="alert alert-secondary">No automations yet.</div>';
            } else {
                echo '<div class="table-responsive mb-4"><table class="table table-sm table-hover align-middle bg-white border">';
                echo '<thead class="table-light"><tr><th>Name</th><th>Job</th><th>Channel</th><th>Interval</th>'
                   . '<th>Last run</th><th>Status</th><th></th></tr></thead><tbody>';
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    echo '<tr>';
                    echo '<td>' . $e((string) $row['name'])
                       . (empty($row['enabled']) ? ' <span class="badge text-bg-light border">off</span>' : '')
                       . '</td>';
                    echo '<td><code>' . $e((string) $row['job_type']) . '</code></td>';
                    echo '<td class="small">' . $e((string) ($row['channel_name'] !== '' ? $row['channel_name'] : 'All')) . '</td>';
                    echo '<td>' . (int) $row['interval_minutes'] . 'm</td>';
                    echo '<td class="small">' . $e((string) ($row['last_run_at'] ?? '—')) . '</td>';
                    echo '<td class="small">' . $e((string) ($row['last_status'] !== '' ? $row['last_status'] : '—')) . '</td>';
                    echo '<td class="text-end text-nowrap">';
                    echo '<form method="post" class="d-inline" action="'
                       . $e($this->url->to('store/admin/automations/run', ['id' => $id])) . '">'
                       . $this->request->csrfField()
                       . '<button class="btn btn-sm btn-outline-primary">Run now</button></form> ';
                    echo '<form method="post" class="d-inline" action="'
                       . $e($this->url->to('store/admin/automations/delete', ['id' => $id])) . '"'
                       . ' onsubmit="return confirm(\'Delete automation?\');">'
                       . $this->request->csrfField()
                       . '<button class="btn btn-sm btn-outline-danger">Delete</button></form>';
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }

            echo '<h2 class="h6">Recent sync_log errors</h2>';
            if ($errors === []) {
                echo '<div class="alert alert-success py-2">No recent errors.</div>';
            } else {
                echo '<div class="table-responsive"><table class="table table-sm bg-white border">'
                   . '<thead class="table-light"><tr><th>When</th><th>Action</th><th>Summary</th></tr></thead><tbody>';
                foreach ($errors as $err) {
                    echo '<tr><td class="small text-nowrap">' . $e((string) ($err['created_at'] ?? '')) . '</td>'
                       . '<td><code>' . $e((string) ($err['action'] ?? '')) . '</code></td>'
                       . '<td class="small">' . $e((string) ($err['summary'] ?? '')) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
        });
    }

    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/automations');
            return;
        }
        try {
            $this->request->checkToken();
            $id = (int) $this->request->post('id', 'int', 0);
            $this->automations->save($id > 0 ? $id : null, [
                'name'             => (string) $this->request->post('name', 'string', ''),
                'job_type'         => (string) $this->request->post('job_type', 'string', 'orders_sync'),
                'channel_id'       => (int) $this->request->post('channel_id', 'int', 0),
                'interval_minutes' => (int) $this->request->post('interval_minutes', 'int', 60),
                'enabled'          => $this->request->post('enabled', 'string', '') === '1',
            ]);
            $this->notify->success('Automation saved.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/automations');
    }

    public function runAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/automations');
            return;
        }
        try {
            $this->request->checkToken();
            $id = (int) $this->request->get('id', 'int', 0);
            if ($this->automations->find($id) === null) {
                throw new NotFoundException('Automation not found.');
            }
            $r = $this->runner->runOne($id);
            $this->notify->success('Run ok: ' . $r['summary']);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/automations');
    }

    public function runDueAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/automations');
            return;
        }
        try {
            $this->request->checkToken();
            $results = $this->runner->runDue();
            $ok = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'ok'));
            $this->notify->success('Ran ' . count($results) . ' due job(s); ' . $ok . ' ok.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/automations');
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/automations');
            return;
        }
        try {
            $this->request->checkToken();
            $this->automations->delete((int) $this->request->get('id', 'int', 0));
            $this->notify->success('Deleted.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('store/admin/automations');
    }
}
