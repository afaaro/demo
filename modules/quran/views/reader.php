<?php
/** @var array $view */
/** @var array $surahs */
/** @var array $translations */
/** @var array $reciters */
/** @var array $player */

$mode = $view['mode'];
$current = (int) $view['current'];
$highlight = (int) $view['highlight'];
$neighbors = $view['neighbors'];
$ayahs = $view['ayahs'];

$prevUrl = null;
$nextUrl = null;
if ($neighbors['prev']) {
    $prevUrl = $mode === 'sura'
        ? url('quran', ['sura' => $neighbors['prev'], 'aya' => 0])
        : url('quran/' . $mode, ['n' => $neighbors['prev']]);
}
if ($neighbors['next']) {
    $nextUrl = $mode === 'sura'
        ? url('quran', ['sura' => $neighbors['next'], 'aya' => 0])
        : url('quran/' . $mode, ['n' => $neighbors['next']]);
}

$suraFormAction = url('quran', ['sura' => $mode === 'sura' ? $current : 1, 'aya' => 0]);
$juzFormAction  = url('quran/juz', ['n' => $mode === 'juz' ? $current : 1]);
$pageFormAction = url('quran/page', ['n' => $mode === 'page' ? $current : 1]);
$quranBase = (string) preg_replace('#/1/0$#', '', url('quran', ['sura' => 1, 'aya' => 0]));

