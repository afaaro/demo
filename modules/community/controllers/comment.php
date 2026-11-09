<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Comments\Services\CommentRepository;
use Module\Community\Services\FriendshipRepository;
use Module\Community\Services\NotificationRepository;
use Module\Community\Services\PostRepository;
use System\Engine\Controller;

class CommunityComment extends Controller
{
    public function postAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $userId = (int) ($this->auth->id() ?? 0);
        if ($userId < 1) {
            echo json_encode(['success' => false, 'error' => 'Authentication required.']);
            return;
        }

        $module = trim((string) $this->request->post('module', 'string', 'community'));
        $entityType = trim((string) $this->request->post('entity_type', 'string', 'post'));
        $entityId = trim((string) $this->request->post('entity_id', 'string', ''));
        $body = trim((string) $this->request->post('body', 'string', ''));

        if ($module !== 'community' || $entityType !== 'post') {
            echo json_encode(['success' => false, 'error' => 'Invalid target.']);
            return;
        }
        if ($body === '') {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty.']);
            return;
        }
        if (mb_strlen($body) > 2000) {
            echo json_encode(['success' => false, 'error' => 'Comment is too long.']);
            return;
        }

        $postId = (int) $entityId;
        $posts = new PostRepository($this->db);
        $friends = new FriendshipRepository($this->db);
        $post = $posts->find($postId);
        if ($post === null || !$posts->canView($post, $userId, $friends)) {
            echo json_encode(['success' => false, 'error' => 'Post not found.']);
            return;
        }
        if (!community_rate_allow('comment', $userId, 5)) {
            echo json_encode(['success' => false, 'error' => 'Please wait a moment before commenting again.']);
            return;
        }

        $repo = new CommentRepository($this->db);
        try {
            $row = $repo->create('community', 'post', (string) $postId, $userId, $body);
        } catch (\Throwable) {
            echo json_encode(['success' => false, 'error' => 'Could not save comment.']);
            return;
        }

        $notifications = new NotificationRepository($this->db);
        $notifications->create(
            (int) $post['user_id'],
            $userId,
            'post_comment',
            'post',
            (string) $postId
        );

        $label = trim((string) ($row['username'] ?? ''));
        if ($label === '') {
            $label = $this->auth->displayLabel();
        }

        echo json_encode([
            'success'  => true,
            'id'       => (int) ($row['id'] ?? 0),
            'username' => $label !== '' ? $label : 'User',
            'body'     => (string) ($row['body'] ?? $body),
            'when'     => 'Just now',
        ]);
    }
}
