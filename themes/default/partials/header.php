<?php
$url = $view->url;

// Load menu items safely — never fatal if the menu service is missing.
$menuItems = [];
try {
    $menuItems = $view->menu->all();
} catch (\Throwable) {
    $menuItems = [];
}

// Current route for active-state highlighting.
$currentRoute = trim((string) $view->request->get('route', 'raw', ''), '/');

$isActive = static function (string $route) use ($currentRoute): bool {
    $route = trim($route, '/');
    if ($route === '') {
        return $currentRoute === '' || $currentRoute === '/';
    }
    return $currentRoute === $route || str_starts_with($currentRoute, $route . '/');
};
?>
<header class="site-header">
    <nav class="site-nav" aria-label="Primary">

        <!-- Logo -->
        <a href="<?= $view->e(BASEURL) ?>" class="site-nav__logo">
            <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
            <span class="site-nav__brand"><?= $view->e($view->config->get('meta.site_name', 'My Site')) ?></span>
        </a>


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
                            <?php if (!empty($item['icon'])): ?>
                                <i class="<?= $view->e((string) $item['icon']) ?>"></i>
                            <?php endif; ?>
                            <?= $view->e((string) $item['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        
        <!-- Search -->
        <form action="<?= $view->e($view->url->to('search')) ?>" method="get" class="site-nav__search" role="search">
            <input type="search"
                   name="q"
                   class="site-nav__search-input"
                   placeholder="Search..."
                   aria-label="Search"
                   value="<?= $view->e($view->request->query('q', '')) ?>">
            <button type="submit" class="site-nav__search-btn" aria-label="Submit search">
                <i class="bi bi-search" aria-hidden="true"></i>
            </button>
        </form>

        <!-- Auth area -->
        <?php $currentUser = $view->auth->current(); ?>

        <div class="site-nav__user">
            <?php if ($currentUser !== null): ?>
                <div class="site-nav__dropdown">
                    <button type="button"
                            class="site-nav__dropdown-toggle"
                            aria-expanded="false"
                            aria-haspopup="true">
                        <i class="bi bi-person-circle" aria-hidden="true"></i>
                        <span class="site-nav__user-name"><?= $view->e($currentUser->displayLabel()) ?></span>
                        <i class="bi bi-chevron-down site-nav__dropdown-caret" aria-hidden="true"></i>
                    </button>

                    <ul class="site-nav__dropdown-menu" role="menu">
                        <li role="none">
                            <a href="<?= $view->e($view->url->to('user/profile')) ?>" class="site-nav__dropdown-link" role="menuitem">
                                <i class="bi bi-person" aria-hidden="true"></i> My Profile
                            </a>
                        </li>
                        <li role="none">
                            <a href="<?= $view->e($view->url->to('user/settings')) ?>" class="site-nav__dropdown-link" role="menuitem">
                                <i class="bi bi-gear" aria-hidden="true"></i> Settings
                            </a>
                        </li>
                        <li role="none">
                            <a href="<?= $view->e($view->url->to('user/groups')) ?>" class="site-nav__dropdown-link" role="menuitem">
                                <i class="bi bi-people" aria-hidden="true"></i> My Groups
                            </a>
                        </li>
                        <li role="none"><hr class="dropdown-divider"></li>
                        <li role="none">
                            <a href="<?= $view->e($view->url->to('user/logout')) ?>" class="site-nav__dropdown-link text-danger" role="menuitem">
                                <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign Out
                            </a>
                        </li>
                    </ul>
                </div>
            <?php else: ?>
                <div class="site-nav__auth">
                    <a href="<?= $view->e($view->url->to('user/login')) ?>" class="site-nav__auth-link">Sign In</a>
                    <span class="site-nav__auth-sep" aria-hidden="true">·</span>
                    <a href="<?= $view->e($view->url->to('user/register')) ?>" class="site-nav__auth-link site-nav__auth-link--primary">Register</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Mobile toggle -->
        <button type="button"
                class="site-nav__toggle"
                aria-label="Toggle menu"
                aria-expanded="false"
                aria-controls="site-nav-menu">
            <span class="site-nav__toggle-bar" aria-hidden="true"></span>
            <span class="site-nav__toggle-bar" aria-hidden="true"></span>
            <span class="site-nav__toggle-bar" aria-hidden="true"></span>
        </button>

    </nav>
</header>