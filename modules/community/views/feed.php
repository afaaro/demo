<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $feed */
/** @var list<array<string,mixed>> $discover */
/** @var array<int,int> $likeCounts */
/** @var array<int,bool> $liked */
/** @var array<int,int> $commentCounts */
/** @var int $unread */
/** @var string $csrf */
/** @var string $defaultVisibility */
/** @var int $page */
/** @var int $limit */
/** @var int $userId */
/** @var int $friendCount */
/** @var string $emptyKind */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$limit = (int) ($limit ?? 15);
$discover = $discover ?? [];
$commentCounts = $commentCounts ?? [];
$friendCount = (int) ($friendCount ?? 0);
$emptyKind = (string) ($emptyKind ?? 'quiet');
?>
<div class="cm-shell">
  <?php include __DIR__ . '/partials/nav.php'; ?>

  <section class="cm-compose">
    <p class="cm-kicker">Your circle</p>
    <h1 class="cm-title">News feed</h1>
    <?php
      $placeholder = "What's on your mind?";
      include __DIR__ . '/partials/compose.php';
    ?>
  </section>

  <section class="cm-feed">
    <?php if ($feed === []): ?>
      <div class="cm-empty">
        <?php if ($emptyKind === 'no_friends'): ?>
          <p class="cm-empty__title">Your feed is waiting</p>
          <p>Add friends to see their posts here, or share something public.</p>
          <p><a class="cm-btn is-accent" href="<?= $e(url('community/friends')) ?>">Find friends</a></p>
        <?php else: ?>
          <p class="cm-empty__title">It's quiet right now</p>
          <p>You have <?= (int) $friendCount ?> friend<?= $friendCount === 1 ? '' : 's' ?>. Be the first to post today.</p>
        <?php endif; ?>
      </div>

      <?php if ($discover !== []): ?>
        <h2 class="cm-subtitle">Public posts nearby</h2>
        <p class="cm-muted cm-discover-hint">From people outside your friend list.</p>
        <?php foreach ($discover as $post): ?>
          <?php include __DIR__ . '/partials/post_card.php'; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php else: ?>
      <?php foreach ($feed as $post): ?>
        <?php include __DIR__ . '/partials/post_card.php'; ?>
      <?php endforeach; ?>
      <div class="cm-pager">
        <?php if ($page > 1): ?>
          <a class="cm-btn" href="<?= $e(url('community', ['page' => $page - 1])) ?>">Newer</a>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
        <?php if (count($feed) >= $limit): ?>
          <a class="cm-btn" href="<?= $e(url('community', ['page' => $page + 1])) ?>">Older</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<script>
window.COMMUNITY_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.COMMUNITY_REACT_URL = <?= json_encode(url('community/react'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
