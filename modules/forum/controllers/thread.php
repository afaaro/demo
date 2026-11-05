<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use System\Engine\Controller;
use System\Library\Paginator;

class ForumThread extends Controller
{
    private const POSTS_PER_PAGE = 20;

    public function viewAction(): void
    {
        $id = (int) $this->request->param('id', 'int', 0);
        $found = $this->loadThread($id);
        if ($found === null) {
            return;
        }
        [$thread, $category] = $found;

        $this->view->addModuleStyle('forum', 'css/forum.css');

        try {
            $this->db->query(
                "UPDATE `#__forum_threads` SET `view_count` = `view_count` + 1 WHERE `id` = ?",
                [$id]
            );
        } catch (\Throwable) {
        }

        $userId = $this->auth->check() ? (int) $this->auth->id() : 0;
        if ($userId > 0) {
            try {
                $this->db->query(
                    "INSERT INTO `#__forum_reads` (`user_id`, `thread_id`, `last_read_at`)
                     VALUES (?, ?, NOW())
                     ON DUPLICATE KEY UPDATE `last_read_at` = NOW()",
                    [$userId, $id]
                );
            } catch (\Throwable) {
            }
        }

        $total = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__forum_posts`
              WHERE `thread_id` = ? AND `is_deleted` = 0",
            [$id]
        )->value;

        $pager = Paginator::make(
            $total,
            (int) $this->request->get('page', 'int', 1),
            self::POSTS_PER_PAGE
        );

        $posts = $this->db->query(
            "SELECT p.*,
                    COALESCE(NULLIF(u.`display_name`, ''), u.`username`) AS username,
                    u.`created_at` AS author_joined,
                    (SELECT COUNT(*) FROM `#__forum_posts` x
                      WHERE x.`user_id` = p.`user_id` AND x.`is_deleted` = 0) AS author_posts
               FROM `#__forum_posts` p
               LEFT JOIN `#__users` u ON u.`id` = p.`user_id`
              WHERE p.`thread_id` = ? AND p.`is_deleted` = 0
              ORDER BY p.`created_at` ASC, p.`id` ASC
              LIMIT {$pager->perPage} OFFSET {$pager->offset}",
            [$id]
        )->rows;

        $quote = '';
        $quoteId = (int) $this->request->get('quote', 'int', 0);
        if ($quoteId > 0) {
            foreach ($posts as $post) {
                if ((int) $post['id'] === $quoteId) {
                    $quote = forum_quote_markdown(
                        (string) ($post['username'] ?? 'member'),
                        (string) ($post['content'] ?? '')
                    );
                    break;
                }
            }
            if ($quote === '') {
                $quoted = $this->db->query(
                    "SELECT p.`content`, COALESCE(NULLIF(u.`display_name`, ''), u.`username`) AS username
                       FROM `#__forum_posts` p
                       LEFT JOIN `#__users` u ON u.`id` = p.`user_id`
                      WHERE p.`id` = ? AND p.`thread_id` = ? AND p.`is_deleted` = 0
                      LIMIT 1",
                    [$quoteId, $id]
                )->row;
                if ($quoted !== []) {
                    $quote = forum_quote_markdown(
                        (string) ($quoted['username'] ?? 'member'),
                        (string) ($quoted['content'] ?? '')
                    );
                }
            }
        }

        echo $this->view->render($this, 'view', 'main', [
            'title'    => (string) $thread['title'],
            'thread'   => $thread,
            'category' => $category,
            'posts'    => $posts,
            'pager'    => $pager,
            'quote'    => $quote,
        ]);
    }

    public function replyAction(): void
    {
        $threadId = (int) $this->request->param('id', 'int', 0);
        $found = $this->loadThread($threadId);
        if ($found === null) {
            return;
        }
        [$thread, $category] = $found;

        if ((int) $thread['is_locked'] === 1 && !forum_can_moderate()) {
            $this->notify->error('This thread is locked.');
            redirect_to('forum/thread', ['id' => $threadId]);
            return;
        }

        if (!forum_can_post($category)) {
            $this->notify->error('You cannot post in this category.');
            redirect_to('forum/thread', ['id' => $threadId]);
            return;
        }

        try {
            $this->request->enforceRateLimit('forum_reply', 1, 20);
        } catch (\RuntimeException) {
            $this->notify->error('Please wait a few seconds before posting again.');
            redirect_to('forum/thread', ['id' => $threadId]);
            return;
        }

        $content = trim((string) $this->request->post('content', 'raw', ''));
        $error   = forum_validate_reply($content);
        if ($error !== null) {
            $this->notify->error($error);
            redirect_to('forum/thread', ['id' => $threadId]);
            return;
        }

        $userId = (int) $this->auth->id();
        $postId = 0;

        try {
            $postId = (int) $this->db->transaction(function () use ($userId, $threadId, $content) {
                $id = (int) $this->db->insert('forum_posts', [
                    'thread_id'    => $threadId,
                    'user_id'      => $userId,
                    'content'      => $content,
                    'content_html' => forum_render_markdown($content),
                ]);

                $this->db->query(
                    "UPDATE `#__forum_threads`
                        SET `post_count` = `post_count` + 1,
                            `last_post_id` = ?,
                            `last_post_at` = NOW(),
                            `last_post_by` = ?
                      WHERE `id` = ?",
                    [$id, $userId, $threadId]
                );

                $this->db->query(
                    "UPDATE `#__forum_categories`
                        SET `posts_count` = `posts_count` + 1
                      WHERE `id` = (SELECT `category_id` FROM `#__forum_threads` WHERE `id` = ?)",
                    [$threadId]
                );

                return $id;
            });
        } catch (\Throwable $e) {
            $this->log->error('forum reply failed: ' . $e->getMessage());
            $this->notify->error('Failed to post reply. Please try again.');
            redirect_to('forum/thread', ['id' => $threadId]);
            return;
        }

        $total = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__forum_posts` WHERE `thread_id` = ? AND `is_deleted` = 0",
            [$threadId]
        )->value;
        $lastPage = max(1, (int) ceil($total / self::POSTS_PER_PAGE));
        $url = $this->url->to('forum/thread', array_filter([
            'id'   => $threadId,
            'page' => $lastPage > 1 ? $lastPage : null,
        ]));

        $this->notify->success('Reply posted.');
        redirect($url . '#post-' . $postId);
    }

    public function editAction(): void
    {
        $postId = (int) $this->request->get('id', 'int', (int) $this->request->post('id', 'int', 0));
        $post = $this->findLivePost($postId);
        if ($post === null) {
            $this->notify->error('Post not found.');
            redirect_to('forum');
            return;
        }
        if (!forum_can_edit_post($post)) {
            $this->notify->error('You cannot edit this post.');
            redirect_to('forum/thread', ['id' => (int) $post['thread_id']]);
            return;
        }

        $found = $this->loadThread((int) $post['thread_id']);
        if ($found === null) {
            return;
        }
        [$thread, $category] = $found;

        if ($this->request->isPost()) {
            $content = trim((string) $this->request->post('content', 'raw', ''));
            $error = forum_validate_reply($content);
            if ($error !== null) {
                $this->notify->error($error);
            } else {
                $this->db->update('forum_posts', [
                    'content'      => $content,
                    'content_html' => forum_render_markdown($content),
                    'edited_at'    => date('Y-m-d H:i:s'),
                    'edited_by'    => (int) $this->auth->id(),
                ], ['id' => $postId]);
                $this->notify->success('Post updated.');
                redirect($this->url->to('forum/thread', ['id' => (int) $thread['id']]) . '#post-' . $postId);
                return;
            }
        } else {
            $content = (string) ($post['content'] ?? '');
        }

        $this->view->addModuleStyle('forum', 'css/forum.css');
        echo $this->view->render($this, 'edit', 'main', [
            'title'    => 'Edit post',
            'thread'   => $thread,
            'category' => $category,
            'post'     => $post,
            'content'  => $content,
        ]);
    }

    public function deletePostAction(): void
    {
        $postId = (int) $this->request->post('id', 'int', 0);
        $post = $this->findLivePost($postId);
        if ($post === null) {
            $this->notify->error('Post not found.');
            redirect_to('forum');
            return;
        }
        if (!forum_can_edit_post($post)) {
            $this->notify->error('You cannot delete this post.');
            redirect_to('forum/thread', ['id' => (int) $post['thread_id']]);
            return;
        }

        $threadId = (int) $post['thread_id'];
        $remaining = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__forum_posts`
              WHERE `thread_id` = ? AND `is_deleted` = 0 AND `id` <> ?",
            [$threadId, $postId]
        )->value;

        $this->db->transaction(function () use ($postId, $threadId, $remaining, $post) {
            $this->db->update('forum_posts', ['is_deleted' => 1], ['id' => $postId]);

            if ($remaining === 0) {
                $this->db->update('forum_threads', ['is_deleted' => 1], ['id' => $threadId]);
                $this->db->query(
                    "UPDATE `#__forum_categories`
                        SET `threads_count` = GREATEST(`threads_count` - 1, 0),
                            `posts_count` = GREATEST(`posts_count` - 1, 0)
                      WHERE `id` = ?",
                    [(int) $post['category_id']]
                );
                return;
            }

            $last = $this->db->query(
                "SELECT `id`, `user_id`, `created_at`
                   FROM `#__forum_posts`
                  WHERE `thread_id` = ? AND `is_deleted` = 0
                  ORDER BY `created_at` DESC, `id` DESC
                  LIMIT 1",
                [$threadId]
            )->row;

            $this->db->query(
                "UPDATE `#__forum_threads`
                    SET `post_count` = GREATEST(`post_count` - 1, 1),
                        `last_post_id` = ?,
                        `last_post_at` = ?,
                        `last_post_by` = ?
                  WHERE `id` = ?",
                [
                    (int) ($last['id'] ?? 0),
                    (string) ($last['created_at'] ?? date('Y-m-d H:i:s')),
                    (int) ($last['user_id'] ?? 0),
                    $threadId,
                ]
            );
            $this->db->query(
                "UPDATE `#__forum_categories`
                    SET `posts_count` = GREATEST(`posts_count` - 1, 0)
                  WHERE `id` = ?",
                [(int) $post['category_id']]
            );
        });

        $this->notify->success('Post deleted.');
        if ($remaining === 0) {
            redirect_to('forum');
            return;
        }
        redirect_to('forum/thread', ['id' => $threadId]);
    }

    public function moderateAction(): void
    {
        if (!forum_can_moderate()) {
            $this->notify->error('Moderation access required.');
            redirect_to('forum');
            return;
        }

        $threadId = (int) $this->request->post('id', 'int', 0);
        $found = $this->loadThread($threadId);
        if ($found === null) {
            return;
        }
        [$thread, $category] = $found;

        $do = (string) $this->request->post('do', 'string', '');
        if ($do === 'pin') {
            $this->db->update('forum_threads', [
                'is_pinned' => (int) $thread['is_pinned'] === 1 ? 0 : 1,
            ], ['id' => $threadId]);
            $this->notify->success((int) $thread['is_pinned'] === 1 ? 'Thread unpinned.' : 'Thread pinned.');
        } elseif ($do === 'lock') {
            $this->db->update('forum_threads', [
                'is_locked' => (int) $thread['is_locked'] === 1 ? 0 : 1,
            ], ['id' => $threadId]);
            $this->notify->success((int) $thread['is_locked'] === 1 ? 'Thread unlocked.' : 'Thread locked.');
        } elseif ($do === 'delete') {
            $this->db->update('forum_threads', ['is_deleted' => 1], ['id' => $threadId]);
            $this->db->query(
                "UPDATE `#__forum_categories`
                    SET `threads_count` = GREATEST(`threads_count` - 1, 0),
                        `posts_count` = GREATEST(`posts_count` - ?, 0)
                  WHERE `id` = ?",
                [(int) $thread['post_count'], (int) $category['id']]
            );
            $this->notify->success('Thread deleted.');
            redirect_to('forum/category', ['slug' => (string) $category['slug']]);
            return;
        } else {
            $this->notify->error('Unknown moderation action.');
        }

        redirect_to('forum/thread', ['id' => $threadId]);
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}|null
     */
    private function loadThread(int $id): ?array
    {
        if ($id <= 0) {
            $this->notify->error('Thread not found.');
            redirect_to('forum');
            return null;
        }

        $thread = $this->db->first('forum_threads', [
            'id'         => $id,
            'is_deleted' => 0,
        ]);
        if ($thread === []) {
            $this->notify->error('Thread not found.');
            redirect_to('forum');
            return null;
        }

        $category = $this->db->first('forum_categories', [
            'id' => (int) $thread['category_id'],
        ]);
        if ($category === []) {
            $this->notify->error('Category not found.');
            redirect_to('forum');
            return null;
        }

        if (!forum_can_view_category($category)) {
            $this->notify->error("You don't have permission to view this forum.");
            redirect_to('forum');
            return null;
        }

        return [$thread, $category];
    }

    /** @return array<string, mixed>|null */
    private function findLivePost(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->db->query(
            "SELECT p.*, t.`category_id`
               FROM `#__forum_posts` p
               JOIN `#__forum_threads` t ON t.`id` = p.`thread_id`
              WHERE p.`id` = ? AND p.`is_deleted` = 0 AND t.`is_deleted` = 0
              LIMIT 1",
            [$id]
        )->row;
        return $row === [] ? null : $row;
    }
}
