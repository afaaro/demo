<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Community\Services\FriendshipRepository;
use Module\Community\Services\NotificationRepository;
use Module\Community\Services\PostRepository;
use Module\Community\Services\ReactionRepository;
use System\Engine\Controller;

class CommunityReact extends Controller
{
    public function toggleAction(): void
    {
        $wantsJson = $this->wantsJson();
        $userId = (int) ($this->auth->id() ?? 0);
        $postId = (int) $this->request->post('post_id', 'int', 0);

        $posts = new PostRepository($this->db);
        $friends = new FriendshipRepository($this->db);
        $reactions = new ReactionRepository($this->db);
        $notifications = new NotificationRepository($this->db);

        $post = $posts->find($postId);
        if ($post === null || !$posts->canView($post, $userId, $friends)) {
            $this->fail($wantsJson, 'Post not found.');
            return;
        }
        if (!community_rate_allow('react', $userId, 1)) {
            $this->fail($wantsJson, 'Please wait a moment before liking again.');
            return;
        }

        try {
            $liked = $reactions->toggleLike($postId, $userId);
            if ($liked) {
                $notifications->create(
                    (int) $post['user_id'],
                    $userId,
                    'post_like',
                    'post',
                    (string) $postId
                );
            }
            $count = $reactions->countLikes($postId);
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'liked'   => $liked,
                    'count'   => $count,
                ]);
                return;
            }
            redirect_to('community/post', ['id' => $postId]);
        } catch (\Throwable $e) {
            $this->fail($wantsJson, $e->getMessage());
        }
    }

    private function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest';
    }

    private function fail(bool $json, string $message): void
    {
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $message]);
            return;
        }
        $this->notify->error($message);
        redirect_to('community');
    }
}
