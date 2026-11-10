<?php
declare(strict_types=1);

use Module\Store\Services\ConversationRepository;
use Module\Store\Services\DashboardService;
use Module\Store\Services\SupportCaseRepository;
use System\Engine\Controller;

class StoreAdminOverdueHub extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
    }

    public function indexAction(): void
    {
        $dash = new DashboardService($this->db);
        $cases = (new SupportCaseRepository($this->db))->overdueCases(50);
        $convRepo = new ConversationRepository($this->db);

        $conversations = $convRepo->overdueAcrossChannels(50);

        $msgOverdue = $dash->overdueConversationCount();
        $caseOverdue = $dash->overdueCaseCount();

        echo $this->view->inline(function () use ($conversations, $cases, $msgOverdue, $caseOverdue) {
            $this->view->assign('title', 'Overdue hub');
            $this->view->block('right_sidebar', 'Messages');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-alarm me-1"></i>Overdue hub</h1>'
               . '<p class="text-muted small mb-0">'
               . (int) $msgOverdue . ' overdue conversation(s) · '
               . (int) $caseOverdue . ' overdue case(s)</p></div>';
            echo '</div>';

            echo '<div class="row g-3">';
            echo '<div class="col-lg-6">';
            echo '<div class="card border-0 shadow-sm"><div class="card-body">';
            echo '<div class="d-flex justify-content-between align-items-center mb-2">'
               . '<h2 class="h6 mb-0">Overdue conversations</h2>'
               . '<a class="small" href="' . $e($this->url->to('store/admin/messages/conversations', ['assignment' => 'overdue']))
               . '">Open inbox</a></div>';
            if ($conversations === []) {
                echo '<p class="text-muted small mb-0">None overdue.</p>';
            } else {
                echo '<ul class="list-group list-group-flush">';
                foreach ($conversations as $c) {
                    $title = trim((string) ($c['latest_body'] ?? ''));
                    if ($title === '') {
                        $title = trim((string) ($c['latest_sender'] ?? ''));
                    }
                    if ($title === '') {
                        $title = 'Conversation';
                    }
                    $due = (string) ($c['due_at'] ?? '');
                    $cid = (int) ($c['channel_id'] ?? 0);
                    $ext = (string) ($c['external_id'] ?? '');
                    $type = (string) ($c['conversation_type'] ?? 'FROM_MEMBERS');
                    echo '<li class="list-group-item px-0 d-flex justify-content-between gap-2">';
                    echo '<div><a href="' . $e($this->url->to('store/admin/messages/conversation', [
                        'channel_id' => $cid,
                        'id'         => $ext,
                        'type'       => $type,
                    ])) . '">' . $e(mb_substr($title, 0, 80)) . '</a>'
                       . '<div class="small text-muted">'
                       . $e((string) ($c['channel_label'] ?? '')) . ' · Due ' . $e($due) . '</div></div>';
                    echo '<span class="badge text-bg-danger align-self-center">Overdue</span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '</div></div></div>';

            echo '<div class="col-lg-6">';
            echo '<div class="card border-0 shadow-sm"><div class="card-body">';
            echo '<div class="d-flex justify-content-between align-items-center mb-2">'
               . '<h2 class="h6 mb-0">Overdue support cases</h2>'
               . '<a class="small" href="' . $e($this->url->to('store/admin/cases')) . '">Open cases</a></div>';
            if ($cases === []) {
                echo '<p class="text-muted small mb-0">None overdue.</p>';
            } else {
                echo '<ul class="list-group list-group-flush">';
                foreach ($cases as $row) {
                    $label = (string) (($row['item_title'] ?? '') !== ''
                        ? $row['item_title']
                        : ('Case #' . $row['id']));
                    echo '<li class="list-group-item px-0 d-flex justify-content-between gap-2">';
                    echo '<div><a href="' . $e($this->url->to('store/admin/cases/view', ['id' => (int) $row['id']])) . '">'
                       . $e($label) . '</a>'
                       . '<div class="small text-muted">'
                       . $e((string) ($row['channel_label'] ?? '')) . ' · Due '
                       . $e((string) ($row['seller_response_due'] ?? '')) . '</div></div>';
                    echo '<span class="badge text-bg-danger align-self-center">Overdue</span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '</div></div></div>';
            echo '</div>';
        });
    }
}
