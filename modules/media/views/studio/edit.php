<?php
declare(strict_types=1);
/** @var array<string,mixed> $video */
/** @var int $channelId */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<div class="media-shell">
  <div class="media-upload-box">
    <h1 style="margin-top:0">Edit media</h1>
    <form method="post" action="<?= $e(url('media/studio/edit', ['id' => (string) $video['video_id'], 'channel_id' => $channelId])) ?>">
      <?= $this->request->csrfField() ?>

      <label for="title">Title</label>
      <input id="title" type="text" name="title" required maxlength="255" value="<?= $e((string) $video['title']) ?>">

      <label for="description">Description</label>
      <textarea id="description" name="description" rows="4"><?= $e((string) ($video['description'] ?? '')) ?></textarea>

      <label for="status">Visibility</label>
      <select id="status" name="status">
        <?php foreach (['public' => 'Public', 'private' => 'Private', 'processing' => 'Processing'] as $val => $label): ?>
          <option value="<?= $val ?>" <?= ($video['status'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>

      <div style="display:flex;gap:.5rem;justify-content:flex-end">
        <a class="media-btn is-ghost" href="<?= $e(url('media/studio', ['channel_id' => $channelId])) ?>">Cancel</a>
        <button class="media-btn is-accent" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>
