<?php
declare(strict_types=1);
/** @var array<string,mixed> $post */
/** @var int $likeCount */
/** @var bool $liked */
/** @var int $unread */
/** @var string $csrf */
/** @var string $commentsHtml */
/** @var int $userId */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$likeCounts = [(int) $post['id'] => (int) $likeCount];
$liked = [(int) $post['id'] => (bool) $liked];
?>
<div class="cm-shell cm-shell--narrow">
  <?php include __DIR__ . '/partials/nav.php'; ?>

  <section class="cm-feed">
    <?php include __DIR__ . '/partials/post_card.php'; ?>
  </section>

  <section class="cm-comments">
    <?= $commentsHtml ?>
  </section>
</div>
<script>
window.COMMUNITY_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.COMMUNITY_REACT_URL = <?= json_encode(url('community/react'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.COMMENTS_URLS = window.COMMENTS_URLS || {};
window.COMMENTS_URLS.post = <?= json_encode(url('community/comment'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
