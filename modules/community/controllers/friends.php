<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Community\Services\FriendshipRepository;
use Module\Community\Services\NotificationRepository;
use System\Engine\Controller;

class CommunityFriends extends Controller
{
    public function indexAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $friendsRepo = new FriendshipRepository($this->db);
        $notifications = new NotificationRepository($this->db);

        $friends = $friendsRepo->listFriends($userId);
        $incoming = $friendsRepo->pendingIncoming($userId);
        $outgoing = $friendsRepo->pendingOutgoingWithUsers($userId);
        $unread = $notifications->unreadCount($userId);
        $csrf = $this->request->csrfToken();
        $q = trim((string) $this->request->get('q', 'string', ''));
        $searchResults = $q !== '' ? $this->searchUsers($q, $userId) : [];
        $searchStatuses = $friendsRepo->statusesBetween(
            $userId,
            array_map(static fn(array $p): int => (int) $p['id'], $searchResults)
        );

        $this->bootAssets('Friends');
        echo $this->view->inline(function () use (
            $friends,
            $incoming,
            $outgoing,
            $unread,
            $csrf,
            $q,
            $searchResults,
            $searchStatuses,
            $userId,
        ): void {
            include get_module_path('community') . '/views/friends.php';
        }, 'main', ['title' => 'Friends']);
    }

    public function requestAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $toId = (int) $this->request->post('user_id', 'int', 0);
        if (!community_rate_allow('friend_request', $userId, 5)) {
            $this->notify->error('Please wait a moment before sending another request.');
            $this->backToProfileOrFriends($toId);
        }
        $friends = new FriendshipRepository($this->db);
        $notifications = new NotificationRepository($this->db);
        try {
            $friends->request($userId, $toId);
            $notifications->create($toId, $userId, 'friend_request', 'user', (string) $userId);
            $this->notify->success('Friend request sent.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        $this->backToProfileOrFriends($toId);
    }

    public function acceptAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $id = (int) $this->request->post('id', 'int', 0);
        $friends = new FriendshipRepository($this->db);
        $notifications = new NotificationRepository($this->db);
        try {
            $row = $friends->accept($userId, $id);
            $actorId = (int) ($row['requested_by'] ?? 0);
            if ($actorId > 0) {
                $notifications->create($actorId, $userId, 'friend_accepted', 'user', (string) $userId);
            }
            $this->notify->success('Friend request accepted.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('community/friends');
    }

    public function declineAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $id = (int) $this->request->post('id', 'int', 0);
        $friends = new FriendshipRepository($this->db);
        try {
            $friends->decline($userId, $id);
            $this->notify->success('Friend request declined.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('community/friends');
    }

    public function unfriendAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $otherId = (int) $this->request->post('user_id', 'int', 0);
        $friends = new FriendshipRepository($this->db);
        try {
            $friends->unfriend($userId, $otherId);
            $this->notify->success('Removed from friends.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        $this->backToProfileOrFriends($otherId);
    }

    public function cancelAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $id = (int) $this->request->post('id', 'int', 0);
        $otherId = (int) $this->request->post('user_id', 'int', 0);
        $friends = new FriendshipRepository($this->db);
        try {
            $row = $friends->cancelOutgoing($userId, $id);
            if ($otherId < 1) {
                $low = (int) ($row['user_id'] ?? 0);
                $high = (int) ($row['friend_id'] ?? 0);
                $otherId = $low === $userId ? $high : $low;
            }
            $this->notify->success('Friend request cancelled.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        $this->backToProfileOrFriends($otherId);
    }

    private function backToProfileOrFriends(int $userId): never
    {
        $next = trim((string) $this->request->post('next', 'string', ''));
        if ($next === 'profile' && $userId > 0) {
            redirect_to('community/profile', ['u' => $userId]);
        }
        redirect_to('community/friends');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchUsers(string $q, int $excludeId): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . $q . '%';
        return $this->db->query(
            "SELECT `id`, `username`, `display_name`
               FROM `#__users`
              WHERE `status` = 'active'
                AND `id` <> ?
                AND (`username` LIKE ? OR `display_name` LIKE ?)
              ORDER BY `username`
              LIMIT 20",
            [$excludeId, $like, $like]
        )->rows;
    }

    private function bootAssets(string $title): void
    {
        community_boot_assets($this->view, $title);
    }
}
