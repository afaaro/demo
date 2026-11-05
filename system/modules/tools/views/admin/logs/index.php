<?php
/** @var System\Library\View $view */
/** @var array<string, list<array<string, mixed>>> $byChannel */
/** @var list<array<string, mixed>> $files */
/** @var list<string> $channels */
/** @var string $q */
/** @var string $channel */
/** @var int $totalBytes */
/** @var int $errorHits */
/** @var string $logDir */
/** @var array<string, mixed> $settings */

use ToolsAdminLogs as LogsAdmin;
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Logs</h1>
            <p class="text-muted mb-0">Browse, filter, download and prune application log files.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <form method="post" action="<?= $view->e($view->url->to('tools/admin/logs/prune')) ?>" class="d-inline"
                  onsubmit="return confirm('Delete log files older than <?= (int) $settings['keep_days'] ?> day(s)?');">
                <?= $view->request->csrfField() ?>
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    Prune old (<?= (int) $settings['keep_days'] ?>d)
                </button>
            </form>
            <?php if ($files !== []): ?>
                <form method="post" action="<?= $view->e($view->url->to('tools/admin/logs/clear')) ?>" class="d-inline"
                      onsubmit="return confirm('Clear ALL log files? Contents will be emptied.');">
                    <?= $view->request->csrfField() ?>
                    <input type="hidden" name="target" value="all">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Clear all</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <div class="fs-4 fw-semibold"><?= count($files) ?></div>
                <div class="small text-muted">File(s)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <div class="fs-4 fw-semibold"><?= $view->e(LogsAdmin::formatBytes($totalBytes)) ?></div>
                <div class="small text-muted">Total size</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <div class="fs-4 fw-semibold"><?= count($channels) ?></div>
                <div class="small text-muted">Channel(s)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <div class="fs-4 fw-semibold<?= $errorHits > 0 ? ' text-danger' : '' ?>"><?= (int) $errorHits ?></div>
                <div class="small text-muted">Errors (recent files)</div>
            </div>
        </div>
    </div>

    <div class="border rounded p-3 mb-4 bg-body-tertiary small">
        <strong class="me-2">Logger</strong>
        <code><?= $view->e($logDir) ?></code>
        · channel <code><?= $view->e((string) $settings['channel']) ?></code>
        · level <code><?= $view->e((string) $settings['level']) ?></code>
        · format <code><?= $view->e((string) $settings['format']) ?></code>
        · keep <?= (int) $settings['keep_days'] ?>d
        · max <?= $view->e(LogsAdmin::formatBytes((int) $settings['max_file_size'])) ?>
        · <?= !empty($settings['enabled']) ? 'enabled' : 'disabled' ?>
    </div>

    <form method="get" action="<?= $view->e($view->url->to('tools/admin/logs')) ?>" class="row g-2 align-items-end mb-3">
        <div class="col-md-4">
            <label class="form-label small mb-1">Search</label>
            <input type="search" name="q" value="<?= $view->e($q) ?>" class="form-control" placeholder="Filename…">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Channel</label>
            <select name="channel" class="form-select">
                <option value="">All channels</option>
                <?php foreach ($channels as $ch): ?>
                    <option value="<?= $view->e($ch) ?>"<?= $channel === $ch ? ' selected' : '' ?>>
                        <?= $view->e($ch) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary">Apply</button>
            <?php if ($q !== '' || $channel !== ''): ?>
                <a class="btn btn-outline-secondary" href="<?= $view->e($view->url->to('tools/admin/logs')) ?>">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($files === []): ?>
        <div class="alert alert-info mb-0">
            No log files found in <code><?= $view->e($logDir) ?></code><?= $q !== '' || $channel !== '' ? ' matching your filters' : '' ?>.
        </div>
    <?php else: ?>
        <?php foreach ($byChannel as $ch => $group): ?>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4 mb-2">
                <h2 class="h5 mb-0">
                    <?= $view->e((string) $ch) ?>
                    <span class="text-muted small">(<?= count($group) ?>)</span>
                </h2>
                <form method="post" action="<?= $view->e($view->url->to('tools/admin/logs/clear')) ?>" class="d-inline"
                      onsubmit="return confirm('Clear all <?= $view->e((string) $ch) ?> log files?');">
                    <?= $view->request->csrfField() ?>
                    <input type="hidden" name="target" value="channel">
                    <input type="hidden" name="channel" value="<?= $view->e((string) $ch) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Clear channel</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Date</th>
                            <th class="text-end">Size</th>
                            <th class="text-end">Errors</th>
                            <th>Modified</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group as $f): ?>
                            <?php $viewUrl = $view->url->to('tools/admin/logs/view', ['file' => $f['name']]); ?>
                            <tr>
                                <td>
                                    <a href="<?= $view->e($viewUrl) ?>" class="fw-semibold text-decoration-none">
                                        <code><?= $view->e((string) $f['name']) ?></code>
                                    </a>
                                </td>
                                <td class="small text-muted"><?= $view->e((string) ($f['date'] !== '' ? $f['date'] : '—')) ?></td>
                                <td class="text-end small text-muted"><?= $view->e(LogsAdmin::formatBytes((int) $f['size'])) ?></td>
                                <td class="text-end small">
                                    <?php if ((int) $f['errors'] > 0): ?>
                                        <span class="badge text-bg-danger"><?= (int) $f['errors'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= date('Y-m-d H:i', (int) $f['mtime']) ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= $view->e($viewUrl) ?>">View</a>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= $view->e($view->url->to('tools/admin/logs/download', ['file' => $f['name']])) ?>">Download</a>
                                    <form method="post" action="<?= $view->e($view->url->to('tools/admin/logs/delete')) ?>" class="d-inline"
                                          onsubmit="return confirm('Delete <?= $view->e((string) $f['name']) ?> permanently?');">
                                        <?= $view->request->csrfField() ?>
                                        <input type="hidden" name="file" value="<?= $view->e((string) $f['name']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
