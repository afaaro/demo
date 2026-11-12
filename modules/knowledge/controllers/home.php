<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Knowledge\Services\Domain;
use Module\Knowledge\Services\DomainRepository;
use Module\Knowledge\Services\Entry;
use Module\Knowledge\Services\EntryRepository;
use Module\Knowledge\Services\Poll;
use Module\Knowledge\Services\PollRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\HtmlCleaner;
use System\Library\Paginator;

class KnowledgeHome extends Controller
{
    private EntryRepository $entries;
    private DomainRepository $domains;
    private PollRepository $polls;

    protected function boot(): void
    {
        $this->entries = new EntryRepository($this->db);
        $this->domains = new DomainRepository($this->db);
        $this->polls = new PollRepository($this->db);
        $this->view->addModuleStyle('knowledge', 'knowledge.css');
    }

    public function indexAction(): void
    {
        $cfg = knowledge_module_config();
        $lang = knowledge_resolve_lang($this->request, $this->session);
        $page = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage = $cfg['entries_per_page'];
        $offset = ($page - 1) * $perPage;
        $domainId = (int) $this->request->get('domain', 'int', 0);
        $q = trim((string) $this->request->get('q', 'string', ''));
        if (function_exists('mb_substr')) {
            $q = trim(mb_substr($q, 0, 120));
        } else {
            $q = trim(substr($q, 0, 120));
        }

        $domainFilter = $domainId > 0 ? $domainId : null;
        $items = $this->entries->published($perPage, $offset, $domainFilter, $q, $lang);
        $total = $this->entries->countPublished($domainFilter, $q, $lang);
        $domains = $this->domains->all(true);
        $active = $domainId > 0 ? $this->domains->find($domainId) : null;
        $pager = Paginator::make($total, $page, $perPage);

        echo $this->view->inline(function () use ($items, $pager, $domains, $active, $q, $total, $lang, $cfg) {
            $title = $active !== null ? $active->name : 'Knowledge';
            $this->view->assign('title', $q !== '' ? 'Search · ' . $title : $title);
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="kb">';
            echo '<div class="kb-head">';
            echo '<h1>' . $e($title) . '</h1>';
            echo '<div class="kb-head__tools">';
            echo $this->langSwitcher($cfg['locales'], $lang, [
                'q' => $q,
                'domain' => $active?->id,
            ]);
            echo '<form class="kb-search" method="get" action="' . $e($this->url->to('knowledge')) . '">';
            if ($active !== null) {
                echo '<input type="hidden" name="domain" value="' . (int) $active->id . '">';
            }
            echo '<input type="hidden" name="lang" value="' . $e($lang) . '">';
            echo '<input type="search" name="q" value="' . $e($q) . '" placeholder="Search knowledge…" maxlength="120">';
            echo '<button type="submit">Search</button>';
            echo '</form>';
            echo '</div></div>';

            echo $this->domainNav($domains, $active, $q, $lang);

            if ($items === []) {
                echo '<p class="kb-empty">'
                   . ($q !== '' ? 'No entries match “' . $e($q) . '”.' : 'No knowledge entries yet.')
                   . '</p></div>';
                return;
            }

            if ($q !== '') {
                echo '<p class="kb-results">' . (int) $total . ' result'
                   . ((int) $total === 1 ? '' : 's') . ' for “' . $e($q) . '”</p>';
            }

            echo '<div class="kb-list">';
            foreach ($items as $item) {
                echo $this->entryCard($item, $lang);
            }
            echo '</div>';

            $params = ['lang' => $lang];
            if ($active !== null) {
                $params['domain'] = $active->id;
            }
            if ($q !== '') {
                $params['q'] = $q;
            }
            echo $pager->render(
                fn (int $p) => $this->url->to('knowledge', $params + ['page' => $p]),
                ['theme' => 'bootstrap']
            );
            echo '</div>';
        });
    }

