<?php
/** @var System\Library\View $view */
/** @var string $name */
/** @var string $tab */
/** @var array<string, mixed> $status */
/** @var list<array<string, mixed>> $columns */
/** @var list<array<string, mixed>> $indexes */
/** @var list<array<string, mixed>> $rows */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */

use ToolsAdminDatabase as DbAdmin;

$size = (int) ($status['data_length'] ?? 0) + (int) ($status['index_length'] ?? 0);
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <a class="small text-muted text-decoration-none" href="<?= $view->e($view->url->to('tools/admin/database')) ?>">
                ← Database
            </a>
            <h1 class="h3 mb-1"><code><?= $view->e($name) ?></code></h1>
            <div class="text-muted small">
                <?= $view->e((string) ($status['engine'] ?? '')) ?>
                · ~<?= number_format((int) ($status['rows'] ?? 0)) ?> rows
                · <?= $view->e(DbAdmin::formatBytes($size)) ?>
                <?php if (!empty($status['collation'])): ?>
                    · <?= $view->e((string) $status['collation']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <form method="post" action="<?= $view->e($view->url->to('tools/admin/database/export')) ?>" class="d-inline">
                <?= $view->request->csrfField() ?>
                <input type="hidden" name="tables[]" value="<?= $view->e($name) ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-download me-1"></i>Export
                </button>
            </form>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <?php foreach (['structure' => 'Structure', 'indexes' => 'Indexes', 'browse' => 'Browse'] as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link<?= $tab === $key ? ' active' : '' ?>"
                   href="<?= $view->e($view->url->to('tools/admin/database/table', ['name' => $name, 'tab' => $key])) ?>">
                    <?= $view->e($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($tab === 'structure'): ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>Column</th>
                        <th>Type</th>
                        <th>Null</th>
                        <th>Key</th>
                        <th>Default</th>
                        <th>Extra</th>
                        <th>Comment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($columns as $col): ?>
                        <tr>
                            <td><code><?= $view->e((string) ($col['Field'] ?? '')) ?></code></td>
                            <td class="small"><?= $view->e((string) ($col['Type'] ?? '')) ?></td>
                            <td class="small"><?= $view->e((string) ($col['Null'] ?? '')) ?></td>
                            <td class="small"><?= $view->e((string) ($col['Key'] ?? '')) ?></td>
                            <td class="small text-muted">
                                <?php
                                $def = $col['Default'] ?? null;
                                echo $def === null ? '<em>NULL</em>' : $view->e((string) $def);
                                ?>
                            </td>
                            <td class="small text-muted"><?= $view->e((string) ($col['Extra'] ?? '')) ?></td>
                            <td class="small text-muted"><?= $view->e((string) ($col['Comment'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'indexes'): ?>
        <?php if ($indexes === []): ?>
            <div class="alert alert-info">No indexes on this table.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Unique</th>
                            <th>Seq</th>
                            <th>Column</th>
                            <th>Type</th>
                            <th>Cardinality</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($indexes as $idx): ?>
                            <tr>
                                <td><code><?= $view->e((string) ($idx['Key_name'] ?? '')) ?></code></td>
                                <td class="small"><?= ((int) ($idx['Non_unique'] ?? 1)) === 0 ? 'Yes' : 'No' ?></td>
                                <td class="small"><?= (int) ($idx['Seq_in_index'] ?? 0) ?></td>
                                <td><code><?= $view->e((string) ($idx['Column_name'] ?? '')) ?></code></td>
                                <td class="small text-muted"><?= $view->e((string) ($idx['Index_type'] ?? '')) ?></td>
                                <td class="small text-muted"><?= $view->e((string) ($idx['Cardinality'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php else: /* browse */ ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="small text-muted">
                <?= number_format($total) ?> row(s)
                · page <?= (int) $page ?> / <?= (int) $pages ?>
                · <?= (int) $perPage ?> per page
            </div>
            <div class="btn-group btn-group-sm">
                <?php if ($page > 1): ?>
                    <a class="btn btn-outline-secondary"
                       href="<?= $view->e($view->url->to('tools/admin/database/table', ['name' => $name, 'tab' => 'browse', 'page' => $page - 1])) ?>">Prev</a>
                <?php endif; ?>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-outline-secondary"
                       href="<?= $view->e($view->url->to('tools/admin/database/table', ['name' => $name, 'tab' => 'browse', 'page' => $page + 1])) ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($rows === []): ?>
            <div class="alert alert-info mb-0">No rows in this table.</div>
        <?php else: ?>
            <?php $headers = array_keys($rows[0]); ?>
            <div class="table-responsive" style="max-height:70vh;">
                <table class="table table-sm table-striped table-bordered align-middle mb-0" style="font-size:12px;">
                    <thead class="sticky-top bg-body">
                        <tr>
                            <?php foreach ($headers as $h): ?>
                                <th class="text-nowrap"><?= $view->e((string) $h) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($headers as $h): ?>
                                    <?php
                                    $val = $row[$h] ?? null;
                                    if ($val === null) {
                                        $cell = '<span class="text-muted fst-italic">NULL</span>';
                                    } else {
                                        $str = (string) $val;
                                        if (strlen($str) > 200) {
                                            $str = substr($str, 0, 200) . '…';
                                        }
                                        $cell = '<code class="text-break">' . $view->e($str) . '</code>';
                                    }
                                    ?>
                                    <td><?= $cell ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
