<?php
/** @var array $category */
/** @var list<array<string,mixed>> $threads */
/** @var \System\Library\Paginator $pager */
/** @var string $q */
/** @var string $sort */
/** @var \System\Library\View $view */
$url = $view->url;
$root = dirname(__DIR__, 2);
$categorySlug = (string) ($category['slug'] ?? '');
$base = array_filter([
    'slug' => $categorySlug,
    'q'    => $q !== '' ? $q : null,
    'sort' => $sort !== 'activity' ? $sort : null,
]);
$sticky = [];
$normal = [];
foreach ($threads as $thread) {
    if ((int) ($thread['is_pinned'] ?? 0) === 1) {
        $sticky[] = $thread;
    } else {
        $normal[] = $thread;
    }
}
?>

<div class="xf">
    <?= $view->partial('nav', ['active' => 'forums', 'postSlug' => $categorySlug, 'q' => $q], $root) ?>

    <nav class="xf-crumbs">
        <a href="<?= $view->e($url->to('forum')) ?>">Forums</a>
        <span>›</span>
        <span><?= $view->e((string) $category['name']) ?></span>
    </nav>

    <div class="xf-pagehead">
        <div>
            <h1><?= $view->e((string) $category['name']) ?></h1>
            <?php if (!empty($category['description'])): ?>
                <p><?= $view->e((string) $category['description']) ?></p>
            <?php endif; ?>
        </div>
        <?php
            $postHref = forum_new_url($categorySlug);
            if (!registry('auth')->check()) {
                $next = $categorySlug !== ''
                    ? 'forum/new/' . ltrim((string) $categorySlug, '/')
                    : 'forum/new';
                $postHref = $url->to('user/login', ['next' => $next]);
            }
        ?>
        <a class="xf-btn" href="<?= $view->e($postHref) ?>">Post thread</a>
    </div>

    <form class="xf-filter" method="get" action="<?= $view->e($url->to('forum/category', ['slug' => $categorySlug])) ?>">
        <input type="search" name="q" value="<?= $view->e($q) ?>" placeholder="Filter this forum…">
        <select name="sort">
            <?php foreach (['activity' => 'Last activity', 'newest' => 'Newest', 'replies' => 'Most replies', 'views' => 'Most views'] as $val => $label): ?>
                <option value="<?= $view->e($val) ?>"<?= $sort === $val ? ' selected' : '' ?>><?= $view->e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Apply</button>
    </form>

    <?php
    $renderThread = static function (array $thread) use ($url, $view): void {
        $id = (int) $thread['id'];
        $href = $url->to('forum/thread', ['id' => $id]);
        $unread = forum_is_unread($thread);
        $locked = (int) ($thread['is_locked'] ?? 0) === 1;
        $pinned = (int) ($thread['is_pinned'] ?? 0) === 1;
        $replies = max(0, (int) ($thread['post_count'] ?? 1) - 1);
        $poster = (string) ($thread['last_poster'] ?? '—');
        echo '<div class="xf-thread' . ($unread ? ' is-unread' : '') . ($pinned ? ' is-sticky' : '') . '">';
        echo '<div class="xf-thread__icon' . ($unread ? ' is-unread' : '') . '" aria-hidden="true">';
        echo '<i class="bi ' . ($locked ? 'bi-lock-fill' : ($pinned ? 'bi-pin-angle-fill' : 'bi-chat-left-text')) . '"></i></div>';
        echo '<div class="xf-thread__main">';
        echo '<a class="xf-thread__title" href="' . $view->e($href) . '">';
        if ($pinned) {
            echo '<span class="xf-tag xf-tag--sticky">Sticky</span>';
        }
        if ($locked) {
            echo '<span class="xf-tag">Locked</span>';
        }
        echo $view->e((string) $thread['title']) . '</a>';
        echo '<div class="xf-meta">Started by ' . $view->e((string) ($thread['author_name'] ?? '—')) . '</div>';
        echo '</div><div class="xf-thread__stats">';
        echo '<dl><dt>Replies</dt><dd>' . $view->e(forum_count($replies)) . '</dd></dl>';
        echo '<dl><dt>Views</dt><dd>' . $view->e(forum_count((int) ($thread['view_count'] ?? 0))) . '</dd></dl>';
        echo '</div><div class="xf-thread__last">';
        echo forum_avatar($poster, 's');
        echo '<div><a href="' . $view->e($href) . '">' . $view->e($poster) . '</a>';
        echo '<div class="xf-meta">' . $view->e(forum_when($thread['last_post_at'] ?? null)) . '</div></div></div></div>';
    };
    ?>

    <?php if ($threads === []): ?>
        <div class="xf-empty"><?= $q !== '' ? 'No threads match that filter.' : 'There are no threads in this forum yet.' ?></div>
    <?php else: ?>
        <?php if ($sticky !== []): ?>
            <section class="xf-block">
                <header class="xf-block__bar">
                    <h2>Sticky threads</h2>
                    <div class="xf-block__cols xf-block__cols--thread">
                        <span>Replies</span><span>Views</span><span class="xf-block__last">Last message</span>
                    </div>
                </header>
                <?php foreach ($sticky as $thread) { $renderThread($thread); } ?>
            </section>
        <?php endif; ?>

        <section class="xf-block">
            <header class="xf-block__bar">
                <h2>Normal threads</h2>
                <div class="xf-block__cols xf-block__cols--thread">
                    <span>Replies</span><span>Views</span><span class="xf-block__last">Last message</span>
                </div>
            </header>
            <?php if ($normal === []): ?>
                <div class="xf-empty">No other threads.</div>
            <?php else: ?>
                <?php foreach ($normal as $thread) { $renderThread($thread); } ?>
            <?php endif; ?>
        </section>

        <div class="xf-pager">
            <?= $pager->render(
                static fn(int $p): string => $url->to(
                    'forum/category',
                    array_filter($base + ['page' => $p > 1 ? $p : null])
                ),
                ['theme' => 'bootstrap']
            ) ?>
        </div>
    <?php endif; ?>
</div>
