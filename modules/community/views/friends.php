<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $friends */
/** @var list<array<string,mixed>> $incoming */
/** @var list<array<string,mixed>> $outgoing */
/** @var list<array<string,mixed>> $searchResults */
/** @var array<int,string> $searchStatuses */
/** @var int $unread */
/** @var string $csrf */
/** @var string $q */
/** @var int $userId */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$searchStatuses = $searchStatuses ?? [];
?>
<div class="cm-shell">
  <?php include __DIR__ . '/partials/nav.php'; ?>

  <h1 class="cm-title">Friends</h1>

  <section class="cm-panel">
    <h2>Find people</h2>
    <form method="get" action="<?= $e(url('community/friends')) ?>" class="cm-search">
      <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Search by name or username…" minlength="2">
      <button type="submit" class="cm-btn">Search</button>
    </form>
    <?php if ($q !== ''): ?>
      <?php if ($searchResults === []): ?>
        <p class="cm-muted">No users found.</p>
      <?php else: ?>
        <ul class="cm-people">
          <?php foreach ($searchResults as $person): ?>
            <?php
              $pid = (int) $person['id'];
              $status = $searchStatuses[$pid] ?? 'none';
              $name = community_display_name($person);
            ?>
            <li>
              <a href="<?= $e(url('community/profile', ['u' => $pid])) ?>"><?= $e($name) ?></a>
              <span class="cm-muted">@<?= $e((string) $person['username']) ?></span>
              <?php if ($status === 'none' || $status === 'declined'): ?>
                <form method="post" action="<?= $e(url('community/friends/request')) ?>">
                  <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="user_id" value="<?= $pid ?>">
                  <button type="submit" class="cm-btn is-accent">Add friend</button>
                </form>
              <?php elseif ($status === 'friends'): ?>
                <span class="cm-pill">Friends</span>
              <?php elseif ($status === 'outgoing'): ?>
                <span class="cm-pill">Request sent</span>
              <?php else: ?>
                <span class="cm-pill">Responds on profile</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="cm-panel">
    <h2>Friend requests</h2>
    <?php if ($incoming === []): ?>
      <p class="cm-muted">No pending requests.</p>
    <?php else: ?>
      <ul class="cm-people">
        <?php foreach ($incoming as $req): ?>
          <li>
            <a href="<?= $e(url('community/profile', ['u' => (int) $req['requested_by']])) ?>">
              <?= $e(community_display_name($req)) ?>
            </a>
            <form method="post" action="<?= $e(url('community/friends/accept')) ?>">
              <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
              <button type="submit" class="cm-btn is-accent">Accept</button>
            </form>
            <form method="post" action="<?= $e(url('community/friends/decline')) ?>">
              <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
              <button type="submit" class="cm-btn">Decline</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($outgoing !== []): ?>
      <h3 class="cm-subtitle">Sent</h3>
      <ul class="cm-people">
        <?php foreach ($outgoing as $req): ?>
          <li>
            <a href="<?= $e(url('community/profile', ['u' => (int) $req['other_id']])) ?>">
              <?= $e(community_display_name($req)) ?>
            </a>
            <span class="cm-pill">Pending</span>
            <form method="post" action="<?= $e(url('community/friends/cancel')) ?>">
              <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
              <button type="submit" class="cm-btn">Cancel</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="cm-panel">
    <h2>Your friends (<?= count($friends) ?>)</h2>
    <?php if ($friends === []): ?>
      <p class="cm-muted">No friends yet.</p>
    <?php else: ?>
      <ul class="cm-people">
        <?php foreach ($friends as $person): ?>
          <li>
            <a href="<?= $e(url('community/profile', ['u' => (int) $person['id']])) ?>">
              <?= $e(community_display_name($person)) ?>
            </a>
            <form method="post" action="<?= $e(url('community/friends/unfriend')) ?>"
                  onsubmit="return confirm('Remove this friend?');">
              <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
              <button type="submit" class="cm-btn">Unfriend</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
