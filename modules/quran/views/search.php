<?php
/** @var string $q */
/** @var array $results */
?>
<div class="quran quran--search">
    <header class="quran-toolbar">
        <a class="quran-btn" href="<?= escape(url('quran', ['sura' => 1, 'aya' => 0])) ?>">Back to reader</a>
        <form class="quran-search" method="get" action="<?= escape(url('quran/search')) ?>">
            <label class="quran-field quran-field--grow">
                <span>Search</span>
                <input type="search" name="q" value="<?= escape($q) ?>" placeholder="Arabic (without tashkeel) or translation" maxlength="200" autofocus>
            </label>
            <button type="submit" class="quran-btn">Search</button>
        </form>
    </header>

    <?php if ($q === ''): ?>
        <p class="quran-empty">Type an Arabic word without tashkeel, or a phrase from the Somali or English translation.</p>
    <?php elseif ($results === []): ?>
        <p class="quran-empty">No ayahs matched “<?= escape($q) ?>”.</p>
    <?php else: ?>
        <p class="quran-heading__meta"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?> for “<?= escape($q) ?>”</p>
        <ol class="quran-results">
            <?php foreach ($results as $hit): ?>
                <li>
                    <a href="<?= escape(url('quran', ['sura' => (int) $hit['surah_id'], 'aya' => (int) $hit['ayah']])) ?>">
                        <strong><?= (int) $hit['surah_id'] ?>:<?= (int) $hit['ayah'] ?></strong>
                        <?= escape($hit['name_so']) ?> — <?= escape($hit['name_latin']) ?>
                    </a>
                    <p class="quran-results__ar" lang="ar" dir="rtl"><?= escape($hit['text_uthmani']) ?></p>
                    <?php if (!empty($hit['snippet'])): ?>
                        <p class="quran-results__sn"><?= escape($hit['snippet']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <p class="quran-credit">
        Quran text: <a href="https://tanzil.net/" rel="noopener noreferrer">Tanzil Project</a>
        (verbatim, not modified). Translations listed with author names.
    </p>
</div>
