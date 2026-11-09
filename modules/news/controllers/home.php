<?php
declare(strict_types=1);

use Module\News\Services\News;
use Module\News\Services\NewsCategory;
use Module\News\Services\NewsCategoryRepository;
use Module\News\Services\NewsRepository;
use Module\News\Services\NewsTag;
use Module\News\Services\NewsTagRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\HtmlCleaner;
use System\Library\Paginator;

class NewsHome extends Controller
{
    private NewsRepository         $news;
    private NewsCategoryRepository $categories;
    private NewsTagRepository      $tags;

    protected function boot(): void
    {
        $this->news       = new NewsRepository($this->db);
        $this->categories = new NewsCategoryRepository($this->db);
        $this->tags       = new NewsTagRepository($this->db);
        $this->news->publishDue();
        $this->view->addModuleStyle('news', 'news.css');
    }

    // =========================================================================
    // List — GET /news
    // =========================================================================

    public function indexAction(): void
    {
        $page       = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage    = $this->postsPerPage();
        $offset     = ($page - 1) * $perPage;
        $categoryId = (int) $this->request->get('category', 'int', 0);
        $q          = trim((string) $this->request->get('q', 'string', ''));
        if (function_exists('mb_substr')) {
            $q = trim(mb_substr($q, 0, 120));
        } else {
            $q = trim(substr($q, 0, 120));
        }

        $tagSlug   = trim((string) $this->request->get('tag', 'string', ''));
        $activeTag = $tagSlug !== '' ? $this->tags->findBySlug($tagSlug) : null;
        $tagId     = $activeTag?->id;

        $catFilter = $categoryId > 0 ? $categoryId : null;
        $items     = $this->news->published($perPage, $offset, $catFilter, $q, [], $tagId);
        $total     = $this->news->countPublished($catFilter, $q, $tagId);
        $cats      = $this->categories->all(true);
        $active    = $categoryId > 0 ? $this->categories->find($categoryId) : null;
        $pager     = Paginator::make($total, $page, $perPage);
        $popular   = ($page === 1 && $q === '' && $catFilter === null && $activeTag === null)
            ? $this->news->mostRead(5)
            : [];
        if (count($popular) < 2) {
            $popular = [];
        }

        if ($page === 1 && $q === '' && $catFilter === null && $activeTag === null) {
            $items = $this->withFeaturedLead($items, $perPage);
        }

        echo $this->view->inline(function () use ($items, $pager, $cats, $active, $q, $total, $activeTag, $popular) {
            $title = $activeTag !== null ? $activeTag->name : ($active !== null ? $active->name : 'News');
            $this->view->assign('title', $q !== '' ? 'Search · ' . $title : $title);
            $this->addFeedLink();
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="news">';
            echo $this->categoryNav($cats, $active, $q, $activeTag);

            if ($items === []) {
                echo '<p class="news-empty">'
                   . ($q !== '' ? 'No articles match “' . $e($q) . '”.'
                        : ($activeTag !== null ? 'No articles with this tag yet.' : 'No articles yet.'))
                   . '</p></div>';
                return;
            }

            if ($q !== '') {
                echo '<p class="news-results">' . (int) $total . ' result'
                   . ((int) $total === 1 ? '' : 's') . ' for “' . $e($q) . '”</p>';
            }

            echo '<div class="news-grid">';
            $lead = $pager->page === 1 && $q === '';
            foreach ($items as $n) {
                echo $this->articleCard($n, $lead);
                $lead = false;
            }
            echo '</div>';

            $params = [];
            if ($active !== null) {
                $params['category'] = $active->id;
            }
            if ($q !== '') {
                $params['q'] = $q;
            }
            if ($activeTag !== null) {
                $params['tag'] = $activeTag->slug;
            }
            echo $pager->render(
                fn (int $p) => $this->url->to('news', $params + ['page' => $p]),
                ['theme' => 'bootstrap']
            );
            echo $this->mostReadStrip($popular);
            echo '</div>';
        });
    }

    // =========================================================================
    // RSS — GET /news/rss
    // =========================================================================

