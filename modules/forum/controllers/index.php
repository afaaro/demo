<?php

class ForumIndex extends Controller
{
    public function indexAction(): void
    {
        $this->asset->addTitle('Forums');
        $this->asset->addModuleStyle('forum', 'css/forum.css');

        $userId = $this->auth->check() ? (int) $this->auth->id() : 0;

        $categories = $this->db->query(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM `#__forum_threads` t
                      WHERE t.`category_id` = c.`id` AND t.`is_deleted` = 0) AS thread_count,
                    (SELECT t2.`title` FROM `#__forum_threads` t2
                      WHERE t2.`category_id` = c.`id` AND t2.`is_deleted` = 0
                      ORDER BY t2.`last_post_at` DESC, t2.`id` DESC LIMIT 1) AS last_title,
                    (SELECT t2.`id` FROM `#__forum_threads` t2
                      WHERE t2.`category_id` = c.`id` AND t2.`is_deleted` = 0
                      ORDER BY t2.`last_post_at` DESC, t2.`id` DESC LIMIT 1) AS last_thread_id,
                    (SELECT t2.`last_post_at` FROM `#__forum_threads` t2
                      WHERE t2.`category_id` = c.`id` AND t2.`is_deleted` = 0
                      ORDER BY t2.`last_post_at` DESC, t2.`id` DESC LIMIT 1) AS last_post_at,
                    (SELECT u.`username`
                       FROM `#__forum_threads` t2
                       LEFT JOIN `#__user` u ON u.`id` = t2.`last_post_by`
                      WHERE t2.`category_id` = c.`id` AND t2.`is_deleted` = 0
                      ORDER BY t2.`last_post_at` DESC, t2.`id` DESC LIMIT 1) AS last_poster
             FROM `#__forum_categories` c
             WHERE c.`status` = 'visible'
             ORDER BY c.`position`, c.`name`"
        )->rows ?? [];

        $unreadByCat = [];
        if ($userId > 0 && $categories !== []) {
            $rows = $this->db->query(
                "SELECT t.`category_id`, COUNT(*) AS unread
                   FROM `#__forum_threads` t
                   LEFT JOIN `#__forum_reads` r
                     ON r.`thread_id` = t.`id` AND r.`user_id` = ?
                  WHERE t.`is_deleted` = 0
                    AND (r.`last_read_at` IS NULL OR r.`last_read_at` < t.`last_post_at`)
                  GROUP BY t.`category_id`",
                [$userId]
            )->rows ?? [];
            foreach ($rows as $row) {
                $unreadByCat[(int) $row['category_id']] = (int) $row['unread'];
            }
        }

        $latest = $this->db->query(
            "SELECT t.`id`, t.`title`, t.`last_post_at`, t.`post_count`, t.`is_pinned`,
                    c.`name` AS category_name, c.`slug` AS category_slug,
                    u.`username` AS last_poster
               FROM `#__forum_threads` t
               JOIN `#__forum_categories` c ON c.`id` = t.`category_id` AND c.`status` = 'visible'
               LEFT JOIN `#__user` u ON u.`id` = t.`last_post_by`
              WHERE t.`is_deleted` = 0
              ORDER BY t.`last_post_at` DESC, t.`id` DESC
              LIMIT 8"
        )->rows ?? [];

