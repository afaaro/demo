<?php
declare(strict_types=1);

use Module\Store\Services\SupportCaseRepository;
use Module\Store\Services\TenantContext;
use System\Engine\Controller;

class StoreAdminDisputes extends Controller
{
    private SupportCaseRepository $cases;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->cases = new SupportCaseRepository($this->db);
    }

    public function indexAction(): void
    {
        $openCases = $this->cases->overdueCases(40);
        $overdueCount = 0;
        if (TenantContext::get()->has()) {
            try {
                $overdueCount = (int) $this->db->query(
                    "SELECT COUNT(*) AS c FROM `#__store_orders`
                      WHERE `tenant_id` = ?
                        AND UPPER(`status`) NOT IN ('FULFILLED','CANCELLED','CANCELED')
                        AND `ship_by` IS NOT NULL
                        AND `ship_by` < CURDATE()",
                    [(int) TenantContext::get()->id()]
                )->row['c'];
            } catch (\Throwable) {
                $overdueCount = 0;
            }
        }

        echo $this->view->inline(function () use ($openCases, $overdueCount) {
            $this->view->assign('title', 'Disputes');
            $this->view->block('right_sidebar', 'Orders');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="sf-page">';
            echo '<div class="sf-head"><div><h1>Disputes</h1>'
               . '<p>Overdue support cases and late ship-by orders.</p></div>'
               . '<div class="sf-actions">'
               . '<a class="sf-btn" href="' . $e($this->url->to('store/admin/cases')) . '">All support cases</a>'
               . '<a class="sf-btn" href="' . $e($this->url->to('store/admin/overdue')) . '">Overdue hub</a>'
               . '</div></div>';

            echo '<div class="sf-stats">';
            echo '<a class="sf-stat" href="' . $e($this->url->to('store/admin/cases')) . '">'
               . '<span>Overdue cases</span><strong>' . count($openCases) . '</strong></a>';
            echo '<a class="sf-stat" href="' . $e($this->url->to('store/admin/overdue')) . '">'
               . '<span>Overdue orders</span><strong>' . (int) $overdueCount . '</strong></a>';
            echo '</div>';

            echo '<div class="sf-card table-responsive"><table class="table table-sm align-middle mb-0">';
            echo '<thead><tr><th>Case</th><th>Channel</th><th>Status</th><th>Due</th></tr></thead><tbody>';
            if ($openCases === []) {
                echo '<tr><td colspan="4" class="text-muted text-center py-4">'
                   . 'No overdue cases right now.</td></tr>';
            }
            foreach ($openCases as $c) {
                $id = (int) ($c['id'] ?? 0);
                echo '<tr>';
                echo '<td>' . ($id > 0
                    ? '<a href="' . $e($this->url->to('store/admin/cases/view', ['id' => $id])) . '">#'
                        . $id . '</a> ' . $e((string) ($c['subject'] ?? $c['title'] ?? $c['external_id'] ?? ''))
                    : $e((string) ($c['subject'] ?? ''))) . '</td>';
                echo '<td class="small">' . $e((string) ($c['channel_label'] ?? '')) . '</td>';
                echo '<td class="small">' . $e((string) ($c['status'] ?? '')) . '</td>';
                echo '<td class="small text-nowrap">' . $e((string) ($c['seller_response_due'] ?? $c['updated_at'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';
        });
    }
}
