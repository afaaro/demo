<?php
/** @var System\Library\View $view */
/** @var string $sql */
/** @var list<array<string, mixed>>|null $result */
/** @var string|null $error */
/** @var float|null $ms */
/** @var int $maxRows */
?>
<div class="container-fluid py-3">
    <div class="mb-3">
        <a class="small text-muted text-decoration-none" href="<?= $view->e($view->url->to('tools/admin/database')) ?>">
            ← Database
        </a>
        <h1 class="h3 mb-1">SQL Query</h1>
        <p class="text-muted mb-0">Read-only: <code>SELECT</code>, <code>SHOW</code>, <code>DESCRIBE</code>, <code>EXPLAIN</code>. Max <?= (int) $maxRows ?> rows.</p>
    </div>

    <form method="post" action="<?= $view->e($view->url->to('tools/admin/database/query')) ?>" class="mb-4">
        <?= $view->request->csrfField() ?>
        <label class="form-label small" for="db-sql">Query</label>
        <textarea id="db-sql" name="sql" rows="8" class="form-control font-monospace mb-2"
                  style="font-size:13px;"
                  placeholder="SELECT * FROM users LIMIT 20"><?= $view->e($sql) ?></textarea>
        <button type="submit" class="btn btn-primary">Run</button>
        <a class="btn btn-outline-secondary" href="<?= $view->e($view->url->to('tools/admin/database/query')) ?>">Clear</a>
    </form>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $view->e($error) ?></div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="small text-muted mb-2">
            <?= count($result) ?> row(s)
            <?php if ($ms !== null): ?>
                · <?= number_format($ms, 1) ?> ms
            <?php endif; ?>
        </div>

        <?php if ($result === []): ?>
            <div class="alert alert-info mb-0">Query returned no rows.</div>
        <?php else: ?>
            <?php $headers = array_keys($result[0]); ?>
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
                        <?php foreach ($result as $row): ?>
                            <tr>
                                <?php foreach ($headers as $h): ?>
                                    <?php
                                    $val = $row[$h] ?? null;
                                    if ($val === null) {
                                        echo '<td><span class="text-muted fst-italic">NULL</span></td>';
                                    } else {
                                        $str = (string) $val;
                                        if (strlen($str) > 300) {
                                            $str = substr($str, 0, 300) . '…';
                                        }
                                        echo '<td><code class="text-break">' . $view->e($str) . '</code></td>';
                                    }
                                    ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
