<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Community\Services\FriendshipRepository;
use Module\Community\Services\NotificationRepository;
use Module\Community\Services\PostRepository;
use Module\Community\Services\ReactionRepository;
use System\Engine\Controller;

class CommunityHome extends Controller
{
    public function indexAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $cfg = community_module_config();
        $page = max(1, (int) $this->request->get('page', 'int', 1));
        $limit = $cfg['posts_per_page'];
        $offset = ($page - 1) * $limit;

        $friends = new FriendshipRepository($this->db);
        $posts = new PostRepository($this->db);
        $reactions = new ReactionRepository($this->db);
        $notifications = new NotificationRepository($this->db);

        $friendIds = $friends->friendIds($userId);
        $friendCount = count($friendIds);
        $authorIds = $friendIds;
        $authorIds[] = $userId;
        $feed = $posts->feedForAuthors($authorIds, $limit, $offset);
        $postIds = array_map(static fn(array $p): int => (int) $p['id'], $feed);
        $likeCounts = $reactions->likeCountsForPosts($postIds);
        $liked = $reactions->likedByUser($postIds, $userId);
        $commentCounts = $posts->commentCountsForPosts($postIds);
        $unread = $notifications->unreadCount($userId);
        $discover = [];
        if ($page === 1 && $feed === []) {
            $discover = $posts->publicDiscover($authorIds, 5);
            $dIds = array_map(static fn(array $p): int => (int) $p['id'], $discover);
            $likeCounts += $reactions->likeCountsForPosts($dIds);
            $liked += $reactions->likedByUser($dIds, $userId);
            $commentCounts += $posts->commentCountsForPosts($dIds);
        }

        $this->bootAssets('Community');
        $csrf = $this->request->csrfToken();
        $defaultVisibility = $cfg['default_visibility'];
        $emptyKind = $feed !== [] ? 'none' : ($friendCount === 0 ? 'no_friends' : 'quiet');

        echo $this->view->inline(function () use (
            $feed,
            $discover,
            $likeCounts,
            $liked,
            $commentCounts,
            $unread,
            $csrf,
            $defaultVisibility,
            $page,
            $limit,
            $userId,
            $friendCount,
            $emptyKind,
        ): void {
            include get_module_path('community') . '/views/feed.php';
        }, 'main', ['title' => 'Community']);
    }

    private function bootAssets(string $title): void
    {
        community_boot_assets($this->view, $title);
    }
}
