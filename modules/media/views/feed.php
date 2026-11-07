<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $feed */
/** @var string $format */
/** @var string $q */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$filters = [
    'all'    => 'All',
    'video'  => 'Video',
    'audio'  => 'Audio',
    'images' => 'Images',
];
?>
<div class="media-shell">
  <div class="media-top">
    <a class="media-brand" href="<?= $e(url('media')) ?>">
      <strong>Media</strong>
      <span>Watch &amp; share</span>
    </a>

    <form class="media-search" method="get" action="<?= $e(url('media')) ?>">
      <?php if ($format !== 'all'): ?>
        <input type="hidden" name="format" value="<?= $e($format) ?>">
      <?php endif; ?>
      <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Search media…" aria-label="Search media">
      <button type="submit">Search</button>
    </form>

    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <?php if (!empty($this->auth) && $this->auth->check()): ?>
        <a class="media-btn is-accent" href="<?= $e(url('media/studio')) ?>">Studio</a>
      <?php else: ?>
        <a class="media-btn is-ghost" href="<?= $e(url('user/login', ['next' => 'media/studio'])) ?>">Sign in</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="media-filters">
    <?php foreach ($filters as $key => $label): ?>
      <a class="<?= $format === $key ? 'is-active' : '' ?>"
         href="<?= $e(url('media', array_filter(['format' => $key === 'all' ? null : $key, 'q' => $q !== '' ? $q : null]))) ?>">
        <?= $e($label) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($feed === []): ?>
    <div class="media-empty">
      <p>No media yet<?= $q !== '' ? ' matching your search' : '' ?>.</p>
      <?php if (!empty($this->auth) && $this->auth->check()): ?>
        <p><a class="media-btn is-accent" href="<?= $e(url('media/studio')) ?>">Open Studio</a></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="media-grid">
      <?php foreach ($feed as $item): ?>
        <?php
          $thumb = media_asset_url($item['thumbnail_path'] ?? null);
          $type = (string) ($item['media_type'] ?? 'video');
          $icon = match ($type) {
              'audio' => '♪',
              'image' => '▣',
              'embed' => '▶',
              default => '▶',
          };
        ?>
        <a class="media-card" href="<?= $e(url('media/watch', ['id' => (string) $item['video_id']])) ?>">
          <div class="media-thumb">
            <?php if ($thumb !== ''): ?>
              <img src="<?= $e($thumb) ?>" alt="" loading="lazy">
            <?php else: ?>
              <div class="media-thumb-fallback"><?= $icon ?></div>
            <?php endif; ?>
            <span class="media-badge"><?= $e($type) ?></span>
          </div>
          <h3><?= $e((string) $item['title']) ?></h3>
          <div class="media-meta">
            <?= $e((string) ($item['channel_title'] ?? '')) ?>
            · <?= $e(media_format_views((int) ($item['views_count'] ?? 0))) ?> views
            · <?= $e(media_relative_time($item['created_at'] ?? null)) ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
