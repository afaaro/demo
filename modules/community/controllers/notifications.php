<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Community\Services\NotificationRepository;
use System\Engine\Controller;

class CommunityNotifications extends Controller
{
    public function indexAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $repo = new NotificationRepository($this->db);
        $items = $repo->forUser($userId, 50);
        $unread = $repo->unreadCount($userId);
        $csrf = $this->request->csrfToken();

        $this->bootAssets('Notifications');
        echo $this->view->inline(function () use ($items, $unread, $csrf): void {
            include get_module_path('community') . '/views/notifications.php';
        }, 'main', ['title' => 'Notifications']);
    }

    public function readAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $id = (int) $this->request->post('id', 'int', 0);
        $repo = new NotificationRepository($this->db);
        $repo->markRead($userId, $id > 0 ? $id : null);

        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'unread'  => $repo->unreadCount($userId),
            ]);
            return;
        }

        $this->notify->success('Notifications updated.');
        redirect_to('community/notifications');
    }

    private function bootAssets(string $title): void
    {
        community_boot_assets($this->view, $title);
    }
}
