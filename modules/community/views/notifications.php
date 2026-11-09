<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $unread */
/** @var string $csrf */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$linkFor = static function (array $n): string {
    $type = (string) ($n['type'] ?? '');
    $entityType = (string) ($n['entity_type'] ?? '');
    $entityId = (string) ($n['entity_id'] ?? '');
    if ($entityType === 'post' || str_starts_with($type, 'post_')) {
        return url('community/post', ['id' => (int) $entityId]);
    }
    if ($entityType === 'user' || str_starts_with($type, 'friend_')) {
        return url('community/profile', ['u' => (int) ($n['actor_id'] ?? $entityId)]);
    }
    return url('community/notifications');
};
?>
<div class="cm-shell cm-shell--narrow">
  <?php include __DIR__ . '/partials/nav.php'; ?>

  <div class="cm-panel__head">
    <h1 class="cm-title">Notifications</h1>
    <?php if ($unread > 0): ?>
      <form method="post" action="<?= $e(url('community/notifications/read')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <button type="submit" class="cm-btn">Mark all read</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($items === []): ?>
    <div class="cm-empty">
      <p class="cm-empty__title">All caught up</p>
      <p>Likes, comments, and friend requests will show up here.</p>
    </div>
  <?php else: ?>
    <ul class="cm-notifs">
      <?php foreach ($items as $n): ?>
        <?php $isUnread = !(int) ($n['is_read'] ?? 0); ?>
        <li class="<?= $isUnread ? 'is-unread' : '' ?>" data-notif-id="<?= (int) $n['id'] ?>">
          <a href="<?= $e($linkFor($n)) ?>"
             data-cm-notif-link
             data-notif-id="<?= (int) $n['id'] ?>"
             data-is-unread="<?= $isUnread ? '1' : '0' ?>">
            <strong><?= $e((string) ($n['actor_name'] ?? 'Someone')) ?></strong>
            <?= $e(community_notification_label((string) ($n['type'] ?? ''))) ?>
            <time><?= $e(community_relative_time($n['created_at'] ?? null)) ?></time>
          </a>
          <?php if ($isUnread): ?>
            <form method="post" action="<?= $e(url('community/notifications/read')) ?>">
              <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
              <button type="submit" class="cm-btn">Read</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<script>
window.COMMUNITY_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.COMMUNITY_NOTIF_READ_URL = <?= json_encode(url('community/notifications/read'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