    public function rssAction(): void
    {
        $items = $this->news->published(30, 0);
        $site  = (string) $this->config->get('meta.site_name', 'soomaali.net');
        $home  = $this->url->to('news', [], true);
        $self  = $this->url->to('news/rss', [], true);

        header('Content-Type: application/rss+xml; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');

        echo $this->view->inline(function () use ($items, $site, $home, $self) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
            echo '<title>' . $this->xml($site . ' News') . '</title>';
            echo '<link>' . $this->xml($home) . '</link>';
            echo '<description>' . $this->xml('Latest news from ' . $site) . '</description>';
            echo '<language>en</language>';
            echo '<atom:link href="' . $this->xml($self) . '" rel="self" type="application/rss+xml"/>';
            if ($items !== []) {
                $ts = strtotime((string) ($items[0]->publishedAt ?? 'now')) ?: time();
                echo '<lastBuildDate>' . $this->xml(date(DATE_RSS, $ts)) . '</lastBuildDate>';
            }
            foreach ($items as $n) {
                $url = $this->url->to('news/view', ['slug' => $n->slug], true);
                $ts  = strtotime((string) ($n->publishedAt ?? '')) ?: time();
                echo '<item>';
                echo '<title>' . $this->xml($n->title) . '</title>';
                echo '<link>' . $this->xml($url) . '</link>';
                echo '<guid isPermaLink="true">' . $this->xml($url) . '</guid>';
                echo '<pubDate>' . $this->xml(date(DATE_RSS, $ts)) . '</pubDate>';
                if ($n->excerpt !== '') {
                    echo '<description>' . $this->xml($n->excerpt) . '</description>';
                }
                if ($n->categoryName) {
                    echo '<category>' . $this->xml($n->categoryName) . '</category>';
                }
                echo '</item>';
            }
            echo '</channel></rss>';
        }, '');
    }

    // =========================================================================
    // Sitemap — GET /news/sitemap
    // =========================================================================

    public function sitemapAction(): void
    {
        $items = $this->news->published(1000, 0);
        $cats  = $this->categories->all(true);

        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');

        echo $this->view->inline(function () use ($items, $cats) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            echo $this->sitemapUrl($this->url->to('news', [], true), date('c'), 'hourly', '0.8');
            echo $this->sitemapUrl($this->url->to('news/rss', [], true), date('c'), 'hourly', '0.4');
            foreach ($cats as $c) {
                if ($c->id === null) {
                    continue;
                }
                echo $this->sitemapUrl(
                    $this->url->to('news', ['category' => $c->id], true),
                    date('c'),
                    'daily',
                    '0.5'
                );
            }
            foreach ($items as $n) {
                $mod = $n->updatedAt ?: $n->publishedAt ?: date('c');
                $ts  = strtotime((string) $mod);
                echo $this->sitemapUrl(
                    $this->url->to('news/view', ['slug' => $n->slug], true),
                    $ts !== false ? date('c', $ts) : date('c'),
                    'weekly',
                    '0.7'
                );
            }
            echo '</urlset>';
        }, '');
    }

    // =========================================================================
    // Detail — GET /news/view?slug=...
    // =========================================================================

    public function viewAction(): void
    {
        $slug = trim((string) $this->request->get('slug', 'string', ''));
        if ($slug === '') {
            throw new NotFoundException('Article not found.');
        }

        $item = $this->news->findBySlug($slug);
        if ($item === null || !$item->isPublished()) {
            throw new NotFoundException('Article not found.');
        }

        if (!$this->isBot()) {
            $this->news->incrementViews($item);
        }

        $this->renderArticle($item, false);
    }

    // =========================================================================
    // Draft preview — GET /news/preview/{slug}?token=
    // =========================================================================

    public function previewAction(): void
    {
        $slug  = trim((string) $this->request->get('slug', 'string', ''));
        $token = trim((string) $this->request->get('token', 'string', ''));
        if ($slug === '' || $token === '') {
            throw new NotFoundException('Article not found.');
        }

        $item = $this->news->findBySlug($slug);
        if ($item === null) {
            throw new NotFoundException('Article not found.');
        }

        $stored = $this->news->ensurePreviewToken($item);
        if ($stored === '' || !hash_equals($stored, $token)) {
            throw new NotFoundException('Article not found.');
        }

        $this->renderArticle($item, true);
    }

    private function renderArticle(News $item, bool $preview): void
    {
        $related = $item->isPublished() ? $this->news->related($item, 4) : [];
        $item->tags = $this->tags->forArticle((int) ($item->id ?? 0));
        $popular = $item->isPublished()
            ? $this->news->mostRead(5, $item->id !== null ? [(int) $item->id] : [])
            : [];

        echo $this->view->inline(function () use ($item, $related, $preview, $popular) {
            $this->view->assign('title', $item->title);
            if ($item->excerpt !== '') {
                $this->view->setDescription($item->excerpt);
            }
            if ($preview) {
                $this->view->setMeta('robots', 'noindex, nofollow');
            } else {
                $this->applyShareMeta($item);
            }

            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $backUrl = $this->url->to('news');

            echo '<div class="news">';
            echo '<article class="news-article">';
            if ($preview) {
                echo '<p class="news-preview-banner">Draft preview — not published</p>';
            }
            echo '<a class="news-back" href="' . $e($backUrl) . '">&larr; Back to news</a>';
            echo '<h1>' . $e($item->title) . '</h1>';
            echo $this->metaLine($item, false, true);
            echo $this->sourceLine($item);
            if (!$preview) {
                echo $this->shareBar($item);
            }

            $image = $this->imageUrl($item->image);
            if ($image !== '') {
                echo '<figure class="news-figure">';
                echo '<img class="news-article__hero" src="' . $e($image) . '" alt="">';
                echo $this->imageCaption($item);
                echo '</figure>';
            }

            echo $this->tagLine($item);
            echo '<div class="news-body">' . $this->renderBody($item->body) . '</div>';

            if (!$preview && $this->commentsEnabled()) {
                $commentsPath = get_module_path('comments');
                if ($commentsPath !== null) {
                    require_once $commentsPath . '/helpers/functions.php';
                    echo comments_render('news', 'article', (string) (int) $item->id, [
                        'login_query' => ['next' => 'news/view/' . ltrim((string) $item->slug, '/')],
                    ]);
                }
            }

            echo '<div class="news-article__foot">'
               . '<a class="news-back" href="' . $e($backUrl) . '">&larr; Back to news</a>'
               . '</div></article>';

            if ($related !== []) {
                echo '<section class="news-related" aria-label="Related articles">';
                echo '<h2>Related</h2>';
                echo '<div class="news-grid">';
                foreach ($related as $n) {
                    echo $this->articleCard($n, false);
                }
                echo '</div></section>';
            }

            echo $this->mostReadStrip($popular);
            echo '</div>';
        });
    }

    /** @param list<News> $items @return list<News> */
    private function withFeaturedLead(array $items, int $perPage): array
    {
        $featured = $this->news->featured();
        if ($featured === null || $featured->id === null) {
            return $items;
        }
        $rest = array_values(array_filter(
            $items,
            static fn(News $n) => $n->id !== $featured->id
        ));
        return array_slice(array_merge([$featured], $rest), 0, $perPage);
    }

    private function addFeedLink(): void
    {
        $href = $this->url->to('news/rss', [], true);
        $this->view->block(
            'head',
            '<link rel="alternate" type="application/rss+xml" title="News RSS" href="'
            . $this->view->e($href) . '">'
        );
    }

    private function sitemapUrl(string $loc, string $lastmod, string $freq, string $priority): string
    {
        return '<url><loc>' . $this->xml($loc) . '</loc>'
             . '<lastmod>' . $this->xml($lastmod) . '</lastmod>'
             . '<changefreq>' . $this->xml($freq) . '</changefreq>'
             . '<priority>' . $this->xml($priority) . '</priority></url>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function searchForm(string $q, ?NewsCategory $active, ?NewsTag $tag = null): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $html = '<form class="news-search" method="get" action="' . $e($this->url->to('news')) . '" role="search">';
        if ($active !== null && $active->id) {
            $html .= '<input type="hidden" name="category" value="' . (int) $active->id . '">';
        }
        if ($tag !== null && $tag->slug !== '') {
            $html .= '<input type="hidden" name="tag" value="' . $e($tag->slug) . '">';
        }
        $html .= '<input type="search" name="q" value="' . $e($q) . '" placeholder="Search news…" aria-label="Search news">';
        $html .= '<button type="submit">Search</button>';
        $html .= '</form>';
        return $html;
    }

    private function applyShareMeta(News $item): void
    {
        $url = $this->url->to('news/view', ['slug' => $item->slug], true);
        $desc = $item->excerpt !== '' ? $item->excerpt : $item->title;
        $image = $this->imageUrl($item->image);
        $site = (string) $this->config->get('meta.site_name', 'soomaali.net');

        $this->view->setOG('og:type', 'article');
        $this->view->setOG('og:site_name', $site);
        $this->view->setOG('og:title', $item->title);
        $this->view->setOG('og:description', $desc);
        $this->view->setOG('og:url', $url);
        if ($image !== '') {
            $this->view->setOG('og:image', $image);
        }

        $this->view->setMeta('twitter:card', $image !== '' ? 'summary_large_image' : 'summary');
        $this->view->setMeta('twitter:title', $item->title);
        $this->view->setMeta('twitter:description', $desc);
        if ($image !== '') {
            $this->view->setMeta('twitter:image', $image);
        }
    }

    private function shareBar(News $item): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $url = $this->url->to('news/view', ['slug' => $item->slug], true);
        $text = $item->title;
        $encodedUrl = rawurlencode($url);
        $encodedText = rawurlencode($text);

        $html = '<div class="news-share" data-share-url="' . $e($url) . '">';
        $html .= '<span>Share</span>';
        $html .= '<a href="https://wa.me/?text=' . $e(rawurlencode($text . ' ' . $url)) . '" target="_blank" rel="noopener noreferrer">WhatsApp</a>';
        $html .= '<a href="https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl . '" target="_blank" rel="noopener noreferrer">Facebook</a>';
        $html .= '<a href="https://twitter.com/intent/tweet?text=' . $encodedText . '&url=' . $encodedUrl . '" target="_blank" rel="noopener noreferrer">X</a>';
        $html .= '<button type="button" class="news-share__copy">Copy link</button>';
        $html .= '</div>';
        $html .= '<script>(function(){var r=document.querySelector(".news-share");if(!r)return;var b=r.querySelector(".news-share__copy");if(!b)return;b.addEventListener("click",function(){var u=r.getAttribute("data-share-url")||"";if(navigator.clipboard&&u){navigator.clipboard.writeText(u).then(function(){b.textContent="Copied";setTimeout(function(){b.textContent="Copy link";},1400);});}else{window.prompt("Copy this link",u);}});})();</script>';
        return $html;
    }

    /** @param list<NewsCategory> $cats */
    private function categoryNav(array $cats, ?NewsCategory $active, string $q = '', ?NewsTag $tag = null): string
    {
        if ($cats === []) {
            return '';
        }

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $extra = $q !== '' ? ['q' => $q] : [];
        if ($tag !== null && $tag->slug !== '') {
            $extra['tag'] = $tag->slug;
        }
        $html = '<nav class="news-cats" aria-label="Categories">';
        $html .= '<a href="' . $e($this->url->to('news', $extra)) . '"'
               . ($active === null ? ' class="is-on"' : '') . '>All</a>';

        foreach ($cats as $c) {
            $on = $active !== null && $active->id === $c->id ? ' class="is-on"' : '';
            $html .= '<a href="' . $e($this->url->to('news', $extra + ['category' => $c->id])) . '"'
                   . $on . '>' . $e($c->name) . '</a>';
        }

        return $html . '</nav>';
    }

    private function imageCaption(News $n): string
    {
        $caption = trim($n->imageCaption);
        $credit  = trim($n->imageCredit);
        if ($caption === '' && $credit === '') {
            return '';
        }
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $html = '<figcaption class="news-caption">';
        if ($caption !== '') {
            $html .= '<span>' . $e($caption) . '</span>';
        }
        if ($credit !== '') {
            $html .= '<cite>' . $e($credit) . '</cite>';
        }
        return $html . '</figcaption>';
    }

    private function tagLine(News $n): string
    {
        if ($n->tags === []) {
            return '';
        }
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $html = '<p class="news-tags">';
        foreach ($n->tags as $tag) {
            $html .= '<a href="' . $e($this->url->to('news', ['tag' => $tag->slug])) . '">'
                  . $e($tag->name) . '</a>';
        }
        return $html . '</p>';
    }

    /** @param list<News> $items */
    private function mostReadStrip(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $html = '<section class="card p-3 news-popular" aria-label="Most read">';
        $html .= '<h2>Most read</h2><ol>';
        $i = 1;
        foreach ($items as $n) {
            $html .= '<li><a href="' . $e($this->url->to('news/view', ['slug' => $n->slug])) . '">'
                  . '<span>' . $i . '</span>' . $e($n->title) . '</a></li>';
            $i++;
        }
        return $html . '</ol></section>';
    }

    private function isBot(): bool
    {
        $ua = strtolower((string) ($this->request->header('user-agent', '') ?? ''));
        if ($ua === '') {
            return false;
        }
        return (bool) preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegram/i', $ua);
    }

    private function articleCard(News $n, bool $lead): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $url = $this->url->to('news/view', ['slug' => $n->slug]);
        $image = $this->imageUrl($n->image);
        $hasMedia = $image !== '';

        $class = 'news-card';
        if ($lead) {
            $class .= ' is-lead';
        }
        if (!$hasMedia) {
            $class .= ' is-text';
        }

        $html = '<article class="' . $class . '"><a href="' . $e($url) . '">';
        if ($hasMedia) {
            $html .= '<div class="news-card__media"><img src="' . $e($image) . '" alt=""'
                   . ($lead ? '' : ' loading="lazy"') . '></div>';
        }
        $html .= '<div class="news-card__body">';
        $html .= $this->metaLine($n, true);
        $html .= '<h2>' . $e($n->title) . '</h2>';
        if ($lead && $n->excerpt !== '') {
            $html .= '<p class="news-card__excerpt">' . $e($n->excerpt) . '</p>';
        }
        $html .= '</div></a></article>';

        return $html;
    }

    private function metaLine(News $n, bool $relative = true, bool $detail = false): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $when = $this->formatWhen($n->publishedAt, $relative);
        $parts = [];

        if ($n->categoryName !== null && $n->categoryName !== '') {
            $parts[] = $e($n->categoryName);
        }
        if ($detail) {
            $author = $n->authorLabel();
            if ($author !== '') {
                $parts[] = $e($author);
            }
        }
        if ($when !== '') {
            $parts[] = $e($when);
        }
        if ($detail) {
            $mins = $n->readingMinutes();
            if ($mins > 0) {
                $parts[] = $e($mins . ' min read');
            }
        }

        return $parts === [] ? '' : '<p class="news-meta">' . implode(' · ', $parts) . '</p>';
    }

    private function sourceLine(News $n): string
    {
        $name = trim($n->source);
        $url  = trim($n->sourceUrl);
        if ($name === '' && $url === '') {
            return '';
        }

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $label = $name !== '' ? $name : 'Original article';

        $html = '<p class="news-source">Source: ';
        if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
            $html .= '<a href="' . $e($url) . '" rel="noopener noreferrer" target="_blank">'
                  . $e($label) . '</a>';
        } else {
            $html .= $e($label);
        }

        return $html . '</p>';
    }

    private function renderBody(string $body): string
    {
        $body = str_replace('../../../../', rtrim($this->request->baseUrl(), '/'), $body);
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        if (strip_tags($trimmed) === $trimmed) {
            $parts = preg_split('/\R{2,}/', $trimmed) ?: [];
            $html = '';
            foreach ($parts as $p) {
                $html .= '<p>' . nl2br($this->view->e($p), false) . '</p>';
            }
            return $html !== '' ? $html : '<p>' . nl2br($this->view->e($trimmed), false) . '</p>';
        }

        return self::safeHtml($body);
    }

    private static function safeHtml(string $html): string
    {
        if (is_callable([HtmlCleaner::class, 'sanitize'])) {
            return HtmlCleaner::sanitize($html);
        }
        return HtmlCleaner::clean($html);
    }

    private function imageUrl(string $image): string
    {
        $image = trim($image);
        if ($image === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $image) === 1) {
            return $image;
        }
        return rtrim($this->request->baseUrl(), '/') . '/' . ltrim($image, '/');
    }

    private function formatWhen(?string $at, bool $relative = false): string
    {
        if ($at === null || trim($at) === '') {
            return '';
        }
        $ts = strtotime($at);
        if ($ts === false) {
            return $at;
        }
        if (!$relative) {
            return date('j M Y', $ts);
        }

        $diff = time() - $ts;
        if ($diff < 3600) {
            return max(1, (int) floor($diff / 60)) . 'm';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . 'h';
        }
        if ($diff < 172800) {
            return 'Yesterday';
        }
        return date('j M', $ts);
    }

    private function postsPerPage(): int
    {
        $default = 12;
        $cfg = $this->newsModuleConfig();
        $n = (int) ($cfg['posts_per_page'] ?? $default);
        return max(1, min(100, $n > 0 ? $n : $default));
    }

    private function commentsEnabled(): bool
    {
        $cfg = $this->newsModuleConfig();
        $raw = $cfg['allow_comments'] ?? false;
        if (is_bool($raw)) {
            return $raw;
        }
        return in_array((string) $raw, ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<string, mixed> */
    private function newsModuleConfig(): array
    {
        try {
            $row = $this->db->first('modules', ['name' => 'news']);
            if ($row === [] || empty($row['config'])) {
                return [];
            }
            $cfg = json_decode((string) $row['config'], true);
            return is_array($cfg) ? $cfg : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
