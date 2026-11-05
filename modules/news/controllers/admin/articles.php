<?php
declare(strict_types=1);

use Module\News\Services\ArticleImporter;
use Module\News\Services\News;
use Module\News\Services\NewsCategoryRepository;
use Module\News\Services\NewsRepository;
use Module\News\Services\NewsRevision;
use Module\News\Services\NewsRevisionRepository;
use Module\News\Services\NewsTagRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\HtmlCleaner;
use System\Library\Paginator;
use System\Library\Dataset;

class NewsAdminArticles extends Controller
{
    private NewsRepository         $news;
    private NewsCategoryRepository $categories;
    private NewsRevisionRepository $revisions;
    private NewsTagRepository      $tags;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->news       = new NewsRepository($this->db);
        $this->categories = new NewsCategoryRepository($this->db);
        $this->revisions  = new NewsRevisionRepository($this->db);
        $this->tags       = new NewsTagRepository($this->db);
        $this->news->publishDue();
    }

    // --------------------------------------------------------------
    // List
    // --------------------------------------------------------------

    public function indexAction(): void
    {
        $categories = $this->categories->all();

        $categoryOptions = ['0' => 'All categories'];

        foreach ($categories as $category) {
            $categoryOptions[(string) $category->id] = $category->name;
        }

        $dataset = (new Dataset($this->registry))
            ->perPage(15)
            ->searchPlaceholder('Search title, slug or excerpt...')
            ->sortable([
                'title',
                'category_name',
                'status',
                'published_at',
                'created_at',
            ])
            ->defaultSort('created_at', 'desc')
            ->filter('status', 'select', [
                ''          => 'All statuses',
                'draft'     => 'Draft',
                'published' => 'Published',
                'scheduled' => 'Scheduled',
                'archived'  => 'Archived',
            ])
            ->filter('category', 'select', $categoryOptions)
            ->columns([
                'select' => '',
                'title' => 'Title',
                'category_name' => 'Category',
                'status' => 'Status',
                'published_at' => 'Published',
                'actions' => '',
            ])
            ->query(function ($db, array $state): array {
                $filters = [
                    'q' => $state['q'],
                    'status' => $state['filters']['status'] ?? '',
                    'category_id' => (int) ($state['filters']['category'] ?? 0),
                ];

                $items = $this->news->search(
                    $filters,
                    $state['per_page'],
                    $state['offset'],
                    $state['sort'],
                    $state['dir']
                );

                $total = $this->news->countSearch($filters);

                return [
                    'rows' => array_map(
                        static fn(News $item): array => ['_news' => $item],
                        $items
                    ),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $value): string =>
            $this->view->e((string) $value);

        $dataset->columns([
            'select' => function (array $row): string {
                $item = $row['_news'];

                return '<input form="news-bulk" type="checkbox" '
                    . 'class="form-check-input news-bulk-id" '
                    . 'name="ids[]" value="' . (int) $item->id . '" '
                    . 'autocomplete="off">';
            },

            'title' => function (array $row) use ($e): string {
                $item = $row['_news'];

                $html = '<a class="sf-title" href="'
                    . $e($this->url->to('news/admin/articles/edit', [
                        'id' => $item->id,
                    ]))
                    . '" title="' . $e($item->title) . '">'
                    . $e($item->title) . '</a>';

                if ($item->featured) {
                    $html .= ' <span class="sf-pill is-pin">Featured</span>';
                }

                $html .= '<span class="sf-sub">'
                    . $e($item->slug);

                if ($item->authorLabel() !== '') {
                    $html .= ' · ' . $e($item->authorLabel());
                }

                return $html . '</span>';
            },

            'category_name' => function (array $row) use ($e): string {
                return $e($row['_news']->categoryName ?? '—');
            },

            'status' => function (array $row): string {
                return $this->statusBadge($row['_news']);
            },

            'published_at' => function (array $row) use ($e): string {
                return $e($this->formatWhen(
                    $row['_news']->publishedAt ?? null
                ));
            },

            'actions' => function (array $row) use ($e): string {
                $item = $row['_news'];

                $html = '<div class="sf-row-acts">';

                if ($item->isPublished()) {
                    $url = $this->url->to('news/view', [
                        'slug' => $item->slug,
                    ]);
                    $label = 'View';
                } else {
                    $url = $this->previewUrl($item);
                    $label = 'Preview';
                }

                $html .= '<a href="' . $e($url) . '" class="sf-btn" '
                    . 'target="_blank" rel="noopener">'
                    . $label . '</a>';

                $html .= '<a href="' . $e($this->url->to(
                    'news/admin/articles/edit',
                    ['id' => $item->id]
                )) . '" class="sf-btn">Edit</a>';

                $html .= '<form method="post" action="'
                    . $e($this->url->to('news/admin/articles/delete'))
                    . '" onsubmit="return confirm(\'Delete this article?\');">';

                $html .= $this->request->csrfField();

                $html .= '<input type="hidden" name="id" value="'
                    . (int) $item->id . '">';

                $html .= '<button type="submit" class="sf-btn is-danger">'
                    . 'Delete</button></form>';

                return $html . '</div>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $categories, $e) {
            $this->view->assign('title', 'News articles');
            $this->view->block('right_sidebar', 'News');

            echo '<div class="sf-page">';

            echo '<div class="sf-head">';
            echo '<div><h1>Articles</h1>'
                . '<p>Create, publish and manage news articles.</p></div>';

            echo '<div class="sf-actions">';

            echo '<a href="' . $e($this->url->to('news'))
                . '" class="sf-btn" target="_blank" rel="noopener">'
                . '<i class="bi bi-box-arrow-up-right"></i>View site</a>';

            echo '<a href="' . $e($this->url->to('news/admin/articles/add'))
                . '" class="sf-btn is-primary">'
                . '<i class="bi bi-plus-lg"></i>New article</a>';

            echo '</div></div>';

            echo '<section class="sf-card">';

            echo '<form id="news-bulk" method="post" action="'
                . $e($this->url->to('news/admin/articles/bulk'))
                . '" class="sf-bulk" autocomplete="off">';

            echo $this->request->csrfField();

            echo '<label class="sf-bulk__all">'
                . '<input type="checkbox" class="form-check-input" '
                . 'id="news-bulk-all">Select all</label>';

            echo '<label class="sf-bulk__action">Action'
                . '<select name="action" id="news-bulk-action">';

            echo '<option value="">Choose action…</option>';
            echo '<option value="publish">Publish</option>';
            echo '<option value="draft">Move to draft</option>';
            echo '<option value="archive">Archive</option>';
            echo '<option value="category">Set category</option>';
            echo '<option value="delete">Delete</option>';

            echo '</select></label>';

            echo '<label class="sf-bulk__cat d-none" '
                . 'id="news-bulk-cat-wrap">Category<select name="category_id">';

            echo '<option value="0">— None —</option>';

            foreach ($categories as $category) {
                echo '<option value="' . (int) $category->id . '">'
                    . $e($category->name) . '</option>';
            }

            echo '</select></label>';

            echo '<button type="submit" class="sf-btn" '
                . 'id="news-bulk-apply" disabled>Apply</button>';

            echo '<span class="sf-bulk__count" '
                . 'id="news-bulk-count">0 selected</span>';

            echo '</form>';

            echo $dataset->render();

            echo $this->bulkScript();

            echo '</section></div>';
        });
    }

    // --------------------------------------------------------------
    // Add
    // --------------------------------------------------------------

    public function addAction(): void
    {
        $old = $this->emptyForm();

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $old = $this->readForm();

            try {
                if (empty($old['author_id'])) {
                    $old['author_id'] = $this->auth->id();
                }
                $item = $this->news->create($old);
                $this->syncTags($item, (string) ($old['tags'] ?? ''));
                $this->revisions->snapshot($item, $this->auth->id());
                $this->notify->success("Article '{$item->title}' created.");
                redirect_to('news/admin/articles');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old) {
            $this->view->assign('title', 'New article');
            $this->view->block('right_sidebar', 'News');
            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>New article</h1><p>Write and publish a news post.</p></div>';
            echo '<div class="sf-actions">'
               . '<a href="' . $this->view->e($this->url->to('news/admin/articles')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a></div>';
            echo '</div>';
            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->articleForm($old, $this->url->to('news/admin/articles/add'), 'Create');
            echo '</div></section></div>';
        });
    }

    // --------------------------------------------------------------
    // Edit
    // --------------------------------------------------------------

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $item = $this->news->find($id);
        if ($item === null) {
            throw new NotFoundException('Article not found.');
        }

        $old = $this->itemToForm($item);

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $old = $this->readForm();

            try {
                $item = $this->news->update($id, $old);
                $this->syncTags($item, (string) ($old['tags'] ?? ''));
                $this->revisions->snapshot($item, $this->auth->id());
                $this->notify->success('Article updated.');
                redirect_to('news/admin/articles');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old, $id, $item) {
            $this->view->assign('title', 'Edit article');
            $this->view->block('right_sidebar', 'News');
            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>Edit article</h1><p>' . $this->view->e((string) $old['title']) . '</p></div>';
            echo '<div class="sf-actions">';
            echo '<a href="' . $this->view->e($this->url->to('news/admin/articles')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a>';
            if ($item->isPublished()) {
                echo '<a class="sf-btn" target="_blank" rel="noopener" href="'
                   . $this->view->e($this->url->to('news/view', ['slug' => $item->slug])) . '">'
                   . '<i class="bi bi-box-arrow-up-right"></i>View on site</a>';
            }
            echo '<a class="sf-btn" target="_blank" rel="noopener" href="'
               . $this->view->e($this->previewUrl($item)) . '">'
               . '<i class="bi bi-eye"></i>Preview</a>';
            echo '</div></div>';
            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->articleForm($old, $this->url->to('news/admin/articles/edit', ['id' => $id]), 'Save', $item);
            echo '</div></section></div>';
        });
    }

    // --------------------------------------------------------------
    // Delete
    // --------------------------------------------------------------

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('news/admin/articles');
            return;
        }
        $this->guardCsrf();

        $id = (int) $this->request->post('id', 'int', 0);
        $item = $this->news->find($id);

        if ($item !== null) {
            $this->revisions->deleteForArticle($id);
            $this->news->delete($id);
            $this->notify->success("Article '{$item->title}' deleted.");
        } else {
            $this->notify->error('Article not found.');
        }

        redirect_to('news/admin/articles');
    }

    // --------------------------------------------------------------
    // Bulk
    // --------------------------------------------------------------

    public function bulkAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('news/admin/articles');
            return;
        }
        $this->guardCsrf();

        $ids = $this->postedIds();
        $action = trim((string) $this->request->post('action', 'string', ''));

        if ($ids === []) {
            $this->notify->error('Select at least one article.');
            redirect_to('news/admin/articles');
            return;
        }

        try {
            $n = match ($action) {
                'publish'  => $this->news->bulkSetStatus($ids, 'published'),
                'draft'    => $this->news->bulkSetStatus($ids, 'draft'),
                'archive'  => $this->news->bulkSetStatus($ids, 'archived'),
                'category' => $this->news->bulkSetCategory(
                    $ids,
                    (int) $this->request->post('category_id', 'int', 0)
                ),
                'delete'   => $this->bulkDeleteArticles($ids),
                default    => 0,
            };
        } catch (\Throwable $e) {
            $this->notify->error($e->getMessage());
            redirect_to('news/admin/articles');
            return;
        }

        if ($action === '' || !in_array($action, ['publish', 'draft', 'archive', 'category', 'delete'], true)) {
            $this->notify->error('Choose a bulk action.');
        } elseif ($n === 0) {
            $this->notify->error('No articles were updated.');
        } else {
            $label = match ($action) {
                'publish'  => 'published',
                'draft'    => 'moved to draft',
                'archive'  => 'archived',
                'category' => 'updated',
                default    => 'deleted',
            };
            $this->notify->success($n . ' article' . ($n === 1 ? '' : 's') . ' ' . $label . '.');
        }

        redirect_to('news/admin/articles');
    }

    // --------------------------------------------------------------
    // Import from URL
    // --------------------------------------------------------------

    public function importAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->request->isPost()) {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'POST required.']);
            return;
        }

        try {
            $this->request->checkToken();
            $this->request->enforceRateLimit('news.import', 12, 60);

            $url = trim((string) $this->request->post('url', 'string', ''));
            $article = (new ArticleImporter())->import($url);
            echo json_encode(['ok' => true, 'article' => $article], JSON_UNESCAPED_UNICODE);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            http_response_code($code === 419 ? 403 : 400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    // --------------------------------------------------------------
    // Autosave
    // --------------------------------------------------------------

    public function autosaveAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->request->isPost()) {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'POST required.']);
            return;
        }

        try {
            $this->request->checkToken();
            $this->request->enforceRateLimit('news.autosave', 40, 60);

            if (!$this->revisions->enabled()) {
                echo json_encode(['ok' => false, 'message' => 'Run migrate:module news to enable autosave.']);
                return;
            }

            $data = $this->readAutosaveForm();
            $id   = (int) $this->request->post('id', 'int', 0);
            $item = $id > 0 ? $this->news->find($id) : null;

            if ($item === null) {
                if (trim($data['title']) === '' && trim(strip_tags($data['body'])) === '') {
                    echo json_encode(['ok' => true, 'skipped' => true]);
                    return;
                }
                if (trim($data['title']) === '') {
                    $data['title'] = 'Untitled draft ' . date('j M H:i');
                }
                $data['status'] = 'draft';
                $data['slug']   = '';
                if (empty($data['author_id'])) {
                    $data['author_id'] = $this->auth->id();
                }
                $item = $this->news->create($data);
            } elseif ($item->status === 'draft') {
                $this->news->update((int) $item->id, [
                    'title'      => $data['title'] !== '' ? $data['title'] : $item->title,
                    'excerpt'    => $data['excerpt'],
                    'body'       => $data['body'],
                    'image'      => $data['image'],
                    'source'     => $data['source'],
                    'source_url' => $data['source_url'] !== '' ? $data['source_url'] : $item->sourceUrl,
                ]);
                $item = $this->news->find((int) $item->id) ?? $item;
            } else {
                $item->title   = $data['title'] !== '' ? $data['title'] : $item->title;
                $item->excerpt = $data['excerpt'];
                $item->body    = $data['body'];
            }

            $rev = $this->revisions->snapshot($item, $this->auth->id());
            echo json_encode([
                'ok'          => true,
                'article_id'  => (int) $item->id,
                'edit_url'    => $this->url->to('news/admin/articles/edit', ['id' => $item->id]),
                'revision_id' => $rev?->id,
                'saved_at'    => date('H:i:s'),
                'live'        => $item->status === 'draft',
            ], JSON_UNESCAPED_UNICODE);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            http_response_code($code === 419 ? 403 : 400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    public function revisionAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $id = (int) $this->request->get('id', 'int', 0);
        $rev = $this->revisions->find($id);
        if ($rev === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Revision not found.']);
            return;
        }

        echo json_encode([
            'ok'      => true,
            'revision'=> [
                'id'      => $rev->id,
                'news_id' => $rev->newsId,
                'title'   => $rev->title,
                'excerpt' => $rev->excerpt,
                'body'    => $rev->body,
                'created' => $rev->createdAt,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    // --------------------------------------------------------------
    // Helpers
    // --------------------------------------------------------------

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('news/admin/articles');
            exit;
        }
    }

    private function emptyForm(): array
    {
        return [
            'category_id'  => 0,
            'slug'         => '',
            'title'        => '',
            'excerpt'      => '',
            'body'         => '',
            'image'        => '',
            'image_caption'=> '',
            'image_credit' => '',
            'tags'         => '',
            'source'       => '',
            'source_url'   => '',
            'status'       => 'draft',
            'featured'     => false,
            'author_id'    => (int) ($this->auth->id() ?? 0),
            'published_at' => '',
            'clean_html'   => true,
        ];
    }

    private function itemToForm(News $item): array
    {
        return [
            'category_id'  => $item->categoryId ?? 0,
            'slug'         => $item->slug,
            'title'        => $item->title,
            'excerpt'      => $item->excerpt,
            'body'         => $item->body,
            'image'        => $item->image,
            'image_caption'=> $item->imageCaption,
            'image_credit' => $item->imageCredit,
            'tags'         => implode(', ', array_map(static fn($t) => $t->name, $this->tags->forArticle((int) ($item->id ?? 0)))),
            'source'       => $item->source,
            'source_url'   => $item->sourceUrl,
            'status'       => $item->status,
            'featured'     => $item->featured,
            'author_id'    => $item->authorId ?? 0,
            'published_at' => $item->publishedAt ?? '',
            'clean_html'   => true,
        ];
    }

    private function readForm(): array
    {
        $data = [
            'category_id'  => (int) $this->request->post('category_id', 'int', 0),
            'slug'         => trim((string) $this->request->post('slug', 'string', '')),
            'title'        => trim((string) $this->request->post('title', 'string', '')),
            'excerpt'      => trim((string) $this->request->post('excerpt', 'string', '')),
            'body'         => (string) $this->request->post('body', 'raw', ''),
            'image'        => trim((string) $this->request->post('image', 'string', '')),
            'image_caption'=> trim((string) $this->request->post('image_caption', 'string', '')),
            'image_credit' => trim((string) $this->request->post('image_credit', 'string', '')),
            'tags'         => trim((string) $this->request->post('tags', 'string', '')),
            'source'       => trim((string) $this->request->post('source', 'string', '')),
            'source_url'   => trim((string) $this->request->post('source_url', 'string', '')),
            'status'       => (string) $this->request->post('status', 'string', 'draft'),
            'featured'     => (int) $this->request->post('featured', 'int', 0) === 1,
            'author_id'    => (int) $this->request->post('author_id', 'int', 0),
            'published_at' => trim((string) $this->request->post('published_at', 'string', '')),
            'clean_html'   => (int) $this->request->post('clean_html', 'int', 0) === 1,
        ];
        if (!empty($data['clean_html'])) {
            $data['body'] = HtmlCleaner::clean($data['body']);
        } elseif (is_callable([HtmlCleaner::class, 'sanitize'])) {
            $data['body'] = HtmlCleaner::sanitize($data['body']);
        } else {
            $data['body'] = HtmlCleaner::clean($data['body']);
        }
        return $data;
    }

    private function readAutosaveForm(): array
    {
        $body = (string) $this->request->post('body', 'raw', '');
        if (is_callable([HtmlCleaner::class, 'sanitize'])) {
            $body = HtmlCleaner::sanitize($body);
        }
        $sourceUrl = trim((string) $this->request->post('source_url', 'string', ''));
        if ($sourceUrl !== '' && !preg_match('#^https?://#i', $sourceUrl)) {
            $sourceUrl = '';
        }
        return [
            'title'      => trim((string) $this->request->post('title', 'string', '')),
            'excerpt'    => trim((string) $this->request->post('excerpt', 'string', '')),
            'body'       => $body,
            'image'      => trim((string) $this->request->post('image', 'string', '')),
            'source'     => trim((string) $this->request->post('source', 'string', '')),
            'source_url' => $sourceUrl,
            'author_id'  => (int) $this->request->post('author_id', 'int', 0),
        ];
    }

    private function bulkDeleteArticles(array $ids): int
    {
        $this->revisions->deleteForArticles($ids);
        return $this->news->bulkDelete($ids);
    }

    /** @param list<NewsRevision> $revisions */
    private function articleForm(array $old, string $action, string $submitLabel, ?News $item = null): string
    {
        $categories = $this->categories->all();

        $categoryOptions = ['0' => '— None —'];
        foreach ($categories as $c) {
            $categoryOptions[(string) $c->id] = $c->name;
        }

        $statusOptions = [
            'draft'     => 'Draft',
            'published' => 'Published',
            'archived'  => 'Archived',
        ];

        $publishedLocal = '';
        if (!empty($old['published_at'])) {
            $ts = strtotime((string) $old['published_at']);
            if ($ts !== false) {
                $publishedLocal = date('Y-m-d\TH:i', $ts);
            }
        }

        $pickerUrl = $this->url->to('tools/admin/media/picker');
        $imageVal  = (string) $old['image'];
        $preview   = $imageVal !== ''
            ? '<img src="' . $this->view->e($imageVal) . '" alt="" class="img-thumbnail mt-2"'
              . ' style="max-height:120px;" id="news-image-preview">'
            : '<img src="" alt="" class="img-thumbnail mt-2 d-none" style="max-height:120px;" id="news-image-preview">';

        $importUrl = $this->url->to('news/admin/articles/import');
        $articleId = (int) ($item->id ?? 0);
        $revisions = $articleId > 0 ? $this->revisions->forArticle($articleId) : [];

        $form  = '<div class="mb-4 p-3 border rounded bg-light">';
        $form .= '<label class="form-label" for="news-import-url">Import from URL</label>';
        $form .= '<div class="input-group">';
        $form .= '<input type="url" class="form-control" id="news-import-url"'
            . ' placeholder="https://example.com/article" autocomplete="off">';
        $form .= '<button type="button" class="btn btn-outline-primary" id="news-import-btn">'
            . '<i class="bi bi-download"></i> Fetch article</button>';
        $form .= '</div>';
        $form .= '<div class="form-text">Pulls title, body, image and source. Review and clean before you save.</div>';
        $form .= '<p class="small mb-0 mt-2 text-muted" id="news-import-status"></p>';
        $form .= '</div>';

        $form .= $this->form->open(['action' => $action, 'method' => 'POST']);
        $form .= '<input type="hidden" name="id" id="news-article-id" value="' . $articleId . '">';

        $form .= $this->form->row(['fields' => [
            [
                'type'     => 'text',
                'name'     => 'title',
                'label'    => 'Title',
                'value'    => (string) $old['title'],
                'required' => true,
                'col'      => 'col-md-8',
            ],
            [
                'type'  => 'text',
                'name'  => 'slug',
                'label' => 'Slug',
                'value' => (string) $old['slug'],
                'help'  => 'Leave blank to auto-generate from title.',
                'col'   => 'col-md-4',
            ],
        ]]);

        $form .= $this->form->field([
            'type'    => 'select',
            'name'    => 'category_id',
            'label'   => 'Category',
            'options' => $categoryOptions,
            'value'   => (string) ((int) $old['category_id']),
        ]);

        $form .= $this->form->field([
            'type'  => 'text',
            'name'  => 'excerpt',
            'label' => 'Excerpt',
            'value' => (string) $old['excerpt'],
            'help'  => 'Short summary for list pages (max 500 chars).',
        ]);

        $form .= $this->form->row(['fields' => [
            [
                'type'  => 'text',
                'name'  => 'source',
                'label' => 'Source',
                'value' => (string) ($old['source'] ?? ''),
                'help'  => 'Site or outlet name, e.g. Hiiraan Online.',
                'col'   => 'col-md-5',
            ],
            [
                'type'  => 'url',
                'name'  => 'source_url',
                'label' => 'Source URL',
                'value' => (string) ($old['source_url'] ?? ''),
                'help'  => 'Original article link.',
                'col'   => 'col-md-7',
            ],
        ]]);

        $form .= $this->form->field([
            'type'   => 'textarea',
            'name'   => 'body',
            'label'  => 'Body',
            'value'  => (string) $old['body'],
            'editor' => true,
            'rows'   => 14,
        ]);

        $form .= $this->form->field([
            'type'    => 'checkbox',
            'name'    => 'clean_html',
            'label'   => 'Clean copied HTML',
            'checked' => !empty($old['clean_html']),
            'help'    => 'Strip ads, wrapper divs, and tracking markup when you paste or save.',
        ]);
        $form .= '<p class="mb-3"><button type="button" class="sf-btn" id="news-clean-now">'
            . '<i class="bi bi-eraser"></i> Clean body now</button></p>';

        $form .= '<div class="mb-3">'
            . '<label class="form-label" for="field-image">Featured image</label>'
            . '<div class="input-group">'
            . '<input type="text" class="form-control" name="image" id="field-image"'
            . ' value="' . $this->view->e($imageVal) . '" placeholder="/uploads/media/…">'
            . '<button type="button" class="btn btn-outline-secondary" id="news-browse-media">Browse media</button>'
            . '</div>'
            . '<div class="form-text">Pick from the media library or paste a URL.</div>'
            . $preview
            . '</div>';

        $form .= $this->form->row(['fields' => [
            [
                'type'  => 'text',
                'name'  => 'image_caption',
                'label' => 'Image caption',
                'value' => (string) ($old['image_caption'] ?? ''),
                'help'  => 'Shown under the photo on the article page.',
                'col'   => 'col-md-7',
            ],
            [
                'type'  => 'text',
                'name'  => 'image_credit',
                'label' => 'Image credit',
                'value' => (string) ($old['image_credit'] ?? ''),
                'help'  => 'Agency or photographer, e.g. Reuters.',
                'col'   => 'col-md-5',
            ],
        ]]);

        $form .= $this->form->field([
            'type'  => 'text',
            'name'  => 'tags',
            'label' => 'Tags',
            'value' => (string) ($old['tags'] ?? ''),
            'help'  => 'Comma-separated, e.g. Politics, War, Hargeisa.',
        ]);

        $form .= $this->form->row(['fields' => [
            [
                'type'    => 'select',
                'name'    => 'status',
                'label'   => 'Status',
                'options' => $statusOptions,
                'value'   => (string) $old['status'],
                'col'     => 'col-md-6',
            ],
            [
                'type'  => 'datetime-local',
                'name'  => 'published_at',
                'label' => 'Publish at',
                'value' => $publishedLocal,
                'help'  => 'Set a future time and status = Published to schedule. Drafts with a future time go live automatically.',
                'col'   => 'col-md-6',
            ],
        ]]);

        $form .= $this->form->field([
            'type'    => 'checkbox',
            'name'    => 'featured',
            'label'   => 'Pin on homepage',
            'checked' => !empty($old['featured']),
            'help'    => 'Shows this story as the homepage hero. Only one article can be pinned.',
        ]);

        $authorOptions = $this->authorOptions();
        if ($authorOptions !== []) {
            $form .= $this->form->field([
                'type'    => 'select',
                'name'    => 'author_id',
                'label'   => 'Author',
                'options' => $authorOptions,
                'value'   => (string) ((int) ($old['author_id'] ?? 0)),
                'help'    => 'Shown as the byline on the public article.',
            ]);
        }

        $form .= $this->revisionPanel($revisions);

        $form .= '<div class="sf-actions mt-4">'
            . '<button type="submit" class="sf-btn is-primary">'
            . $this->view->e($submitLabel)
            . '</button>'
            . '<a href="' . $this->view->e($this->url->to('news/admin/articles')) . '"'
            . ' class="sf-btn">Cancel</a>'
            . '<span class="news-autosave-status" id="news-autosave-status">Autosave is on</span></div>';

        $form .= $this->form->close();

        $pickerJs = $this->view->e($pickerUrl);
        $importJs = $this->view->e($importUrl);
        $autosaveJs = $this->view->e($this->url->to('news/admin/articles/autosave'));
        $revisionJs = $this->view->e($this->url->to('news/admin/articles/revision'));
        $form .= <<<HTML
<script>
(function () {
    var input = document.getElementById('field-image');
    var preview = document.getElementById('news-image-preview');
    var btn = document.getElementById('news-browse-media');
    if (!input || !btn) return;

    input.addEventListener('input', function () {
        if (!preview) return;
        if (input.value) {
            preview.src = input.value;
            preview.classList.remove('d-none');
        } else {
            preview.classList.add('d-none');
        }
    });

    btn.addEventListener('click', function () {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1080;display:flex;align-items:center;justify-content:center;padding:1rem;';
        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;border-radius:8px;max-width:720px;width:100%;max-height:80vh;overflow:auto;padding:1rem;';
        box.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-2"><strong>Select image</strong><button type="button" class="btn btn-sm btn-outline-secondary" id="news-media-close">Close</button></div><div id="news-media-list" class="text-muted">Loading…</div>';
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        function close() { overlay.remove(); }
        box.querySelector('#news-media-close').addEventListener('click', close);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

        fetch('{$pickerJs}?per_page=36&mime=image', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var list = box.querySelector('#news-media-list');
                if (!data.items || !data.items.length) {
                    list.innerHTML = '<div class="alert alert-info mb-0">No images in the media library.</div>';
                    return;
                }
                var html = '<div class="row g-2">';
                data.items.forEach(function (item) {
                    html += '<div class="col-4 col-md-3"><button type="button" class="btn p-0 border w-100 news-pick-img" data-url="'
                        + String(item.url).replace(/"/g, '&quot;') + '">'
                        + '<img src="' + String(item.url).replace(/"/g, '&quot;') + '" alt="" style="height:90px;width:100%;object-fit:cover;display:block;">'
                        + '</button></div>';
                });
                html += '</div>';
                list.innerHTML = html;
                list.querySelectorAll('.news-pick-img').forEach(function (b) {
                    b.addEventListener('click', function () {
                        input.value = b.dataset.url;
                        input.dispatchEvent(new Event('input'));
                        close();
                    });
                });
            })
            .catch(function () {
                box.querySelector('#news-media-list').innerHTML = '<div class="alert alert-danger mb-0">Could not load media.</div>';
            });
    });
})();
(function () {
    var DROP = 'script,style,noscript,iframe,object,embed,form,button,input,select,textarea,svg,canvas,video,audio,template,dialog';
    var UNWRAP = 'div,span,section,article,header,footer,main,aside,font,center,picture';
    var ALLOW = {p:1,br:1,strong:1,b:1,em:1,i:1,u:1,s:1,a:1,img:1,h1:1,h2:1,h3:1,h4:1,ul:1,ol:1,li:1,blockquote:1,figcaption:1};
    var AD = /(?:^|[\s_-])(?:ads?(?:unit|slot|wrapper|banner)?|advert(?:isement|orial)?|adsbygoogle|sponsor(?:ed)?|promo(?:ted)?|taboola|outbrain|newsletter|cookie|popup|modal)(?:$|[\s_-])|digital[-_]?wrapper|xsouk/i;

    function looksLikeAd(el) {
        var bits = [el.id, el.className];
        for (var i = 0; i < el.attributes.length; i++) {
            bits.push(el.attributes[i].name, el.attributes[i].value);
        }
        return AD.test(bits.join(' '));
    }

    function unwrap(el) {
        var parent = el.parentNode;
        if (!parent) return;
        while (el.firstChild) parent.insertBefore(el.firstChild, el);
        parent.removeChild(el);
    }

    function cleanHtml(html) {
        var box = document.createElement('div');
        box.innerHTML = html;
        box.querySelectorAll(DROP).forEach(function (n) { n.remove(); });
        Array.from(box.querySelectorAll('*')).reverse().forEach(function (el) {
            if (looksLikeAd(el)) el.remove();
        });
        var changed = true;
        while (changed) {
            changed = false;
            box.querySelectorAll(UNWRAP).forEach(function (el) {
                unwrap(el);
                changed = true;
            });
        }
        Array.from(box.querySelectorAll('*')).forEach(function (el) {
            var tag = el.tagName.toLowerCase();
            if (!ALLOW[tag]) {
                unwrap(el);
                return;
            }
            var keep = tag === 'a' ? ['href'] : (tag === 'img' ? ['src', 'alt'] : []);
            Array.from(el.attributes).forEach(function (attr) {
                if (keep.indexOf(attr.name.toLowerCase()) === -1) el.removeAttribute(attr.name);
            });
            if (tag === 'a' && /^\s*javascript:/i.test(el.getAttribute('href') || '')) unwrap(el);
            if (tag === 'img' && (/^\s*(javascript|data):/i.test(el.getAttribute('src') || '') || !(el.getAttribute('src') || '').trim())) el.remove();
        });
        box.querySelectorAll('p').forEach(function (p) {
            if (!p.textContent.replace(/\u00a0/g, ' ').trim() && !p.querySelector('img')) p.remove();
        });
        return box.innerHTML;
    }

    function editor() {
        return window.tinymce ? tinymce.get('field-body') : null;
    }

    function shouldClean() {
        var box = document.getElementById('field-clean_html');
        return !box || box.checked;
    }

    function hookPaste() {
        var ed = editor();
        if (!ed) {
            setTimeout(hookPaste, 200);
            return;
        }
        ed.on('PastePreProcess', function (e) {
            if (shouldClean()) e.content = cleanHtml(e.content);
        });
    }

    var now = document.getElementById('news-clean-now');
    if (now) {
        now.addEventListener('click', function () {
            var ed = editor();
            if (ed) {
                ed.setContent(cleanHtml(ed.getContent()));
                return;
            }
            var ta = document.getElementById('field-body');
            if (ta) ta.value = cleanHtml(ta.value);
        });
    }
    hookPaste();
})();
(function () {
    var btn = document.getElementById('news-import-btn');
    var input = document.getElementById('news-import-url');
    var status = document.getElementById('news-import-status');
    if (!btn || !input) return;

    function setStatus(text, isError) {
        if (!status) return;
        status.textContent = text;
        status.classList.toggle('text-danger', !!isError);
        status.classList.toggle('text-muted', !isError);
    }

    function fill(id, value) {
        var el = document.getElementById(id);
        if (!el || value == null || value === '') return;
        el.value = value;
        el.dispatchEvent(new Event('input'));
    }

    btn.addEventListener('click', function () {
        var url = (input.value || '').trim();
        if (!url) {
            setStatus('Paste an article URL first.', true);
            return;
        }
        btn.disabled = true;
        setStatus('Fetching…');

        var fd = new FormData();
        fd.append('url', url);

        fetch('{$importJs}', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            if (!res.body || !res.body.ok) {
                setStatus((res.body && res.body.message) ? res.body.message : 'Import failed.', true);
                return;
            }
            var a = res.body.article || {};
            fill('field-title', a.title);
            fill('field-excerpt', a.excerpt);
            fill('field-source', a.source);
            fill('field-source_url', a.source_url);
            fill('field-image', a.image);
            var ed = window.tinymce ? tinymce.get('field-body') : null;
            if (ed && a.body) {
                ed.setContent(a.body);
            } else if (a.body) {
                fill('field-body', a.body);
            }
            var clean = document.getElementById('field-clean_html');
            if (clean) clean.checked = true;
            setStatus('Imported. Review the text, then save.');
        })
        .catch(function () {
            setStatus('Network error during import.', true);
        })
        .finally(function () {
            btn.disabled = false;
        });
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            btn.click();
        }
    });
})();
(function () {
    var idInput = document.getElementById('news-article-id');
    var status = document.getElementById('news-autosave-status');
    var form = idInput ? idInput.closest('form') : null;
    if (!form) return;

    var last = '';
    var saving = false;
    var autosaveUrl = '{$autosaveJs}';
    var revisionUrl = '{$revisionJs}';

    function field(id) {
        var el = document.getElementById(id);
        return el ? el.value : '';
    }
    function body() {
        var ed = window.tinymce ? tinymce.get('field-body') : null;
        if (ed) return ed.getContent();
        var ta = document.getElementById('field-body');
        return ta ? ta.value : '';
    }
    function payload() {
        return {
            id: idInput.value || '0',
            title: field('field-title'),
            excerpt: field('field-excerpt'),
            body: body(),
            image: field('field-image'),
            source: field('field-source'),
            source_url: field('field-source_url'),
            author_id: field('field-author_id')
        };
    }
    function setStatus(text, isError) {
        if (!status) return;
        status.textContent = text;
        status.classList.toggle('is-error', !!isError);
    }
    function fingerprint(data) {
        return data.title + '\\n' + data.excerpt + '\\n' + data.body;
    }
    function save() {
        var data = payload();
        var fp = fingerprint(data);
        if (fp === last) return;
        if (!data.title.trim() && data.body.replace(/<[^>]+>/g, '').trim().length < 20) return;
        if (saving) return;
        saving = true;
        setStatus('Saving…');

        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });

        fetch(autosaveUrl, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                saving = false;
                if (!res || !res.ok) {
                    setStatus((res && res.message) ? res.message : 'Autosave failed', true);
                    return;
                }
                if (res.skipped) {
                    setStatus('Autosave is on');
                    return;
                }
                last = fp;
                if (res.article_id) {
                    idInput.value = String(res.article_id);
                    if (res.edit_url) {
                        form.setAttribute('action', res.edit_url);
                        if (window.history && history.replaceState) {
                            history.replaceState({}, '', res.edit_url);
                        }
                    }
                }
                setStatus(res.live ? 'Draft saved ' + res.saved_at : 'Snapshot saved ' + res.saved_at + ' (not live)');
            })
            .catch(function () {
                saving = false;
                setStatus('Autosave failed', true);
            });
    }

    setInterval(save, 20000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') save();
    });
    window.addEventListener('beforeunload', function () { save(); });
    setTimeout(save, 8000);

    document.querySelectorAll('.news-rev-restore').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            if (!id || !window.confirm('Replace the editor with this snapshot? Unsaved typing will be lost.')) return;
            fetch(revisionUrl + '?id=' + encodeURIComponent(id), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok || !res.revision) {
                        setStatus('Could not load snapshot', true);
                        return;
                    }
                    var rev = res.revision;
                    var title = document.getElementById('field-title');
                    var excerpt = document.getElementById('field-excerpt');
                    if (title) title.value = rev.title || '';
                    if (excerpt) excerpt.value = rev.excerpt || '';
                    var ed = window.tinymce ? tinymce.get('field-body') : null;
                    if (ed) ed.setContent(rev.body || '');
                    else {
                        var ta = document.getElementById('field-body');
                        if (ta) ta.value = rev.body || '';
                    }
                    last = '';
                    setStatus('Snapshot loaded — Save to make it live');
                })
                .catch(function () { setStatus('Could not load snapshot', true); });
        });
    });
})();
</script>
HTML;

        return $form;
    }

    /** @param list<NewsRevision> $revisions */
    private function revisionPanel(array $revisions): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $html = '<div class="news-revisions">';
        $html .= '<h3>Revisions</h3>';
        if ($revisions === []) {
            $html .= '<p class="news-revisions__empty">Snapshots appear here as you type. A crashed editor can be restored from this list.</p>';
            return $html . '</div>';
        }
        $html .= '<ul>';
        foreach ($revisions as $rev) {
            $when = $rev->createdAt ? date('j M H:i', strtotime((string) $rev->createdAt) ?: time()) : '';
            $who  = $rev->authorName !== null && $rev->authorName !== '' ? $rev->authorName : '';
            $html .= '<li><span>' . $e($when)
                  . ($who !== '' ? ' · ' . $e($who) : '')
                  . ' — ' . $e($rev->summary())
                  . '</span><button type="button" class="sf-btn news-rev-restore" data-id="'
                  . (int) $rev->id . '">Restore</button></li>';
        }
        return $html . '</ul></div>';
    }

    /** @return list<int> */
    private function postedIds(): array
    {
        $raw = $this->request->post('ids', 'raw', []);
        if (!is_array($raw)) {
            $raw = $raw === null || $raw === '' ? [] : [$raw];
        }
        $ids = [];
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /** @return array<string, string> */
    private function authorOptions(): array
    {
        try {
            $rows = $this->db->query(
                "SELECT `id`, `display_name`, `username`
                   FROM `users`
                  WHERE `status` = 'active'
                  ORDER BY `display_name`, `username`"
            )->rows;
        } catch (\Throwable) {
            return [];
        }

        $out = ['0' => '— Unassigned —'];
        foreach ($rows as $r) {
            $label = trim((string) ($r['display_name'] ?? ''));
            if ($label === '') {
                $label = (string) ($r['username'] ?? '');
            }
            if ($label === '') {
                $label = 'User #' . (int) $r['id'];
            }
            $out[(string) ((int) $r['id'])] = $label;
        }
        return $out;
    }

    /**
     * Always-visible list footer. Paginator::render() is empty on a single page,
     * which made the admin articles screen look like it had no paging at all.
     *
     * @param array<string, mixed> $query
     */
    private function listPager(Paginator $pager, array $query): string
    {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $html = '<div class="sf-pager-bar">';
        $html .= '<span class="sf-pager-bar__sum">Showing ' . $e($pager->summary()) . '</span>';
        $html .= $pager->render(
            fn (int $p): string => $this->url->to('news/admin/articles', $query + ['page' => $p]),
            ['theme' => 'bootstrap-sm', 'class' => 'mb-0']
        );

        return $html . '</div>';
    }

    private function bulkScript(): string
    {
        return <<<'HTML'
<script>
(function () {
    var form = document.getElementById('news-bulk');
    var all = document.getElementById('news-bulk-all');
    var boxes = document.querySelectorAll('.news-bulk-id');
    var action = document.getElementById('news-bulk-action');
    var catWrap = document.getElementById('news-bulk-cat-wrap');
    var apply = document.getElementById('news-bulk-apply');
    var count = document.getElementById('news-bulk-count');
    if (!form || !action || !apply) return;

    Array.prototype.forEach.call(boxes, function (b) { b.checked = false; });
    if (all) all.checked = false;
    action.value = '';

    function selected() {
        return Array.prototype.filter.call(boxes, function (b) { return b.checked; });
    }
    function sync() {
        var n = selected().length;
        if (count) count.textContent = n + ' selected';
        apply.disabled = n === 0 || !action.value;
        if (all) {
            all.checked = boxes.length > 0 && n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
        }
        if (catWrap) catWrap.classList.toggle('d-none', action.value !== 'category');
    }
    if (all) {
        all.addEventListener('change', function () {
            Array.prototype.forEach.call(boxes, function (b) { b.checked = all.checked; });
            sync();
        });
    }
    Array.prototype.forEach.call(boxes, function (b) { b.addEventListener('change', sync); });
    action.addEventListener('change', sync);
    form.addEventListener('submit', function (e) {
        if (selected().length === 0 || !action.value) {
            e.preventDefault();
            return;
        }
        if (action.value === 'delete' && !window.confirm('Delete the selected articles?')) {
            e.preventDefault();
        }
    });
    sync();
})();
</script>
HTML;
    }

    private function previewUrl(News $item): string
    {
        $token = $this->news->ensurePreviewToken($item);
        return $this->url->to('news/preview', [
            'slug'  => $item->slug,
            'token' => $token,
        ]);
    }

    private function formatWhen(?string $at): string
    {
        if ($at === null || trim($at) === '') {
            return '—';
        }
        $ts = strtotime($at);
        return $ts === false ? $at : date('d M H:i', $ts);
    }

    private function syncTags(News $item, string $raw): void
    {
        if ($item->id === null || !$this->tags->enabled()) {
            return;
        }
        $this->tags->sync((int) $item->id, $this->tags->parseNames($raw));
    }

    private function statusBadge(News $n): string
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
