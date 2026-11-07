<?php
declare(strict_types=1);
/** @var int $channelId */
/** @var string $csrf */
/** @var string $chunkUrl */
/** @var string $saveUrl */
/** @var string $linkUrl */
/** @var string $studioUrl */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<div class="media-shell">
  <div class="media-upload-box">
    <h1 style="margin-top:0">Upload media</h1>
    <p class="media-meta">Files upload in 1MB chunks (works with a 2M PHP limit). Or paste a YouTube / Vimeo / direct URL.</p>

    <div class="media-tabs">
      <button type="button" class="is-active" data-media-tab="file">File</button>
      <button type="button" data-media-tab="link">Link</button>
    </div>

    <div data-media-panel="file">
      <label for="media-file">Choose file</label>
      <input id="media-file" type="file" accept="video/*,audio/*,image/*"
             onchange="mediaUploadFile(this.files[0], <?= (int) $channelId ?>)">
      <div class="media-progress" id="media-progress"><span></span></div>
      <div class="media-meta" id="media-progress-label"></div>
    </div>

    <div data-media-panel="link" hidden>
      <label for="media-link-title">Title</label>
      <input id="media-link-title" type="text" maxlength="255" placeholder="Optional title">

      <label for="media-link-url">URL</label>
      <input id="media-link-url" type="url" placeholder="https://www.youtube.com/watch?v=…">

      <label for="media-link-desc">Description</label>
      <textarea id="media-link-desc" rows="3"></textarea>

      <button type="button" class="media-btn is-accent" onclick="mediaSaveLink(<?= (int) $channelId ?>)">Add link</button>
    </div>

    <p style="margin-top:1.25rem">
      <a class="media-btn is-ghost" href="<?= $e($studioUrl) ?>">Back to studio</a>
    </p>
  </div>
</div>
<script>
window.MEDIA_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MEDIA_URLS = {
  chunk: <?= json_encode($chunkUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  save: <?= json_encode($saveUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  link: <?= json_encode($linkUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  studio: <?= json_encode($studioUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>
