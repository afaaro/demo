<?php
/** @var System\Library\View $view */
/** @var string $file */
/** @var list<array{raw:string,level:string,time:string,message:string}> $entries */
/** @var int $size */
/** @var int $mtime */
/** @var int $lines */
/** @var string $level */
/** @var string $q */
/** @var array<string, int> $levels */

use ToolsAdminLogs as LogsAdmin;

$levelOptions = [
    ''        => 'All levels',
    'error'   => 'Error and above',
    'warning' => 'Warning and above',
    'info'    => 'Info and above',
    'debug'   => 'Debug',
];
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <a class="small text-muted text-decoration-none" href="<?= $view->e($view->url->to('tools/admin/logs')) ?>">
                ← All logs
            </a>
            <h1 class="h4 mb-1"><code><?= $view->e($file) ?></code></h1>
            <div class="text-muted small">
                <?= $view->e(LogsAdmin::formatBytes($size)) ?>
                · modified <?= date('Y-m-d H:i:s', $mtime) ?>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= $view->e($view->url->to('tools/admin/logs/download', ['file' => $file])) ?>">
                Download
            </a>
            <form method="post" action="<?= $view->e($view->url->to('tools/admin/logs/clear')) ?>" class="d-inline"
                  onsubmit="return confirm('Clear this log file?');">
                <?= $view->request->csrfField() ?>
                <input type="hidden" name="target" value="file">
                <input type="hidden" name="file" value="<?= $view->e($file) ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Clear</button>
            </form>
            <form method="post" action="<?= $view->e($view->url->to('tools/admin/logs/delete')) ?>" class="d-inline"
                  onsubmit="return confirm('Delete this log file permanently?');">
                <?= $view->request->csrfField() ?>
                <input type="hidden" name="file" value="<?= $view->e($file) ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
        </div>
    </div>

    <?php
    $shown = array_filter($levels, static fn($n) => $n > 0);
    if ($shown !== []):
    ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($shown as $lvl => $n): ?>
                <span class="badge text-bg-<?= $view->e(LogsAdmin::levelColor((string) $lvl)) ?>">
                    <?= $view->e(strtoupper((string) $lvl)) ?> <?= (int) $n ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="get" action="<?= $view->e($view->url->to('tools/admin/logs/view')) ?>"
          class="row g-2 align-items-end mb-3">
        <input type="hidden" name="file" value="<?= $view->e($file) ?>">
        <div class="col-md-4">
            <label class="form-label small mb-1">Search</label>
            <input type="search" name="q" class="form-control form-control-sm"
                   value="<?= $view->e($q) ?>" placeholder="substring match…">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Level</label>
            <select name="level" class="form-select form-select-sm">
                <?php foreach ($levelOptions as $val => $label): ?>
                    <option value="<?= $view->e($val) ?>"<?= $level === $val ? ' selected' : '' ?>>
                        <?= $view->e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Lines</label>
            <select name="lines" class="form-select form-select-sm">
                <?php foreach ([200, 500, 1000, 2000, 5000] as $n): ?>
                    <option value="<?= $n ?>"<?= $lines === $n ? ' selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-sm btn-primary">Apply</button>
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= $view->e($view->url->to('tools/admin/logs/view', ['file' => $file])) ?>">Reset</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="log-refresh">Refresh</button>
        </div>
    </form>

    <?php if ($entries === []): ?>
        <div class="alert alert-info mb-0">No matching entries.</div>
    <?php else: ?>
        <div class="small text-muted mb-2">
            Showing <?= count($entries) ?> entr<?= count($entries) === 1 ? 'y' : 'ies' ?> (newest first).
        </div>
        <div class="log-viewer border rounded overflow-auto" style="max-height:70vh; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px; line-height:1.5;">
            <?php foreach ($entries as $i => $entry): ?>
                <?php
                $lvl = $entry['level'];
                $color = LogsAdmin::levelColor($lvl);
                $bg = match ($lvl) {
                    'emergency', 'alert', 'critical', 'error' => 'rgba(220,53,69,.08)',
                    'warning' => 'rgba(255,193,7,.12)',
                    'notice'  => 'rgba(13,110,253,.08)',
                    default   => 'transparent',
                };
                $id = 'log-line-' . $i;
                ?>
                <div class="log-entry px-2 py-1 border-bottom" style="background:<?= $bg ?>; white-space:pre-wrap; word-break:break-word;" id="<?= $id ?>">
                    <span class="badge text-bg-<?= $view->e($color) ?> me-2"><?= $view->e(strtoupper($lvl)) ?></span>
                    <?php if ($entry['time'] !== ''): ?>
                        <span class="text-muted me-2"><?= $view->e($entry['time']) ?></span>
                    <?php endif; ?>
                    <span><?= $view->e($entry['message'] !== '' ? $entry['message'] : $entry['raw']) ?></span>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2 log-copy text-muted" data-target="<?= $id ?>">copy</button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    document.querySelectorAll('.log-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.dataset.target);
            if (!target) return;
            var text = target.innerText.replace(/\s*copy\s*$/, '');
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    var orig = btn.textContent;
                    btn.textContent = 'copied';
                    setTimeout(function () { btn.textContent = orig; }, 1200);
                });
            }
        });
    });
    var refresh = document.getElementById('log-refresh');
    if (refresh) {
        refresh.addEventListener('click', function () { window.location.reload(); });
    }
})();
</script>
