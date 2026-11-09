<?php
declare(strict_types=1);

use System\Engine\Controller;
use System\Services\PageAccessService;

class UserAdminStats extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            $this->session->set('redirect_after_login', 'user/admin/stats');
            redirect_to('user/login', ['next' => 'user/admin/stats']);
            return;
        }
        // Permissions: Action.php only (user.admin.stats.*).
    }

    public function indexAction(): void
    {
        $days = (int) $this->request->get('days', 'int', 30);
        if (!in_array($days, [7, 14, 30, 90], true)) {
            $days = 30;
        }

        $svc = new PageAccessService($this->db);
        $report = $svc->report($days);

        echo $this->view->inline(function () use ($report, $days) {
            $this->view->assign('title', 'Page access statistics');
            $this->view->block('right_sidebar', 'Users');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<div><h1 class="h3 mb-1">Page access</h1>'
               . '<p class="text-muted small mb-0">Views by route, module, and user</p></div>';
            echo '<div class="btn-group btn-group-sm">';
            foreach ([7, 14, 30, 90] as $d) {
                $cls = $d === $days ? 'btn-primary' : 'btn-outline-secondary';
                echo '<a class="btn ' . $cls . '" href="'
                   . $e($this->url->to('user/admin/stats', ['days' => $d])) . '">'
                   . $d . 'd</a>';
            }
            echo '</div></div>';

            echo '<div class="row g-3 mb-4">';
            $cards = [
                ['Hits today', (string) $report['hits_today'], 'bi-calendar-day'],
                ['Hits 7 days', (string) $report['hits_7d'], 'bi-calendar-week'],
                ['Hits ' . $days . ' days', (string) $report['hits_30d'], 'bi-graph-up'],
                ['Users today', (string) $report['unique_today'], 'bi-people'],
                ['Users 7 days', (string) $report['unique_7d'], 'bi-person-check'],
            ];
            foreach ($cards as [$label, $value, $icon]) {
                echo '<div class="col-6 col-md">'
                   . '<div class="card border-0 shadow-sm h-100"><div class="card-body py-3">'
                   . '<div class="text-muted small"><i class="bi ' . $icon . ' me-1"></i>'
                   . $e($label) . '</div>'
                   . '<div class="fs-4 fw-semibold">' . $e($value) . '</div>'
                   . '</div></div></div>';
            }
            echo '</div>';

            echo '<div class="row g-3 mb-4">';
            echo '<div class="col-lg-8"><div class="card border-0 shadow-sm h-100">'
               . '<div class="card-header bg-white fw-semibold">Hits by day</div>'
               . '<div class="card-body">';
            if ($report['by_day'] === []) {
                echo '<p class="text-muted mb-0">No page views recorded yet. Browse the site, then refresh.</p>';
            } else {
                $max = max(1, ...array_map(static fn(array $r): int => $r['hits'], $report['by_day']));
                echo '<div class="d-flex align-items-end gap-1" style="height:140px">';
                foreach ($report['by_day'] as $day) {
                    $h = max(4, (int) round(($day['hits'] / $max) * 120));
                    $title = $day['access_date'] . ': ' . $day['hits'];
                    echo '<div class="flex-fill bg-primary rounded-top" style="height:' . $h . 'px;opacity:.85"'
                       . ' title="' . $e($title) . '"></div>';
                }
                echo '</div>';
                echo '<div class="d-flex justify-content-between small text-muted mt-2">'
                   . '<span>' . $e($report['by_day'][0]['access_date']) . '</span>'
                   . '<span>' . $e($report['by_day'][count($report['by_day']) - 1]['access_date']) . '</span>'
                   . '</div>';
            }
            echo '</div></div></div>';

            echo '<div class="col-lg-4"><div class="card border-0 shadow-sm h-100">'
               . '<div class="card-header bg-white fw-semibold">By module</div>'
               . '<div class="card-body p-0"><div class="table-responsive">'
               . '<table class="table table-sm align-middle mb-0"><thead><tr>'
               . '<th>Module</th><th class="text-end">Hits</th></tr></thead><tbody>';
            if ($report['by_module'] === []) {
                echo '<tr><td colspan="2" class="text-muted px-3">—</td></tr>';
            } else {
                foreach ($report['by_module'] as $row) {
                    echo '<tr><td class="px-3">' . $e($row['module']) . '</td>'
                       . '<td class="text-end px-3 fw-semibold">' . (int) $row['hits'] . '</td></tr>';
                }
            }
            echo '</tbody></table></div></div></div></div>';
            echo '</div>';

            echo '<div class="row g-3 mb-4">';
            echo '<div class="col-lg-7"><div class="card border-0 shadow-sm">'
               . '<div class="card-header bg-white fw-semibold">Top pages (' . (int) $days . 'd)</div>'
               . '<div class="card-body p-0"><div class="table-responsive">'
               . '<table class="table table-sm align-middle mb-0"><thead><tr>'
               . '<th>Route</th><th>Module</th><th class="text-end">Hits</th>'
               . '<th class="text-end">Users</th></tr></thead><tbody>';
            if ($report['top_pages'] === []) {
                echo '<tr><td colspan="4" class="text-muted px-3 py-3">No data yet.</td></tr>';
            } else {
                foreach ($report['top_pages'] as $row) {
                    echo '<tr><td class="px-3"><code class="small">' . $e($row['route']) . '</code></td>'
                       . '<td class="small text-muted">' . $e($row['module']) . '</td>'
                       . '<td class="text-end fw-semibold">' . (int) $row['hits'] . '</td>'
                       . '<td class="text-end text-muted">' . (int) $row['unique_users'] . '</td></tr>';
                }
            }
            echo '</tbody></table></div></div></div></div>';

            echo '<div class="col-lg-5"><div class="card border-0 shadow-sm">'
               . '<div class="card-header bg-white fw-semibold">Top users</div>'
               . '<div class="card-body p-0"><div class="table-responsive">'
               . '<table class="table table-sm align-middle mb-0"><thead><tr>'
               . '<th>User</th><th class="text-end">Hits</th></tr></thead><tbody>';
            if ($report['top_users'] === []) {
                echo '<tr><td colspan="2" class="text-muted px-3 py-3">No signed-in views yet.</td></tr>';
            } else {
                foreach ($report['top_users'] as $row) {
                    $label = $row['username'];
                    if ($row['display_name'] !== '') {
                        $label .= ' · ' . $row['display_name'];
                    }
                    echo '<tr><td class="px-3">' . $e($label) . '</td>'
                       . '<td class="text-end fw-semibold px-3">' . (int) $row['hits'] . '</td></tr>';
                }
            }
            echo '</tbody></table></div></div></div></div>';
            echo '</div>';

            echo '<div class="card border-0 shadow-sm">'
               . '<div class="card-header bg-white fw-semibold">Recent page views</div>'
               . '<div class="card-body p-0"><div class="table-responsive">'
               . '<table class="table table-sm align-middle mb-0"><thead><tr>'
               . '<th>When</th><th>User</th><th>Route</th><th>Path</th><th>IP</th>'
               . '</tr></thead><tbody>';
            if ($report['recent'] === []) {
                echo '<tr><td colspan="5" class="text-muted px-3 py-3">No recent hits.</td></tr>';
            } else {
                foreach ($report['recent'] as $row) {
                    echo '<tr>'
                       . '<td class="small text-muted text-nowrap px-3">' . $e($row['created_at']) . '</td>'
                       . '<td class="small">' . $e($row['username']) . '</td>'
                       . '<td><code class="small">' . $e($row['route']) . '</code></td>'
                       . '<td class="small text-muted">' . $e($row['path']) . '</td>'
                       . '<td class="small text-muted">' . $e($row['ip']) . '</td>'
                       . '</tr>';
                }
            }
            echo '</tbody></table></div></div></div>';
        });
    }
}
