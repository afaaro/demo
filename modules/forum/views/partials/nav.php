<?php
/** @var \System\Library\View $view */
/** @var string $active */
/** @var string $postSlug */
$url = $view->url;
$active = $active ?? 'forums';
$postSlug = $postSlug ?? '';
?>
<nav class="xf-nav" aria-label="Forum">
    <div class="xf-nav__links">
        <a href="<?= $view->e($url->to('forum')) ?>" class="<?= $active === 'forums' ? 'is-on' : '' ?>">Forums</a>
        <a href="<?= $view->e($url->to('forum/whats-new')) ?>" class="<?= $active === 'new' ? 'is-on' : '' ?>">What's new</a>
        <a href="<?= $view->e($url->to('forum/search')) ?>" class="<?= $active === 'search' ? 'is-on' : '' ?>">Search</a>
    </div>
    <div class="xf-nav__tools">
        <form class="xf-search" method="get" action="<?= $view->e($url->to('forum/search')) ?>" role="search">
            <input type="search" name="q" placeholder="Search…" aria-label="Search forum"
                   value="<?= $view->e((string) ($q ?? '')) ?>">
            <button type="submit" aria-label="Search"><i class="bi bi-search"></i></button>
        </form>
        <?php
            $postHref = forum_new_url($postSlug);
            if (!registry('auth')->check()) {
                $postHref = $url->to('user/login', ['next' => $postHref]);
            }
        ?>
        <a class="xf-btn" href="<?= $view->e($postHref) ?>">
            <i class="bi bi-plus-lg"></i> Post thread
        </a>
    </div>
</nav>
