<?php /** @var \System\Library\View $view */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $view->e($view->request->csrfToken()) ?>">
    <title><?= $view->e($view->pageTitle((string) ($title ?? 'Admin'))) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= $view->asset('css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $view->asset('css/admin-pages.css') ?>">
    <?php foreach ($view->getStyles() as $href): ?>
    <link rel="stylesheet" href="<?= $view->e($href) ?>">
    <?php endforeach; ?>
    <?php foreach ($view->getInlineStyles() as $css): ?>
    <style><?= $css ?></style>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="<?= $view->asset('js/csrf.js') ?>"></script>
    <?= $view->getBlock('head') ?>
</head>
<body class="admin-body">

<div class="admin-shell" id="adminShell">

    <!-- Mobile backdrop -->
    <div class="admin-backdrop" data-sidebar-close hidden></div>

    <aside class="admin-sidebar" id="adminSidebar" aria-label="Admin navigation">
        <div class="admin-sidebar__brand">
            <a href="<?= $view->e($view->url->to('')) ?>" class="admin-brand">
                <span class="admin-brand__icon"><i class="bi bi-grid-3x3-gap-fill"></i></span>
                <span class="admin-brand__text"><?= $view->e($view->config->get('meta.site_name', 'CMS')) ?></span>
            </a>
        </div>

        <nav class="admin-sidebar__nav">
            <?= $view->getBlock('admin_sidebar') ?>

            <?= $view->adminNav->render(static fn(string $perm): bool => $view->auth->can($perm)) ?>

            <div class="admin-sidebar__footer">
                <a href="<?= $view->e($view->url->to('')) ?>" class="admin-nav-link">
                    <i class="bi bi-arrow-left-circle"></i> <span>Back to site</span>
                </a>
            </div>
        </nav>
    </aside>

    <div class="admin-content">
        <header class="admin-topbar">
            <button type="button" class="admin-topbar__toggle d-lg-none"
                    data-sidebar-open aria-label="Open navigation"
                    aria-controls="adminSidebar" aria-expanded="false">
                <i class="bi bi-list"></i>
            </button>

            <?php
            $currentUser = $view->auth->current();
            $userLabel = $currentUser !== null ? $currentUser->displayLabel() : 'Admin';
            $userInitial = function_exists('mb_substr')
                ? mb_strtoupper(mb_substr($userLabel, 0, 1))
                : strtoupper(substr($userLabel, 0, 1));
            ?>
            <h1 class="admin-topbar__title"><?= $view->e($view->heading('Admin')) ?></h1>

            <div class="admin-topbar__actions">
                <?= $view->getBlock('admin_topbar') ?>
                <div class="admin-user" title="<?= $view->e($userLabel) ?>">
                    <span class="admin-user__avatar"><?= $view->e($userInitial) ?></span>
                    <span class="admin-user__name"><?= $view->e($userLabel) ?></span>
                </div>
                <button type="button" class="admin-sidebar__collapse d-none d-lg-inline-flex"
                        data-sidebar-collapse
                        aria-label="Collapse sidebar" title="Collapse sidebar"
                        aria-pressed="false">
                    <i class="bi bi-chevron-double-left"></i>
                </button>
            </div>
        </header>

        <main class="admin-main">
            <div class="admin-main__inner">
                <?= $view->notify->display() ?>
                <?= $content ?>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $view->asset('js/admin.js') ?>" defer></script>
<?= $view->getBlock('scripts') ?>
</body>
</html>