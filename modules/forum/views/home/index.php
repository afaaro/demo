<?php
/** @var list<array<string,mixed>> $categories */
/** @var array<int,int> $unreadByCat */
/** @var list<array<string,mixed>> $latest */
/** @var \System\Library\View $view */
$url = $view->url;
$root = dirname(__DIR__, 2);
?>

<div class="xf">
    <?= $view->partial('nav', ['active' => 'forums'], $root) ?>

    <?php if ($categories === []): ?>
        <div class="xf-empty">No forums yet. An administrator needs to create one.</div>
    <?php else: ?>
        <section class="xf-block">
            <header class="xf-block__bar">
                <h1>Forums</h1>
                <div class="xf-block__cols">
                    <span>Threads</span>
                    <span>Messages</span>
                    <span class="xf-block__last">Last message</span>
                </div>
            </header>
            <?php foreach ($categories as $cat): ?>
                <?php
                    $href   = $url->to('forum/category', ['slug' => (string) $cat['slug']]);
                    $unread = (int) ($unreadByCat[(int) $cat['id']] ?? 0);
                    $lastId = (int) ($cat['last_thread_id'] ?? 0);
                    $poster = forum_user_label($cat, 'last_poster', 'last_poster');
                ?>
                <div class="xf-node<?= $unread > 0 ? ' is-unread' : '' ?>">
                    <a class="xf-node__icon<?= $unread > 0 ? ' is-unread' : '' ?>" href="<?= $view->e($href) ?>" aria-hidden="true">
                        <i class="bi <?= $unread > 0 ? 'bi-chat-square-text-fill' : 'bi-chat-square-text' ?>"></i>
                    </a>
                    <div class="xf-node__main">
                        <a class="xf-node__title" href="<?= $view->e($href) ?>">
                            <?= $view->e((string) $cat['name']) ?>
                        </a>
                        <?php if (!empty($cat['description'])): ?>
                            <p class="xf-node__desc"><?= $view->e((string) $cat['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="xf-node__stats">
                        <dl><dt>Threads</dt><dd><?= $view->e(forum_count((int) ($cat['thread_count'] ?? 0))) ?></dd></dl>
                        <dl><dt>Messages</dt><dd><?= $view->e(forum_count((int) ($cat['posts_count'] ?? 0))) ?></dd></dl>
                    </div>
                    <div class="xf-node__extra">
                        <?php if ($lastId > 0): ?>
                            <?= forum_avatar($poster, 's') ?>
                            <div>
                                <a href="<?= $view->e($url->to('forum/thread', ['id' => $lastId])) ?>">
                                    <?= $view->e(forum_excerpt((string) ($cat['last_title'] ?? ''), 42)) ?>
                                </a>
                                <div class="xf-meta"><?= $view->e($poster) ?> · <?= $view->e(forum_when($cat['last_post_at'] ?? null)) ?></div>
                            </div>
                        <?php else: ?>
                            <div class="xf-meta">No messages yet</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($latest !== []): ?>
        <section class="xf-block">
            <header class="xf-block__bar"><h2>Latest activity</h2></header>
            <?php foreach ($latest as $row): ?>
                <div class="xf-mini">
                    <?= forum_avatar((string) ($row['last_poster'] ?? ''), 's') ?>
                    <div>
                        <a href="<?= $view->e($url->to('forum/thread', ['id' => (int) $row['id']])) ?>">
                            <?= $view->e((string) $row['title']) ?>
                        </a>
                        <div class="xf-meta">
                            in <a href="<?= $view->e($url->to('forum/category', ['slug' => (string) $row['category_slug']])) ?>">
                                <?= $view->e((string) $row['category_name']) ?></a>
                            · <?= $view->e(forum_user_label($row, 'last_poster', 'last_poster')) ?>
                            · <?= $view->e(forum_when($row['last_post_at'] ?? null)) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>
