<?php
declare(strict_types=1);

use Module\Store\Services\SyncLogRepository;
use System\Engine\Controller;

class StoreAdminSyncHealth extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
    }

    public function indexAction(): void
    {
        $repo = new SyncLogRepository($this->db);
        $health = $repo->healthByChannel();
        $recent = $repo->recentErrors(30, [], 72);

        echo $this->view->inline(function () use ($health, $recent) {
            $this->view->assign('title', 'Sync health');
            $this->view->block('right_sidebar', 'Integrations');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-activity me-1"></i>Sync health</h1>'
               . '<p class="text-muted small mb-0">Last OK / error per channel action.</p></div>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/channels')) . '">Channels</a>';
            echo '</div>';

            if ($health === []) {
                echo '<div class="alert alert-info">No sync log entries yet. Run a channel sync to populate health.</div>';
            } else {
                echo '<div class="table-responsive mb-4"><table class="table table-sm table-hover align-middle bg-white border">';
                echo '<thead class="table-light"><tr>'
                   . '<th>Channel</th><th>Action</th><th>Last status</th>'
                   . '<th>Last OK</th><th>Last error</th><th>Error summary</th>'
                   . '</tr></thead><tbody>';
                foreach ($health as $row) {
                    $st = strtolower((string) $row['last_status']);
                    $badge = match ($st) {
                        'ok' => 'text-bg-success',
                        'error' => 'text-bg-danger',
                        'skipped' => 'text-bg-secondary',
                        default => 'text-bg-light',
                    };
                    $errorNewer = $row['last_error_at'] !== null
                        && ($row['last_ok_at'] === null || strcmp((string) $row['last_error_at'], (string) $row['last_ok_at']) > 0);
                    echo '<tr' . ($errorNewer ? ' class="table-danger"' : '') . '>';
                    echo '<td>' . $e($row['channel_label'] !== '' ? $row['channel_label'] : '#' . $row['channel_id'])
                       . ' <span class="text-muted small">' . $e($row['channel_type']) . '</span></td>';
                    echo '<td><code>' . $e($row['action']) . '</code></td>';
                    echo '<td><span class="badge ' . $badge . '">' . $e($row['last_status']) . '</span></td>';
                    echo '<td class="small text-nowrap">' . $e($row['last_ok_at'] ?? '—') . '</td>';
                    echo '<td class="small text-nowrap">' . $e($row['last_error_at'] ?? '—') . '</td>';
                    echo '<td class="small">' . $e($row['last_error_summary'] !== '' ? $row['last_error_summary'] : '—') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }

            echo '<h2 class="h5 mb-2">Recent errors (72h)</h2>';
            if ($recent === []) {
                echo '<p class="text-muted small">No recent errors.</p>';
            } else {
                echo '<div class="table-responsive"><table class="table table-sm align-middle bg-white border">';
                echo '<thead class="table-light"><tr>'
                   . '<th>When</th><th>Channel</th><th>Action</th><th>Summary</th>'
                   . '</tr></thead><tbody>';
                foreach ($recent as $r) {
                    echo '<tr>';
                    echo '<td class="small text-nowrap">' . $e($r['created_at']) . '</td>';
                    echo '<td class="small">#' . (int) $r['channel_id'] . '</td>';
                    echo '<td><code>' . $e($r['action']) . '</code></td>';
                    echo '<td class="small">' . $e($r['summary']) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
        });
    }
}
