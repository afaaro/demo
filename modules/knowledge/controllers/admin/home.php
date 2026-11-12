<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use Module\Knowledge\Services\EntryRepository;
use System\Engine\Controller;

class KnowledgeAdminHome extends Controller
{
    private EntryRepository $entries;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->entries = new EntryRepository($this->db);
    }

    public function indexAction(): void
    {
        $counts = $this->entries->countsByStatus();
        $latest = $this->entries->latest(5);

        echo $this->view->inline(function () use ($counts, $latest) {
            $this->view->assign('title', 'Knowledge');
            $this->view->block('right_sidebar', 'Knowledge');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>Knowledge</h1><p>Entries, domains and translations.</p></div>';
            echo '<div class="sf-actions">';
            echo '<a href="' . $e($this->url->to('knowledge/admin/domains')) . '" class="sf-btn">'
               . '<i class="bi bi-tags"></i>Domains</a>';
            echo '<a href="' . $e($this->url->to('knowledge/admin/entries/add')) . '" class="sf-btn is-primary">'
               . '<i class="bi bi-plus-lg"></i>New entry</a>';
            echo '</div></div>';

            echo '<div class="sf-stats">';
            foreach ([
                ['Total',     $counts['total'],     $this->url->to('knowledge/admin/entries')],
                ['Published', $counts['published'], $this->url->to('knowledge/admin/entries', ['status' => 'published'])],
                ['Drafts',    $counts['draft'],     $this->url->to('knowledge/admin/entries', ['status' => 'draft'])],
                ['Archived',  $counts['archived'],  $this->url->to('knowledge/admin/entries', ['status' => 'archived'])],
            ] as [$label, $value, $url]) {
                echo '<a href="' . $e($url) . '" class="sf-stat"><span>'
                   . $e($label) . '</span><strong>' . (int) $value . '</strong></a>';
            }
            echo '</div>';

            echo '<section class="sf-card">';
            echo '<div class="sf-card-h"><h2>Latest</h2>'
               . '<a href="' . $e($this->url->to('knowledge/admin/entries')) . '">View all</a></div>';

            if ($latest === []) {
                echo '<p class="sf-empty">No entries yet. '
                   . '<a href="' . $e($this->url->to('knowledge/admin/entries/add')) . '">Create the first one</a>.</p>';
                echo '</section></div>';
                return;
            }

            echo '<div class="table-responsive"><table class="sf-table">';
            echo '<thead><tr><th>Title</th><th class="c-cat">Domain</th>'
               . '<th class="c-status">Status</th><th class="c-when">Created</th>'
               . '<th class="c-acts"></th></tr></thead><tbody>';

            foreach ($latest as $item) {
                $title = $item->title !== '' ? $item->title : $item->slug;
                echo '<tr>';
                echo '<td><a class="sf-title" href="'
                   . $e($this->url->to('knowledge/admin/entries/edit', ['id' => $item->id]))
                   . '" title="' . $e($title) . '">' . $e($title) . '</a></td>';
                echo '<td class="c-cat">' . $e($item->domainName ?? '—') . '</td>';
                echo '<td class="c-status">' . $this->statusPill($item->status) . '</td>';
                echo '<td class="c-when sf-when">' . $e($this->formatWhen($item->createdAt ?? null)) . '</td>';
                echo '<td class="c-acts"><div class="sf-row-acts">'
                   . '<a href="' . $e($this->url->to('knowledge/admin/entries/edit', ['id' => $item->id])) . '"'
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

    private function statusPill(string $status): string
    {
        return match ($status) {
            'published' => '<span class="sf-pill is-ok">Published</span>',
            'archived'  => '<span class="sf-pill is-off">Archived</span>',
            default     => '<span class="sf-pill is-wait">Draft</span>',
        };
    }
}
