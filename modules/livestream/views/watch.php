<?php
/** @var array<string,mixed> $stream */
/** @var bool $isLive */
/** @var string $hlsUrl */
/** @var string $hlsFallback */
/** @var string $statusUrl */
$e = static fn(string|int|float $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$isLive = !empty($isLive);
$hlsUrl = (string) ($hlsUrl ?? '');
$hlsFallback = (string) ($hlsFallback ?? '');
$statusUrl = (string) ($statusUrl ?? '');
$slug = (string) ($stream['slug'] ?? '');
?>
<div class="ls-shell"
     data-ls-watch
     data-slug="<?= $e($slug) ?>"
     data-live="<?= $isLive ? '1' : '0' ?>"
     data-hls="<?= $e($hlsUrl) ?>"
     data-hls-fallback="<?= $e($hlsFallback) ?>"
     data-ls-status-url="<?= $e($statusUrl) ?>">
  <p class="ls-back"><a href="<?= $e(url('livestream')) ?>">&larr; All livestreams</a></p>
  <h1 class="ls-watch-title">
    <?= $e((string) ($stream['title'] ?? 'Livestream')) ?>
    <span class="ls-badge" id="ls-watch-badge"<?= $isLive ? '' : ' hidden' ?>>LIVE</span>
    <span class="ls-badge is-off" id="ls-watch-badge-off"<?= $isLive ? ' hidden' : '' ?>>Offline</span>
  </h1>

  <div class="ls-player-wrap">
    <video id="ls-player" class="ls-player" controls autoplay muted playsinline
           <?= $isLive ? '' : ' hidden' ?>></video>
    <div class="ls-offline" id="ls-offline"<?= $isLive ? ' hidden' : '' ?>>
      This stream is offline. It will start automatically when the broadcaster goes live.
    </div>
  </div>
</div>
