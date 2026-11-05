<?php
/** @var array $thread */
/** @var array $category */
/** @var array $post */
/** @var string $content */
/** @var \System\Library\View $view */
$url = $view->url;
$root = dirname(__DIR__, 2);
$threadId = (int) $thread['id'];
?>

<div class="xf">
    <?= $view->partial('nav', ['active' => 'forums', 'postSlug' => (string) ($category['slug'] ?? '')], $root) ?>

    <nav class="xf-crumbs">
        <a href="<?= $view->e($url->to('forum')) ?>">Forums</a>
        <span>›</span>
        <a href="<?= $view->e($url->to('forum/thread', ['id' => $threadId])) ?>">
            <?= $view->e((string) $thread['title']) ?>
        </a>
        <span>›</span>
        <span>Edit post</span>
    </nav>

    <section class="xf-block">
        <header class="xf-block__bar"><h1>Edit post</h1></header>
        <form method="post" action="<?= $view->e($url->to('forum/thread/edit')) ?>" class="xf-reply__form">
            <?= $view->request->csrfField() ?>
            <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
            <textarea name="content" rows="12" required><?= $view->e($content) ?></textarea>
            <p class="xf-hint">Markdown: **bold**, *italic*, `code`, lists, &gt; quotes, [links](https://…)</p>
            <div class="xf-form-acts">
                <button type="submit" class="xf-btn">Save</button>
                <a class="xf-btn xf-btn--ghost" href="<?= $view->e($url->to('forum/thread', ['id' => $threadId])) ?>">Cancel</a>
            </div>
        </form>
    </section>
</div>
