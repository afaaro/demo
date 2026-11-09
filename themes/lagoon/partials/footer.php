<div class="site-footer__grid">
    <div class="site-footer__brand">
        <strong><?= $view->e($view->config->get('meta.site_name', 'My Site')) ?></strong>
        <p>Stories, community, and conversation in one place.</p>
    </div>
    <div class="site-footer__col">
        <h3>Explore</h3>
        <a href="<?= $view->e($view->url->to('news')) ?>">News</a>
        <a href="<?= $view->e($view->url->to('forum')) ?>">Forum</a>
        <a href="<?= $view->e($view->url->to('community')) ?>">Community</a>
    </div>
    <div class="site-footer__col">
        <h3>Account</h3>
        <a href="<?= $view->e($view->url->to('user/login')) ?>">Sign in</a>
        <a href="<?= $view->e($view->url->to('user/register')) ?>">Register</a>
        <a href="<?= $view->e($view->url->to('search')) ?>">Search</a>
    </div>
    <div class="site-footer__meta">
        <p class="site-footer__copy mb-0">
            &copy; <?= date('Y') ?>
            <?= $view->e($view->config->get('app.name', 'My Site')) ?>
        </p>
    </div>
</div>
