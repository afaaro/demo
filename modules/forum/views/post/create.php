<?php
/** @var list<array<string,mixed>> $categories */
/** @var array{title:string,content:string,category_id:int} $form */
/** @var \System\Library\View $view */
$url = $view->url;
$root = dirname(__DIR__, 2);
?>

<div class="xf">
    <?= $view->partial('nav', ['active' => 'forums'], $root) ?>

    <nav class="xf-crumbs">
        <a href="<?= $view->e($url->to('forum')) ?>">Forums</a>
        <span>›</span>
        <span>Post thread</span>
    </nav>

    <section class="xf-block">
        <header class="xf-block__bar"><h1>Post thread</h1></header>
        <form method="post" action="<?= $view->e(forum_new_url()) ?>" class="xf-reply__form">
            <?= $view->request->csrfField() ?>
            <label>Forum
                <select name="category_id" required>
                    <option value="0">Choose a forum…</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= (int) $form['category_id'] === (int) $c['id'] ? ' selected' : '' ?>>
                            <?= $view->e((string) $c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Title
                <input type="text" name="title" required maxlength="255" value="<?= $view->e((string) $form['title']) ?>">
            </label>
            <label>Message
                <textarea name="content" rows="12" required><?= $view->e((string) $form['content']) ?></textarea>
            </label>
            <p class="xf-hint">Markdown: **bold**, *italic*, `code`, lists, &gt; quotes, [links](https://…)</p>
            <div class="xf-form-acts">
                <button type="submit" class="xf-btn">Post thread</button>
                <a class="xf-btn xf-btn--ghost" href="<?= $view->e($url->to('forum')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</div>
