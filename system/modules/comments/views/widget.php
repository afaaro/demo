<?php
/** @var string $module */
/** @var string $entityType */
/** @var string $entityId */
/** @var list<array<string,mixed>> $comments */
/** @var int $userId */
/** @var string $csrf */
/** @var array<string,string|int> $loginQuery */
/** @var string $heading */
/** @var callable(string|int|float): string $e */

$module = (string) ($module ?? '');
$entityType = (string) ($entityType ?? '');
$entityId = (string) ($entityId ?? '');
$comments = $comments ?? [];
$userId = (int) ($userId ?? 0);
$csrf = (string) ($csrf ?? '');
$loginQuery = is_array($loginQuery ?? null) ? $loginQuery : ['next' => 'comments'];
$heading = (string) ($heading ?? 'Comments');
$uid = 'cmt-' . preg_replace('/[^a-z0-9_-]+/i', '-', $module . '-' . $entityType . '-' . $entityId);
?>
<section class="site-comments" id="<?= $e($uid) ?>"
         data-module="<?= $e($module) ?>"
         data-entity-type="<?= $e($entityType) ?>"
         data-entity-id="<?= $e($entityId) ?>">
    <h2 class="site-comments__title"><?= $e($heading) ?></h2>

    <?php if ($userId > 0): ?>
        <div class="site-comments__compose">
            <textarea class="site-comments__input" rows="3" maxlength="2000" placeholder="Add a comment…"></textarea>
            <button type="button" class="site-comments__post">Post</button>
        </div>
    <?php else: ?>
        <p class="site-comments__signin">
            <a href="<?= $e(url('user/login', $loginQuery)) ?>">Sign in</a> to comment.
        </p>
    <?php endif; ?>

    <div class="site-comments__list">
        <?php if ($comments === []): ?>
            <p class="site-comments__empty">No comments yet.</p>
        <?php else: ?>
            <?php foreach ($comments as $com): ?>
                <article class="site-comments__item">
                    <header>
                        <strong><?= $e((string) ($com['username'] ?? 'User')) ?></strong>
                        <time><?= $e(comments_relative_time($com['created_at'] ?? null)) ?></time>
                    </header>
                    <p><?= nl2br($e((string) ($com['body'] ?? $com['comment_text'] ?? ''))) ?></p>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
<script>
window.COMMENTS_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.COMMENTS_URLS = window.COMMENTS_URLS || {
  list: <?= json_encode(url('comments/list'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  post: <?= json_encode(url('comments/post'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>
