<?php
/** @var System\Library\View $view */
/** @var list<array<string, mixed>> $tables */
/** @var array<string, mixed> $info */
/** @var array<string, mixed> $health */
/** @var string $q */
/** @var int $totalRows */
/** @var int $totalBytes */

use ToolsAdminDatabase as DbAdmin;
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Database</h1>
            <p class="text-muted mb-0">Inspect tables, run migrations, maintain and export the database.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?= $view->e($view->url->to('tools/admin/database/query')) ?>">
                <i class="bi bi-terminal me-1"></i>SQL query
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <h2 class="h6 text-uppercase text-muted mb-3">Connection</h2>
                <dl class="row small mb-0">
                    <dt class="col-5">Host</dt><dd class="col-7"><?= $view->e((string) $info['hostname']) ?></dd>
                    <dt class="col-5">Database</dt><dd class="col-7"><code><?= $view->e((string) $info['database']) ?></code></dd>
                    <dt class="col-5">User</dt><dd class="col-7"><?= $view->e((string) $info['username']) ?></dd>
                    <dt class="col-5">Prefix</dt><dd class="col-7"><?= $info['prefix'] !== '' ? '<code>' . $view->e((string) $info['prefix']) . '</code>' : '<span class="text-muted">none</span>' ?></dd>
                    <dt class="col-5">Charset</dt><dd class="col-7"><?= $view->e((string) $info['charset']) ?></dd>
                    <dt class="col-5">Server</dt><dd class="col-7"><?= $view->e((string) $info['version']) ?></dd>
                </dl>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <h2 class="h6 text-uppercase text-muted mb-3">Overview</h2>
                <div class="d-flex flex-wrap gap-3">
                    <div>
                        <div class="fs-4 fw-semibold"><?= count($tables) ?></div>
                        <div class="small text-muted">Tables</div>
                    </div>
                    <div>
                        <div class="fs-4 fw-semibold"><?= number_format($totalRows) ?></div>
                        <div class="small text-muted">~ Rows</div>
                    </div>
                    <div>
                        <div class="fs-4 fw-semibold"><?= $view->e(DbAdmin::formatBytes($totalBytes)) ?></div>
                        <div class="small text-muted">Size</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <h2 class="h6 text-uppercase text-muted mb-3">Migrations</h2>
                <div class="mb-2">
                    <span class="badge text-bg-success"><?= (int) $health['applied'] ?> applied</span>
                    <?php if ((int) $health['pending'] > 0): ?>
                        <span class="badge text-bg-warning"><?= (int) $health['pending'] ?> pending</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">up to date</span>
                    <?php endif; ?>
                    <span class="small text-muted ms-1">batch <?= (int) $health['last_batch'] ?></span>
                </div>
                <?php if (!empty($health['pending_keys'])): ?>
                    <ul class="small text-muted mb-3 ps-3" style="max-height:5rem;overflow:auto;">
                        <?php foreach (array_slice($health['pending_keys'], 0, 8) as $key): ?>
                            <li><code><?= $view->e((string) $key) ?></code></li>
                        <?php endforeach; ?>
                        <?php if (count($health['pending_keys']) > 8): ?>
                            <li>… +<?= count($health['pending_keys']) - 8 ?> more</li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" action="<?= $view->e($view->url->to('tools/admin/database/migrate')) ?>" class="d-inline">
                        <?= $view->request->csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-primary"<?= (int) $health['pending'] === 0 ? ' disabled' : '' ?>>
                            Run pending
                        </button>
                    </form>
                    <form method="post" action="<?= $view->e($view->url->to('tools/admin/database/stamp')) ?>" class="d-inline"
                          onsubmit="return confirm('Mark ALL pending migrations as applied without running SQL?\n\nUse this when you imported the schema manually.');">
                        <?= $view->request->csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-outline-secondary"<?= (int) $health['pending'] === 0 ? ' disabled' : '' ?>>
                            Mark pending as applied
                        </button>
                    </form>
                    <form method="post" action="<?= $view->e($view->url->to('tools/admin/database/rollback')) ?>" class="d-inline"
                          onsubmit="return confirm('Roll back the last migration batch?');">
                        <?= $view->request->csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-outline-warning"<?= (int) $health['last_batch'] === 0 ? ' disabled' : '' ?>>
                            Rollback last batch
                        </button>
                    </form>
                </div>
                <?php if ((int) $health['pending'] > 0): ?>
                    <p class="small text-muted mb-0 mt-2">
                        Imported schema manually? Use <strong>Mark pending as applied</strong> instead of Run pending.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <form method="get" action="<?= $view->e($view->url->to('tools/admin/database')) ?>" class="row g-2 align-items-end mb-3">
        <div class="col-md-5">
            <label class="form-label small mb-1">Search tables</label>
            <input type="search" name="q" value="<?= $view->e($q) ?>" class="form-control" placeholder="Table name…">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary">Apply</button>
            <?php if ($q !== ''): ?>
                <a class="btn btn-outline-secondary" href="<?= $view->e($view->url->to('tools/admin/database')) ?>">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($tables === []): ?>
        <div class="alert alert-info mb-0">No tables found<?= $q !== '' ? ' matching your search' : '' ?>.</div>
    <?php else: ?>
        <form method="post" id="db-tables-form">
            <?= $view->request->csrfField() ?>

            <div class="d-flex flex-wrap gap-2 mb-2">
                <button type="submit" formaction="<?= $view->e($view->url->to('tools/admin/database/maintain')) ?>"
                        name="op" value="optimize" class="btn btn-sm btn-outline-secondary"
                        onclick="return confirm('OPTIMIZE selected tables?');">Optimize</button>
                <button type="submit" formaction="<?= $view->e($view->url->to('tools/admin/database/maintain')) ?>"
                        name="op" value="analyze" class="btn btn-sm btn-outline-secondary">Analyze</button>
                <button type="submit" formaction="<?= $view->e($view->url->to('tools/admin/database/maintain')) ?>"
                        name="op" value="check" class="btn btn-sm btn-outline-secondary">Check</button>
                <button type="submit" formaction="<?= $view->e($view->url->to('tools/admin/database/export')) ?>"
                        class="btn btn-sm btn-outline-primary"
                        onclick="return confirm('Download SQL export for selected tables (or all if none selected)?');">
                    <i class="bi bi-download me-1"></i>Export SQL
                </button>
                <span class="small text-muted align-self-center ms-1">Select tables for actions; export with none selected dumps everything.</span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width:2rem">
                                <input type="checkbox" class="form-check-input" id="db-check-all"
                                       onclick="document.querySelectorAll('.db-table-cb').forEach(c=>c.checked=this.checked)">
                            </th>
                            <th>Table</th>
                            <th>Engine</th>
                            <th class="text-end">Rows</th>
                            <th class="text-end">Size</th>
                            <th>Collation</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tables as $t): ?>
                            <?php
                            $size = (int) $t['data_length'] + (int) $t['index_length'];
                            $tableUrl = $view->url->to('tools/admin/database/table', ['name' => $t['name']]);
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input db-table-cb"
                                           name="tables[]" value="<?= $view->e((string) $t['name']) ?>">
                                </td>
                                <td>
                                    <a href="<?= $view->e($tableUrl) ?>" class="fw-semibold text-decoration-none">
                                        <?= $view->e((string) $t['name']) ?>
                                    </a>
                                    <?php if ($t['comment'] !== ''): ?>
                                        <div class="small text-muted"><?= $view->e((string) $t['comment']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= $view->e((string) $t['engine']) ?></td>
                                <td class="text-end small"><?= number_format((int) $t['rows']) ?></td>
                                <td class="text-end small text-muted"><?= $view->e(DbAdmin::formatBytes($size)) ?></td>
                                <td class="small text-muted"><?= $view->e((string) $t['collation']) ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= $view->e($tableUrl) ?>">Structure</a>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= $view->e($view->url->to('tools/admin/database/table', ['name' => $t['name'], 'tab' => 'browse'])) ?>">Browse</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
</div>
