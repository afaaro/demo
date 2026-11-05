<?php
/** @var list<array<string,mixed>> $items */
/** @var \System\Library\View $view */
$url = $view->url;
$root = dirname(__DIR__, 2);
?>

<div class="xf">
    <?= $view->partial('nav', ['active' => 'new'], $root) ?>

    <nav class="xf-crumbs">
        <a href="<?= $view->e($url->to('forum')) ?>">Forums</a>
        <span>›</span>
        <span>What's new</span>
    </nav>

    <section class="xf-block">
        <header class="xf-block__bar">
            <h1>New posts</h1>
            <div class="xf-block__cols xf-block__cols--thread">
                <span>Replies</span>
                <span>Views</span>
                <span class="xf-block__last">Last message</span>
            </div>
        </header>
        <?php if ($items === []): ?>
            <div class="xf-empty">No recent threads.</div>
        <?php else: ?>
            <?php foreach ($items as $thread): ?>
                <?php
                    $id = (int) $thread['id'];
                    $href = $url->to('forum/thread', ['id' => $id]);
                    $unread = forum_is_unread($thread);
                    $replies = max(0, (int) ($thread['post_count'] ?? 1) - 1);
                    $poster = (string) ($thread['last_poster'] ?? '—');
                ?>
                <div class="xf-thread<?= $unread ? ' is-unread' : '' ?>">
                    <div class="xf-thread__icon<?= $unread ? ' is-unread' : '' ?>" aria-hidden="true">
                        <i class="bi <?= !empty($thread['is_locked']) ? 'bi-lock-fill' : 'bi-chat-left-text' ?>"></i>
                    </div>
                    <div class="xf-thread__main">
                        <a class="xf-thread__title" href="<?= $view->e($href) ?>">
                            <?= $view->e((string) $thread['title']) ?>
                        </a>
                        <div class="xf-meta">
                            Started by <?= $view->e((string) ($thread['author_name'] ?? '—')) ?>
                            · in <a href="<?= $view->e($url->to('forum/category', ['slug' => (string) $thread['category_slug']])) ?>">
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
        <?php endif; ?>
    </section>
</div>
