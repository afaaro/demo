<?php
/** @var string $q */
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var \System\Library\Paginator $pager */
/** @var \System\Library\View $view */
$url = $view->url;
$root = dirname(__DIR__, 2);
?>

<div class="xf">
    <?= $view->partial('nav', ['active' => 'search', 'q' => $q], $root) ?>

    <nav class="xf-crumbs">
        <a href="<?= $view->e($url->to('forum')) ?>">Forums</a>
        <span>›</span>
        <span>Search</span>
    </nav>

    <section class="xf-block">
        <header class="xf-block__bar"><h1>Search forums</h1></header>
        <form class="xf-reply__form" method="get" action="<?= $view->e($url->to('forum/search')) ?>">
            <input type="search" name="q" value="<?= $view->e($q) ?>" placeholder="Search titles and messages…" autofocus>
            <button type="submit" class="xf-btn">Search</button>
        </form>
    </section>

    <?php if ($q !== '' && mb_strlen($q) < 2): ?>
        <div class="xf-empty">Type at least two characters.</div>
    <?php elseif ($q !== '' && $items === []): ?>
        <div class="xf-empty">No threads matched “<?= $view->e($q) ?>”.</div>
    <?php elseif ($items !== []): ?>
        <section class="xf-block">
            <header class="xf-block__bar">
                <h2><?= (int) $total ?> result<?= (int) $total === 1 ? '' : 's' ?></h2>
                <div class="xf-block__cols xf-block__cols--thread">
                    <span>Replies</span><span>Views</span><span class="xf-block__last">Last message</span>
                </div>
            </header>
            <?php foreach ($items as $thread): ?>
                <?php
                    $id = (int) $thread['id'];
                    $href = $url->to('forum/thread', ['id' => $id]);
                    $replies = max(0, (int) ($thread['post_count'] ?? 1) - 1);
                    $poster = (string) ($thread['last_poster'] ?? '');
                ?>
                <div class="xf-thread<?= forum_is_unread($thread) ? ' is-unread' : '' ?>">
                    <div class="xf-thread__icon" aria-hidden="true"><i class="bi bi-chat-left-text"></i></div>
                    <div class="xf-thread__main">
                        <a class="xf-thread__title" href="<?= $view->e($href) ?>"><?= $view->e((string) $thread['title']) ?></a>
                        <div class="xf-meta">
                            in <a href="<?= $view->e($url->to('forum/category', ['slug' => (string) $thread['category_slug']])) ?>">
                                <?= $view->e((string) $thread['category_name']) ?></a>
                        </div>
                    </div>
                    <div class="xf-thread__stats">
                        <dl><dt>Replies</dt><dd><?= $view->e(forum_count($replies)) ?></dd></dl>
                        <dl><dt>Views</dt><dd><?= $view->e(forum_count((int) ($thread['view_count'] ?? 0))) ?></dd></dl>
                    </div>
                    <div class="xf-thread__last">
                        <?= forum_avatar($poster, 's') ?>
                        <div>
                            <a href="<?= $view->e($href) ?>"><?= $view->e($poster) ?></a>
                            <div class="xf-meta"><?= $view->e(forum_when($thread['last_post_at'] ?? null)) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
        <div class="xf-pager">
            <?= $pager->render(
                static fn(int $p): string => $url->to('forum/search', array_filter([
                    'q'    => $q,
                    'page' => $p > 1 ? $p : null,
                ])),
                ['theme' => 'bootstrap']
            ) ?>
        </div>
    <?php endif; ?>
</div>
