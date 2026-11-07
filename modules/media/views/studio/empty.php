<?php
declare(strict_types=1);
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<div class="media-shell">
  <div class="media-empty" style="padding:4rem 1.5rem">
    <h1 style="color:var(--media-ink);margin:0 0 .5rem">Creator Studio</h1>
    <p>Create a channel to upload videos, audio, images, or link YouTube / Vimeo.</p>
    <p style="margin-top:1.25rem">
      <a class="media-btn is-accent" href="<?= $e(url('media/studio/create')) ?>">Create channel</a>
      <a class="media-btn is-ghost" href="<?= $e(url('media')) ?>">Browse feed</a>
    </p>
  </div>
</div>
