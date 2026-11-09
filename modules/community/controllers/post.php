<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Community\Services\FriendshipRepository;
use Module\Community\Services\NotificationRepository;
use Module\Community\Services\PostRepository;
use Module\Community\Services\ReactionRepository;
use System\Engine\Controller;

class CommunityPost extends Controller
{
    public function createAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $cfg = community_module_config();
        $body = trim((string) $this->request->post('body', 'string', ''));
        $visibility = trim((string) $this->request->post('visibility', 'string', $cfg['default_visibility']));
        if (!in_array($visibility, ['friends', 'public'], true)) {
            $visibility = $cfg['default_visibility'];
        }

        $posts = new PostRepository($this->db);
        try {
            $photo = community_save_photo($this->request->file('photo'), $cfg['max_photo_mb']);
            $post = $posts->create($userId, $body, $visibility, $photo);
            $this->notify->success('Post shared.');
            redirect_to('community/post', ['id' => (int) $post['id']]);
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
            redirect_to('community');
        }
    }

    public function viewAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $id = (int) $this->request->get('id', 'int', 0);
        $posts = new PostRepository($this->db);
        $friends = new FriendshipRepository($this->db);
        $reactions = new ReactionRepository($this->db);
        $notifications = new NotificationRepository($this->db);

        $post = $posts->find($id);
        if ($post === null || !$posts->canView($post, $userId, $friends)) {
            $this->notify->error('Post not found.');
            redirect_to('community');
        }

        $likeCount = $reactions->countLikes($id);
        $liked = $reactions->userLiked($id, $userId);
        $unread = $notifications->unreadCount($userId);
        $csrf = $this->request->csrfToken();

        require_once get_module_path('comments') . '/helpers/functions.php';
        $commentsHtml = comments_render('community', 'post', (string) $id, [
            'title'       => 'Comments',
            'login_next'  => 'community/post/' . $id,
            'login_query' => ['next' => 'community/post/' . $id],
        ]);

        $this->bootAssets('Post');
        echo $this->view->inline(function () use (
            $post,
            $likeCount,
            $liked,
            $unread,
            $csrf,
            $commentsHtml,
            $userId,
        ): void {
            include get_module_path('community') . '/views/post.php';
        }, 'main', ['title' => 'Post']);
    }

    public function deleteAction(): void
    {
        $userId = (int) ($this->auth->id() ?? 0);
        $id = (int) $this->request->post('id', 'int', 0);
        $posts = new PostRepository($this->db);
        $asAdmin = $this->auth->can('community.admin');
        try {
            $posts->softDelete($id, $userId, $asAdmin);
            $this->notify->success('Post removed.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }
        redirect_to('community');
    }

    private function bootAssets(string $title): void
    {
        community_boot_assets($this->view, $title);
    }
}
