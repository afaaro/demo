<?php
/** @var array $thread */
/** @var array $category */
/** @var list<array<string,mixed>> $posts */
/** @var \System\Library\Paginator $pager */
/** @var string $quote */
/** @var \System\Library\View $view */
$url = $view->url;
$root = dirname(__DIR__, 2);
$threadId  = (int) $thread['id'];
$threadUrl = $url->to('forum/thread', ['id' => $threadId]);
$isLocked  = (int) ($thread['is_locked'] ?? 0) === 1;
$canReply  = (!$isLocked || forum_can_moderate()) && registry('auth')->check() && forum_can_post($category);
$staff     = forum_can_moderate();
$page      = $pager->page;
?>

<div class="xf">
    <?= $view->partial('nav', ['active' => 'forums', 'postSlug' => (string) $category['slug']], $root) ?>

    <nav class="xf-crumbs">
        <a href="<?= $view->e($url->to('forum')) ?>">Forums</a>
        <span>›</span>
        <a href="<?= $view->e($url->to('forum/category', ['slug' => (string) $category['slug']])) ?>">
            <?= $view->e((string) $category['name']) ?>
        </a>
        <span>›</span>
        <span><?= $view->e((string) $thread['title']) ?></span>
    </nav>

    <div class="xf-pagehead">
        <div>
            <h1>
                <?php if ((int) ($thread['is_pinned'] ?? 0) === 1): ?>
                    <span class="xf-tag xf-tag--sticky">Sticky</span>
                <?php endif; ?>
                <?php if ($isLocked): ?>
                    <span class="xf-tag">Locked</span>
                <?php endif; ?>
                <?= $view->e((string) $thread['title']) ?>
            </h1>
            <p><?= $view->e(forum_count((int) $thread['view_count'])) ?> views
                · <?= $view->e(forum_count((int) $thread['post_count'])) ?> messages</p>
        </div>
        <?php if ($staff): ?>
            <form method="post" action="<?= $view->e($url->to('forum/thread/moderate')) ?>" class="xf-mod">
                <?= $view->request->csrfField() ?>
                <input type="hidden" name="id" value="<?= $threadId ?>">
                <button name="do" value="pin"><?= (int) $thread['is_pinned'] === 1 ? 'Unsticky' : 'Sticky' ?></button>
                <button name="do" value="lock"><?= $isLocked ? 'Unlock' : 'Lock' ?></button>
                <button name="do" value="delete" class="is-danger" onclick="return confirm('Delete this thread?')">Delete</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="xf-messages">
        <?php foreach ($posts as $i => $post): ?>
            <?php
                $postId = (int) $post['id'];
                $num    = ($page - 1) * $pager->perPage + $i + 1;
                $canEdit = forum_can_edit_post($post);
                $name = (string) ($post['username'] ?? '—');
                $quoteUrl = $url->to('forum/thread', array_filter([
                    'id'    => $threadId,
                    'page'  => $page > 1 ? $page : null,
                    'quote' => $postId,
                ])) . '#reply';
            ?>
            <article class="xf-msg" id="post-<?= $postId ?>">
                <aside class="xf-msg__user">
                    <?= forum_avatar($name, 'l') ?>
                    <strong class="xf-msg__name"><?= $view->e($name) ?></strong>
                    <span class="xf-msg__title">Member</span>
                    <dl>
                        <dt>Messages</dt>
                        <dd><?= $view->e(forum_count((int) ($post['author_posts'] ?? 0))) ?></dd>
                        <?php if (!empty($post['author_joined'])): ?>
                            <dt>Joined</dt>
                            <dd><?= $view->e(date('M Y', strtotime((string) $post['author_joined']) ?: time())) ?></dd>
                        <?php endif; ?>
                    </dl>
                </aside>
                <div class="xf-msg__main">
                    <header class="xf-msg__attr">
                        <time datetime="<?= $view->e((string) $post['created_at']) ?>">
                            <?= $view->e(forum_when((string) $post['created_at'])) ?>
                        </time>
                        <a href="#post-<?= $postId ?>">#<?= $num ?></a>
                    </header>
                    <div class="xf-msg__body">
                        <?= $post['content_html'] ?>
                    </div>
                    <?php if (!empty($post['edited_at'])): ?>
                        <p class="xf-msg__edited">Last edited: <?= $view->e(forum_when((string) $post['edited_at'])) ?></p>
                    <?php endif; ?>
                    <footer class="xf-msg__bar">
                        <?php if ($canReply): ?>
                            <a href="<?= $view->e($quoteUrl) ?>">Quote</a>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                            <a href="<?= $view->e($url->to('forum/thread/edit', ['id' => $postId])) ?>">Edit</a>
                            <form method="post" action="<?= $view->e($url->to('forum/thread/delete-post')) ?>"
                                  onsubmit="return confirm('Delete this post?')">
                                <?= $view->request->csrfField() ?>
                                <input type="hidden" name="id" value="<?= $postId ?>">
                                <button type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </footer>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="xf-pager">
        <?= $pager->render(
            static fn(int $p): string => $url->to(
                'forum/thread',
                array_filter(['id' => $threadId, 'page' => $p > 1 ? $p : null])
            ),
            ['theme' => 'bootstrap']
        ) ?>
    </div>

    <?php if ($canReply): ?>
        <section class="xf-block xf-reply" id="reply">
            <header class="xf-block__bar"><h2>Reply to thread</h2></header>
            <form method="post" action="<?= $view->e($url->to('forum/thread/reply', ['id' => $threadId])) ?>" class="xf-reply__form">
                <?= $view->request->csrfField() ?>
                <textarea name="content" rows="8" required
                          placeholder="Write your reply…"><?= $view->e($quote) ?></textarea>
                <p class="xf-hint">Markdown: **bold**, *italic*, `code`, lists, &gt; quotes, [links](https://…)</p>
                <button type="submit" class="xf-btn">Post reply</button>
            </form>
        </section>
    <?php elseif ($isLocked): ?>
        <div class="xf-empty">This thread is closed.</div>
    <?php else: ?>
        <div class="xf-empty"><a href="<?= $view->e($url->to('user/login', ['next' => $threadUrl])) ?>">Log in</a> to reply.</div>
    <?php endif; ?>
</div>
