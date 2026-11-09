<?php
declare(strict_types=1);
/** @var array<string,mixed> $profile */
/** @var list<array<string,mixed>> $wall */
/** @var array<int,int> $likeCounts */
/** @var array<int,bool> $liked */
/** @var array<int,int> $commentCounts */
/** @var string $relation */
/** @var int $friendCount */
/** @var int $mutualCount */
/** @var list<array<string,mixed>> $mutualFriends */
/** @var int $unread */
/** @var string $csrf */
/** @var int $viewerId */
/** @var int $incomingId */
/** @var int $outgoingId */
/** @var bool $isSelf */
/** @var string $defaultVisibility */
/** @var int $page */
/** @var int $limit */
/** @var bool $canSeeFriendsWall */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$name = community_display_name($profile);
$profileId = (int) $profile['id'];
$userId = $viewerId;
$commentCounts = $commentCounts ?? [];
$mutualFriends = $mutualFriends ?? [];
$mutualCount = (int) ($mutualCount ?? 0);
$page = max(1, (int) ($page ?? 1));
$limit = (int) ($limit ?? 15);
$joined = !empty($profile['created_at']) ? date('M Y', strtotime((string) $profile['created_at'])) : '';
?>
<div class="cm-shell">
  <?php include __DIR__ . '/partials/nav.php'; ?>

  <section class="cm-profile">
    <div class="cm-profile__banner" aria-hidden="true"></div>
    <div class="cm-profile__hero">
      <div class="cm-avatar is-lg"><?= $e(mb_strtoupper(mb_substr($name, 0, 1))) ?></div>
      <div class="cm-profile__meta">
        <h1 class="cm-title"><?= $e($name) ?></h1>
        <p class="cm-muted">
          @<?= $e((string) $profile['username']) ?>
          · <?= (int) $friendCount ?> friends
          <?php if ($joined !== ''): ?>
            · Joined <?= $e($joined) ?>
          <?php endif; ?>
        </p>
        <?php if (!$isSelf && $mutualCount > 0): ?>
          <p class="cm-mutual">
            <?= (int) $mutualCount ?> mutual friend<?= $mutualCount === 1 ? '' : 's' ?>
            <?php if ($mutualFriends !== []): ?>
              ·
              <?php foreach ($mutualFriends as $i => $mf): ?>
                <?php if ($i > 0): ?>, <?php endif; ?>
                <a href="<?= $e(url('community/profile', ['u' => (int) $mf['id']])) ?>"><?= $e(community_display_name($mf)) ?></a>
              <?php endforeach; ?>
              <?php if ($mutualCount > count($mutualFriends)): ?>
                <span class="cm-muted">+<?= (int) ($mutualCount - count($mutualFriends)) ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </div>
      <div class="cm-profile__actions">
        <?php if ($isSelf): ?>
          <a class="cm-btn" href="<?= $e(url('user/profile/edit')) ?>">Edit account</a>
        <?php elseif ($relation === 'friends'): ?>
          <form method="post" action="<?= $e(url('community/friends/unfriend')) ?>"
                onsubmit="return confirm('Remove this friend?');">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="user_id" value="<?= $profileId ?>">
            <input type="hidden" name="next" value="profile">
            <button type="submit" class="cm-btn">Unfriend</button>
          </form>
        <?php elseif ($relation === 'outgoing'): ?>
          <form method="post" action="<?= $e(url('community/friends/cancel')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $outgoingId ?>">
            <input type="hidden" name="next" value="profile">
            <input type="hidden" name="user_id" value="<?= $profileId ?>">
            <button type="submit" class="cm-btn">Cancel request</button>
          </form>
        <?php elseif ($relation === 'incoming'): ?>
          <form method="post" action="<?= $e(url('community/friends/accept')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $incomingId ?>">
            <button type="submit" class="cm-btn is-accent">Accept request</button>
          </form>
          <form method="post" action="<?= $e(url('community/friends/decline')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $incomingId ?>">
            <button type="submit" class="cm-btn">Decline</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= $e(url('community/friends/request')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="user_id" value="<?= $profileId ?>">
            <input type="hidden" name="next" value="profile">
            <button type="submit" class="cm-btn is-accent">Add friend</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($isSelf): ?>
    <section class="cm-compose">
      <?php
        $placeholder = 'Share something on your wall…';
        include __DIR__ . '/partials/compose.php';
      ?>
    </section>
  <?php endif; ?>

  <section class="cm-feed">
    <h2 class="cm-subtitle">Wall</h2>
    <?php if ($wall === []): ?>
      <div class="cm-empty">
        <?php if (!$canSeeFriendsWall): ?>
          <p>Only public posts are visible. Add them as a friend to see more.</p>
        <?php else: ?>
          <p>No posts to show yet.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php foreach ($wall as $post): ?>
        <?php include __DIR__ . '/partials/post_card.php'; ?>
      <?php endforeach; ?>
      <div class="cm-pager">
        <?php if ($page > 1): ?>
          <a class="cm-btn" href="<?= $e(url('community/profile', ['u' => $profileId, 'page' => $page - 1])) ?>">Newer</a>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
        <?php if (count($wall) >= $limit): ?>
          <a class="cm-btn" href="<?= $e(url('community/profile', ['u' => $profileId, 'page' => $page + 1])) ?>">Older</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<script>
window.COMMUNITY_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.COMMUNITY_REACT_URL = <?= json_encode(url('community/react'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
