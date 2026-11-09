<?php
$url = $view->url;

$menuItems = [];
try {
    $menuItems = $view->menu->all();
} catch (\Throwable) {
    $menuItems = [];
}

$currentRoute = trim((string) $view->request->get('route', 'raw', ''), '/');
$siteName = (string) $view->config->get('meta.site_name', 'My Site');

$isActive = static function (string $route) use ($currentRoute): bool {
    $route = trim($route, '/');
    if ($route === '') {
        return $currentRoute === '' || $currentRoute === '/';
    }
    return $currentRoute === $route || str_starts_with($currentRoute, $route . '/');
};

$currentUser = $view->auth->current();
?>
<header class="site-header">
    <div class="site-header__top">
        <a href="<?= $view->e(get_base_url()) ?>" class="site-logo">
            <span class="site-logo__mark" aria-hidden="true"></span>
            <span class="site-logo__text"><?= $view->e($siteName) ?></span>
        </a>

        <form action="<?= $view->e($view->url->to('search')) ?>" method="get" class="site-header__search" role="search">
            <input type="search"
                   name="q"
                   placeholder="Search…"
                   aria-label="Search"
                   value="<?= $view->e($view->request->query('q', '')) ?>">
            <button type="submit" aria-label="Submit search">
                <i class="bi bi-search" aria-hidden="true"></i>
            </button>
        </form>

        <div class="site-header__actions">
            <button type="button"
                    class="site-theme-toggle"
                    data-theme-toggle
                    aria-label="Toggle color theme"
                    title="Toggle color theme">
                <i class="bi bi-moon-stars" data-theme-icon-dark aria-hidden="true"></i>
                <i class="bi bi-sun" data-theme-icon-light aria-hidden="true"></i>
            </button>

            <?php if ($currentUser !== null): ?>
                <div class="site-nav__dropdown">
                    <button type="button"
                            class="site-nav__dropdown-toggle"
                            aria-expanded="false"
                            aria-haspopup="true">
                        <i class="bi bi-person-circle" aria-hidden="true"></i>
                        <span><?= $view->e($currentUser->displayLabel()) ?></span>
                        <i class="bi bi-chevron-down site-nav__dropdown-caret" aria-hidden="true"></i>
                    </button>
                    <ul class="site-nav__dropdown-menu" role="menu">
                        <li role="none">
                            <a href="<?= $view->e($view->url->to('user/profile')) ?>" class="site-nav__dropdown-link" role="menuitem">My Profile</a>
                        </li>
                        <li role="none">
                            <a href="<?= $view->e($view->url->to('user/settings')) ?>" class="site-nav__dropdown-link" role="menuitem">Settings</a>
                        </li>
                        <li role="none">
                            <a href="<?= $view->e($view->url->to('user/groups')) ?>" class="site-nav__dropdown-link" role="menuitem">My Groups</a>
                        </li>
                        <li role="none"><hr class="dropdown-divider"></li>
                        <li role="none">
                            <a href="<?= $view->e($view->url->to('user/logout')) ?>" class="site-nav__dropdown-link text-danger" role="menuitem">Sign Out</a>
                        </li>
                    </ul>
                </div>
            <?php else: ?>
                <a href="<?= $view->e($view->url->to('user/login')) ?>" class="site-header__login">
                    <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                    Sign in
                </a>
            <?php endif; ?>

            <button type="button"
                    class="site-nav__toggle"
                    aria-label="Toggle menu"
                    aria-expanded="false"
                    aria-controls="site-nav-menu">
                <span class="site-nav__toggle-bar" aria-hidden="true"></span>
                <span class="site-nav__toggle-bar" aria-hidden="true"></span>
                <span class="site-nav__toggle-bar" aria-hidden="true"></span>
            </button>
        </div>
    </div>

    <nav class="site-nav" aria-label="Primary">
        <?php if ($menuItems !== []): ?>
            <ul class="site-nav__menu" id="site-nav-menu">
                <?php foreach ($menuItems as $item): ?>
                    <?php
                        $route = (string) ($item['route'] ?? '');
                        $args  = (array)  ($item['args'] ?? []);
                        $href  = $route === '' ? BASEURL : $url->to($route, $args);
                        $active = $isActive($route);
                    ?>
                    <li>
                        <a class="site-nav__link<?= $active ? ' is-active' : '' ?>"
                           href="<?= $view->e($href) ?>"
                           <?= $active ? 'aria-current="page"' : '' ?>>
                            <?= $view->e((string) $item['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </nav>
</header>
