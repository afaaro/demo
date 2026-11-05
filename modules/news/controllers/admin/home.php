<?php
declare(strict_types=1);

use Module\News\Services\NewsRepository;
use System\Engine\Controller;

class NewsAdminHome extends Controller
{
    private NewsRepository $news;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->news = new NewsRepository($this->db);
    }

    public function indexAction(): void
    {
        $counts = $this->news->countsByStatus();
        $latest = $this->news->latest(5);

        echo $this->view->inline(function () use ($counts, $latest) {
            $this->view->assign('title', 'News');
            $this->view->block('right_sidebar', 'News');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>News</h1><p>Articles, drafts and categories.</p></div>';
            echo '<div class="sf-actions">';
            echo '<a href="' . $e($this->url->to('news/admin/categories')) . '" class="sf-btn">'
               . '<i class="bi bi-tags"></i>Categories</a>';
            echo '<a href="' . $e($this->url->to('news/admin/articles/add')) . '" class="sf-btn is-primary">'
               . '<i class="bi bi-plus-lg"></i>New article</a>';
            echo '</div></div>';

            echo '<div class="sf-stats">';
            foreach ([
                ['Total',     $counts['total'],     $this->url->to('news/admin/articles')],
                ['Published', $counts['published'], $this->url->to('news/admin/articles', ['status' => 'published'])],
                ['Drafts',    $counts['draft'],     $this->url->to('news/admin/articles', ['status' => 'draft'])],
                ['Archived',  $counts['archived'],  $this->url->to('news/admin/articles', ['status' => 'archived'])],
            ] as [$label, $value, $url]) {
                echo '<a href="' . $e($url) . '" class="sf-stat"><span>'
                   . $e($label) . '</span><strong>' . (int) $value . '</strong></a>';
            }
            echo '</div>';

            echo '<section class="sf-card">';
            echo '<div class="sf-card-h"><h2>Latest</h2>'
               . '<a href="' . $e($this->url->to('news/admin/articles')) . '">View all</a></div>';

            if ($latest === []) {
                echo '<p class="sf-empty">No articles yet. '
                   . '<a href="' . $e($this->url->to('news/admin/articles/add')) . '">Create the first one</a>.</p>';
                echo '</section></div>';
                return;
            }

            echo '<div class="table-responsive"><table class="sf-table">';
            echo '<thead><tr><th>Title</th><th class="c-cat">Category</th>'
               . '<th class="c-status">Status</th><th class="c-when">Created</th>'
               . '<th class="c-acts"></th></tr></thead><tbody>';

            foreach ($latest as $n) {
                echo '<tr>';
                echo '<td><a class="sf-title" href="'
                   . $e($this->url->to('news/admin/articles/edit', ['id' => $n->id]))
                   . '" title="' . $e($n->title) . '">' . $e($n->title) . '</a></td>';
                echo '<td class="c-cat">' . $e($n->categoryName ?? '—') . '</td>';
                echo '<td class="c-status">' . $this->statusPill($n) . '</td>';
                echo '<td class="c-when sf-when">' . $e($this->formatWhen($n->createdAt ?? null)) . '</td>';
                echo '<td class="c-acts"><div class="sf-row-acts">'
                   . '<a href="' . $e($this->url->to('news/admin/articles/edit', ['id' => $n->id])) . '"'
                   . ' class="sf-btn">Edit</a></div></td>';
                echo '</tr>';
            }

            echo '</tbody></table></div></section></div>';
        });
    }

    private function formatWhen(?string $at): string
    {
        if ($at === null || trim($at) === '') {
            return '—';
        }
        $ts = strtotime($at);
        return $ts === false ? $at : date('d M H:i', $ts);
    }

    private function statusPill(\Module\News\Services\News $n): string
    {
        if ($n->isScheduled()) {
            return '<span class="sf-pill is-pin">Scheduled</span>';
        }
        return match ($n->status) {
            'published' => '<span class="sf-pill is-ok">Published</span>',
            'archived'  => '<span class="sf-pill is-off">Archived</span>',
            default     => '<span class="sf-pill is-wait">Draft</span>',
        };
    }
}
