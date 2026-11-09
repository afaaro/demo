<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Community\Services\FriendshipRepository;
use Module\Community\Services\NotificationRepository;
use Module\Community\Services\PostRepository;
use Module\Community\Services\ReactionRepository;
use System\Engine\Controller;

class CommunityProfile extends Controller
{
    public function indexAction(): void
    {
        $viewerId = (int) ($this->auth->id() ?? 0);
        $u = trim((string) $this->request->get('u', 'string', ''));
        if ($u === '') {
            $u = (string) $viewerId;
        }

        $profile = $this->resolveUser($u);
        if ($profile === null) {
            $this->notify->error('User not found.');
            redirect_to('community');
        }

        $profileId = (int) $profile['id'];
        $cfg = community_module_config();
        $page = max(1, (int) $this->request->get('page', 'int', 1));
        $limit = $cfg['posts_per_page'];
        $offset = ($page - 1) * $limit;

        $friends = new FriendshipRepository($this->db);
        $posts = new PostRepository($this->db);
        $reactions = new ReactionRepository($this->db);
        $notifications = new NotificationRepository($this->db);

        $isSelf = $profileId === $viewerId;
        $relation = $isSelf ? 'self' : $friends->statusBetween($viewerId, $profileId);
        $canSeeFriendsWall = $isSelf || $relation === 'friends';
        $wall = $posts->wallForUser($profileId, $viewerId, $canSeeFriendsWall, $limit, $offset);
        $postIds = array_map(static fn(array $p): int => (int) $p['id'], $wall);
        $likeCounts = $reactions->likeCountsForPosts($postIds);
        $liked = $reactions->likedByUser($postIds, $viewerId);
        $commentCounts = $posts->commentCountsForPosts($postIds);
        $friendCount = $friends->friendCount($profileId);
        $mutualCount = $isSelf ? 0 : $friends->mutualFriendCount($viewerId, $profileId);
        $mutualFriends = $isSelf ? [] : $friends->mutualFriends($viewerId, $profileId, 6);
        $unread = $notifications->unreadCount($viewerId);
        $csrf = $this->request->csrfToken();
        $defaultVisibility = $cfg['default_visibility'];
        $outgoingId = 0;
        $incomingId = 0;
        if ($relation === 'incoming' || $relation === 'outgoing') {
            $row = $friends->findBetween($viewerId, $profileId);
            $fid = (int) ($row['id'] ?? 0);
            if ($relation === 'incoming') {
                $incomingId = $fid;
            } else {
                $outgoingId = $fid;
            }
        }

        $this->bootAssets(community_display_name($profile));
        echo $this->view->inline(function () use (
            $profile,
            $wall,
            $likeCounts,
            $liked,
            $commentCounts,
            $relation,
            $friendCount,
            $mutualCount,
            $mutualFriends,
            $unread,
            $csrf,
            $viewerId,
            $incomingId,
            $outgoingId,
            $isSelf,
            $defaultVisibility,
            $page,
            $limit,
            $canSeeFriendsWall,
        ): void {
            include get_module_path('community') . '/views/profile.php';
        }, 'main', ['title' => community_display_name($profile)]);
    }

    /** @return array<string, mixed>|null */
    private function resolveUser(string $u): ?array
    {
        if (ctype_digit($u)) {
            $row = $this->db->query(
                "SELECT `id`, `username`, `display_name`, `status`, `created_at`
                   FROM `#__users` WHERE `id` = ? LIMIT 1",
                [(int) $u]
            )->row;
        } else {
            $row = $this->db->query(
                "SELECT `id`, `username`, `display_name`, `status`, `created_at`
                   FROM `#__users` WHERE `username` = ? LIMIT 1",
                [strtolower($u)]
            )->row;
        }
        if ($row === [] || ($row['status'] ?? '') !== 'active') {
            return null;
        }
        return $row;
    }

    private function bootAssets(string $title): void
    {
        community_boot_assets($this->view, $title);
    }
}
