<?php
declare(strict_types=1);

use Module\Store\Services\ChannelRepository;
use Module\Store\Services\ListingTemplate;
use Module\Store\Services\ListingTemplateRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;

class StoreAdminListingTemplates extends Controller
{
    private ListingTemplateRepository $templates;
    private ChannelRepository         $channels;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }

        $this->templates = new ListingTemplateRepository($this->db);
        $this->channels  = new ChannelRepository($this->db);
        // Permissions: Action.php only.
    }

    public function indexAction(): void
    {
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $channel   = $this->channels->find($channelId);
        if ($channel === null) {
            throw new NotFoundException('Channel not found.');
        }

        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->sortable(['name', 'is_default'])
            ->defaultSort('name', 'asc')
            ->columns([
                'name'            => 'Name',
                'is_default'      => 'Default',
                'title_template'  => 'Title template',
                'actions'         => '',
            ])
            ->query(function ($db, array $state) use ($channelId): array {
                $rows = $this->templates->forChannel($channelId);
                $mult = strtolower($state['dir']) === 'desc' ? -1 : 1;
                $sort = $state['sort'];
                usort($rows, function (ListingTemplate $a, ListingTemplate $b) use ($sort, $mult): int {
                    if ($sort === 'is_default') {
                        return $mult * ((int) $a->isDefault <=> (int) $b->isDefault);
                    }
                    return $mult * strcasecmp($a->name, $b->name);
                });
                $total = count($rows);
                $slice = array_slice($rows, $state['offset'], $state['per_page']);

                return [
                    'rows'  => array_map(static fn(ListingTemplate $t): array => [
                        '_template'   => $t,
                        '_channel_id' => $channelId,
                    ], $slice),
                    'total' => $total,
                ];
            });

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $dataset->columns([
            'name' => function (array $row) use ($e): string {
                return '<span class="fw-semibold">' . $e($row['_template']->name) . '</span>';
            },
            'is_default' => fn(array $row): string => $row['_template']->isDefault
                ? '<span class="badge text-bg-success">Default</span>'
                : '<span class="text-muted">—</span>',
            'title_template' => function (array $row) use ($e): string {
                return '<span class="small text-muted">' . $e(mb_substr($row['_template']->titleTemplate, 0, 80)) . '</span>';
            },
            'actions' => function (array $row) use ($e): string {
                $t = $row['_template'];
                $channelId = (int) $row['_channel_id'];
                $html = '<div class="text-end text-nowrap">';
                $html .= '<a class="btn btn-sm btn-outline-primary" href="'
                    . $e($this->url->to('store/admin/listing-templates/edit', [
                        'id'         => $t->id,
                        'channel_id' => $channelId,
                    ])) . '">Edit</a> ';
                if (!$t->isDefault) {
                    $html .= '<form method="post" class="d-inline" action="'
                        . $e($this->url->to('store/admin/listing-templates/set-default', [
                            'id'         => $t->id,
                            'channel_id' => $channelId,
                        ])) . '">';
                    $html .= $this->request->csrfField();
                    $html .= '<button type="submit" class="btn btn-sm btn-outline-secondary">Make default</button></form> ';
                }
                $html .= '<form method="post" class="d-inline" action="'
                    . $e($this->url->to('store/admin/listing-templates/delete', [
                        'id'         => $t->id,
                        'channel_id' => $channelId,
                    ])) . '" onsubmit="return confirm(\'Delete this template?\');">';
                $html .= $this->request->csrfField();
                $html .= '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button></form>';
                return $html . '</div>';
            },
        ]);

        echo $this->view->inline(function () use ($channel, $channelId, $dataset) {
            $this->view->assign('title', 'Listing Templates');
            $this->view->block('right_sidebar', 'Integrations');

            echo '<div class="mb-3"><a href="'
               . $this->view->e($this->url->to('store/admin/channels/edit', ['id' => $channelId]))
               . '" class="text-decoration-none small">&larr; Integration Details</a></div>';

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">Listing Templates'
               . ' <span class="text-muted fs-6">— ' . $this->view->e($channel->label) . '</span></h1>';
            echo '<a class="btn btn-success" href="'
               . $this->view->e($this->url->to('store/admin/listing-templates/edit', [
                   'channel_id' => $channelId,
               ]))
               . '"><i class="bi bi-plus-lg me-1"></i>New template</a>';
            echo '</div>';

            echo '<p class="text-muted small">Templates control title and description HTML when pushing products to this channel. '
               . 'Placeholders: <code>{{title}}</code> <code>{{description}}</code> <code>{{product_name}}</code> '
               . '<code>{{variant_name}}</code> <code>{{sku}}</code> <code>{{price}}</code> '
               . '<code>{{currency}}</code> <code>{{barcode}}</code> <code>{{image_url}}</code></p>';

            echo $dataset->render();
        });
    }

    public function editAction(): void
    {
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $id        = (int) $this->request->get('id', 'int', 0);
        $channel   = $this->channels->find($channelId);
        if ($channel === null) {
            throw new NotFoundException('Channel not found.');
        }

        $template = $id > 0 ? $this->templates->find($id) : null;
        if ($id > 0 && ($template === null || $template->channelId !== $channelId)) {
            throw new NotFoundException('Template not found.');
        }

        $old = [
            'name'                 => $template?->name ?? '',
            'title_template'       => $template?->titleTemplate ?? '{{title}}',
            'description_template' => $template?->descriptionTemplate ?? $this->defaultDescriptionHtml(),
            'is_default'           => $template?->isDefault ?? ($this->templates->forChannel($channelId) === []),
        ];

        if ($this->request->isPost()) {
            $old = [
                'name'                 => trim((string) $this->request->post('name', 'string', '')),
                'title_template'       => trim((string) $this->request->post('title_template', 'string', '')),
                'description_template' => (string) $this->request->post('description_template', 'raw', ''),
                'is_default'           => !empty($this->request->post('is_default', 'int', 0)),
            ];

            try {
                if ($template === null) {
                    $created = $this->templates->create($channelId, $old);
                    $this->notify->success('Template created.');
                    redirect_to('store/admin/listing-templates/edit', [
                        'id'         => $created->id,
                        'channel_id' => $channelId,
                    ]);
                    return;
                }

                $this->templates->update($id, $old);
                $this->notify->success('Template saved.');
                redirect_to('store/admin/listing-templates', ['channel_id' => $channelId]);
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($channel, $channelId, $id, $old) {
            $this->view->assign('title', $id > 0 ? 'Edit Listing Template' : 'New Listing Template');
            $this->view->block('right_sidebar', 'Integrations');

            echo '<div class="mb-3"><a href="'
               . $this->view->e($this->url->to('store/admin/listing-templates', ['channel_id' => $channelId]))
               . '" class="text-decoration-none small">&larr; Listing Templates</a></div>';
            echo '<h1 class="h3 mb-4">' . ($id > 0 ? 'Edit' : 'New') . ' Listing Template'
               . ' <span class="text-muted fs-6">— ' . $this->view->e($channel->label) . '</span></h1>';

            echo $this->form->open([
                'action' => $this->url->to('store/admin/listing-templates/edit', array_filter([
                    'channel_id' => $channelId,
                    'id'         => $id > 0 ? $id : null,
                ])),
                'method' => 'POST',
            ]);

            echo $this->form->field([
                'name'     => 'name',
                'label'    => 'Name',
                'type'     => 'text',
                'value'    => $old['name'],
                'required' => true,
            ]);
            echo $this->form->field([
                'name'  => 'title_template',
                'label' => 'Title template',
                'type'  => 'text',
                'value' => $old['title_template'],
                'help'  => 'eBay inventory title is limited to 80 characters after substitution.',
            ]);

            echo '<div class="mb-3"><label class="form-label" for="description_template">Description template (HTML OK)</label>';
            echo '<textarea class="form-control font-monospace" name="description_template" id="description_template" rows="14">'
               . $this->view->e($old['description_template']) . '</textarea></div>';

            echo '<div class="form-check form-switch mb-4">'
               . '<input type="hidden" name="is_default" value="0">'
               . '<input class="form-check-input" type="checkbox" role="switch" name="is_default" value="1" id="is_default"'
               . (!empty($old['is_default']) ? ' checked' : '') . '>'
               . '<label class="form-check-label" for="is_default">Use as default for this integration</label>'
               . '</div>';

            echo '<button type="submit" class="btn btn-primary">Save</button> ';
            echo '<a class="btn btn-outline-secondary" href="'
               . $this->view->e($this->url->to('store/admin/listing-templates', ['channel_id' => $channelId]))
               . '">Cancel</a>';
            echo $this->form->close();
        });
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired.');
            redirect_to('store/admin/channels');
            return;
        }

        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $id        = (int) $this->request->get('id', 'int', 0);
        $template  = $this->templates->find($id);
        if ($template === null || $template->channelId !== $channelId) {
            $this->notify->error('Template not found.');
        } else {
            $this->templates->delete($id);
            $this->notify->success('Template deleted.');
        }

        redirect_to('store/admin/listing-templates', ['channel_id' => $channelId]);
    }

    public function setDefaultAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/channels');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired.');
            redirect_to('store/admin/channels');
            return;
        }

        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $id        = (int) $this->request->get('id', 'int', 0);
        $template  = $this->templates->find($id);
        if ($template === null || $template->channelId !== $channelId) {
            $this->notify->error('Template not found.');
        } else {
            $this->templates->setDefault($channelId, $id);
            $this->notify->success('Default template updated.');
        }

        redirect_to('store/admin/listing-templates', ['channel_id' => $channelId]);
    }

    private function defaultDescriptionHtml(): string
    {
        return <<<'HTML'
<div>
  <h2>{{product_name}}</h2>
  <p>{{description}}</p>
  <p><strong>SKU:</strong> {{sku}}</p>
  <p><strong>Price:</strong> {{currency}} {{price}}</p>
</div>
HTML;
    }
}