    public function viewAction(): void
    {
        $cfg = knowledge_module_config();
        $lang = knowledge_resolve_lang($this->request, $this->session);
        $slug = trim((string) $this->request->get('slug', 'string', ''));
        $item = $slug !== '' ? $this->entries->findBySlug($slug, $lang) : null;

        if ($item === null || !$item->isPublished()) {
            throw new NotFoundException('Knowledge entry not found.');
        }

        $userId = $this->auth->check() ? (int) $this->auth->id() : null;
        $voterKey = knowledge_voter_key($userId, $this->request, $this->session);
        $poll = $this->polls->findByEntryId((int) $item->id, $lang, $voterKey);

        echo $this->view->inline(function () use ($item, $lang, $cfg, $poll) {
            $title = $item->title !== '' ? $item->title : $item->slug;
            $this->view->assign('title', $title);
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="kb"><article class="kb-article">';
            echo '<div class="kb-head">';
            echo '<p class="kb-article__crumb mb-0">';
            echo '<a href="' . $e($this->url->to('knowledge', ['lang' => $lang])) . '">Knowledge</a>';
            if ($item->domainId && $item->domainName) {
                echo ' / <a href="' . $e($this->url->to('knowledge', [
                    'domain' => $item->domainId,
                    'lang'   => $lang,
                ])) . '">' . $e((string) $item->domainName) . '</a>';
            }
            echo '</p>';
            echo $this->langSwitcher($cfg['locales'], $lang, ['slug' => $item->slug], 'knowledge/view');
            echo '</div>';

            echo '<h1>' . $e($title) . '</h1>';
            if ($item->summary !== '') {
                echo '<p class="kb-article__summary">' . $e($item->summary) . '</p>';
            }
            if (trim(strip_tags($item->body)) !== '') {
                echo '<div class="kb-article__body">' . $this->safeHtml($item->body) . '</div>';
            }
            if ($item->sourceUrl !== '') {
                echo '<p class="kb-article__source">Source: <a href="'
                   . $e($item->sourceUrl) . '" target="_blank" rel="noopener noreferrer">'
                   . $e($item->sourceUrl) . '</a></p>';
            }

            if ($poll !== null && $poll->options !== []) {
                echo $this->renderPoll($poll, $item, $lang, $cfg['allow_guest_votes']);
            }

            echo '</article></div>';
        });
    }

    public function voteAction(): void
    {
        $cfg = knowledge_module_config();
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('knowledge');
            return;
        }

        $slug = trim((string) $this->request->post('slug', 'string', ''));
        $pollId = (int) $this->request->post('poll_id', 'int', 0);
        $optionId = (int) $this->request->post('option_id', 'int', 0);
        $lang = knowledge_resolve_lang($this->request, $this->session);

        $item = $slug !== '' ? $this->entries->findBySlug($slug, $lang) : null;
        if ($item === null || !$item->isPublished()) {
            $this->notify->error('Entry not found.');
            redirect_to('knowledge');
            return;
        }

        $loggedIn = $this->auth->check();
        if (!$loggedIn && empty($cfg['allow_guest_votes'])) {
            $this->notify->error('Please sign in to vote.');
            redirect_to('user/login');
            return;
        }

        $userId = $loggedIn ? (int) $this->auth->id() : null;
        $voterKey = knowledge_voter_key($userId, $this->request, $this->session);

        try {
            $this->polls->castVote($pollId, $optionId, $voterKey, $userId);
            $this->notify->success('Thanks for voting.');
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
        }

