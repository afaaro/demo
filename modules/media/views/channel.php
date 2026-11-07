<?php
declare(strict_types=1);
/** @var array<string,mixed> $channel */
/** @var list<array<string,mixed>> $items */
/** @var int $page */
/** @var int $totalPages */
/** @var int $total */

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$avatar = media_asset_url($channel['avatar'] ?? null);
$channelId = (int) $channel['channel_id'];
?>
<div class="media-shell">
  <div class="media-channel-hero">
    <?php if ($avatar !== ''): ?>
      <img class="media-avatar" src="<?= $e($avatar) ?>" alt="">
    <?php else: ?>
      <span class="media-avatar">▣</span>
    <?php endif; ?>
    <div>
      <h1 style="margin:0;font-size:1.6rem"><?= $e((string) $channel['title']) ?></h1>
      <p><?= number_format($total) ?> public item<?= $total === 1 ? '' : 's' ?></p>
      <?php if (!empty($channel['description'])): ?>
        <p><?= $e((string) $channel['description']) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($items === []): ?>
    <div class="media-empty">This channel has no public media yet.</div>
  <?php else: ?>
    <div class="media-grid">
      <?php foreach ($items as $item): ?>
        <?php
          $thumb = media_asset_url($item['thumbnail_path'] ?? null);
          $type = (string) ($item['media_type'] ?? 'video');
        ?>
        <a class="media-card" href="<?= $e(url('media/watch', ['id' => (string) $item['video_id']])) ?>">
          <div class="media-thumb">
            <?php if ($thumb !== ''): ?>
              <img src="<?= $e($thumb) ?>" alt="" loading="lazy">
            <?php else: ?>
              <div class="media-thumb-fallback">▶</div>
            <?php endif; ?>
            <span class="media-badge"><?= $e($type) ?></span>
          </div>
          <h3><?= $e((string) $item['title']) ?></h3>
          <div class="media-meta">
            <?= $e(media_format_views((int) ($item['views_count'] ?? 0))) ?> views
            · <?= $e(media_relative_time($item['created_at'] ?? null)) ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="media-pager">
        <?php if ($page > 1): ?>
          <a class="media-btn is-ghost is-sm" href="<?= $e(url('media/channel', ['id' => $channelId, 'page' => $page - 1])) ?>">Prev</a>
        <?php endif; ?>
        <span class="media-meta">Page <?= (int) $page ?> / <?= (int) $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="media-btn is-ghost is-sm" href="<?= $e(url('media/channel', ['id' => $channelId, 'page' => $page + 1])) ?>">Next</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
