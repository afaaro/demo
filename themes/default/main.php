<?php /** @var \System\Library\View $view */ ?>
<!DOCTYPE html>
<html lang="<?= $view->e((string) ($view->config->get('meta.locale', 'en'))) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= $view->e($view->getDescription() !== '' ? $view->getDescription() : ($description ?? '')) ?>">
    <meta name="csrf-token" content="<?= $view->e($view->request->csrfToken()) ?>">
    <?php foreach ($view->getMeta() as $metaName => $metaContent): ?>
    <meta name="<?= $view->e($metaName) ?>" content="<?= $view->e($metaContent) ?>">
    <?php endforeach; ?>
    <?php foreach ($view->getOG() as $ogProperty => $ogContent): ?>
    <meta property="<?= $view->e($ogProperty) ?>" content="<?= $view->e($ogContent) ?>">
    <?php endforeach; ?>
    <title><?= $view->e($view->pageTitle((string) ($title ?? 'soomaali.net'))) ?></title>
    <link rel="canonical" href="<?= $view->e((string) $view->request->url()) ?>">
    <link rel="icon" href="<?= $view->asset('img/favicon.ico') ?>">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
      integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= $view->asset('css/main.css') ?>">
    <?php foreach ($view->getStyles() as $href): ?>
    <link rel="stylesheet" href="<?= $view->e($href) ?>">
    <?php endforeach; ?>
    <?php foreach ($view->getInlineStyles() as $css): ?>
    <style><?= $css ?></style>
    <?php endforeach; ?>
    <?php
    $toolbarHtml = '';
    if ($view->user->canAccessAdminUi() && $view->toolbar->isEnabled()) {
        $toolbarHtml = $view->toolbar->render();
    }
    $hasToolbar = $toolbarHtml !== '';
    ?>
    <?php if ($hasToolbar): ?>
        <link rel="stylesheet" href="<?= $view->asset('css/admin-toolbar.css') ?>">
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js" defer></script>
    <script src="<?= $view->asset('js/csrf.js') ?>" defer></script>
    <?php foreach ($view->getScripts('header') as $src): ?>
    <script src="<?= $view->e($src) ?>" defer></script>
    <?php endforeach; ?>
    <?= $view->getBlock('head') ?>
</head>
<body class="site-body<?= $hasToolbar ? ' has-admin-toolbar' : '' ?>">

<?php if ($hasToolbar): ?>
    <?= $toolbarHtml ?>
<?php endif; ?>

<a href="#main-content" class="skip-link">Skip to content</a>

<?php
$hasLeft  = $view->hasBlock('left_sidebar');
$hasRight = $view->hasBlock('right_sidebar');
$user     = $view->user->current();

?>

<div id="wrapper" class="site-wrapper">

    <?php if ($view->hasBlock('upper')): ?>
        <div class="region region--upper"><?= $view->getBlock('upper') ?></div>
    <?php endif; ?>

    <?= $view->partial('header', ['user' => $user]) ?>

    <?php if ($view->hasBlock('top')): ?>
        <div class="region region--top"><?= $view->getBlock('top') ?></div>
    <?php endif; ?>

    <div class="site-layout">
        <?php if ($hasLeft): ?>
            <aside class="site-sidebar site-sidebar--left" aria-label="Left sidebar">
                <?= $view->getBlock('left_sidebar') ?>
            </aside>
        <?php endif; ?>

        <main id="main-content" class="site-main">
            <?= $view->notify->display() ?>
            <?= $content ?? '' ?>   <!-- ← correct for render() -->
        </main>

        <?php if ($hasRight): ?>
            <aside class="site-sidebar site-sidebar--right" aria-label="Right sidebar">
                <?= $view->getBlock('right_sidebar') ?>
            </aside>
        <?php endif; ?>
    </div>

    <?php if ($view->hasBlock('bottom')): ?>
        <div class="region region--bottom"><?= $view->getBlock('bottom') ?></div>
    <?php endif; ?>

    <footer class="site-footer">
        <?= $view->partial('footer') ?>
    </footer>

    <?php if ($view->hasBlock('lower')): ?>
        <div class="region region--lower"><?= $view->getBlock('lower') ?></div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $view->asset('js/main.js') ?>" defer></script>
<?php foreach ($view->getScripts('footer') as $src): ?>
<script src="<?= $view->e($src) ?>" defer></script>
<?php endforeach; ?>
<?php foreach ($view->getInlineScripts() as $js): ?>
<script><?= $js ?></script>
<?php endforeach; ?>
<?= $view->getBlock('scripts') ?>
</body>
</html>