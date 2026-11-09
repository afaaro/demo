<?php
declare(strict_types=1);
/** @var int $unread */
$e = $e ?? static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$unread = (int) ($unread ?? 0);
$current = (string) (registry('request')->currentRoute() ?? '');
?>
<nav class="cm-nav">
  <a class="cm-brand" href="<?= $e(url('community')) ?>">
    <strong>Community</strong>
    <span>Friends &amp; feed</span>
  </a>
  <div class="cm-nav-links">
    <a class="<?= str_starts_with($current, 'community') && !str_contains($current, '/') ? 'is-active' : '' ?>"
       href="<?= $e(url('community')) ?>">Feed</a>
    <a class="<?= str_starts_with($current, 'community/friends') ? 'is-active' : '' ?>"
       href="<?= $e(url('community/friends')) ?>">Friends</a>
    <a class="<?= str_starts_with($current, 'community/profile') ? 'is-active' : '' ?>"
       href="<?= $e(url('community/profile')) ?>">Profile</a>
    <a class="<?= str_starts_with($current, 'community/notifications') ? 'is-active' : '' ?>"
       href="<?= $e(url('community/notifications')) ?>">
      Notifications
      <?php if ($unread > 0): ?>
        <span class="cm-badge"><?= $e((string) min(99, $unread)) ?></span>
      <?php endif; ?>
    </a>
  </div>
</nav>
