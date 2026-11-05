<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use System\Engine\Controller;

class ForumPost extends Controller
{
    /**
     * New thread form (GET) + submission (POST).
     *
     * URL:   /forum/new  or  /forum/new/{category_slug}
     * Route: 'forum/new' + ['category_slug'] → createAction, GET+POST, auth
     */
    public function createAction(): void
    {
        $slug = (string) $this->request->param('category_slug', 'slug', '');

        // Load the dropdown options
        $categories = $this->db->query(
            "SELECT `id`, `slug`, `name`, `status`, `access_role`, `post_role`
             FROM `#__forum_categories`
             WHERE `status` = 'visible'
             ORDER BY `position`, `name`"
        )->rows;
        $categories = array_values(array_filter(
            $categories,
            static fn(array $c): bool => forum_can_post($c)
        ));

        if ($categories === []) {
            $this->notify->error('No categories available to post in.');
            redirect_to('forum');
            return;
        }

        // Default selected category
        $selectedCategoryId = 0;
        if ($slug !== '') {
            foreach ($categories as $c) {
                if ($c['slug'] === $slug) {
                    $selectedCategoryId = (int) $c['id'];
                    break;
                }
            }
        }

        // POST → create
        if ($this->request->isPost()) {
            $this->handleCreate($categories);
            return;
        }

        // GET → form
        $this->view->addModuleStyle('forum', 'css/forum.css');

        echo $this->view->render($this, 'create', 'main', [
            'title'               => 'New thread',
            'categories'          => $categories,
            'selectedCategoryId'  => $selectedCategoryId,
            'form'                => [
                'title'         => '',
                'content'       => '',
                'category_id'   => $selectedCategoryId,
            ],
        ]);
    }

    /**
     * @param list<array<string,mixed>> $categories
     */
    private function handleCreate(array $categories): void
    {
        $categoryId = (int) $this->request->post('category_id', 'int', 0);
        $title      = trim((string) $this->request->post('title', 'raw', ''));
        $content    = trim((string) $this->request->post('content', 'raw', ''));

        // Validate category exists and is postable
        $category = null;
        foreach ($categories as $c) {
            if ((int) $c['id'] === $categoryId) {
                $category = $c;
                break;
            }
        }
        if ($category === null) {
            $this->notify->error('Select a valid category.');
            $this->rerenderForm($categories, $categoryId, $title, $content);
            return;
        }

        $categoryFull = $this->db->first('forum_categories', ['id' => $categoryId]);
        if ($categoryFull === [] || !forum_can_post($categoryFull)) {
            $this->notify->error('You cannot post in that category.');
            $this->rerenderForm($categories, $categoryId, $title, $content);
            return;
        }

        // Validate content
        $error = forum_validate_post($title, $content);
        if ($error !== null) {
            $this->notify->error($error);
            $this->rerenderForm($categories, $categoryId, $title, $content);
            return;
        }

        // Rate limit: 1 new thread per 60 seconds per user.
        try {
            $this->request->enforceRateLimit('forum_new_thread', 1, 60);
        } catch (\RuntimeException) {
            $this->notify->error('Please wait a minute before creating another thread.');
            $this->rerenderForm($categories, $categoryId, $title, $content);
            return;
        }

        $slug   = forum_slugify($title, $this->db, $categoryId);
        $userId = (int) $this->auth->id();

        try {
            $threadId = $this->db->transaction(function () use (
                $userId, $categoryId, $title, $slug, $content
            ) {
                $threadId = $this->db->insert('forum_threads', [
                    'category_id'  => $categoryId,
                    'user_id'      => $userId,
                    'title'        => $title,
                    'slug'         => $slug,
                    'post_count'   => 1,
                    'view_count'   => 0,
                    'is_pinned'    => 0,
                    'is_locked'    => 0,
                    'is_deleted'   => 0,
                    'last_post_at' => date('Y-m-d H:i:s'),
                    'last_post_by' => $userId,
                ]);

                $postId = $this->db->insert('forum_posts', [
                    'thread_id'    => $threadId,
                    'user_id'      => $userId,
                    'content'      => $content,
                    'content_html' => forum_render_markdown($content),
                ]);

                $this->db->query(
                    "UPDATE `#__forum_threads` SET `last_post_id` = ? WHERE `id` = ?",
                    [$postId, $threadId]
                );

                $this->db->query(
                    "UPDATE `#__forum_categories`
                     SET `threads_count` = `threads_count` + 1,
                         `posts_count`   = `posts_count` + 1
                     WHERE `id` = ?",
                    [$categoryId]
                );

                return $threadId;
            });
        } catch (\Throwable $e) {
            error_log('forum new thread failed: ' . $e->getMessage());
            $this->notify->error('Failed to create thread. Please try again.');
            $this->rerenderForm($categories, $categoryId, $title, $content);
            return;
        }

        $this->notify->success('Thread created.');
        redirect_to('forum/thread', ['id' => (int) $threadId]);
    }

    private function rerenderForm(
        array $categories,
        int $categoryId,
        string $title,
        string $content
    ): void {
        $this->view->addModuleStyle('forum', 'css/forum.css');

        echo $this->view->render($this, 'create', 'main', [
            'title'               => 'New thread',
            'categories'          => $categories,
            'selectedCategoryId'  => $categoryId,
            'form'                => [
                'title'       => $title,
                'content'     => $content,
                'category_id' => $categoryId,
            ],
        ]);
    }
}