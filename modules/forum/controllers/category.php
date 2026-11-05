<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use System\Engine\Controller;
use System\Library\Paginator;

class ForumCategory extends Controller
{
    private const PER_PAGE = 25;

    public function viewAction(): void
    {
        $slug = (string) $this->request->param('slug', 'slug', '');

        if ($slug === '') {
            $this->notify->error('Category not found.');
            redirect_to('forum');
            return;
        }

        $category = $this->db->first('forum_categories', [
            'slug'   => $slug,
            'status' => 'visible',
        ]);

        if ($category === []) {
            $this->notify->error('Category not found.');
            redirect_to('forum');
            return;
        }

        if (!forum_can_view_category($category)) {
            $this->notify->error("You don't have permission to view this forum.");
            redirect_to('forum');
            return;
        }

        $this->view->addModuleStyle('forum', 'css/forum.css');

        $categoryId = (int) $category['id'];
        $userId     = $this->auth->check() ? (int) $this->auth->id() : 0;
        $q          = trim((string) $this->request->get('q', 'string', ''));
        $sort       = (string) $this->request->get('sort', 'string', 'activity');
        $order      = match ($sort) {
            'newest'  => 't.`created_at` DESC, t.`id` DESC',
            'replies' => 't.`post_count` DESC, t.`last_post_at` DESC',
            'views'   => 't.`view_count` DESC, t.`last_post_at` DESC',
            default   => 't.`last_post_at` DESC, t.`id` DESC',
        };
        if (!in_array($sort, ['activity', 'newest', 'replies', 'views'], true)) {
            $sort = 'activity';
        }

        $where  = 't.`category_id` = ? AND t.`is_deleted` = 0';
        $params = [$categoryId];
        if ($q !== '') {
            $where   .= ' AND t.`title` LIKE ?';
            $params[] = '%' . $q . '%';
        }

        $total = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__forum_threads` t WHERE {$where}",
            $params
        )->value;

        $pager = Paginator::make(
            $total,
            (int) $this->request->get('page', 'int', 1),
            self::PER_PAGE
        );

        $threads = $this->db->query(
            "SELECT t.*,
                    COALESCE(NULLIF(au.`display_name`, ''), au.`username`) AS author_name,
                    COALESCE(NULLIF(lu.`display_name`, ''), lu.`username`) AS last_poster,
                    r.`last_read_at`
               FROM `#__forum_threads` t
               LEFT JOIN `#__users` au ON au.`id` = t.`user_id`
               LEFT JOIN `#__users` lu ON lu.`id` = t.`last_post_by`
               LEFT JOIN `#__forum_reads` r ON r.`thread_id` = t.`id` AND r.`user_id` = ?
              WHERE {$where}
              ORDER BY t.`is_pinned` DESC, {$order}
              LIMIT {$pager->perPage} OFFSET {$pager->offset}",
            array_merge([$userId], $params)
        )->rows;

        echo $this->view->render($this, 'view', 'main', [
            'title'    => (string) $category['name'],
            'category' => $category,
            'threads'  => $threads,
            'pager'    => $pager,
            'q'        => $q,
            'sort'     => $sort,
        ]);
    }

    public function indexAction(): void
    {
        redirect_to('forum');
    }
}
