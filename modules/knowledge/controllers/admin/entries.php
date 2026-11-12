<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use Module\Knowledge\Services\DomainRepository;
use Module\Knowledge\Services\Entry;
use Module\Knowledge\Services\EntryRepository;
use Module\Knowledge\Services\PollRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class KnowledgeAdminEntries extends Controller
{
    private EntryRepository $entries;
    private DomainRepository $domains;
    private PollRepository $polls;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->entries = new EntryRepository($this->db);
        $this->domains = new DomainRepository($this->db);
        $this->polls = new PollRepository($this->db);
    }

    public function indexAction(): void
    {
        $domains = $this->domains->all();
        $domainOptions = ['0' => 'All domains'];
        foreach ($domains as $domain) {
            $domainOptions[(string) $domain->id] = $domain->name;
        }

        $dataset = (new Dataset($this->registry))
            ->perPage(15)
            ->searchPlaceholder('Search title, slug or summary…')
            ->sortable(['title', 'domain_name', 'status', 'created_at', 'updated_at'])
            ->defaultSort('created_at', 'desc')
            ->filter('status', 'select', [
                ''          => 'All statuses',
                'draft'     => 'Draft',
                'published' => 'Published',
                'archived'  => 'Archived',
            ])
            ->filter('domain', 'select', $domainOptions)
            ->columns([
                'title'       => 'Title',
                'domain_name' => 'Domain',
                'status'      => 'Status',
                'updated_at'  => 'Updated',
                'actions'     => '',
            ])
            ->query(function ($db, array $state): array {
                $filters = [
                    'q'         => $state['q'],
                    'status'    => $state['filters']['status'] ?? '',
                    'domain_id' => (int) ($state['filters']['domain'] ?? 0),
                ];
                $items = $this->entries->search(
                    $filters,
                    $state['per_page'],
                    $state['offset'],
                    $state['sort'],
                    $state['dir']
                );
                $total = $this->entries->countSearch($filters);

                return [
                    'rows'  => array_map(static fn(Entry $item): array => ['_entry' => $item], $items),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $value): string => $this->view->e((string) $value);

        $dataset->columns([
            'title' => function (array $row) use ($e): string {
                $item = $row['_entry'];
                $title = $item->title !== '' ? $item->title : $item->slug;
                return '<a class="sf-title" href="'
                    . $e($this->url->to('knowledge/admin/entries/edit', ['id' => $item->id]))
                    . '" title="' . $e($title) . '">' . $e($title) . '</a>'
                    . '<span class="sf-sub">' . $e($item->slug) . '</span>';
            },
            'domain_name' => function (array $row) use ($e): string {
                return $e($row['_entry']->domainName ?? '—');
            },
            'status' => function (array $row): string {
                return match ($row['_entry']->status) {
                    'published' => '<span class="sf-pill is-ok">Published</span>',
                    'archived'  => '<span class="sf-pill is-off">Archived</span>',
                    default     => '<span class="sf-pill is-wait">Draft</span>',
                };
            },
            'updated_at' => function (array $row) use ($e): string {
                $at = $row['_entry']->updatedAt ?? null;
                if ($at === null || trim((string) $at) === '') {
                    return '—';
                }
                $ts = strtotime((string) $at);
                return $e($ts === false ? (string) $at : date('d M H:i', $ts));
            },
            'actions' => function (array $row) use ($e): string {
                $item = $row['_entry'];
                $html = '<div class="sf-row-acts">';
                if ($item->isPublished()) {
                    $html .= '<a href="' . $e($this->url->to('knowledge/view', ['slug' => $item->slug]))
                        . '" class="sf-btn" target="_blank" rel="noopener">View</a>';
                }
                $html .= '<a href="' . $e($this->url->to('knowledge/admin/entries/edit', ['id' => $item->id]))
                    . '" class="sf-btn">Edit</a>';
                $html .= '<form method="post" action="'
                    . $e($this->url->to('knowledge/admin/entries/delete'))
                    . '" onsubmit="return confirm(\'Delete this entry?\');">';
                $html .= $this->request->csrfField();
                $html .= '<input type="hidden" name="id" value="' . (int) $item->id . '">';
                $html .= '<button type="submit" class="sf-btn is-danger">Delete</button></form>';
                return $html . '</div>';
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e) {
            $this->view->assign('title', 'Knowledge entries');
            $this->view->block('right_sidebar', 'Knowledge');

            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>Entries</h1><p>Create and translate knowledge articles.</p></div>';
            echo '<div class="sf-actions">';
            echo '<a href="' . $e($this->url->to('knowledge')) . '" class="sf-btn" target="_blank" rel="noopener">'
               . '<i class="bi bi-box-arrow-up-right"></i>View site</a>';
            echo '<a href="' . $e($this->url->to('knowledge/admin/entries/add')) . '" class="sf-btn is-primary">'
               . '<i class="bi bi-plus-lg"></i>New entry</a>';
            echo '</div></div>';
            echo '<section class="sf-card">';
            echo $dataset->render();
            echo '</section></div>';
        });
    }

    public function addAction(): void
    {
        $old = $this->emptyForm();

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $old = $this->readForm();
            try {
                $old['author_id'] = $this->auth->id();
                $item = $this->entries->create($old);
                $this->syncPoll((int) $item->id, $old);
                $this->notify->success('Entry created.');
                redirect_to('knowledge/admin/entries');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old) {
            $this->view->assign('title', 'New knowledge entry');
            $this->view->block('right_sidebar', 'Knowledge');
            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>New entry</h1><p>Write content in one or more languages.</p></div>';
            echo '<div class="sf-actions">'
               . '<a href="' . $this->view->e($this->url->to('knowledge/admin/entries')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a></div>';
            echo '</div>';
            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->entryForm($old, $this->url->to('knowledge/admin/entries/add'), 'Create');
            echo '</div></section></div>';
        });
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $item = $this->entries->find($id);
        if ($item === null) {
            throw new NotFoundException('Entry not found.');
        }

        $old = $this->itemToForm($item);

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $old = $this->readForm();
            try {
                $item = $this->entries->update($id, $old);
                $this->syncPoll($id, $old);
                $this->notify->success('Entry updated.');
                redirect_to('knowledge/admin/entries');
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old, $id, $item) {
            $this->view->assign('title', 'Edit knowledge entry');
            $this->view->block('right_sidebar', 'Knowledge');
            $title = (string) (($old['translations'][knowledge_module_config()['default_locale']]['title'] ?? '') ?: $item->slug);
            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>Edit entry</h1><p>' . $this->view->e($title) . '</p></div>';
            echo '<div class="sf-actions">';
            echo '<a href="' . $this->view->e($this->url->to('knowledge/admin/entries')) . '" class="sf-btn">'
               . '<i class="bi bi-arrow-left"></i>Back</a>';
            if ($item->isPublished()) {
                echo '<a class="sf-btn" target="_blank" rel="noopener" href="'
                   . $this->view->e($this->url->to('knowledge/view', ['slug' => $item->slug])) . '">'
                   . '<i class="bi bi-box-arrow-up-right"></i>View on site</a>';
            }
            echo '</div></div>';
            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->entryForm($old, $this->url->to('knowledge/admin/entries/edit', ['id' => $id]), 'Save');
            echo '</div></section></div>';
        });
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('knowledge/admin/entries');
            return;
        }
        $this->guardCsrf();

        $id = (int) $this->request->post('id', 'int', 0);
        $item = $this->entries->find($id);
        if ($item !== null) {
            $this->entries->delete($id);
            $this->notify->success('Entry deleted.');
        } else {
            $this->notify->error('Entry not found.');
        }
        redirect_to('knowledge/admin/entries');
    }

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('knowledge/admin/entries');
            exit;
        }
    }

    private function emptyForm(): array
    {
        $cfg = knowledge_module_config();
        $translations = [];
        foreach ($cfg['locales'] as $locale) {
            $translations[$locale] = [
                'title'   => '',
                'aliases' => '',
                'summary' => '',
                'body'    => '',
            ];
        }

        return [
            'slug'         => '',
            'domain_id'    => 0,
            'status'       => 'draft',
            'source_url'   => '',
            'translations' => $translations,
            'poll_enabled' => 0,
            'poll_active'  => 1,
            'poll_closes_at' => '',
            'poll_questions' => array_fill_keys($cfg['locales'], ''),
            'poll_options' => [
                ['id' => 0, 'labels' => array_fill_keys($cfg['locales'], '')],
                ['id' => 0, 'labels' => array_fill_keys($cfg['locales'], '')],
            ],
        ];
    }

    private function itemToForm(Entry $item): array
    {
        $cfg = knowledge_module_config();
        $translations = [];
        foreach ($cfg['locales'] as $locale) {
            $row = $item->translations[$locale] ?? [];
            $translations[$locale] = [
                'title'   => (string) ($row['title'] ?? ''),
                'aliases' => (string) ($row['aliases'] ?? ''),
                'summary' => (string) ($row['summary'] ?? ''),
                'body'    => (string) ($row['body'] ?? ''),
            ];
        }

        $form = [
            'slug'         => $item->slug,
            'domain_id'    => (int) ($item->domainId ?? 0),
            'status'       => $item->status,
            'source_url'   => $item->sourceUrl,
            'translations' => $translations,
            'poll_enabled' => 0,
            'poll_active'  => 1,
            'poll_closes_at' => '',
            'poll_questions' => array_fill_keys($cfg['locales'], ''),
            'poll_options' => [
                ['id' => 0, 'labels' => array_fill_keys($cfg['locales'], '')],
                ['id' => 0, 'labels' => array_fill_keys($cfg['locales'], '')],
            ],
        ];

        $poll = $this->polls->findByEntryId((int) $item->id);
        if ($poll !== null) {
            $form['poll_enabled'] = 1;
            $form['poll_active'] = $poll->active ? 1 : 0;
            if ($poll->closesAt) {
                $ts = strtotime($poll->closesAt);
                $form['poll_closes_at'] = $ts !== false ? date('Y-m-d\TH:i', $ts) : '';
            }
            foreach ($cfg['locales'] as $locale) {
                $form['poll_questions'][$locale] = (string) ($poll->questions[$locale] ?? '');
            }
            $options = [];
            foreach ($poll->options as $opt) {
                $labels = [];
                foreach ($cfg['locales'] as $locale) {
                    $labels[$locale] = (string) ($opt->labels[$locale] ?? '');
                }
                $options[] = ['id' => (int) $opt->id, 'labels' => $labels];
            }
            if ($options === []) {
                $options = [
                    ['id' => 0, 'labels' => array_fill_keys($cfg['locales'], '')],
                    ['id' => 0, 'labels' => array_fill_keys($cfg['locales'], '')],
                ];
            }
            $form['poll_options'] = $options;
        }

        return $form;
    }

    private function readForm(): array
    {
        $cfg = knowledge_module_config();
        $translations = [];
        foreach ($cfg['locales'] as $locale) {
            $translations[$locale] = [
                'title'   => trim((string) $this->request->post("title_{$locale}", 'string', '')),
                'aliases' => trim((string) $this->request->post("aliases_{$locale}", 'string', '')),
                'summary' => trim((string) $this->request->post("summary_{$locale}", 'string', '')),
                'body'    => (string) $this->request->post("body_{$locale}", 'raw', ''),
            ];
        }

        $questions = [];
        foreach ($cfg['locales'] as $locale) {
            $questions[$locale] = trim((string) $this->request->post("poll_question_{$locale}", 'string', ''));
        }

        $rawOptions = $this->request->post('poll_options', 'raw', []);
        if (!is_array($rawOptions)) {
            $rawOptions = [];
        }
        $options = [];
        foreach ($rawOptions as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $labels = [];
            foreach ($cfg['locales'] as $locale) {
                $labels[$locale] = trim((string) ($opt['labels'][$locale] ?? ''));
            }
            $options[] = [
                'id'     => (int) ($opt['id'] ?? 0),
                'labels' => $labels,
            ];
        }
        if ($options === []) {
            $options = [
                ['id' => 0, 'labels' => array_fill_keys($cfg['locales'], '')],
                ['id' => 0, 'labels' => array_fill_keys($cfg['locales'], '')],
            ];
        }

        return [
            'slug'           => trim((string) $this->request->post('slug', 'string', '')),
            'domain_id'      => (int) $this->request->post('domain_id', 'int', 0),
            'status'         => trim((string) $this->request->post('status', 'string', 'draft')),
            'source_url'     => trim((string) $this->request->post('source_url', 'string', '')),
            'translations'   => $translations,
            'poll_enabled'   => (int) $this->request->post('poll_enabled', 'int', 0),
            'poll_active'    => (int) $this->request->post('poll_active', 'int', 0),
            'poll_closes_at' => trim((string) $this->request->post('poll_closes_at', 'string', '')),
            'poll_questions' => $questions,
            'poll_options'   => $options,
        ];
    }

    private function syncPoll(int $entryId, array $form): void
    {
        if (empty($form['poll_enabled'])) {
            $this->polls->deleteForEntry($entryId);
            return;
        }

        $this->polls->syncForEntry($entryId, [
            'active'    => !empty($form['poll_active']),
            'closes_at' => $form['poll_closes_at'] ?? null,
            'questions' => $form['poll_questions'] ?? [],
            'options'   => $form['poll_options'] ?? [],
        ]);
    }

    private function entryForm(array $old, string $action, string $submitLabel): string
    {
        $cfg = knowledge_module_config();
        $domains = $this->domains->all();
        $domainOptions = ['0' => '— None —'];
        foreach ($domains as $domain) {
            $domainOptions[(string) $domain->id] = $domain->name;
        }

        $statusOptions = [
            'draft'     => 'Draft',
            'published' => 'Published',
            'archived'  => 'Archived',
        ];

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        ob_start();
        echo $this->form->open(['action' => $action, 'method' => 'POST']);

        echo $this->form->row(['fields' => [
            [
                'type'  => 'text',
                'name'  => 'slug',
                'label' => 'Slug',
                'value' => (string) $old['slug'],
                'help'  => 'Leave blank to auto-generate from the default-locale title.',
                'col'   => 'col-md-4',
            ],
            [
                'type'    => 'select',
                'name'    => 'domain_id',
                'label'   => 'Domain',
                'options' => $domainOptions,
                'value'   => (string) ((int) $old['domain_id']),
                'col'     => 'col-md-4',
            ],
            [
                'type'    => 'select',
                'name'    => 'status',
                'label'   => 'Status',
                'options' => $statusOptions,
                'value'   => (string) $old['status'],
                'col'     => 'col-md-4',
            ],
        ]]);

        echo $this->form->field([
            'type'  => 'url',
            'name'  => 'source_url',
            'label' => 'Source URL',
            'value' => (string) $old['source_url'],
            'help'  => 'Optional original link for this knowledge.',
        ]);

        echo '<h2 class="h5 mt-4 mb-3">Translations</h2>';
        echo '<ul class="nav nav-tabs" role="tablist">';
        foreach ($cfg['locales'] as $i => $locale) {
            $active = $i === 0 ? ' active' : '';
            $selected = $i === 0 ? 'true' : 'false';
            echo '<li class="nav-item" role="presentation">';
            echo '<button class="nav-link' . $active . '" id="tab-' . $e($locale) . '" data-bs-toggle="tab"'
               . ' data-bs-target="#pane-' . $e($locale) . '" type="button" role="tab"'
               . ' aria-controls="pane-' . $e($locale) . '" aria-selected="' . $selected . '">'
               . $e(knowledge_locale_label($locale))
               . ($locale === $cfg['default_locale'] ? ' *' : '')
               . '</button></li>';
        }
        echo '</ul>';

        echo '<div class="tab-content border border-top-0 rounded-bottom p-3 mb-4">';
        foreach ($cfg['locales'] as $i => $locale) {
            $tr = $old['translations'][$locale] ?? ['title' => '', 'aliases' => '', 'summary' => '', 'body' => ''];
            $show = $i === 0 ? ' show active' : '';
            echo '<div class="tab-pane fade' . $show . '" id="pane-' . $e($locale) . '" role="tabpanel"'
               . ' aria-labelledby="tab-' . $e($locale) . '">';

            echo $this->form->field([
                'type'     => 'text',
                'name'     => "title_{$locale}",
                'label'    => 'Title (' . knowledge_locale_label($locale) . ')',
                'value'    => (string) $tr['title'],
                'required' => $locale === $cfg['default_locale'] && ($old['status'] ?? '') === 'published',
            ]);
            echo $this->form->field([
                'type'  => 'text',
                'name'  => "aliases_{$locale}",
                'label' => 'Aliases',
                'value' => (string) $tr['aliases'],
                'help'  => 'Comma-separated alternate names / keywords.',
            ]);
            echo $this->form->field([
                'type'  => 'text',
                'name'  => "summary_{$locale}",
                'label' => 'Summary',
                'value' => (string) $tr['summary'],
            ]);
            echo $this->form->field([
                'type'   => 'textarea',
                'name'   => "body_{$locale}",
                'label'  => 'Body',
                'value'  => (string) $tr['body'],
                'editor' => true,
                'rows'   => 12,
            ]);
            echo '</div>';
        }
        echo '</div>';

        echo '<h2 class="h5 mt-2 mb-3">Optional poll</h2>';
        echo '<div class="border rounded p-3 mb-3">';
        echo '<div class="form-check mb-3">';
        echo '<input type="checkbox" class="form-check-input" name="poll_enabled" value="1" id="poll_enabled"'
           . (!empty($old['poll_enabled']) ? ' checked' : '') . '>';
        echo '<label class="form-check-label" for="poll_enabled">Attach a poll to this entry</label>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6"><div class="form-check mt-4">';
        echo '<input type="checkbox" class="form-check-input" name="poll_active" value="1" id="poll_active"'
           . (!empty($old['poll_active']) ? ' checked' : '') . '>';
        echo '<label class="form-check-label" for="poll_active">Poll is open for votes</label>';
        echo '</div></div>';
        echo '<div class="col-md-6">';
        echo $this->form->field([
            'type'  => 'datetime-local',
            'name'  => 'poll_closes_at',
            'label' => 'Closes at',
            'value' => (string) ($old['poll_closes_at'] ?? ''),
            'help'  => 'Optional end time.',
        ]);
        echo '</div></div>';

        foreach ($cfg['locales'] as $locale) {
            echo $this->form->field([
                'type'  => 'text',
                'name'  => "poll_question_{$locale}",
                'label' => 'Question (' . knowledge_locale_label($locale) . ')',
                'value' => (string) ($old['poll_questions'][$locale] ?? ''),
            ]);
        }

        echo '<p class="fw-semibold mb-2">Options</p>';
        $options = is_array($old['poll_options'] ?? null) ? $old['poll_options'] : [];
        foreach ($options as $i => $opt) {
            $oid = (int) ($opt['id'] ?? 0);
            echo '<div class="border rounded p-2 mb-2">';
            echo '<input type="hidden" name="poll_options[' . $i . '][id]" value="' . $oid . '">';
            echo '<div class="row g-2">';
            foreach ($cfg['locales'] as $locale) {
                echo '<div class="col-md-4">';
                echo $this->form->field([
                    'type'  => 'text',
                    'name'  => "poll_options[{$i}][labels][{$locale}]",
                    'label' => 'Option ' . ((int) $i + 1) . ' · ' . knowledge_locale_label($locale),
                    'value' => (string) ($opt['labels'][$locale] ?? ''),
                ]);
                echo '</div>';
            }
            echo '</div></div>';
        }
        echo '<p class="form-text mb-0">Leave option labels blank to remove them. Add more by saving and editing again with extra blank rows if needed.</p>';
        // Extra blank option rows for adding more
        $start = count($options);
        for ($i = $start; $i < $start + 2; $i++) {
            echo '<div class="border rounded p-2 mb-2">';
            echo '<input type="hidden" name="poll_options[' . $i . '][id]" value="0">';
            echo '<div class="row g-2">';
            foreach ($cfg['locales'] as $locale) {
                echo '<div class="col-md-4">';
                echo $this->form->field([
                    'type'  => 'text',
                    'name'  => "poll_options[{$i}][labels][{$locale}]",
                    'label' => 'New option · ' . knowledge_locale_label($locale),
                    'value' => '',
                ]);
                echo '</div>';
            }
            echo '</div></div>';
        }
        echo '</div>';

        echo '<div class="sf-actions mt-4">';
        echo '<button type="submit" class="sf-btn is-primary">' . $e($submitLabel) . '</button>';
        echo '<a href="' . $e($this->url->to('knowledge/admin/entries')) . '" class="sf-btn">Cancel</a>';
        echo '</div>';

        echo $this->form->close();
        return (string) ob_get_clean();
    }
}