$modeLabel = match ($mode) {
    'juz'  => 'Juz',
    'page' => 'Page',
    default => 'Surah',
};
?>
<div class="quran" data-quran-root data-quran-base="<?= escape($quranBase) ?>">
    <div class="quran-shell">
        <header class="quran-chrome">
            <div class="quran-chrome__nav" role="navigation" aria-label="Navigate Mushaf">
                <form class="quran-jump" method="get" action="<?= escape($suraFormAction) ?>" data-quran-jump>
                    <label class="quran-field">
                        <span>Surah</span>
                        <select name="sura" data-quran-sura>
                            <?php foreach ($surahs as $s): ?>
                                <option value="<?= (int) $s['number'] ?>" <?= $mode === 'sura' && $current === (int) $s['number'] ? 'selected' : '' ?>>
                                    <?= (int) $s['number'] ?>. <?= escape($s['name_so']) ?> — <?= escape($s['name_latin']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="quran-field quran-field--narrow">
                        <span>Ayah</span>
                        <select name="aya" data-quran-aya>
                            <option value="0">All</option>
                            <?php if ($mode === 'sura' && $view['surah']): ?>
                                <?php for ($i = 1; $i <= (int) $view['surah']['ayah_count']; $i++): ?>
                                    <option value="<?= $i ?>" <?= $highlight === $i ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </select>
                    </label>
                </form>

                <form class="quran-jump" method="get" action="<?= escape($juzFormAction) ?>">
                    <label class="quran-field quran-field--narrow">
                        <span>Juz</span>
                        <select name="n" data-quran-juz>
                            <?php for ($i = 1; $i <= 30; $i++): ?>
                                <option value="<?= $i ?>" <?= $mode === 'juz' && $current === $i ? 'selected' : '' ?>><?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </form>

                <form class="quran-jump" method="get" action="<?= escape($pageFormAction) ?>">
                    <label class="quran-field quran-field--narrow">
                        <span>Page</span>
                        <select name="n" data-quran-page>
                            <?php for ($i = 1; $i <= 604; $i++): ?>
                                <option value="<?= $i ?>" <?= $mode === 'page' && $current === $i ? 'selected' : '' ?>><?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </form>

                <form class="quran-search" method="get" action="<?= escape(url('quran/search')) ?>">
                    <label class="quran-field quran-field--grow">
                        <span>Search</span>
                        <input type="search" name="q" placeholder="Arabic or translation" maxlength="200">
                    </label>
                    <button type="submit" class="quran-btn quran-btn--ghost">Search</button>
                </form>
            </div>

            <div class="quran-chrome__prefs" data-quran-settings>
                <label class="quran-check">
                    <input type="checkbox" data-quran-toggle-trans checked>
                    Translations
                </label>
                <div class="quran-trans-picks" data-quran-trans-picks>
                    <?php foreach ($translations as $tr): ?>
                        <label class="quran-check">
                            <input type="checkbox" value="<?= (int) $tr['id'] ?>" data-quran-trans-id checked>
                            <?= escape($tr['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <label class="quran-field quran-field--size">
                    <span>Arabic size</span>
                    <input type="range" min="24" max="48" value="34" data-quran-font>
                </label>
            </div>
        </header>

        <section class="quran-masthead" aria-label="<?= escape($modeLabel) ?> heading">
            <p class="quran-masthead__eyebrow"><?= escape($modeLabel) ?></p>
            <?php if ($view['heading_ar'] !== ''): ?>
                <p class="quran-masthead__ar" lang="ar" dir="rtl"><?= escape($view['heading_ar']) ?></p>
            <?php endif; ?>
            <h1 class="quran-masthead__title"><?= escape($view['heading']) ?></h1>
            <p class="quran-masthead__meta"><?= escape($view['meta']) ?></p>
            <div class="quran-masthead__ornament" aria-hidden="true"></div>
        </section>

        <ol class="quran-ayahs" dir="rtl" lang="ar" data-quran-ayahs>
            <?php foreach ($ayahs as $i => $ayah): ?>
                <?php
                    $isCurrent = $mode === 'sura' && $highlight === (int) $ayah['ayah'];
                    $ayahUrl = url('quran', ['sura' => (int) $ayah['surah_id'], 'aya' => (int) $ayah['ayah']]);
                ?>
                <li class="quran-ayah<?= $isCurrent ? ' is-current' : '' ?>"
                    id="ayah-<?= (int) $ayah['surah_id'] ?>-<?= (int) $ayah['ayah'] ?>"
                    style="--ayah-i: <?= (int) $i ?>"
                    data-ayah-id="<?= (int) $ayah['id'] ?>"
                    data-surah="<?= (int) $ayah['surah_id'] ?>"
                    data-ayah="<?= (int) $ayah['ayah'] ?>">
                    <div class="quran-ayah__arabic">
                        <span class="quran-ayah__text"><?= escape($ayah['text_uthmani']) ?></span>
                        <a class="quran-ayah__num" href="<?= escape($ayahUrl) ?>" aria-label="Ayah <?= (int) $ayah['surah_id'] ?>:<?= (int) $ayah['ayah'] ?>">
                            <?= (int) $ayah['ayah'] ?>
                        </a>
                        <?php if ($ayah['sajdah'] !== ''): ?>
                            <span class="quran-sajdah" title="Sajdah <?= escape($ayah['sajdah']) ?>">۩</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($ayah['translations'])): ?>
                        <div class="quran-ayah__trans" dir="ltr">
                            <div class="quran-ayah__ref"><?= (int) $ayah['surah_id'] ?>:<?= (int) $ayah['ayah'] ?></div>
                            <?php foreach ($ayah['translations'] as $tr): ?>
                                <p class="quran-tr" data-translation-id="<?= (int) $tr['translation_id'] ?>" lang="<?= escape($tr['language']) ?>">
                                    <span class="quran-tr__name"><?= escape($tr['name']) ?></span>
                                    <?= escape($tr['text']) ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>

        <nav class="quran-pager" aria-label="Browse">
            <?php if ($prevUrl): ?>
                <a class="quran-btn quran-btn--ghost" href="<?= escape($prevUrl) ?>">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Previous
                </a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            <?php if ($nextUrl): ?>
                <a class="quran-btn quran-btn--ghost" href="<?= escape($nextUrl) ?>">
                    Next <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </nav>

        <p class="quran-credit">
            Text from the <a href="https://tanzil.net/" rel="noopener noreferrer">Tanzil Project</a>
            · Recitation via EveryAyah
        </p>
    </div>

    <footer class="quran-player" data-quran-player>
        <div class="quran-player__transport">
            <button type="button" class="quran-icon-btn" data-quran-prev aria-label="Previous ayah">
                <i class="bi bi-skip-start-fill" aria-hidden="true"></i>
            </button>
            <button type="button" class="quran-icon-btn quran-icon-btn--play" data-quran-play aria-label="Play">
                <i class="bi bi-play-fill" aria-hidden="true"></i>
                <span data-quran-play-label>Play</span>
            </button>
            <button type="button" class="quran-icon-btn" data-quran-next aria-label="Next ayah">
                <i class="bi bi-skip-end-fill" aria-hidden="true"></i>
            </button>
        </div>
        <p class="quran-now" data-quran-now>Ready</p>
        <div class="quran-player__opts">
            <label class="quran-field quran-field--inline">
                <span>Reciter</span>
                <select data-quran-reciter>
                    <?php foreach ($reciters as $reciter): ?>
                        <option value="<?= (int) $reciter['id'] ?>"><?= escape($reciter['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="quran-field quran-field--inline">
                <span>Repeat</span>
                <select data-quran-repeat>
                    <option value="off">Off</option>
                    <option value="ayah">Ayah</option>
                    <option value="range">This view</option>
                </select>
            </label>
            <label class="quran-field quran-field--inline">
                <span>Speed</span>
                <select data-quran-speed>
                    <option value="0.75">0.75×</option>
                    <option value="1" selected>1×</option>
                    <option value="1.25">1.25×</option>
                    <option value="1.5">1.5×</option>
                </select>
            </label>
        </div>
    </footer>
</div>
<script type="application/json" id="quran-player-data"><?= json_encode($player, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
