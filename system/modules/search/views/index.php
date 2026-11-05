<?php
$q = (string) ($q ?? '');
$groups = $groups ?? [];
$total = (int) ($total ?? 0);
?>
<div class="site-search">
    <div class="site-search__head">
        <h1>Search</h1>
        <form class="site-search__form" method="get" action="<?= escape(url('search')) ?>" role="search">
            <input type="search"
                   name="q"
                   value="<?= escape($q) ?>"
                   placeholder="Search the site…"
                   aria-label="Search the site"
                   autofocus>
            <button type="submit">Search</button>
        </form>
    </div>

    <?php if ($q === ''): ?>
        <p class="site-search__empty">Type a word or phrase to search news, store, Quran, media, and pages.</p>
    <?php elseif ($groups === []): ?>
        <p class="site-search__empty">No results for “<?= escape($q) ?>”.</p>
    <?php else: ?>
        <p class="site-search__count"><?= $total ?> result<?= $total === 1 ? '' : 's' ?> for “<?= escape($q) ?>”</p>

        <?php foreach ($groups as $group): ?>
            <section class="site-search__group" aria-labelledby="search-<?= escape($group['module']) ?>">
                <div class="site-search__group-head">
                    <h2 id="search-<?= escape($group['module']) ?>"><?= escape($group['label']) ?></h2>
                    <?php if ($group['more'] !== ''): ?>
                        <a class="site-search__more" href="<?= escape($group['more']) ?>">More in <?= escape($group['label']) ?></a>
                    <?php endif; ?>
                </div>

                <div class="site-search__hits">
                    <?php foreach ($group['hits'] as $hit): ?>
                        <article class="site-search__hit<?= $hit['image'] === '' ? ' is-text' : '' ?>">
                            <a href="<?= escape($hit['url']) ?>">
                                <?php if ($hit['image'] !== ''): ?>
                                    <div class="site-search__media">
                                        <img src="<?= escape($hit['image']) ?>" alt="" loading="lazy">
                                    </div>
                                <?php endif; ?>
                                <div class="site-search__body">
                                    <?php if ($hit['meta'] !== ''): ?>
                                        <p class="site-search__meta"><?= escape($hit['meta']) ?></p>
                                    <?php endif; ?>
                                    <h3><?= escape($hit['title']) ?></h3>
                                    <?php if ($hit['snippet'] !== ''): ?>
                                        <p class="site-search__snippet"><?= escape($hit['snippet']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