        redirect_to('knowledge/view', ['slug' => $item->slug, 'lang' => $lang]);
    }

    /** @param list<string> $locales */
    private function langSwitcher(array $locales, string $active, array $params = [], string $route = 'knowledge'): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $html = '<div class="kb-langs">';
        foreach ($locales as $locale) {
            $cls = $locale === $active ? ' kb-lang is-active' : ' kb-lang';
            $urlParams = $params;
            foreach ($urlParams as $k => $v) {
                if ($v === null || $v === '') {
                    unset($urlParams[$k]);
                }
            }
            $urlParams['lang'] = $locale;
            $html .= '<a class="' . trim($cls) . '" href="'
                . $e($this->url->to($route, $urlParams)) . '">'
                . $e(strtoupper($locale)) . '</a>';
        }
        return $html . '</div>';
    }

    /** @param list<Domain> $domains */
    private function domainNav(array $domains, ?Domain $active, string $q, string $lang): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $html = '<nav class="kb-domains">';
        $allParams = ['lang' => $lang];
        if ($q !== '') {
            $allParams['q'] = $q;
        }
        $html .= '<a class="kb-domain' . ($active === null ? ' is-active' : '') . '" href="'
            . $e($this->url->to('knowledge', $allParams)) . '">All</a>';

        foreach ($domains as $domain) {
            $params = ['domain' => $domain->id, 'lang' => $lang];
            if ($q !== '') {
                $params['q'] = $q;
            }
            $cls = ($active !== null && $active->id === $domain->id) ? ' kb-domain is-active' : ' kb-domain';
            $html .= '<a class="' . trim($cls) . '" href="'
                . $e($this->url->to('knowledge', $params)) . '">'
                . $e($domain->name) . '</a>';
        }
        return $html . '</nav>';
    }

    private function entryCard(Entry $item, string $lang): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $title = $item->title !== '' ? $item->title : $item->slug;
        $html = '<a class="kb-card" href="'
            . $e($this->url->to('knowledge/view', ['slug' => $item->slug, 'lang' => $lang])) . '">';
        $html .= '<h2 class="kb-card__title">' . $e($title) . '</h2>';
        if ($item->domainName) {
            $html .= '<p class="kb-card__meta">' . $e((string) $item->domainName) . '</p>';
        }
        if ($item->summary !== '') {
            $html .= '<p class="kb-card__summary">' . $e($item->summary) . '</p>';
        }
        return $html . '</a>';
    }

    private function renderPoll(Poll $poll, Entry $item, string $lang, bool $allowGuest): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $loggedIn = $this->auth->check();
        $canVote = $poll->isOpen()
            && $poll->votedOptionId === null
            && ($loggedIn || $allowGuest);

        $html = '<section class="kb-poll">';
        $html .= '<h2>' . $e($poll->question !== '' ? $poll->question : 'Poll') . '</h2>';

        if ($canVote) {
            $html .= '<form method="post" action="' . $e($this->url->to('knowledge/vote')) . '">';
            $html .= $this->request->csrfField();
            $html .= '<input type="hidden" name="slug" value="' . $e($item->slug) . '">';
            $html .= '<input type="hidden" name="poll_id" value="' . (int) $poll->id . '">';
            $html .= '<input type="hidden" name="lang" value="' . $e($lang) . '">';
            $html .= '<div class="kb-poll__options">';
            foreach ($poll->options as $opt) {
                $html .= '<label class="kb-poll__option">';
                $html .= '<input type="radio" name="option_id" value="' . (int) $opt->id . '" required> ';
                $html .= '<span>' . $e($opt->label !== '' ? $opt->label : 'Option') . '</span>';
                $html .= '</label>';
            }
            $html .= '</div>';
            $html .= '<div class="kb-poll__actions"><button type="submit" class="sf-btn is-primary">Vote</button></div>';
            $html .= '</form>';
        } else {
            $html .= '<div class="kb-poll__options">';
            $total = max(1, $poll->totalVotes);
            foreach ($poll->options as $opt) {
                $votes = (int) ($poll->tallies[(int) $opt->id] ?? 0);
                $pct = (int) round(($votes / $total) * 100);
                $chosen = $poll->votedOptionId !== null && (int) $poll->votedOptionId === (int) $opt->id;
                $html .= '<div class="kb-poll__option">';
                $html .= '<div style="flex:1">';
                $html .= '<div>' . $e($opt->label !== '' ? $opt->label : 'Option')
                    . ($chosen ? ' <strong>· your vote</strong>' : '')
                    . ' <span class="text-muted">(' . $votes . ' · ' . $pct . '%)</span></div>';
                $html .= '<div class="kb-poll__bar"><span style="width:' . $pct . '%"></span></div>';
                $html .= '</div></div>';
            }
            $html .= '</div>';
            $html .= '<p class="kb-poll__meta">' . (int) $poll->totalVotes . ' vote'
                . ((int) $poll->totalVotes === 1 ? '' : 's');
            if (!$poll->isOpen()) {
                $html .= ' · closed';
            } elseif ($poll->votedOptionId !== null) {
                $html .= ' · you already voted';
            } elseif (!$loggedIn && !$allowGuest) {
                $html .= ' · sign in to vote';
            }
            $html .= '</p>';
        }

        return $html . '</section>';
    }

    private function safeHtml(string $html): string
    {
        if (is_callable([HtmlCleaner::class, 'sanitize'])) {
            return HtmlCleaner::sanitize($html);
        }
        return HtmlCleaner::clean($html);
    }
}
