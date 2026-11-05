<?php
declare(strict_types=1);

use Module\StoreFeeder\Services\ChannelRepository;
use Module\StoreFeeder\Services\ListingTemplateRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;

class StoreFeederAdminListingTemplates extends Controller
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

        $rows = $this->templates->forChannel($channelId);

        echo $this->view->inline(function () use ($channel, $rows, $channelId) {
            $this->view->assign('title', 'Listing Templates');
            $this->view->block('right_sidebar', 'Integrations');

            echo '<div class="mb-3"><a href="'
               . $this->view->e($this->url->to('storefeeder/admin/channels/edit', ['id' => $channelId]))
               . '" class="text-decoration-none small">&larr; Integration Details</a></div>';

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">';
            echo '<h1 class="h3 mb-0">Listing Templates'
               . ' <span class="text-muted fs-6">— ' . $this->view->e($channel->label) . '</span></h1>';
            echo '<a class="btn btn-success" href="'
               . $this->view->e($this->url->to('storefeeder/admin/listing-templates/edit', [
                   'channel_id' => $channelId,
               ]))
               . '"><i class="bi bi-plus-lg me-1"></i>New template</a>';
            echo '</div>';

            echo '<p class="text-muted small">Templates control title and description HTML when pushing products to this channel. '
               . 'Placeholders: <code>{{title}}</code> <code>{{description}}</code> <code>{{product_name}}</code> '
               . '<code>{{variant_name}}</code> <code>{{sku}}</code> <code>{{price}}</code> '
               . '<code>{{currency}}</code> <code>{{barcode}}</code> <code>{{image_url}}</code></p>';

            if ($rows === []) {
                echo '<div class="alert alert-info">No templates yet. Create one and mark it as default.</div>';
                return;
            }

            echo '<div class="table-responsive"><table class="table table-sm align-middle">';
            echo '<thead><tr><th>Name</th><th>Default</th><th>Title template</th><th></th></tr></thead><tbody>';
            foreach ($rows as $t) {
                echo '<tr>';
                echo '<td class="fw-semibold">' . $this->view->e($t->name) . '</td>';
                echo '<td>' . ($t->isDefault
                    ? '<span class="badge text-bg-success">Default</span>'
                    : '<span class="text-muted">—</span>') . '</td>';
                echo '<td class="small text-muted">' . $this->view->e(mb_substr($t->titleTemplate, 0, 80)) . '</td>';
                echo '<td class="text-end text-nowrap">';
                echo '<a class="btn btn-sm btn-outline-primary" href="'
                   . $this->view->e($this->url->to('storefeeder/admin/listing-templates/edit', [
                       'id'         => $t->id,
                       'channel_id' => $channelId,
                   ])) . '">Edit</a> ';
                if (!$t->isDefault) {
                    echo '<form method="post" class="d-inline" action="'
                       . $this->view->e($this->url->to('storefeeder/admin/listing-templates/set-default', [
                           'id'         => $t->id,
                           'channel_id' => $channelId,
                       ])) . '">'
                       . $this->request->csrfField()
                       . '<button type="submit" class="btn btn-sm btn-outline-secondary">Make default</button></form> ';
                }
                echo '<form method="post" class="d-inline" action="'
                   . $this->view->e($this->url->to('storefeeder/admin/listing-templates/delete', [
                       'id'         => $t->id,
                       'channel_id' => $channelId,
                   ])) . '" onsubmit="return confirm(\'Delete this template?\');">'
                   . $this->request->csrfField()
                   . '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button></form>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
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
                    redirect_to('storefeeder/admin/listing-templates/edit', [
                        'id'         => $created->id,
                        'channel_id' => $channelId,
                    ]);
                    return;
                }

                $this->templates->update($id, $old);
                $this->notify->success('Template saved.');
                redirect_to('storefeeder/admin/listing-templates', ['channel_id' => $channelId]);
                return;
            } catch (\Throwable $e) {
                $this->notify->error($e->getMessage());
            }
        }

        echo $this->view->inline(function () use ($channel, $channelId, $id, $old) {
            $this->view->assign('title', $id > 0 ? 'Edit Listing Template' : 'New Listing Template');
            $this->view->block('right_sidebar', 'Integrations');

            echo '<div class="mb-3"><a href="'
               . $this->view->e($this->url->to('storefeeder/admin/listing-templates', ['channel_id' => $channelId]))
               . '" class="text-decoration-none small">&larr; Listing Templates</a></div>';
            echo '<h1 class="h3 mb-4">' . ($id > 0 ? 'Edit' : 'New') . ' Listing Template'
               . ' <span class="text-muted fs-6">— ' . $this->view->e($channel->label) . '</span></h1>';

            echo $this->form->open([
                'action' => $this->url->to('storefeeder/admin/listing-templates/edit', array_filter([
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
               . $this->view->e($this->url->to('storefeeder/admin/listing-templates', ['channel_id' => $channelId]))
               . '">Cancel</a>';
            echo $this->form->close();
        });
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/channels');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired.');
            redirect_to('storefeeder/admin/channels');
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

        redirect_to('storefeeder/admin/listing-templates', ['channel_id' => $channelId]);
    }

    public function setDefaultAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('storefeeder/admin/channels');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException $e) {
            $this->notify->error('Session expired.');
            redirect_to('storefeeder/admin/channels');
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

        redirect_to('storefeeder/admin/listing-templates', ['channel_id' => $channelId]);
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
