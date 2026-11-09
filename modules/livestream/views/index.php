<?php
/** @var list<array<string,mixed>> $previews */
/** @var string $statusUrl */
$previews = $previews ?? [];
$statusUrl = (string) ($statusUrl ?? '');
$e = static fn(string|int|float $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$liveCount = 0;
foreach ($previews as $p) {
    if (!empty($p['live'])) {
        $liveCount++;
    }
}
?>
<div class="ls-mv-page" data-ls-status-url="<?= $e($statusUrl) ?>">
  <header class="ls-mv-toolbar">
    <div class="ls-mv-brand">
      <strong>Multiviewer</strong>
      <span class="ls-muted" id="ls-mv-live-count">
        <?= (int) $liveCount ?> live
        · <?= count($previews) ?> source<?= count($previews) === 1 ? '' : 's' ?>
      </span>
    </div>
    <div class="ls-mv-actions">
      <button type="button" class="ls-btn is-ghost" id="ls-mv-enable-audio" hidden>
        Enable audio meters
      </button>
      <span class="ls-muted ls-mv-audio-hint" id="ls-mv-audio-hint" hidden>
        Click once to unlock VU meters
      </span>
      <a class="ls-btn is-ghost" href="<?= $e(url('livestream/studio')) ?>">Studio</a>
    </div>
  </header>

  <?php if ($previews === []): ?>
    <p class="ls-muted ls-mv-empty">No streams yet. Open studio to create one.</p>
  <?php else: ?>
    <div class="ls-mv-grid" id="ls-mv-grid">
      <?php foreach ($previews as $tile): ?>
        <?php
          $live = !empty($tile['live']);
          $idx = (int) ($tile['index'] ?? 0);
          $slug = (string) ($tile['slug'] ?? '');
          $label = (string) ($tile['label'] ?? $slug);
          $watch = (string) ($tile['watch'] ?? '#');
          $hls = (string) ($tile['hls'] ?? '');
          $hlsFallback = (string) ($tile['hls_fallback'] ?? '');
          $title = (string) ($tile['title'] ?? $slug);
        ?>
        <article class="ls-mv-tile<?= $live ? ' is-live' : ' is-off' ?>"
                 data-ls-mv-tile
                 data-slug="<?= $e($slug) ?>"
                 data-live="<?= $live ? '1' : '0' ?>"
                 data-hls="<?= $e($hls) ?>"
                 data-hls-fallback="<?= $e($hlsFallback) ?>"
                 title="<?= $e($title) ?>">
          <div class="ls-mv-stage">
            <div class="ls-mv-vu ls-mv-vu-l" aria-hidden="true" aria-label="Audio level">
              <?php for ($b = 0; $b < 18; $b++): ?><span></span><?php endfor; ?>
            </div>
            <div class="ls-mv-video-wrap">
              <video class="ls-mv-video" muted autoplay playsinline crossorigin="anonymous"
                    <?= $live ? '' : ' hidden' ?>></video>
              <div class="ls-mv-offline"<?= $live ? ' hidden' : '' ?>>OFF AIR</div>
              <span class="ls-mv-tally"<?= $live ? '' : ' hidden' ?>>LIVE</span>
            </div>
          </div>
          <div class="ls-mv-meta">
            <span class="ls-mv-ch">[<?= $idx ?>]</span>
            <a class="ls-mv-url" href="<?= $e($watch) ?>" title="<?= $e($slug) ?>">
              <?= $e($label !== '' ? $label : $title) ?>
            </a>
            <a class="ls-mv-open" href="<?= $e($watch) ?>" title="Open watch page">↗</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
