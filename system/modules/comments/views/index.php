<?php
/** @var list<array<string,string|int>> $items */
/** @var string $module */
$items = $items ?? [];
$module = (string) ($module ?? '');
?>
<div class="site-comments-hub">
    <div class="site-comments-hub__head">
        <h1>Comments</h1>
        <div class="site-comments-hub__filters">
            <a class="<?= $module === '' ? 'is-active' : '' ?>" href="<?= escape(url('comments')) ?>">All</a>
            <a class="<?= $module === 'media' ? 'is-active' : '' ?>" href="<?= escape(url('comments', ['module' => 'media'])) ?>">Media</a>
            <a class="<?= $module === 'news' ? 'is-active' : '' ?>" href="<?= escape(url('comments', ['module' => 'news'])) ?>">News</a>
        </div>
    </div>

    <?php if ($items === []): ?>
        <p class="site-comments-hub__empty">No comments yet.</p>
    <?php else: ?>
        <div class="site-comments-hub__feed">
            <?php foreach ($items as $item): ?>
                <article class="site-comments-hub__card">
                    <header>
                        <strong><?= escape((string) $item['username']) ?></strong>
                        <time><?= escape((string) $item['when']) ?></time>
                        <span class="site-comments-hub__mod"><?= escape((string) $item['module']) ?></span>
                    </header>
                    <p><?= escape((string) $item['body']) ?></p>
                    <a class="site-comments-hub__link" href="<?= escape((string) $item['url']) ?>">
                        On: <?= escape((string) $item['title']) ?>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
