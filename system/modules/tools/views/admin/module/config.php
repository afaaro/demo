<?php
/** @var System\Library\View $view */
/** @var array<string, mixed> $module */
/** @var array<int, array<string, mixed>> $schema */
/** @var array<string, mixed> $config */

$mig = $module['migrations'] ?? ['total' => 0, 'applied' => 0, 'pending' => 0, 'pending_keys' => []];
?>
<div class="container py-4" style="max-width:48rem">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2">
            <li class="breadcrumb-item"><a href="<?= $view->e($view->url->to('tools/admin/module')) ?>">Modules</a></li>
            <li class="breadcrumb-item active"><?= $view->e((string) $module['label']) ?></li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Configure: <?= $view->e((string) $module['label']) ?></h1>
            <p class="text-muted small mb-0">
                <code><?= $view->e((string) $module['name']) ?></code>
                · v<?= $view->e((string) $module['version']) ?>
                <?php if (!empty($module['is_system'])): ?>
                    · <span class="badge text-bg-primary">System</span>
                <?php endif; ?>
            </p>
        </div>
        <?php if ((int) ($mig['pending'] ?? 0) > 0): ?>
            <form method="post" action="<?= $view->e($view->url->to('tools/admin/module/migrate')) ?>">
                <?= $view->request->csrfField() ?>
                <input type="hidden" name="name" value="<?= $view->e((string) $module['name']) ?>">
                <button type="submit" class="btn btn-warning btn-sm">
                    Run <?= (int) $mig['pending'] ?> pending migration(s)
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($module['description'])): ?>
        <p class="text-muted"><?= $view->e((string) $module['description']) ?></p>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body small">
            <div><strong>Path:</strong> <code><?= $view->e((string) $module['path']) ?></code></div>
            <div><strong>Migrations:</strong>
                <?= (int) ($mig['applied'] ?? 0) ?>/<?= (int) ($mig['total'] ?? 0) ?> applied
                <?php if ((int) ($mig['pending'] ?? 0) > 0): ?>
                    · <span class="text-warning"><?= (int) $mig['pending'] ?> pending</span>
                <?php else: ?>
                    · <span class="text-success">up to date</span>
                <?php endif; ?>
            </div>
            <?php if (!empty($module['installed_at'])): ?>
                <div><strong>Installed:</strong> <?= $view->e((string) $module['installed_at']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($schema === []): ?>
        <div class="alert alert-info">
            No configuration options defined for this module.
            Add a <code>config.json</code> in the module root to expose settings here.
        </div>
        <a href="<?= $view->e($view->url->to('tools/admin/module')) ?>" class="btn btn-secondary">← Back to Modules</a>
        <?php return; ?>
    <?php endif; ?>

    <form method="post" class="card border-0 shadow-sm">
        <?= $view->request->csrfField() ?>
        <div class="card-body">
            <?php foreach ($schema as $field):
                $name = (string) ($field['name'] ?? '');
                $type = (string) ($field['type'] ?? 'text');
                $label = (string) ($field['label'] ?? ucwords(str_replace('_', ' ', $name)));
                $value = $config[$name] ?? $field['default'] ?? '';
                $desc = (string) ($field['description'] ?? '');
                if ($name === '') {
                    continue;
                }
            ?>
            <div class="mb-3">
                <label class="form-label fw-semibold"><?= $view->e($label) ?></label>

                <?php if ($type === 'textarea'): ?>
                    <textarea name="config[<?= $view->e($name) ?>]" class="form-control" rows="4"><?= $view->e((string) $value) ?></textarea>

                <?php elseif ($type === 'number'): ?>
                    <input type="number" name="config[<?= $view->e($name) ?>]" class="form-control"
                           value="<?= $view->e((string) $value) ?>"
                           step="<?= $view->e((string) ($field['step'] ?? '1')) ?>"
                           min="<?= $view->e((string) ($field['min'] ?? '')) ?>"
                           max="<?= $view->e((string) ($field['max'] ?? '')) ?>">

                <?php elseif ($type === 'checkbox'): ?>
                    <div class="form-check">
                        <input type="hidden" name="config[<?= $view->e($name) ?>]" value="0">
                        <input type="checkbox" name="config[<?= $view->e($name) ?>]"
                               class="form-check-input" value="1" id="cfg_<?= $view->e($name) ?>"
                               <?= !empty($value) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="cfg_<?= $view->e($name) ?>">
                            <?= $view->e($desc !== '' ? $desc : 'Enabled') ?>
                        </label>
                    </div>
                    <?php $desc = ''; ?>

                <?php elseif ($type === 'select'): ?>
                    <select name="config[<?= $view->e($name) ?>]" class="form-select">
                        <?php foreach ($field['options'] ?? [] as $opt): ?>
                            <option value="<?= $view->e((string) ($opt['value'] ?? '')) ?>"
                                <?= ((string) $value === (string) ($opt['value'] ?? '')) ? ' selected' : '' ?>>
                                <?= $view->e((string) ($opt['label'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                <?php elseif ($type === 'array'): ?>
                    <textarea name="config[<?= $view->e($name) ?>]" class="form-control" rows="5"
                              placeholder="One entry per line"><?= $view->e(is_array($value) ? implode("\n", $value) : (string) $value) ?></textarea>
                    <div class="form-text">One entry per line</div>

                <?php else: ?>
                    <input type="<?= $view->e($type) ?>" name="config[<?= $view->e($name) ?>]"
                           class="form-control" value="<?= $view->e((string) $value) ?>">
                <?php endif; ?>

                <?php if ($desc !== ''): ?>
                    <div class="form-text"><?= $view->e($desc) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card-footer bg-white d-flex justify-content-between">
            <a href="<?= $view->e($view->url->to('tools/admin/module')) ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save configuration</button>
        </div>
    </form>

    <?php if ($module['name'] === 'user'): ?>
        <p class="small text-muted mt-3 mb-0">
            User settings are also written to <code>system/config/user.php</code> so registration uses them immediately.
        </p>
    <?php endif; ?>
</div>
