<?php
/** @var System\Library\View $view */
/** @var array<string, array<string, mixed>> $modules */
/** @var string $q */
/** @var string $filter */
/** @var int $pendingTotal */

$filters = [
    ''          => 'All',
    'installed' => 'Installed',
    'enabled'   => 'Enabled',
    'disabled'  => 'Disabled',
    'pending'   => 'Pending migrations',
    'system'    => 'System',
];
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Module Management</h1>
            <p class="text-muted mb-0">Install, enable, configure modules, and run pending migrations.</p>
        </div>
        <?php if ($pendingTotal > 0): ?>
            <span class="badge text-bg-warning align-self-center"><?= (int) $pendingTotal ?> pending migration(s)</span>
        <?php endif; ?>
    </div>

    <form method="get" action="<?= escape($view->url->to('tools/admin/module')) ?>" class="row g-2 align-items-end mb-3">
        <div class="col-md-5">
            <label class="form-label small mb-1">Search</label>
            <input type="search" name="q" value="<?= escape($q) ?>" class="form-control"
                   placeholder="Name, label, description…">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Filter</label>
            <select name="filter" class="form-select">
                <?php foreach ($filters as $val => $label): ?>
                    <option value="<?= escape($val) ?>"<?= $filter === $val ? ' selected' : '' ?>>
                        <?= escape($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary">Apply</button>
            <?php if ($q !== '' || $filter !== ''): ?>
                <a class="btn btn-outline-secondary" href="<?= escape($view->url->to('tools/admin/module')) ?>">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-responsive border rounded bg-white">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Module</th>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Migrations</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($modules === []): ?>
                <tr><td colspan="5" class="text-muted text-center py-4">No modules match your filters.</td></tr>
            <?php endif; ?>

            <?php foreach ($modules as $mod): ?>
                <?php
                $isSystem = !empty($mod['is_system']);
                $mig = $mod['migrations'] ?? ['total' => 0, 'applied' => 0, 'pending' => 0];
                $pending = (int) ($mig['pending'] ?? 0);
                $total = (int) ($mig['total'] ?? 0);
                ?>
                <tr>
                    <td style="min-width:14rem">
                        <strong><?= escape((string) $mod['label']) ?></strong>
                        <?php if ($isSystem): ?>
                            <span class="badge text-bg-primary ms-1">System</span>
                        <?php endif; ?>
                        <?php if (!empty($mod['has_config'])): ?>
                            <span class="badge text-bg-light text-dark border ms-1">Configurable</span>
                        <?php endif; ?>
                        <code class="d-block text-muted small"><?= escape((string) $mod['name']) ?></code>
                        <?php if (!empty($mod['description'])): ?>
                            <div class="small text-muted"><?= escape((string) $mod['description']) ?></div>
                        <?php endif; ?>
                        <div class="small text-muted font-monospace" style="font-size:.7rem">
                            <?= escape((string) $mod['path']) ?>
                        </div>
                    </td>
                    <td class="text-nowrap">
                        <?= escape((string) $mod['version']) ?>
                        <?php if (!empty($mod['installed']) && ($mod['disk_version'] ?? '') !== ($mod['version'] ?? '')): ?>
                            <div class="small text-muted">disk <?= escape((string) $mod['disk_version']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($mod['installed'])): ?>
                            <span class="badge text-bg-secondary">Not installed</span>
                        <?php elseif (!empty($mod['enabled'])): ?>
                            <span class="badge text-bg-success">Enabled</span>
                        <?php else: ?>
                            <span class="badge text-bg-warning text-dark">Disabled</span>
                        <?php endif; ?>
                        <?php if (!empty($mod['installed_at'])): ?>
                            <div class="small text-muted"><?= escape((string) $mod['installed_at']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($total === 0): ?>
                            <span class="text-muted small">None</span>
                        <?php elseif ($pending > 0): ?>
                            <span class="badge text-bg-warning text-dark"><?= $pending ?> pending</span>
                            <div class="small text-muted"><?= (int) $mig['applied'] ?>/<?= $total ?> applied</div>
                        <?php else: ?>
                            <span class="badge text-bg-success">Up to date</span>
                            <div class="small text-muted"><?= $total ?> applied</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if (empty($mod['installed'])): ?>
                            <form method="post" action="<?= escape($view->url->to('tools/admin/module/install')) ?>" class="d-inline">
                                <?= $view->request->csrfField() ?>
                                <input type="hidden" name="name" value="<?= escape((string) $mod['name']) ?>">
                                <button type="submit" class="btn btn-sm btn-primary">Install</button>
                            </form>
                        <?php else: ?>
                            <?php if ($pending > 0): ?>
                                <form method="post" action="<?= escape($view->url->to('tools/admin/module/migrate')) ?>" class="d-inline">
                                    <?= $view->request->csrfField() ?>
                                    <input type="hidden" name="name" value="<?= escape((string) $mod['name']) ?>">
                                    <input type="hidden" name="q" value="<?= escape($q) ?>">
                                    <input type="hidden" name="filter" value="<?= escape($filter) ?>">
                                    <button type="submit" class="btn btn-sm btn-warning">Migrate</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($isSystem): ?>
                                <span class="text-muted small me-1">Locked</span>
                            <?php else: ?>
                                <?php if (!empty($mod['enabled'])): ?>
                                    <form method="post" action="<?= escape($view->url->to('tools/admin/module/disable')) ?>" class="d-inline">
                                        <?= $view->request->csrfField() ?>
                                        <input type="hidden" name="name" value="<?= escape((string) $mod['name']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning">Disable</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= escape($view->url->to('tools/admin/module/enable')) ?>" class="d-inline">
                                        <?= $view->request->csrfField() ?>
                                        <input type="hidden" name="name" value="<?= escape((string) $mod['name']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Enable</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= escape($view->url->to('tools/admin/module/uninstall')) ?>" class="d-inline"
                                      onsubmit="return confirm('Permanently uninstall this module? Data may be lost.');">
                                    <?= $view->request->csrfField() ?>
                                    <input type="hidden" name="name" value="<?= escape((string) $mod['name']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Uninstall</button>
                                </form>
                            <?php endif; ?>

                            <?php if (!empty($mod['has_config'])): ?>
                                <a href="<?= escape($view->url->to('tools/admin/module/config', ['name' => $mod['name']])) ?>"
                                   class="btn btn-sm btn-outline-info">Configure</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>