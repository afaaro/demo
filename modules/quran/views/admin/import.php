<?php
/** @var array{surahs:int,ayahs:int,translations:int,reciters:int} $stats */
/** @var array<string,int> $files */
?>
<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Quran Import</h1>
        <a href="<?= escape(url('quran')) ?>" class="btn btn-outline-secondary" target="_blank" rel="noopener">Open reader</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Surahs</div><div class="fs-3 fw-bold"><?= (int) $stats['surahs'] ?> / 114</div></div></div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Ayahs</div><div class="fs-3 fw-bold"><?= (int) $stats['ayahs'] ?> / 6236</div></div></div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Translations</div><div class="fs-3 fw-bold"><?= (int) $stats['translations'] ?></div></div></div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Reciters</div><div class="fs-3 fw-bold"><?= (int) $stats['reciters'] ?></div></div></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5">Bundled Tanzil files</h2>
            <p class="text-muted">Official Tanzil text is imported verbatim (CC-BY 3.0). Do not edit ayah wording.</p>
            <ul class="mb-3">
                <?php foreach ($files as $name => $size): ?>
                    <li>
                        <code><?= escape($name) ?></code>
                        <?php if ($size > 0): ?>
                            — <?= number_format($size / 1024, 1) ?> KB
                        <?php else: ?>
                            — <span class="text-danger">missing</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?= escape(url('quran/admin/import/run')) ?>">
                <?= $this->request->csrfField() ?>
                <button type="submit" class="btn btn-primary">Import Tanzil text + bundled translations</button>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h2 class="h5">Upload a translation</h2>
            <p class="text-muted mb-3">Tanzil <code>sura|aya|text</code> UTF-8 file. Keep the copyright header in the file.</p>
            <form method="post" action="<?= escape(url('quran/admin/import/upload')) ?>" enctype="multipart/form-data" class="row g-3">
                <?= $this->request->csrfField() ?>
                <div class="col-md-3">
                    <label class="form-label" for="tr-code">Code</label>
                    <input class="form-control" id="tr-code" name="code" required placeholder="en.pickthall">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="tr-name">Name</label>
                    <input class="form-control" id="tr-name" name="name" required placeholder="Pickthall">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="tr-author">Author</label>
                    <input class="form-control" id="tr-author" name="author" placeholder="Mohammed Marmaduke Pickthall">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="tr-lang">Language</label>
                    <input class="form-control" id="tr-lang" name="language" required maxlength="8" placeholder="en">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="tr-file">Tanzil .txt</label>
                    <input class="form-control" id="tr-file" type="file" name="file" accept=".txt,text/plain" required>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary">Import translation</button>
                </div>
            </form>
        </div>
    </div>
</div>