        $this->template('forum/home/index', [
            'categories'  => $categories,
            'unreadByCat' => $unreadByCat,
            'latest'      => $latest,
        ]);
        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }

    public function whatsNewAction(): void
    {
        $this->asset->addTitle("What's new");
        $this->asset->addModuleStyle('forum', 'css/forum.css');

        $userId = $this->auth->check() ? (int) $this->auth->id() : 0;

        $items = $this->db->query(
            "SELECT t.`id`, t.`title`, t.`last_post_at`, t.`post_count`, t.`view_count`,
                    t.`is_pinned`, t.`is_locked`, t.`created_at`,
                    c.`name` AS category_name, c.`slug` AS category_slug,
                    au.`username` AS author_name,
                    u.`username` AS last_poster,
                    r.`last_read_at`
               FROM `#__forum_threads` t
               JOIN `#__forum_categories` c ON c.`id` = t.`category_id` AND c.`status` = 'visible'
               LEFT JOIN `#__user` au ON au.`id` = t.`user_id`
               LEFT JOIN `#__user` u ON u.`id` = t.`last_post_by`
               LEFT JOIN `#__forum_reads` r ON r.`thread_id` = t.`id` AND r.`user_id` = ?
              WHERE t.`is_deleted` = 0
              ORDER BY t.`last_post_at` DESC, t.`id` DESC
              LIMIT 40",
            [$userId]
        )->rows ?? [];

        $this->template('forum/home/whats_new', [
            'items' => $items,
        ]);
        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }

    public function newAction(): void
    {
        $slug = trim((string) ($this->request->get('slug') ?: $this->request->param('slug', 'string', '')));

        $categories = $this->db->query(
            "SELECT `id`, `slug`, `name`, `status`
             FROM `#__forum_categories`
             WHERE `status` = 'visible'
             ORDER BY `position`, `name`"
        )->rows ?? [];
        $categories = array_values(array_filter(
            $categories,
            static fn(array $c): bool => forum_can_post($c)
        ));

        if ($categories === []) {
            $this->notify->error('No categories available to post in.');
            redirect('forum');
        }

        $selectedCategoryId = 0;
        if ($slug !== '') {
            foreach ($categories as $c) {
                if ($c['slug'] === $slug) {
                    $selectedCategoryId = (int) $c['id'];
                    break;
                }
            }
        }

        $form = [
            'title'       => '',
            'content'     => '',
            'category_id' => $selectedCategoryId,
        ];

        if ($this->request->isPost()) {
            forum_require_csrf();
            $categoryId = (int) $this->request->post('category_id', 'int', 0);
            $title      = trim((string) $this->request->post('title', 'raw', ''));
            $content    = trim((string) $this->request->post('content', 'raw', ''));
            $form = [
                'title'       => $title,
                'content'     => $content,
                'category_id' => $categoryId,
            ];

            $category = null;
            foreach ($categories as $c) {
                if ((int) $c['id'] === $categoryId) {
                    $category = $c;
                    break;
                }
            }
            if ($category === null) {
                $this->notify->error('Select a valid category.');
            } else {
                $categoryFull = $this->db->first('forum_categories', ['id' => $categoryId]);
                if ($categoryFull === [] || !forum_can_post($categoryFull)) {
                    $this->notify->error('You cannot post in that category.');
                } else {
                    $error = forum_validate_post($title, $content);
                    if ($error !== null) {
                        $this->notify->error($error);
                    } else {
                        $threadSlug = forum_slugify($title, $this->db, $categoryId);
                        $userId = (int) $this->auth->id();

                        try {
                            $threadId = $this->db->transaction(function () use (
                                $userId, $categoryId, $title, $threadSlug, $content
                            ) {
                                $this->db->insert('forum_threads', [
                                    'category_id'  => $categoryId,
                                    'user_id'      => $userId,
                                    'title'        => $title,
                                    'slug'         => $threadSlug,
                                    'post_count'   => 1,
                                    'view_count'   => 0,
                                    'is_pinned'    => 0,
                                    'is_locked'    => 0,
                                    'is_deleted'   => 0,
                                    'last_post_at' => date('Y-m-d H:i:s'),
                                    'last_post_by' => $userId,
                                ]);
                                $threadId = $this->db->insert_id();

                                $this->db->insert('forum_posts', [
                                    'thread_id'    => $threadId,
                                    'user_id'      => $userId,
                                    'content'      => $content,
                                    'content_html' => forum_render_markdown($content),
                                ]);
                                $postId = $this->db->insert_id();

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
                        } catch (Throwable $e) {
                            $this->log->error('forum new thread failed: ' . $e->getMessage());
                            $this->notify->error('Failed to create thread. Please try again.');
                            $this->renderNewForm($categories, $form);
                            return;
                        }

                        $this->notify->success('Thread created.');
                        redirect_to('forum/thread', ['id' => (int) $threadId]);
                    }
                }
            }
        }

        $this->renderNewForm($categories, $form);
    }

    private function renderNewForm(array $categories, array $form): void
    {
        $this->asset->addTitle('New thread');
        $this->asset->addModuleStyle('forum', 'css/forum.css');
        $this->template('forum/post/create', [
            'categories'         => $categories,
            'selectedCategoryId' => (int) ($form['category_id'] ?? 0),
            'form'               => $form,
        ]);
        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }
}
